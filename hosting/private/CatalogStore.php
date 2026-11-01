<?php
declare(strict_types=1);
namespace BotanikArt;

/** Independent website catalogue. Marketplace data is never refreshed or published here. */
final class CatalogStore
{
    public \PDO $db;

    public function __construct(private string $directory, private string $snapshot)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Catalog storage unavailable');
        $this->db = new \PDO('sqlite:' . $directory . '/catalogue.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        @chmod($directory . '/catalogue.sqlite', 0600);
        $this->db->exec('PRAGMA busy_timeout=10000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS products (id TEXT PRIMARY KEY, source TEXT NOT NULL, document TEXT NOT NULL, hidden INTEGER NOT NULL DEFAULT 0, missing INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL)');
        $columns = array_column($this->db->query('PRAGMA table_info(products)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        foreach (['managed','deleted'] as $column) if (!in_array($column, $columns, true)) $this->db->exec('ALTER TABLE products ADD COLUMN ' . $column . ' INTEGER NOT NULL DEFAULT 0');
        $this->db->exec('CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS login_attempts (address TEXT PRIMARY KEY, attempts INTEGER NOT NULL, started INTEGER NOT NULL)');
        $this->initializeEmptyCatalogue();
        $this->migrateStandaloneOnce();
        $this->migratePlantCategoriesOnce();
        $this->migratePlantTypesOnce();
    }

    private function marker(string $name): bool
    {
        $q=$this->db->prepare('SELECT 1 FROM metadata WHERE name=?');$q->execute([$name]);return (bool)$q->fetchColumn();
    }

    /** A snapshot can seed a genuinely empty install once; it never updates an existing catalogue. */
    private function initializeEmptyCatalogue(): void
    {
        if ((int)$this->db->query('SELECT count(*) FROM products')->fetchColumn() !== 0 || !is_file($this->snapshot)) return;
        $catalog=json_decode((string)file_get_contents($this->snapshot),true,512,JSON_THROW_ON_ERROR);
        if (empty($catalog['products']) || !is_array($catalog['products'])) return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ((int)$this->db->query('SELECT count(*) FROM products')->fetchColumn() !== 0) { $this->db->exec('COMMIT');return; }
            $insert=$this->db->prepare('INSERT OR IGNORE INTO products(id,source,document,hidden,missing,managed,deleted,updated_at) VALUES(?,?,?,?,0,1,0,?)');
            foreach($catalog['products'] as $p){
                if(!is_array($p)||empty($p['id'])||!empty($p['assortmentContainer']))continue;
                $p=$this->normalizeProduct($p);
                $insert->execute([$p['id'],'manual',json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)!empty($p['hidden']),gmdate('c')]);
            }
            unset($catalog['products']);
            $set=$this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
            $set->execute(['snapshot_meta',json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $set->execute(['standalone_catalog:v1','initialized_empty']);
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

    private function normalizeProduct(array $p): array
    {
        foreach(['source','url','avitoItemId','avitoVariant','flowwowProductId','flowwowOfferId','assortmentId','assortmentContainer','assortmentSignature','channels','sourceMissing','managed','deleted','version','updatedAt'] as $key)unset($p[$key]);
        $p['purchaseMode']=in_array($p['purchaseMode']??'direct',['direct','inquiry'],true)?($p['purchaseMode']??'direct'):'direct';
        $p['catalogGroup']=$p['catalogGroup']??$p['category']??'potted';
        if(!in_array($p['catalogGroup'],['florarium','composition','potted','plant','material'],true))$p['catalogGroup']='potted';
        if($p['catalogGroup']==='plant'){$p['catalogGroup']='potted';$p['potType']='technical';}
        if($p['catalogGroup']==='potted'&&!in_array($p['potType']??'', ['technical','cachepot'],true)){
            $text=mb_strtolower(($p['title']??'').' '.($p['description']??''));
            $p['potType']=preg_match('/тех(?:ническ[а-я]*|\.?)[\s.-]*горш|транспортировочн[а-я]*\s+горш|без\s+(?:декоративного\s+)?кашпо/u',$text)?'technical':(preg_match('/кашпо|керамическ[а-я]*\s+горш|декоративн[а-я]*\s+горш/u',$text)?'cachepot':'technical');
        }
        if($p['catalogGroup']==='potted'&&!in_array($p['plantType']??'', ['succulent','indoor','terrarium'],true)){
            $title=mb_strtolower($p['title']??'');$description=mb_strtolower($p['description']??'');
            if(preg_match('/суккулент|кактус|алоэ|эхевер|эчевер|хаворт|крассул|толстян|литопс|эониум|седум|очиток|гастери|крестовник\s+роули|curio|senecio/u',$title))$p['plantType']='succulent';
            elseif(preg_match('/для\s+флорариум/u',$title)||(($p['potType']??'')!=='cachepot'&&preg_match('/(?:подход[а-я]*|идеальн[а-я]*|растени[а-я]*)[^.!?\n]{0,65}для\s+(?:озеленения\s+|закрытых\s+|влажных\s+)?флорариум|для\s+закрытых\s+флорариум/u',$description)))$p['plantType']='terrarium';
            else $p['plantType']='indoor';
        }
        $p['category']=$p['catalogGroup'];
        if(($p['status']??'')==='order')$p['status']='preorder';
        if(!in_array($p['status']??'',['ready','preorder','sold'],true))$p['status']=!empty($p['available'])?'ready':'preorder';
        $p['available']=$p['status']==='ready'&&!empty($p['available']);
        return $p;
    }

    /** One-time transactional conversion; assortment parents are archived, their real products stay independent. */
    private function migrateStandaloneOnce(): void
    {
        if($this->marker('standalone_catalog:v1'))return;
        $this->backup();$this->db->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->db->query('SELECT id,document,hidden,missing,deleted FROM products');
            $update=$this->db->prepare('UPDATE products SET source=?,document=?,hidden=?,missing=0,managed=1,deleted=?,version=version+1,updated_at=? WHERE id=?');
            $parents=0;$products=0;
            foreach($q as $row){
                $p=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
                $parent=!empty($p['assortmentContainer']);
                if($parent){$parents++;$p=$this->normalizeProduct($p);$p['title']='Архив ассортимента';$p['description']='';$p['images']=[];$p['available']=false;$p['status']='sold';}
                else{$products++;$p=$this->normalizeProduct($p);}
                $hidden=(int)((bool)$row['hidden']||(bool)$row['missing']||$parent);
                $deleted=(int)((bool)$row['deleted']||$parent);
                $update->execute(['manual',json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$hidden,$deleted,gmdate('c'),$row['id']]);
            }
            // Keep the backup as the migration ledger; these tables are no longer active.
            foreach(['assortment_members','assortments','publication_jobs','product_channels'] as $table)
                if($this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".$this->db->quote($table))->fetchColumn())$this->db->exec('DROP TABLE "'.$table.'"');
            $this->db->exec('DROP INDEX IF EXISTS avito_variant_reference');$this->db->exec('DROP INDEX IF EXISTS avito_reference');
            $meta=$this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
            $meta->execute(['standalone_catalog:v1',json_encode(['completedAt'=>gmdate('c'),'products'=>$products,'archivedAssortmentParents'=>$parents],JSON_THROW_ON_ERROR)]);
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

    private function migratePlantCategoriesOnce(): void
    {
        if($this->marker('plant_categories:v2'))return;
        $this->backup();$this->db->exec('BEGIN IMMEDIATE');
        try{
            $update=$this->db->prepare('UPDATE products SET document=?,version=version+1 WHERE id=?');
            foreach($this->db->query('SELECT id,document FROM products') as $row){
                $p=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
                if(!in_array($p['catalogGroup']??'', ['plant','potted'],true))continue;
                $update->execute([json_encode($this->normalizeProduct($p),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id']]);
            }
            $this->db->exec("INSERT INTO metadata(name,value) VALUES('plant_categories:v2','done')");$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

    private function migratePlantTypesOnce(): void
    {
        if($this->marker('plant_types:v3'))return;
        $this->backup();$this->db->exec('BEGIN IMMEDIATE');
        try{
            $update=$this->db->prepare('UPDATE products SET document=?,version=version+1 WHERE id=?');
            foreach($this->db->query('SELECT id,document FROM products') as $row){
                $p=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
                if(($p['catalogGroup']??'')!=='potted')continue;
                $update->execute([json_encode($this->normalizeProduct($p),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id']]);
            }
            $this->db->exec("INSERT INTO metadata(name,value) VALUES('plant_types:v3','done')");$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

    public function all(bool $administrative=false): array
    {
        $sql=$administrative?'SELECT *,rowid AS catalogue_order FROM products ORDER BY rowid':'SELECT *,rowid AS catalogue_order FROM products WHERE hidden=0 AND missing=0 AND deleted=0 ORDER BY rowid';
        $result=[];
        foreach($this->db->query($sql) as $row){$p=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);$p['catalogueOrder']=(int)$row['catalogue_order'];
            if($administrative){$p['deleted']=(bool)$row['deleted'];$p['hidden']=(bool)$row['hidden'];$p['version']=(int)$row['version'];$p['updatedAt']=$row['updated_at'];}
            $result[]=$p;
        }
        return $result;
    }

    public function publicCatalog(): array
    {
        $raw=$this->db->query("SELECT value FROM metadata WHERE name='snapshot_meta'")->fetchColumn();
        $catalog=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        $catalog['products']=array_map(function($p){if(!empty($p['soldOnSite'])){$p['available']=false;$p['status']='sold';$p['canOrder']=true;}return $p;},$this->all());
        $ids=array_column($catalog['products'],'id');$catalog['featured']=array_values(array_filter($catalog['featured']??[],fn($id)=>in_array($id,$ids,true)));
        return $catalog;
    }

    public function save(array $input): string
    {
        $this->backup();$this->db->exec('SAVEPOINT product_save');
        try{$id=$this->saveProduct($input);$this->db->exec('RELEASE SAVEPOINT product_save');return $id;}
        catch(\Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT product_save');$this->db->exec('RELEASE SAVEPOINT product_save');throw $e;}
    }

    private function saveProduct(array $input): string
    {
        $id=$input['id']??'';if(!is_string($id)||strlen($id)>180)throw new \InvalidArgumentException('invalid_product');
        $find=$this->db->prepare('SELECT * FROM products WHERE id=?');$find->execute([$id]);$existing=$find->fetch(\PDO::FETCH_ASSOC);
        if($id!==''&&!$existing)throw new \OutOfBoundsException('product_missing');
        $version=$input['version']??0;if(!is_int($version)||($existing&&(int)$version!==(int)$existing['version']))throw new \UnexpectedValueException('edit_conflict');
        if($existing&&(int)$existing['deleted'])throw new \InvalidArgumentException('invalid_product');
        $hidden=($input['hidden']??false)===true;
        if($existing&&!array_key_exists('description',$input)){
            $update=$this->db->prepare('UPDATE products SET hidden=?,version=version+1,updated_at=? WHERE id=? AND version=?');$update->execute([(int)$hidden,gmdate('c'),$id,$version]);
        }else{
            $prior=$existing?json_decode($existing['document'],true,512,JSON_THROW_ON_ERROR):[];
            $title=trim((string)($input['title']??''));$description=trim((string)($input['description']??''));$price=$input['price']??null;$group=$input['catalogGroup']??'';$status=$input['status']??'';
            $purchaseMode=$input['purchaseMode']??($prior['purchaseMode']??'direct');
            if(mb_strlen($title)<2||mb_strlen($title)>250||mb_strlen($description)>30000||!is_numeric($price)||(float)$price<=0||(float)$price>10000000||!in_array($group,['florarium','composition','potted','plant','material'],true)||!in_array($status,['ready','preorder','sold'],true)||!in_array($purchaseMode,['direct','inquiry'],true))throw new \InvalidArgumentException('invalid_product');
            $images=$input['images']??[];if(!is_array($images)||!array_is_list($images)||!$images||count($images)>20)throw new \InvalidArgumentException('invalid_images');
            foreach($images as $image){$uploaded=is_string($image)&&preg_match('~^assets/uploads/catalogue/[a-f0-9]{32}\.jpg$~D',$image)&&is_file(dirname($this->snapshot,2).'/'.$image);if(!$uploaded&&!in_array($image,$prior['images']??[],true))throw new \InvalidArgumentException('invalid_images');}
            $soldOnSite=!empty($prior['soldOnSite'])&&$status==='sold';if($soldOnSite)$status=$prior['status']??'ready';
            $product=array_merge($prior,['id'=>$id?:'local-'.bin2hex(random_bytes(12)),'title'=>$title,'description'=>$description,'price'=>round((float)$price,2),'category'=>$group,'catalogGroup'=>$group,'plantType'=>($group==='potted'&&in_array($input['plantType']??'', ['succulent','indoor','terrarium'],true)?$input['plantType']:($prior['plantType']??null)),'potType'=>($group==='potted'&&in_array($input['potType']??'', ['technical','cachepot'],true)?$input['potType']:'unspecified'),'images'=>array_values(array_unique($images)),'available'=>$status==='ready','status'=>$status,'purchaseMode'=>$purchaseMode,'soldOnSite'=>$soldOnSite]);
            $product=$this->normalizeProduct($product);$id=$product['id'];$doc=json_encode($product,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($existing){$update=$this->db->prepare('UPDATE products SET source=\'manual\',document=?,hidden=?,missing=0,managed=1,version=version+1,updated_at=? WHERE id=? AND version=?');$update->execute([$doc,(int)$hidden,gmdate('c'),$id,$version]);}
            else{$update=$this->db->prepare('INSERT INTO products(id,source,document,hidden,missing,managed,deleted,updated_at) VALUES(?,\'manual\',?,?,0,1,0,?)');$update->execute([$id,$doc,(int)$hidden,gmdate('c')]);}
        }
        if($update->rowCount()!==1)throw new \UnexpectedValueException('edit_conflict');return $id;
    }

    public function confirmSale(string $id,string $orderId): string
    {
        $this->backup();$this->db->exec('SAVEPOINT confirm_sale');
        try{$q=$this->db->prepare('SELECT document FROM products WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();if(!$raw)throw new \OutOfBoundsException('product_missing');$p=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$kind=in_array($p['catalogGroup']??$p['category']??'',['florarium','composition'],true)?'single':'repeat';$q=$this->db->prepare('SELECT value FROM metadata WHERE name=?');$q->execute(['sale:'.$orderId]);$prior=$q->fetchColumn();if($prior!==false){$this->db->exec('RELEASE SAVEPOINT confirm_sale');return (string)json_decode($prior,true);}if($kind==='single'){$p['soldOnSite']=true;$p['saleOrderId']=$orderId;$q=$this->db->prepare('UPDATE products SET document=?,managed=1,version=version+1,updated_at=? WHERE id=?');$q->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$id]);}$q=$this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?)');$q->execute(['sale:'.$orderId,json_encode($kind)]);$this->db->exec('RELEASE SAVEPOINT confirm_sale');return $kind;}
        catch(\Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT confirm_sale');$this->db->exec('RELEASE SAVEPOINT confirm_sale');throw $e;}
    }

    public function archive(string $id,int $version,bool $restore=false): void
    {
        $this->backup();$q=$this->db->prepare('UPDATE products SET deleted=?,version=version+1,updated_at=? WHERE id=? AND version=?');$q->execute([$restore?0:1,gmdate('c'),$id,$version]);if($q->rowCount()!==1)throw new \UnexpectedValueException('edit_conflict');
    }

    private function backup(): void
    {
        $backup=$this->directory.'/backup-'.gmdate('Y-m-d').'.sqlite';if(is_file($backup))return;$lock=fopen($this->directory.'/backup.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Catalog backup unavailable');
        try{if(!is_file($backup)){$this->db->exec('VACUUM INTO '.$this->db->quote($backup));chmod($backup,0600);$files=glob($this->directory.'/backup-*.sqlite');rsort($files);foreach(array_slice($files,14) as $file)unlink($file);}}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

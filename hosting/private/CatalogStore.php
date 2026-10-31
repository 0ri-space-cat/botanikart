<?php
declare(strict_types=1);

namespace BotanikArt;

/** The server owns local products and visibility; Flowwow refreshes only its own records. */
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
        $columns=array_column($this->db->query('PRAGMA table_info(products)')->fetchAll(\PDO::FETCH_ASSOC),'name');
        foreach (['managed','deleted'] as $column) if (!in_array($column,$columns,true)) $this->db->exec('ALTER TABLE products ADD COLUMN '.$column.' INTEGER NOT NULL DEFAULT 0');
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS avito_variant_reference ON products(json_extract(document,'$.avitoItemId'),COALESCE(json_extract(document,'$.avitoVariant'),'')) WHERE source='avito'");
        $this->db->exec('DROP INDEX IF EXISTS avito_reference');
        $this->db->exec('CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS login_attempts (address TEXT PRIMARY KEY, attempts INTEGER NOT NULL, started INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS product_channels (product_id TEXT NOT NULL, channel TEXT NOT NULL, document TEXT NOT NULL, PRIMARY KEY(product_id,channel))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS publication_jobs (product_id TEXT NOT NULL, channel TEXT NOT NULL, revision TEXT NOT NULL, state TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(product_id,channel))');
        $this->refresh();
        $this->seedChannels();
        $this->refreshExternalFlowwowChannels();
    }

    private function refreshExternalFlowwowChannels(): void
    {
        $file=dirname($this->directory).'/flowwow-source.json';if(!is_file($file))return;
        $source=json_decode(file_get_contents($file),true);$index=[];foreach($source['items']??[] as $p)$index[(string)$p['productId']]=$p;
        $q=$this->db->query("SELECT product_id,document FROM product_channels WHERE channel='flowwow' AND json_extract(document,'$.management')='external'");
        $update=$this->db->prepare("UPDATE product_channels SET document=? WHERE product_id=? AND channel='flowwow'");
        foreach($q as $row){$c=json_decode($row['document'],true);$p=$index[(string)($c['externalId']??'')]??null;if(!$p)continue;$enabled=!empty($p['isActive'])&&empty($p['isArchived']);if($c['enabled']===$enabled)continue;$c['enabled']=$enabled;$c['state']=$enabled?'external':'hidden';$update->execute([json_encode($c,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['product_id']]);}
    }

    private function refresh(): void
    {
        $raw = file_get_contents($this->snapshot);
        if ($raw === false) throw new \RuntimeException('Catalog snapshot missing');
        $hash = hash('sha256', $raw);
        $get = $this->db->prepare('SELECT value FROM metadata WHERE name=?');
        $get->execute(['snapshot_hash']);
        if ($get->fetchColumn() === $hash) return;
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (empty($catalog['products']) || !is_array($catalog['products'])) throw new \RuntimeException('Empty catalog snapshot');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $get->execute(['snapshot_hash']);
            if ($get->fetchColumn() === $hash) { $this->db->exec('COMMIT'); return; }
            $seen = [];$inputSeen=[];$aliases=[];
            $upsert = $this->db->prepare('INSERT INTO products(id,source,document,updated_at) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET document=excluded.document, missing=0, version=products.version+1, updated_at=excluded.updated_at WHERE products.source=\'flowwow\' AND products.managed=0 AND (products.document<>excluded.document OR products.missing<>0)');
            foreach ($catalog['products'] as $product) {
                $id = (string) ($product['id'] ?? '');
                if ($id === '' || isset($inputSeen[$id])) throw new \RuntimeException('Invalid catalog identifier');
                $seen[$id] = true;$inputSeen[$id]=true;
                $source = $product['source'] ?? 'flowwow';
                $product['source'] = $source;
                if($source==='flowwow'&&!empty($product['flowwowProductId'])) {
                    $q=$this->db->prepare("SELECT product_id FROM product_channels WHERE channel='flowwow' AND json_extract(document,'$.externalId')=? LIMIT 1");$q->execute([(string)$product['flowwowProductId']]);$canonical=$q->fetchColumn();
                    if(!$canonical&&!empty($product['flowwowOfferId'])&&str_starts_with($product['flowwowOfferId'],'ba-')){
                        $candidate=substr($product['flowwowOfferId'],3);$q=$this->db->prepare('SELECT id FROM products WHERE id=?');$q->execute([$candidate]);$canonical=$q->fetchColumn();
                        if($canonical){$c=$this->channels($canonical)['flowwow']??null;if($c && empty($c['externalId'])){$c['externalId']=(string)$product['flowwowProductId'];$c['offerId']=$product['flowwowOfferId'];$c['url']=$product['url']??'';$q=$this->db->prepare("UPDATE product_channels SET document=? WHERE product_id=? AND channel='flowwow'");$q->execute([json_encode($c,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$canonical]);}}
                    }
                    if($canonical && $canonical!==$id){unset($seen[$id]);$seen[$canonical]=true;$aliases[$id]=$canonical;continue;}
                }

                $upsert->execute([$id, $source, json_encode($product, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('c')]);
                if ($upsert->rowCount()>0) $this->queuePublications($id);
            }
            $missing = $this->db->prepare('UPDATE products SET missing=1, version=version+1, updated_at=? WHERE id=? AND missing=0');
            foreach ($this->db->query("SELECT id FROM products WHERE source='flowwow' AND managed=0") as $row) {
                if (!isset($seen[$row['id']])) $missing->execute([gmdate('c'), $row['id']]);
            }
            if($aliases)$catalog['featured']=array_values(array_unique(array_map(fn($id)=>$aliases[$id]??$id,$catalog['featured']??[])));
            unset($catalog['products']);
            $set = $this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
            $set->execute(['snapshot_meta', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $set->execute(['snapshot_hash', $hash]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
    }

    public function all(bool $administrative = false): array
    {
        $products = [];
        foreach ($this->db->query('SELECT * FROM products ORDER BY rowid') as $row) {
            if (!$administrative && ($row['hidden'] || $row['missing'] || $row['deleted'])) continue;
            $product = json_decode($row['document'], true, 512, JSON_THROW_ON_ERROR);
            if ($administrative) {
                $product['channels'] = $this->channels($product['id']);
                $product['deleted'] = (bool) $row['deleted'];
                $product['managed'] = (bool) $row['managed'];
                $product['hidden'] = (bool) $row['hidden'];
                $product['sourceMissing'] = (bool) $row['missing'];
                $product['version'] = (int) $row['version'];
                $product['updatedAt'] = $row['updated_at'];
            }
            $products[] = $product;
        }
        return $products;
    }

    public function publicCatalog(): array
    {
        $raw = $this->db->query("SELECT value FROM metadata WHERE name='snapshot_meta'")->fetchColumn();
        $catalog = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        $catalog['products'] = array_map(function($p){if(!empty($p['soldOnSite'])){$p['available']=false;$p['status']='sold';$p['canOrder']=true;}return $p;},$this->all());
        $ids = array_column($catalog['products'], 'id');
        $catalog['featured'] = array_values(array_filter($catalog['featured'] ?? [], fn($id) => in_array($id, $ids, true)));
        return $catalog;
    }

    public function save(array $input): string
    {
        $this->backup();
        $this->db->exec('SAVEPOINT product_save');
        try {
            $id=$this->saveProduct($input);
            $this->seedChannels($id);
            if (array_key_exists('channels',$input)) $this->saveChannels($id,$input['channels']);
            $this->queuePublications($id);
            $this->db->exec('RELEASE SAVEPOINT product_save');
            return $id;
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK TO SAVEPOINT product_save');
            $this->db->exec('RELEASE SAVEPOINT product_save');throw $error;
        }
    }

    /** Website-only sale state; external listings change only on explicit owner choice. */
    public function confirmSale(string $id,string $orderId): string
    {
        $this->backup();$this->db->exec('SAVEPOINT confirm_sale');
        try{
            $q=$this->db->prepare('SELECT document FROM products WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();if(!$raw)throw new \OutOfBoundsException('product_missing');$p=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            $kind=in_array($p['catalogGroup']??$p['category']??'',['florarium','composition'],true)?'single':'repeat';
            $q=$this->db->prepare('SELECT value FROM metadata WHERE name=?');$q->execute(['sale:'.$orderId]);$prior=$q->fetchColumn();
            if($prior!==false){$this->db->exec('RELEASE SAVEPOINT confirm_sale');return (string)json_decode($prior,true);}
            if($kind==='single'){$p['soldOnSite']=true;$p['saleOrderId']=$orderId;$q=$this->db->prepare('UPDATE products SET document=?,managed=1,version=version+1,updated_at=? WHERE id=?');$q->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$id]);}
            $q=$this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?)');$q->execute(['sale:'.$orderId,json_encode($kind)]);
            $this->db->exec('RELEASE SAVEPOINT confirm_sale');return $kind;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT confirm_sale');$this->db->exec('RELEASE SAVEPOINT confirm_sale');throw $e;}
    }
    public function withdrawSold(string $id,array $channels): void
    {
        if(array_diff($channels,['avito','flowwow']))throw new \InvalidArgumentException('invalid_channels');
        $this->backup();$this->db->exec('SAVEPOINT withdraw_sold');
        try{
            foreach($this->channels($id) as $key=>$c){if(!in_array($key,$channels,true))continue;$c['enabled']=false;$c['management']='site';$c['state']=$key==='avito'?'manual_removal':'queued';
                $q=$this->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');$q->execute([json_encode($c,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$key]);$this->queuePublications($id,$key);}
            $q=$this->db->prepare('UPDATE products SET version=version+1,updated_at=? WHERE id=?');$q->execute([gmdate('c'),$id]);$this->db->exec('RELEASE SAVEPOINT withdraw_sold');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT withdraw_sold');$this->db->exec('RELEASE SAVEPOINT withdraw_sold');throw $e;}
    }

    public function archive(string $id,int $version,bool $restore=false,bool $hideFlowwow=false): void
    {
        $this->backup();$this->db->exec('SAVEPOINT archive_product');
        try {
        $q=$this->db->prepare('UPDATE products SET deleted=?,version=version+1,updated_at=? WHERE id=? AND version=?');
        $q->execute([$restore?0:1,gmdate('c'),$id,$version]);
        if ($q->rowCount()!==1) throw new \UnexpectedValueException('edit_conflict');
        if (!$restore) {
            foreach ($this->channels($id) as $channel=>$settings) {
                if ($channel!=='avito' && !($channel==='flowwow'&&$hideFlowwow)) continue;
                $settings['enabled']=false;$settings['management']='site';$settings['state']=$channel==='avito'?'manual_removal':'queued';
                $update=$this->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');
                $update->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$channel]);
            }
            $this->queuePublications($id);
        }
        $this->db->exec('RELEASE SAVEPOINT archive_product');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT archive_product');$this->db->exec('RELEASE SAVEPOINT archive_product');throw $e;}
    }

    private function saveProduct(array $input): string
    {
        $this->backup();
        $id = $input['id'] ?? '';
        if (!is_string($id) || strlen($id) > 180) throw new \InvalidArgumentException('invalid_product');
        $find = $this->db->prepare('SELECT * FROM products WHERE id=?');
        $find->execute([$id]);
        $existing = $find->fetch(\PDO::FETCH_ASSOC);
        if ($id !== '' && !$existing) throw new \OutOfBoundsException('product_missing');
        $version = $input['version'] ?? 0;
        if (!is_int($version) || ($existing && $version !== (int) $existing['version'])) throw new \UnexpectedValueException('edit_conflict');
        if ($existing && $existing['deleted']) throw new \InvalidArgumentException('invalid_product');
        $hidden = ($input['hidden'] ?? false) === true;
        if ($existing && !array_key_exists('description',$input)) {
            // Visibility is always local. Never accept imported content edits from the browser.
            $update = $this->db->prepare('UPDATE products SET hidden=?,version=version+1,updated_at=? WHERE id=? AND version=?');
            $update->execute([(int) $hidden, gmdate('c'), $id, $version]);
        } else {
            $source = $existing['source'] ?? ($input['source'] ?? 'manual');
            if ((!$existing && $source==='flowwow') || !in_array($source, ['manual', 'avito', 'flowwow'], true)) throw new \InvalidArgumentException('invalid_source');
            $title = trim((string) ($input['title'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));
            $price = $input['price'] ?? null;
            $group = $input['catalogGroup'] ?? '';
            $status = $input['status'] ?? '';
            $purchaseMode = $input['purchaseMode'] ?? ($existing ? (json_decode($existing['document'],true)['purchaseMode'] ?? 'direct') : 'direct');
            if (mb_strlen($title) < 2 || mb_strlen($title) > 250 || mb_strlen($description) > 30000
                || !is_numeric($price) || (float) $price <= 0 || (float) $price > 10000000
                || !in_array($group, ['florarium','composition','potted','plant','material'], true)
                || !in_array($status, ['ready','preorder','sold'], true)
                || !in_array($purchaseMode,['direct','inquiry'],true)) throw new \InvalidArgumentException('invalid_product');
            $images = $input['images'] ?? [];
            if (!is_array($images) || !array_is_list($images) || !$images || count($images) > 20) throw new \InvalidArgumentException('invalid_images');
            $prior = $existing ? json_decode($existing['document'], true, 512, JSON_THROW_ON_ERROR) : [];
            foreach ($images as $image) {
                $uploaded = is_string($image) && preg_match('~^assets/uploads/catalogue/[a-f0-9]{32}\.jpg$~D', $image)
                    && is_file(dirname($this->snapshot, 2) . '/' . $image);
                if (!$uploaded && !in_array($image, $prior['images'] ?? [], true)) throw new \InvalidArgumentException('invalid_images');
            }
            $url = $source==='flowwow'?(string)($prior['url']??''):trim((string) ($input['url'] ?? ''));
            if ($source!=='flowwow' && $url !== '' && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || !in_array(parse_url($url, PHP_URL_HOST), ['avito.ru', 'www.avito.ru'], true))) throw new \InvalidArgumentException('invalid_url');
            if ($id === '') $id = ($source === 'avito' ? 'avito-local-' : 'local-') . bin2hex(random_bytes(12));
            $soldOnSite=!empty($prior['soldOnSite'])&&$status==='sold';
            if($soldOnSite)$status=$prior['status'];
            $product = array_merge($prior, ['id'=>$id,'title'=>$title,'description'=>$description,'price'=>round((float)$price, 2),
                'source'=>$source,'url'=>$url,'category'=>$group,'catalogGroup'=>$group,'images'=>array_values(array_unique($images)),
                'available'=>$status==='ready','status'=>$status,'purchaseMode'=>$purchaseMode,'soldOnSite'=>$soldOnSite]);
            if ($source === 'avito') {
                $externalId = $prior['avitoItemId'] ?? ($input['avitoItemId'] ?? '');
                if (preg_match('~(?:_|/)(\d+)(?:/)?(?:\?|$)~', $url, $match)) {
                    if ($externalId !== '' && $externalId !== $match[1]) throw new \InvalidArgumentException('invalid_source_identity');
                    $externalId = $match[1];
                }
                if ($externalId !== '') {
                    if (!is_string($externalId) || !preg_match('/^\d{5,20}$/D', $externalId)) throw new \InvalidArgumentException('invalid_source_identity');
                    $product['avitoItemId'] = $externalId;
                }
                $variant = $prior['avitoVariant'] ?? ($input['avitoVariant'] ?? '');
                if (!is_string($variant) || ($variant !== '' && !preg_match('/^[a-z0-9-]{1,100}$/D', $variant))) throw new \InvalidArgumentException('invalid_source_identity');
                if ($variant !== '') $product['avitoVariant'] = $variant;
            }
            $document = json_encode($product, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($existing) {
                $changed=false;
                foreach (['title','description','catalogGroup','status','images','purchaseMode'] as $field) {
                    $old=$prior[$field]??($field==='purchaseMode'?'direct':null);if(($product[$field]??null)!==$old)$changed=true;
                }
                if((float)$product['price']!==(float)($prior['price']??0))$changed=true;
                $managed=(int)((bool)$existing['managed']||$changed);
                $update = $this->db->prepare('UPDATE products SET document=?,hidden=?,managed=?,missing=CASE WHEN ?=1 THEN 0 ELSE missing END,version=version+1,updated_at=? WHERE id=? AND version=?');
                $update->execute([$document, (int)$hidden,$managed,$managed,gmdate('c'), $id, $version]);
            } else {
                $update = $this->db->prepare('INSERT INTO products(id,source,document,hidden,updated_at) VALUES(?,?,?,?,?)');
                $update->execute([$id,$source,$document,(int)$hidden,gmdate('c')]);
            }
        }
        if ($update->rowCount() !== 1) throw new \UnexpectedValueException('edit_conflict');
        return $id;
    }

    private function seedChannels(?string $id=null): void
    {
        $rows=$this->db->prepare('SELECT id,source,document FROM products p WHERE (? IS NULL OR p.id=?) AND (NOT EXISTS(SELECT 1 FROM product_channels c WHERE c.product_id=p.id AND c.channel=\'avito\') OR NOT EXISTS(SELECT 1 FROM product_channels c WHERE c.product_id=p.id AND c.channel=\'flowwow\'))');$rows->execute([$id,$id]);
        $insert=$this->db->prepare('INSERT OR IGNORE INTO product_channels(product_id,channel,document) VALUES(?,?,?)');
        foreach ($rows as $row) {
            $p=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
            foreach (['avito','flowwow'] as $channel) {
                $external=$row['source']===$channel;
                $remoteId=$external ? (string)($channel==='avito'?($p['avitoItemId']??''):($p['flowwowProductId']??'')) : '';
                $insert->execute([$row['id'],$channel,json_encode(['enabled'=>$external,'management'=>$external?'external':'site',
                    'externalId'=>$remoteId,'url'=>$external?($p['url']??''):'','price'=>null,'title'=>'','description'=>'',
                    'state'=>$external?'external':'off','error'=>''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function channels(string $id): array
    {
        $query=$this->db->prepare('SELECT channel,document FROM product_channels WHERE product_id=?');$query->execute([$id]);$result=[];
        foreach ($query as $row) $result[$row['channel']]=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
        return $result;
    }

    private function saveChannels(string $id, mixed $channels): void
    {
        if (!is_array($channels) || array_is_list($channels) || array_diff(array_keys($channels),['avito','flowwow'])) throw new \InvalidArgumentException('invalid_channels');
        $current=$this->channels($id);
        $update=$this->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');
        foreach ($channels as $channel=>$input) {
            if (!is_array($input) || array_diff(array_keys($input),['enabled','price','title','description','management','externalId','url','state','error','message','category','attributes','properties','stock','productionMinutes','listingTitle','listingDescription','listingPrice','sharedCount','offerId','checkedAt','configure']) || !is_bool($input['enabled']??null)) throw new \InvalidArgumentException('invalid_channels');
            $p=$current[$channel];$before=$p;
            // Merely saving website fields must not take over an imported Avito listing.
            if($channel==='avito'&&$p['management']==='external'&&($input['configure']??false)!==true&&$input['enabled']===$p['enabled'])continue;

            if ($p['management']==='external' && $input['enabled']!==$p['enabled']) $p['management']='site';
            $price=$input['price']??null;$title=trim((string)($input['title']??''));$description=trim((string)($input['description']??''));
            if (($price!==null && (!is_numeric($price)||!is_finite((float)$price)||(float)$price<=0||(float)$price>10000000)) || mb_strlen($title)>250 || mb_strlen($description)>30000) throw new \InvalidArgumentException('invalid_channels');
            if ($channel==='avito') {
                $category=$input['category']??($p['category']??'');$attributes=$input['attributes']??($p['attributes']??[]);
                if (!is_string($category)||!preg_match('/^[a-z0-9_]{0,100}$/D',$category)||!is_array($attributes)||count($attributes)>30) throw new \InvalidArgumentException('invalid_channels');
                foreach ($attributes as $key=>$value) if (!is_string($key)||!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$key)||(!is_string($value)&&!is_array($value))||strlen(json_encode($value))>2000) throw new \InvalidArgumentException('invalid_channels');
                $p['category']=$category;$p['attributes']=$attributes;
            }
            if ($channel==='flowwow') {
                $properties=$input['properties']??($p['properties']??[]);
                if (!is_array($properties)||count($properties)>80||strlen(json_encode($properties))>25000) throw new \InvalidArgumentException('invalid_channels');
                foreach ($properties as $key=>$value) if (!is_string($key)||!preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,80}$/D',$key)||(!is_scalar($value)&&!is_array($value)&&$value!==null)) throw new \InvalidArgumentException('invalid_channels');
                foreach (['stock','productionMinutes'] as $key) { $value=$input[$key]??($p[$key]??null);if ($value!==null && (!is_int($value)||$value<1||$value>10000000)) throw new \InvalidArgumentException('invalid_channels');$p[$key]=$value; }
                $p['category']=$input['category']??($p['category']??'');if (!is_string($p['category'])||($p['category']!==''&&!ctype_digit($p['category']))) throw new \InvalidArgumentException('invalid_channels');
                $p['properties']=$properties;
            }
            $changed=$input['enabled']!==$p['enabled']||$price!==($p['price']??null)||$title!==($p['title']??'')||$description!==($p['description']??'');
            $managed=(bool)$this->db->query('SELECT managed FROM products WHERE id='.$this->db->quote($id))->fetchColumn();
            if ($changed || ($input['configure']??false)===true || ($managed && $p['enabled'])) $p['management']='site';
            $p['enabled']=$input['enabled'];$p['price']=$price===null?null:round((float)$price,2);$p['title']=$title;$p['description']=$description;

            $update->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$channel]);
            if ($channel==='avito' && !empty($p['externalId']) && $p['management']==='site') {
                $siblings=$this->db->prepare("SELECT product_id,document FROM product_channels WHERE channel='avito' AND product_id<>? AND json_extract(document,'$.externalId')=?");
                $siblings->execute([$id,$p['externalId']]);
                foreach ($siblings as $row) {
                    $previous=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);
                    if($this->channelIntent($previous)!==$this->channelIntent($p)) {
                        $update->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['product_id'],$channel]);
                        $touch=$this->db->prepare('UPDATE products SET version=version+1,updated_at=? WHERE id=?');$touch->execute([gmdate('c'),$row['product_id']]);
                    }
                    $this->queuePublications($row['product_id'],'avito');
                }
            }

        }
    }

    private function channelIntent(array $settings): array
    {
        return array_intersect_key($settings,array_flip(['enabled','price','title','description','category','attributes','properties','stock','productionMinutes','management']));
    }

    private function queuePublications(string $id,?string $onlyChannel=null): void
    {
        $get=$this->db->prepare('SELECT document FROM products WHERE id=?');$get->execute([$id]);$raw=$get->fetchColumn();
        $put=$this->db->prepare('INSERT INTO publication_jobs(product_id,channel,revision,state,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(product_id,channel) DO UPDATE SET revision=excluded.revision,state=excluded.state,updated_at=excluded.updated_at WHERE publication_jobs.revision<>excluded.revision');
        $delete=$this->db->prepare('DELETE FROM publication_jobs WHERE product_id=? AND channel=?');
        foreach ($this->channels($id) as $channel=>$settings) {
            if ($onlyChannel!==null && $channel!==$onlyChannel)continue;
            if ($settings['management']!=='site') continue;
            if (!$settings['enabled'] && $settings['externalId']==='') { $delete->execute([$id,$channel]);$settings['state']='off';$settings['error']='';$u=$this->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');$u->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$channel]);continue; }
            // Queue only intent. A configured connector must validate it before any external write.
            $intent=$this->channelIntent($settings);$content=$raw;
            if($channel==='avito'&&!empty($settings['externalId'])) {
                $legacyFile=__DIR__.'/avito-legacy.json';$legacy=is_file($legacyFile)?json_decode(file_get_contents($legacyFile),true):[];
                if(($legacy[(string)$settings['externalId']]['variants']??0)>1){
                    $q=$this->db->prepare("SELECT document FROM products WHERE json_extract(document,'$.avitoItemId')=? ORDER BY id");$q->execute([(string)$settings['externalId']]);$photos=[];
                    foreach($q as $row)$photos[]=json_decode($row['document'],true)['images'];$content=json_encode($photos);
                }
            }
            $revision=hash('sha256',$content.json_encode($intent));
            $put->execute([$id,$channel,$revision,'queued',gmdate('c')]);
            if($put->rowCount()>0){$settings['state']='queued';$settings['error']='';$u=$this->db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');$u->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$channel]);}
        }
    }

    private function backup(): void
    {
        $backup = $this->directory . '/backup-' . gmdate('Y-m-d') . '.sqlite';
        if (is_file($backup)) return;
        $lock = fopen($this->directory . '/backup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Catalog backup unavailable');
        try {
            if (!is_file($backup)) {
                $this->db->exec('VACUUM INTO ' . $this->db->quote($backup));
                chmod($backup,0600);
                $files = glob($this->directory . '/backup-*.sqlite');
                rsort($files);
                foreach (array_slice($files,14) as $file) unlink($file);
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Apply a reviewed category-only correction once; never replace stock, content or visibility. */
    public function reviseImportedCategories(string $revision, array $assignments, bool $apply): int
    {
        if (!preg_match('/^[a-z0-9-]{1,80}$/D', $revision) || count($assignments)>200) throw new \InvalidArgumentException('invalid_revision');
        $this->backup();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $key='category_revision:'.$revision;
            $get=$this->db->prepare('SELECT value FROM metadata WHERE name=?');$get->execute([$key]);
            if ($get->fetchColumn()!==false) { $this->db->exec('ROLLBACK'); return 0; }
            $map=[];
            foreach ($assignments as $a) {
                if (!in_array($a['from']??'', ['plant','potted'], true) || !in_array($a['to']??'', ['plant','potted'], true)) throw new \InvalidArgumentException('invalid_revision');
                $map[($a['avitoItemId']??'').'/'.($a['avitoVariant']??'')]=$a;
            }
            $count=0;
            $update=$this->db->prepare('UPDATE products SET document=?,version=version+1,updated_at=? WHERE id=?');
            foreach ($this->all(true) as $p) {
                if ($p['source']!=='avito') continue;
                $a=$map[($p['avitoItemId']??'').'/'.($p['avitoVariant']??'')]??null;
                if (!$a || ($p['catalogGroup']??'')!==$a['from']) continue;
                unset($p['hidden'],$p['sourceMissing'],$p['version'],$p['updatedAt'],$p['channels']);
                $p['category']=$p['catalogGroup']=$a['to'];
                $update->execute([json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$p['id']]);$count++;
            }
            $set=$this->db->prepare('INSERT INTO metadata(name,value) VALUES(?,?)');$set->execute([$key,gmdate('c')]);
            $this->db->exec($apply?'COMMIT':'ROLLBACK');return $count;
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK');throw $error; }
    }

    /** Import a trusted, reviewed batch once; repeat deployments never overwrite an owner's edits. */
    public function importBatch(array $items, bool $apply = false): array
    {
        if (!$items || count($items)>200 || !array_is_list($items)) throw new \InvalidArgumentException('invalid_import');
        $this->backup();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $known=[];
            foreach ($this->all(true) as $p) {
                if ($p['source']==='avito' && !empty($p['avitoItemId'])) $known[$p['avitoItemId'].'/'.($p['avitoVariant']??'')]=true;
            }
            $created=[];$skipped=0;
            foreach ($items as $item) {
                if (($item['source']??'')!=='avito' || empty($item['avitoItemId'])) throw new \InvalidArgumentException('invalid_import_source');
                $key=$item['avitoItemId'].'/'.($item['avitoVariant']??'');
                if (isset($known[$key])) { $skipped++; continue; }
                $item['id']='';$item['version']=0;
                $created[]=$this->save($item);
                $known[$key]=true;
            }
            $this->db->exec($apply?'COMMIT':'ROLLBACK');
            return ['applied'=>$apply,'created'=>count($created),'alreadyPresent'=>$skipped,'ids'=>$created];
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK');throw $error; }
    }
}

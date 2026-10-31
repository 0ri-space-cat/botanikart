<?php
declare(strict_types=1);
namespace BotanikArt;
final class Assortments {
 public function __construct(private CatalogStore $s){$s->db->exec('CREATE TABLE IF NOT EXISTS assortments(id TEXT PRIMARY KEY, draft TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)');$s->db->exec('CREATE TABLE IF NOT EXISTS assortment_members(product_id TEXT PRIMARY KEY, assortment_id TEXT NOT NULL)');}
 public function migrate():void{
  $db=$this->s->db;if($db->query("SELECT value FROM metadata WHERE name='assortments:v1'")->fetchColumn())return;
  $backup=dirname($db->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC)['file']).'/before-assortments-v1.sqlite';if(!is_file($backup)){$db->exec('VACUUM INTO '.$db->quote($backup));chmod($backup,0600);}
  $db->exec('SAVEPOINT assortment_migration');try{
   $legacy=json_decode(file_get_contents(__DIR__.'/avito-legacy.json'),true);$groups=[];foreach($this->s->all(true) as $p){$e=$p['channels']['avito']['externalId']??'';if(!$p['deleted']&&!empty($e)&&($legacy[$e]['variants']??0)>1)$groups[$e][]=$p;}
   foreach($groups as $e=>$members){$b=$legacy[$e];$id=$this->create($b['title']);$parent=$this->parent($id);$c=$parent['channels']['avito'];$c=array_replace($c,['enabled'=>true,'management'=>'external','externalId'=>(string)$e,'state'=>'external','category'=>$b['category'],'attributes'=>$b['attributes']]);$db->prepare("UPDATE product_channels SET document=? WHERE product_id=? AND channel='avito'")->execute([json_encode($c),$id]);$draft=['title'=>$b['title'],'description'=>$b['description'],'price'=>$b['price'],'images'=>array_slice(array_values(array_unique(array_merge(...array_column($members,'images')))),0,10),'category'=>$b['category'],'attributes'=>$b['attributes'],'useFirst'=>false];$this->writeDraft($id,$draft,1);foreach($members as $p)$this->attach($p['id'],$id);}
   foreach($this->list() as $g){$p=$this->parent($g['id']);unset($p['channels'],$p['version'],$p['deleted'],$p['managed'],$p['hidden'],$p['sourceMissing'],$p['updatedAt']);$p['assortmentSignature']=$g['signature'];$db->prepare('UPDATE products SET document=? WHERE id=?')->execute([json_encode($p),$g['id']]);}
   $db->exec("INSERT INTO metadata(name,value) VALUES('assortments:v1','true')");$db->exec('RELEASE SAVEPOINT assortment_migration');
  }catch(\Throwable $e){$db->exec('ROLLBACK TO SAVEPOINT assortment_migration');$db->exec('RELEASE SAVEPOINT assortment_migration');throw $e;}
 }
 public function create(string $title):string{
  $title=trim($title);if($title===''||mb_strlen($title)>100)throw new \InvalidArgumentException('invalid_product');
  $id='assortment-'.bin2hex(random_bytes(12));$p=['id'=>$id,'title'=>$title,'description'=>'','price'=>1,'source'=>'manual','catalogGroup'=>'material','status'=>'ready','available'=>true,'images'=>[],'assortmentContainer'=>true];
  $this->s->db->prepare('INSERT INTO products(id,source,document,hidden,managed,deleted,version,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,'manual',json_encode($p),1,1,0,1,gmdate('c')]);
  foreach(['avito','flowwow'] as $k)$this->s->db->prepare('INSERT INTO product_channels VALUES(?,?,?)')->execute([$id,$k,json_encode(['enabled'=>false,'management'=>'site','externalId'=>'','url'=>'','price'=>null,'title'=>'','description'=>'','category'=>'','attributes'=>[],'state'=>'off','error'=>''])]);
  $this->s->db->prepare('INSERT INTO assortments(id,draft) VALUES(?,?)')->execute([$id,json_encode(['title'=>$title,'description'=>'','price'=>null,'images'=>[],'category'=>'','attributes'=>[],'useFirst'=>false])]);return $id;
 }
 public function membership(string $id):string{$q=$this->s->db->prepare('SELECT assortment_id FROM assortment_members WHERE product_id=?');$q->execute([$id]);return (string)($q->fetchColumn()?:'');}
 public function attach(string $id,string $group):void{
  $q=$this->s->db->prepare('SELECT document,deleted FROM products WHERE id=?');$q->execute([$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row||$row['deleted']||!empty(json_decode($row['document'],true)['assortmentContainer']))throw new \InvalidArgumentException('invalid_product');
  if($group!==''){$parent=$this->parent($group);$c=$this->s->channels($id)['avito']??[];if($this->membership($id)===''&&!empty($c['enabled'])&&!empty($c['externalId'])&&($c['externalId']!==$parent['channels']['avito']['externalId']))throw new \InvalidArgumentException('individual_listing_active');$q=$this->s->db->prepare('INSERT INTO assortment_members VALUES(?,?) ON CONFLICT(product_id) DO UPDATE SET assortment_id=excluded.assortment_id');$q->execute([$id,$group]);}
  else{if($this->membership($id)!==''){$c=$this->s->channels($id)['avito'];$c=array_replace($c,['enabled'=>false,'management'=>'site','externalId'=>'','url'=>'','state'=>'off','error'=>'']);$this->s->db->prepare("UPDATE product_channels SET document=? WHERE product_id=? AND channel='avito'")->execute([json_encode($c),$id]);}$this->s->db->prepare('DELETE FROM assortment_members WHERE product_id=?')->execute([$id]);}
  $this->s->db->prepare("DELETE FROM publication_jobs WHERE product_id=? AND channel='avito'")->execute([$id]);
 }
 public function setMember(string $id,string $group,int $version):void{
  $db=$this->s->db;$db->exec('SAVEPOINT assortment_member');try{$q=$db->prepare('UPDATE products SET version=version+1,updated_at=? WHERE id=? AND version=? AND deleted=0');$q->execute([gmdate('c'),$id,$version]);if($q->rowCount()!==1)throw new \UnexpectedValueException('edit_conflict');$this->attach($id,$group);$db->exec('RELEASE SAVEPOINT assortment_member');}catch(\Throwable $e){$db->exec('ROLLBACK TO SAVEPOINT assortment_member');$db->exec('RELEASE SAVEPOINT assortment_member');throw $e;}
 }
 private function parent(string $id):array{foreach($this->s->all(true) as $p)if($p['id']===$id&&!empty($p['assortmentContainer']))return $p;throw new \OutOfBoundsException('product_missing');}
 private function writeDraft(string $id,array $d,int $version):void{$q=$this->s->db->prepare('UPDATE assortments SET draft=?,version=version+1 WHERE id=? AND version=?');$q->execute([json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$version]);if(!$q->rowCount())throw new \UnexpectedValueException('edit_conflict');}
 public function save(array $in):void{
  $id=(string)($in['id']??'');$this->parent($id);$d=array_intersect_key($in,array_flip(['title','description','price','images','category','attributes','useFirst']));
  if(!is_string($d['title']??null)||trim($d['title'])===''||mb_strlen($d['title'])>100||!is_string($d['description']??null)||mb_strlen($d['description'])>7500||!is_numeric($d['price']??null)||$d['price']<1||!is_array($d['images']??null)||count($d['images'])>10||!is_array($d['attributes']??null)||!is_string($d['category']??null)||!is_bool($d['useFirst']??null))throw new \InvalidArgumentException('invalid_product');
  $this->writeDraft($id,$d,(int)($in['version']??0));
 }
 public function list():array{
  $all=$this->s->all(true);$out=[];foreach($this->s->db->query('SELECT * FROM assortments') as $row){$p=$this->parent($row['id']);$members=array_values(array_filter($all,fn($m)=>empty($m['assortmentContainer'])&&!$m['deleted']&&$this->membership($m['id'])===$row['id']));$draft=json_decode($row['draft'],true);$signature=hash('sha256',json_encode([$draft,array_map(fn($m)=>[$m['id'],$m['title'],$m['description'],$m['price'],$m['images'],$m['status']],$members)]));$out[]=array_merge($draft,['id'=>$row['id'],'version'=>(int)$row['version'],'members'=>array_map(fn($m)=>array_intersect_key($m,array_flip(['id','title','price','images','status'])),$members),'state'=>$p['channels']['avito']['state'],'externalId'=>$p['channels']['avito']['externalId'],'url'=>$p['channels']['avito']['url'],'signature'=>$signature,'changed'=>($p['assortmentSignature']??'')!==$signature,'error'=>$p['channels']['avito']['error']??'']);}return $out;
 }
 public function disable(string $id):void{$this->parent($id);$this->s->withdrawSold($id,['avito']);}
 public function publish(string $id,int $version):array{
  $group=null;foreach($this->list() as $g)if($g['id']===$id)$group=$g;if(!$group)throw new \OutOfBoundsException();if($group['version']!==$version)throw new \UnexpectedValueException();if(!$group['members'])throw new \InvalidArgumentException('invalid_product');
  if(!$group['changed']&&!empty($this->parent($id)['channels']['avito']['enabled']))return ['unchanged'=>true];$p=$this->parent($id);$description=$group['description'];if($group['useFirst'])foreach($this->s->all(true) as $m)if($m['id']===$group['members'][0]['id'])$description=$m['description'];
  $p=array_replace($p,['title'=>$group['title'],'description'=>$description,'price'=>(float)$group['price'],'images'=>$group['images']?:array_slice(array_values(array_unique(array_merge(...array_column($group['members'],'images')))),0,10)]);$p['channels']['avito']=array_replace($p['channels']['avito'],['enabled'=>true,'configure'=>true,'category'=>$group['category'],'attributes'=>$group['attributes']]);
  require_once __DIR__.'/AvitoFeed.php';$schema=json_decode(file_get_contents(dirname(__DIR__,2).'/catalogue/avito-schema.json'),true);$check=(new AvitoFeed($schema))->check($p,'https://botanikart.ru');if($check['warnings'])throw new \RuntimeException(implode(' ',$check['warnings']));
  unset($p['assortmentId']);$this->s->db->exec('SAVEPOINT assortment_publish');try{$this->s->save($p);$q=$this->s->db->prepare('SELECT document FROM products WHERE id=?');$q->execute([$id]);$doc=json_decode($q->fetchColumn(),true);$doc['assortmentContainer']=true;$doc['assortmentSignature']=$group['signature'];$this->s->db->prepare('UPDATE products SET document=? WHERE id=?')->execute([json_encode($doc),$id]); $this->s->db->exec('RELEASE SAVEPOINT assortment_publish');return ['id'=>$id];}catch(\Throwable $e){$this->s->db->exec('ROLLBACK TO SAVEPOINT assortment_publish');$this->s->db->exec('RELEASE SAVEPOINT assortment_publish');throw $e;}
 }
}

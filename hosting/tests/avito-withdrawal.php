<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/CatalogStore.php';require dirname(__DIR__).'/private/PublicationRunner.php';
use BotanikArt\{CatalogStore,PublicationRunner};
$root=sys_get_temp_dir().'/ba-withdraw-'.bin2hex(random_bytes(6));mkdir($root.'/data',0700,true);$dir=$root.'/api/.private';mkdir($dir,0700,true);
try{
 $snapshot=$root.'/data/catalog.json';file_put_contents($snapshot,json_encode(['products'=>[['id'=>'flowwow-1','source'=>'flowwow','title'=>'Fixture','description'=>'Fixture','price'=>500,'catalogGroup'=>'plant','status'=>'ready','available'=>true,'images'=>['https://content2.flowwow-images.com/fixture.jpg']]]]));
 $store=new CatalogStore($dir.'/catalogue',$snapshot);$p=$store->all(true)[0];$p['channels']['avito']=['configure'=>true,'enabled'=>true,'category'=>'rasteniya_drugoe','attributes'=>['AdType'=>'Товар от производителя']];$store->save($p);
 $p=$store->all(true)[0];$store->archive($p['id'],$p['version']);$calls=[];$enabled=true;
 $runner=new PublicationRunner($store,$dir,null,function($method,$path,$body)use(&$calls,&$enabled){$calls[]=[$method,$path];if($path!=='/autoload/v2/profile')throw new RuntimeException('Unexpected publication call');if($method==='POST'){if($body['autoload_enabled']!==false)throw new RuntimeException('Archived product restarted uploads');$enabled=false;return [];}return ['autoload_enabled'=>$enabled,'feeds_data'=>[['feed_url'=>'https://botanikart.ru/api/avito-feed.php']],'schedule'=>[]];});
 $result=$runner->stopEmptyAvitoFeed();if($enabled||$store->channels($p['id'])['avito']['enabled']||empty($result['paused']))throw new RuntimeException('Withdrawal failed');
 $count=count($calls);$runner->process($p['id'],'avito');if(count($calls)!==$count)throw new RuntimeException('Confirmed withdrawal replayed');
 if($store->publicCatalog()['products'])throw new RuntimeException('Archived product returned to website');
 echo "Avito withdrawal passed: archived jobs stop owned autoload, never restart publication and do not replay.\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}

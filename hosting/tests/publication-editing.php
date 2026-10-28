<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/CatalogStore.php';require_once dirname(__DIR__).'/private/FlowwowPublisher.php';require_once dirname(__DIR__).'/private/PublicationRunner.php';
use BotanikArt\{CatalogStore,FlowwowPublisher,PublicationRunner};
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/botanik-editing-'.bin2hex(random_bytes(5));mkdir($root.'/data',0700,true);mkdir($root.'/api/.private',0700,true);mkdir($root.'/catalogue',0700,true);
copy(dirname(__DIR__,2).'/catalogue/avito-schema.json',$root.'/catalogue/avito-schema.json');$snapshot=$root.'/data/catalog.json';
$p=['id'=>'flowwow-1','source'=>'flowwow','flowwowProductId'=>123,'title'=>'Исходное название','description'=>'Описание','price'=>450,'status'=>'ready','available'=>true,'catalogGroup'=>'potted','images'=>['https://content2.flowwow-images.com/example.jpg'],'url'=>'https://flowwow.com/example/'];
file_put_contents($snapshot,json_encode(['products'=>[$p]]));
$schema=[['field'=>'name','name'=>'Название','type'=>'text','required'=>true],['field'=>'price','name'=>'Цена','type'=>'decimal','required'=>true,'minValue'=>1],['field'=>'stock','name'=>'Количество','type'=>'decimal','required'=>true,'minValue'=>1,'dependsOn'=>['productionTime']],['field'=>'productionTime','name'=>'Время изготовления','type'=>'measure','required'=>true,'dependsOn'=>['stock'],'measure'=>[['id'=>1,'name'=>'минуты']]],['field'=>'height','name'=>'Высота','type'=>'measure','required'=>true,'measure'=>[['id'=>2,'name'=>'см']]]];
$raw=['productId'=>123,'offerId'=>'existing-offer','type'=>1,'subCategoryId'=>9,'name'=>$p['title'],'description'=>$p['description'],'price'=>'500','discount'=>'10','images'=>$p['images'],'stock'=>4,'minOrder'=>0,'isDeliveryPost'=>false,'isActive'=>true,'properties'=>['height'=>['val'=>8,'measure'=>2],'brand'=>'Сохранить бренд']];
try{
 $store=new CatalogStore($root.'/api/.private/catalogue',$snapshot);$edit=$store->all(true)[0];$edit['title']='Новое название';$edit['price']=480;$store->save($edit);
 $p['price']=100;file_put_contents($snapshot,json_encode(['products'=>[$p]]));$store=new CatalogStore($root.'/api/.private/catalogue',$snapshot);$edit=$store->all(true)[0];
 verify($edit['title']==='Новое название'&&$edit['price']===480&&$edit['managed'],'Import overwrote locally managed fields');
 $edit['channels']['flowwow']['management']='site';
 $calls=[];$remote=$raw;
 $transport=function($method,$path,$payload)use(&$calls,&$remote,$schema){$calls[]=[$method,$path,$payload];
  if(str_starts_with($path,'/apiseller/categories/subCategoryProperties'))return ['data'=>$schema];
  if(str_starts_with($path,'/apiseller/products?'))return ['items'=>[$remote]];
  if(str_starts_with($path,'/apiseller/products/update')){$b=$payload['products'][0];$remote=array_merge($remote,['name'=>$b['name'],'price'=>$b['price'],'discount'=>$b['discount']]);return ['data'=>[['productId'=>123]],'errors'=>[]];}
  if(str_starts_with($path,'/apiseller/products/unhide')){$remote['isActive']=true;return ['errors'=>[]];}
  if(str_starts_with($path,'/apiseller/products/hide')){$remote['isActive']=false;return ['errors'=>[]];}
  throw new RuntimeException('Unexpected endpoint '.$path);
 };
 $fw=new FlowwowPublisher(['token'=>'fixture','shop_id'=>1],$root.'/api/.private',$transport);
 $result=$fw->publish($edit,'https://botanikart.ru');verify($result['state']==='published','Update not verified');
 $writes=array_values(array_filter($calls,fn($c)=>str_starts_with($c[1],'/apiseller/products/update')));$body=$writes[0][2]['products'][0];
 verify($body['offerId']==='existing-offer'&&$body['productType']===1&&$body['subCategoryId']===9,'Existing identity/type changed');
 verify($body['height']===['val'=>8,'measure'=>2]&&$body['brand']==='Сохранить бренд','Unedited properties lost');
 verify($body['price']==='480.00'&&$body['discount']==='0'&&$body['stock']===4,'Price/discount or stock corrupted');
 verify(!isset($body['minOrderCount'])&&!isset($body['productionTime']),'Invalid defaults injected');
 $edit['channels']['flowwow']['enabled']=false;$result=$fw->publish($edit,'https://botanikart.ru');verify($result['state']==='hidden'&&!$remote['isActive'],'Hide not confirmed');
 $edit['channels']['flowwow']['enabled']=true;$raw2=$raw;$raw2['type']=2;
 try{$fw->payload($edit,$raw2,$schema,'https://botanikart.ru');throw new LogicException('Unsupported type accepted');}catch(RuntimeException){}
 $bad=$edit;$bad['channels']['flowwow']['properties']=['height'=>['val'=>4,'measure'=>999]];
 try{$fw->payload($bad,$raw,$schema,'https://botanikart.ru');throw new LogicException('Bad unit accepted');}catch(RuntimeException){}
 $current=$store->all(true)[0];$store->archive($current['id'],$current['version']);verify(!$store->publicCatalog()['products'],'Archived card remained public');
 $p['title']='Изменение на площадке';file_put_contents($snapshot,json_encode(['products'=>[$p]]));$store=new CatalogStore($root.'/api/.private/catalogue',$snapshot);verify($store->all(true)[0]['deleted'],'Source refresh resurrected archived card');
 $current=$store->all(true)[0];$store->archive($current['id'],$current['version'],true);verify(count($store->publicCatalog()['products'])===1,'Restore failed');verify(!$store->channels($current['id'])['avito']['enabled'],'Restore re-enabled Avito');
 // Stale Avito reports cannot acknowledge more recent edits.
 $current=$store->all(true)[0];$current['channels']['avito']['enabled']=true;$current['channels']['avito']['category']='rasteniya_drugoe';$current['channels']['avito']['attributes']=['AdType'=>'Товар от производителя'];$store->save($current);
 $runner=new PublicationRunner($store,$root.'/api/.private',null,fn($method,$path,$payload)=>str_contains($path,'/items')?['items'=>[['ad_id'=>'ba-flowwow-1','avito_id'=>999,'avito_status'=>'active','section'=>['slug'=>'success_published']]],'meta'=>['pages'=>1]]:['started_at'=>'2020-01-01T00:00:00Z']);
 $runner->reports();verify($store->channels('flowwow-1')['avito']['state']!=='published','Stale report falsely confirmed new update');
 // A Flowwow echo of a locally created product must bind back to the same website card.
 mkdir($root.'/assets/uploads/catalogue',0700,true);$photo='assets/uploads/catalogue/'.str_repeat('b',32).'.jpg';file_put_contents($root.'/'.$photo,'fixture');
 $local=$store->save(['title'=>'Свой товар','description'=>'Описание','source'=>'manual','price'=>500,'catalogGroup'=>'plant','status'=>'ready','images'=>[$photo]]);
 $echo=$p;$echo['id']='flowwow-9876';$echo['flowwowProductId']=9876;$echo['flowwowOfferId']='ba-'.$local;
 file_put_contents($snapshot,json_encode(['products'=>[$p,$echo],'featured'=>['flowwow-9876']]));$store=new CatalogStore($root.'/api/.private/catalogue',$snapshot);
 verify(count($store->all(true))===2&&$store->channels($local)['flowwow']['externalId']==='9876','Cross-posting created a duplicate website card');
 verify($store->publicCatalog()['featured']===[$local],'Featured reference not remapped to canonical card');
 $current=array_values(array_filter($store->all(true),fn($p)=>$p['id']===$local))[0];$store->archive($local,$current['version']);
 $echo['price']=999;file_put_contents($snapshot,json_encode(['products'=>[$p,$echo]]));$store=new CatalogStore($root.'/api/.private/catalogue',$snapshot);verify(count($store->publicCatalog()['products'])===1,'Flowwow import resurrected archived cross-post');
 // Imported assortment variants update one existing Avito listing and share its settings.
 $variants=[];foreach(['one','two'] as $suffix)$variants[]=['source'=>'avito','avitoItemId'=>'8271770035','avitoVariant'=>$suffix,'title'=>'Хойя '.$suffix,'description'=>'Описание варианта','price'=>500,'catalogGroup'=>'potted','status'=>'ready','images'=>[$photo],'url'=>'https://www.avito.ru/8271770035'];
 $store->importBatch($variants,true);$group=array_values(array_filter($store->all(true),fn($p)=>($p['avitoItemId']??'')==='8271770035'));$stale=$group[1];$edit=$group[0];
 $legacy=json_decode(file_get_contents(dirname(__DIR__).'/private/avito-legacy.json'),true)['8271770035'];
 $edit['channels']['avito']['configure']=true;$edit['channels']['avito']['category']=$legacy['category'];$edit['channels']['avito']['attributes']=$legacy['attributes']+['AdType'=>'Товар от производителя'];$edit['channels']['avito']['price']=555;
 $store->save($edit);$group=array_values(array_filter($store->all(true),fn($p)=>($p['avitoItemId']??'')==='8271770035'));
 verify($group[1]['channels']['avito']['price']===555,'Shared listing settings diverged across variants');
 try{$store->save($stale);throw new LogicException('Stale group edit accepted');}catch(UnexpectedValueException){}
 $feed=new \BotanikArt\AvitoFeed(json_decode(file_get_contents(dirname(__DIR__,2).'/catalogue/avito-schema.json'),true));$xml=$feed->render($group,'https://botanikart.ru');$doc=new DOMDocument();$doc->loadXML($xml);
 verify($doc->getElementsByTagName('Ad')->length===1&&$doc->getElementsByTagName('AvitoId')->item(0)->textContent==='8271770035','Assortment variants created multiple remote listings');
 $off=$group[0];$off['channels']['avito']['enabled']=false;$store->save($off);$group=array_values(array_filter($store->all(true),fn($p)=>($p['avitoItemId']??'')==='8271770035'));verify(!$group[1]['channels']['avito']['enabled'],'Switching off shared listing left another variant feeding it');
 echo "Editing checks passed: import ownership, full Flowwow update preservation, hide/readback, invalid types/units, archive/restore and stale-report protection.\n";
}finally{
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);
}

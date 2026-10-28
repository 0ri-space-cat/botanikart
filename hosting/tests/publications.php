<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/CatalogStore.php';
require_once dirname(__DIR__).'/private/MarketplaceService.php';
use BotanikArt\{CatalogStore,MarketplaceService};
function check(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/botanik-publications-'.bin2hex(random_bytes(6));mkdir($root.'/data',0700,true);mkdir($root.'/api/.private',0700,true);mkdir($root.'/assets/uploads/catalogue',0700,true);
$snapshot=$root.'/data/catalog.json';$image='assets/uploads/catalogue/'.str_repeat('a',32).'.jpg';file_put_contents($root.'/'.$image,'fixture');
$flow=['id'=>'flowwow-1','source'=>'flowwow','flowwowProductId'=>1,'title'=>'Флорариум','price'=>5000,'available'=>true,'status'=>'ready','catalogGroup'=>'florarium','images'=>[$image],'description'=>'Описание'];
file_put_contents($snapshot,json_encode(['products'=>[$flow]]));
try {
 $s=new CatalogStore($root.'/api/.private/catalogue',$snapshot);$m=new MarketplaceService($s,$root.'/api/.private');
 $old=$s->all(true)[0];check($old['channels']['flowwow']['enabled']&&$old['channels']['flowwow']['management']==='external','Existing Flowwow card lost its destination');
 check(!(int)$s->db->query('SELECT COUNT(*) FROM publication_jobs')->fetchColumn(),'Old cards queued for reposting');
 $input=['title'=>'Новый флорариум','source'=>'manual','description'=>'Текст','price'=>5000,'images'=>[$image],'status'=>'ready','catalogGroup'=>'florarium','channels'=>['avito'=>['enabled'=>true,'price'=>5500],'flowwow'=>['enabled'=>true,'price'=>6000]]];
 $id=$s->save($input);$p=array_values(array_filter($s->all(true),fn($p)=>$p['id']===$id))[0];
 check($p['source']==='manual'&&$p['channels']['avito']['enabled']&&$p['channels']['flowwow']['enabled'],'Multiple channels not saved');
 check($p['price']===5000&&$p['channels']['avito']['price']===5500&&$p['channels']['flowwow']['price']===6000,'Channel prices overwrote website price');
 check((int)$s->db->query('SELECT COUNT(*) FROM publication_jobs')->fetchColumn()===2,'Two destinations need two coalesced jobs');
 check(!isset($s->publicCatalog()['products'][1]['channels']),'Private publication settings leaked to public catalog');
 $before=$p;$p['price']=5100;$p['channels']['avito']['price']=0;
 try{$s->save($p);throw new RuntimeException('Invalid channel price accepted');}catch(InvalidArgumentException){}
 $p=array_values(array_filter($s->all(true),fn($p)=>$p['id']===$id))[0];check($p['version']===$before['version']&&$p['price']===5000,'Invalid channel save partially committed content');
 $p['hidden']=true;$s->save($p);$p=array_values(array_filter($s->all(true),fn($p)=>$p['id']===$id))[0];
 check($p['channels']['avito']['enabled']&&count($s->publicCatalog()['products'])===1,'Website hiding disabled external destinations');
 $p['channels']['avito']['externalId']='FORGED';$s->save($p);check($s->channels($id)['avito']['externalId']==='','Client forged remote identity');
 $p=array_values(array_filter($s->all(true),fn($p)=>$p['id']===$id))[0];$p['channels']['avito']['enabled']=false;$s->save($p);
 check((int)$s->db->query('SELECT COUNT(*) FROM publication_jobs')->fetchColumn()===1,'Disabled unsent destination still queued');
 $old['channels']['avito']['enabled']=true;$old['channels']['avito']['price']=5100;$s->save($old);
 $flow['price']=5500;file_put_contents($snapshot,json_encode(['products'=>[$flow]]));$s=new CatalogStore($root.'/api/.private/catalogue',$snapshot);
 check($s->channels('flowwow-1')['avito']['enabled']&&$s->channels('flowwow-1')['avito']['price']===5100,'Flowwow refresh reset destination settings');
 $old=$s->all(true)[0];$old['channels']['flowwow']['enabled']=false;
 try{$s->save($old);throw new RuntimeException('Externally managed ad could be removed');}catch(InvalidArgumentException){}
 $m=new MarketplaceService($s,$root.'/api/.private');$preview=$m->preview($id,'flowwow');check(!$preview['ready']&&$preview['product']['price']===6000,'Preview pretended to publish or lost override');
 $m->saveRenewalDate('2026-11-28');$week=$m->connections(new DateTimeImmutable('2026-11-21 12:00:00',new DateTimeZone('Europe/Moscow')))['avito']['reminder'];
 check($week['days']===7&&!$week['seen'],'One-week reminder missing');
 check($m->connections(new DateTimeImmutable('2026-11-20 12:00:00',new DateTimeZone('Europe/Moscow')))['avito']['reminder']===null,'Reminder too early');
 $three=$m->connections(new DateTimeImmutable('2026-11-25 00:00:00',new DateTimeZone('Europe/Moscow')))['avito']['reminder'];check($three['days']===3&&$three['id']!==$week['id'],'Three-day reminder did not have its own acknowledgment');
 check($m->connections(new DateTimeImmutable('2026-11-24 21:30:00',new DateTimeZone('UTC')))['avito']['reminder']['days']===3,'Reminder did not use Moscow date');
 $m->saveRenewalDate((new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow')))->modify('+5 days')->format('Y-m-d'));
 $current=$m->connections()['avito']['reminder'];$m->acknowledgeReminder($current['id']);check($m->connections()['avito']['reminder']['seen'],'Reminder acknowledgment not saved');
 try{$m->saveRenewalDate('2026-02-30');throw new RuntimeException('Invalid renewal date accepted');}catch(InvalidArgumentException){}
 $calls=0;$m->checkConnections(function()use(&$calls){$calls++;throw new RuntimeException('Must not call');});check($calls===0,'Unconfigured integration called a remote API');
 $cfg=['avito'=>['client_id'=>'private-id','client_secret'=>'private-secret']];$m=new MarketplaceService($s,$root.'/api/.private',$cfg);
 $probe=function($method,$path,$body,$headers)use(&$calls){$calls++;return $path==='/token'?['status'=>200,'json'=>['access_token'=>'fixture-token']]:['status'=>403,'json'=>[]];};
 $result=$m->checkConnections($probe);check($result['avito']['health']['state']==='access_denied','Lost autoload permission was not detected');
 check(!str_contains(json_encode($result),'private-secret')&&!str_contains(json_encode($result),'fixture-token'),'Credentials leaked in connection status');
 $before=$calls;$m->checkConnections($probe);check($calls===$before,'Connection checks were not throttled');
 $s->db->exec("DELETE FROM metadata WHERE name='health:avito'");$result=$m->checkConnections(fn()=>throw new RuntimeException('timeout'));check($result['avito']['health']['state']==='unreachable','Network error was mislabeled as subscription expiry');
 $xml=MarketplaceService::avitoXml([['id'=>'constant-id','title'=>'Мох & <лес>','description'=>'Описание','price'=>100,'images'=>['https://botanikart.ru/a.jpg'],'catalogGroup'=>'plant']],['plant'=>['Category'=>'Растения','Address'=>'Москва']]);
 check(str_contains($xml,'Мох &amp; &lt;лес&gt;')&&str_contains($xml,'<Id>constant-id</Id>'),'Feed identity or escaping failed');
 try{MarketplaceService::avitoXml([],[]);throw new RuntimeException('Empty/incomplete feed accepted');}catch(InvalidArgumentException){}
 echo "Publication checks passed: multiple destinations, immutable legacy ownership, atomic saves, private settings, queue coalescing, channel prices, Moscow reminders and truthful connection health.\n";
} finally {
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);
}

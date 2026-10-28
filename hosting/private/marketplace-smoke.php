<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/CatalogStore.php';require_once __DIR__.'/PublicationRunner.php';
use BotanikArt\{CatalogStore,PublicationRunner};
$mode=$argv[1]??'inspect';if(!in_array($mode,['inspect','start','check','cleanup'],true))throw new RuntimeException('Invalid smoke action');
$store=new CatalogStore(__DIR__.'/catalogue',dirname(__DIR__,2).'/data/catalog.json');$runner=new PublicationRunner($store,__DIR__);
$stateFile=__DIR__.'/marketplace-smoke-state.php';$guard="<?php exit; ?>\n";
$state=is_file($stateFile)?json_decode(substr(file_get_contents($stateFile),strlen($guard)),true):[];
$find=function($id)use($store){foreach($store->all(true) as $p)if($p['id']===$id)return $p;throw new RuntimeException('Product not found');};
$save=function($p,$changes)use($store){$body=array_intersect_key($p,array_flip(['id','version','title','description','source','catalogGroup','status','url','price','images','hidden','purchaseMode']));$body['channels']=[];foreach(['avito','flowwow'] as $key)$body['channels'][$key]=array_intersect_key($p['channels'][$key],array_flip(['enabled','price','title','description','category','attributes','properties']));return $store->save(array_replace_recursive($body,$changes));};
$persist=function()use(&$state,$stateFile,$guard){$tmp=$stateFile.'.tmp';file_put_contents($tmp,$guard.json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$stateFile);};
if($mode==='inspect'){
 $p=$find('flowwow-76364430');$fw=$runner->flowwow()->findProduct('76364430');
 echo json_encode(['base'=>['id'=>$p['id'],'title'=>$p['title'],'price'=>$p['price']],'flowwow'=>array_intersect_key($fw??[],array_flip(['productId','type','subCategoryId','stock','productionTime','isActive'])),'previousSmoke'=>$state],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";exit;
}
if($mode==='start'&&!$state){
 $p=$find('flowwow-76364430');$fw=$runner->flowwow()->findProduct('76364430');if(!$fw||($fw['type']??0)!==1)throw new RuntimeException('Unsupported base product');
 $editor=$runner->flowwow()->editor($p);$properties=$editor['properties'];foreach(['offerId','productType','subCategoryId','name','price','discount','description','images'] as $key)unset($properties[$key]);
 $new=array_intersect_key($p,array_flip(['title','description','catalogGroup','price','images','purchaseMode']));
 $new['source']='manual';$new['hidden']=true;$new['status']='ready';$new['url']='';
 $dir=dirname(__DIR__,2).'/assets/uploads/catalogue';if(!is_dir($dir))mkdir($dir,0755,true);$images=[];
 foreach($p['images'] as $path){$file=dirname(__DIR__,2).'/'.$path;if(is_file($file)){$name=bin2hex(random_bytes(16)).'.jpg';copy($file,$dir.'/'.$name);chmod($dir.'/'.$name,0644);$images[]='assets/uploads/catalogue/'.$name;}}
 if(!$images)throw new RuntimeException('No base photographs');$new['images']=$images;
 $new['channels']=['avito'=>['configure'=>true,'enabled'=>true,'category'=>'rasteniya_drugoe','attributes'=>['AdType'=>'Товар от производителя']],'flowwow'=>['configure'=>true,'enabled'=>true,'category'=>(string)$fw['subCategoryId'],'properties'=>$properties]];
 $state=['id'=>$store->save($new),'createdAt'=>gmdate('c'),'base'=>'flowwow-76364430'];$persist();
}
if(!$state)throw new RuntimeException('No smoke record exists');
if($mode==='start'){
 if(!empty($state['cleanedAt']))throw new RuntimeException('Smoke already withdrawn; no republishing');
 echo json_encode($runner->process($state['id']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
 // Explicit upload of the already validated, solely owned feed; no paid over-limit placement.
 $cfg=require __DIR__.'/marketplaces-config.php';$send=function($path,$body)use($cfg){$token=curl_init('https://api.avito.ru/token');curl_setopt_array($token,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$cfg['avito']['client_id'],'client_secret'=>$cfg['avito']['client_secret']]),CURLOPT_TIMEOUT=>15]);$raw=curl_exec($token);curl_close($token);$key=json_decode((string)$raw,true)['access_token']??'';if(!$key)throw new RuntimeException('Avito auth failed');$h=curl_init('https://api.avito.ru'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_TIMEOUT=>20]);$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return ['http'=>$code,'response'=>json_decode((string)$raw,true)];};
 $p=$find($state['id']);if(($p['channels']['avito']['state']??'')==='awaiting_report')echo json_encode(['upload'=>$send('/autoload/v1/upload',new stdClass())],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}
if($mode==='cleanup'){
 $p=$find($state['id']);$state['remoteIds']=array_map(fn($c)=>$c['externalId']??'',$p['channels']);
 if(empty($state['cleanedAt'])){$store->archive($p['id'],$p['version'],false,true);$state['cleanedAt']=gmdate('c');$persist();}
 echo json_encode($runner->process($state['id']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}
if($mode==='check'){try{echo json_encode(['reports'=>$runner->reports()],JSON_THROW_ON_ERROR)."\n";}catch(Throwable $e){echo json_encode(['reportError'=>$e->getMessage()],JSON_UNESCAPED_UNICODE)."\n";}}
$p=$find($state['id']);echo json_encode(['smoke'=>$state,'channels'=>$p['channels'],'deleted'=>$p['deleted']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/AvitoFeed.php';
use BotanikArt\AvitoFeed;
$schema=json_decode(file_get_contents(dirname(__DIR__,2).'/catalogue/avito-schema.json'),true,512,JSON_THROW_ON_ERROR);
$feed=new AvitoFeed($schema);
function assertFeed(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$p=['id'=>'manual-fixture','title'=>'Пилея & мох','description'=>'Первый абзац.\nСледующий ]]> абзац.','price'=>400,'status'=>'ready','hidden'=>true,'images'=>['assets/photo.jpg'],'channels'=>['avito'=>['management'=>'site','enabled'=>true,'category'=>'rasteniya_drugoe','attributes'=>['AdType'=>'Товар от производителя'],'title'=>'','description'=>'','price'=>450]]];
$xml=$feed->render([$p],'https://botanikart.ru');$dom=new DOMDocument();assertFeed($dom->loadXML($xml),'XML malformed');
assertFeed($dom->getElementsByTagName('Id')->item(0)->textContent==='ba-manual-fixture','ID not stable');
assertFeed($dom->getElementsByTagName('Price')->item(0)->textContent==='450','Override price lost');
assertFeed($dom->getElementsByTagName('Description')->item(0)->textContent===str_replace('\\n',"\n",$p['description']),'Description damaged');
assertFeed($dom->getElementsByTagName('AdStatus')->item(0)->textContent==='Free','Paid promotion enabled');
assertFeed($dom->getElementsByTagName('ListingFee')->item(0)->textContent==='Package','Balance fallback enabled');
$cdn=$p;$cdn['images']=['https://content2.flowwow-images.com/data/flowers/example.jpg'];assertFeed(!$feed->check($cdn,'https://botanikart.ru')['warnings'],'Official source photo rejected');
$external=$p;$external['id']='old-import';$external['channels']['avito']['management']='external';
assertFeed($feed->render([$external,$p],'https://botanikart.ru')===$xml,'Legacy ad exported again');
$p['price']=900;$p['channels']['avito']['price']=null;$changed=$feed->render([$p],'https://botanikart.ru');assertFeed(str_contains($changed,'<Price>900</Price>'),'Live price not used');
foreach (['empty','invalid','sold'] as $test) {
 $bad=$p;
 if($test==='empty')$bad['channels']['avito']['enabled']=false;
 if($test==='invalid')$bad['channels']['avito']['category']='not-a-category';
 if($test==='sold')$bad['status']='sold';
 try{$feed->render([$bad],'https://botanikart.ru');throw new LogicException('Unsafe feed accepted: '.$test);}catch(RuntimeException $e){assertFeed(($e->getMessage()==='empty_feed'||str_contains($e->getMessage(),'Авито: исправьте объявление «')),'Unexpected error');}
}
$p['channels']['avito']['category']='dekorativno_listvennye_rasteniya';$p['channels']['avito']['attributes']['PlantType']='НЕСУЩЕСТВУЮЩЕЕ';assertFeed(count($feed->check($p,'https://botanikart.ru')['warnings'])>0,'Invalid category enum accepted');
assertFeed(AvitoFeed::applicable(['conditions'=>[['clause'=>'value','source_field_tag'=>'PlantType','values'=>['Орхидея']]]],['PlantType'=>'Орхидея']),'Conditional field not enabled');
assertFeed(!AvitoFeed::applicable(['conditions'=>[['clause'=>'value','source_field_tag'=>'PlantType','values'=>['Орхидея']]]],['PlantType'=>'Бегония']),'Irrelevant conditional field required');
echo "Avito feed checks passed: stable IDs, live prices, legacy exclusion, no promotion, valid XML and fail-closed invalid/empty export.\n";

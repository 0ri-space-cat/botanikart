<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/CatalogStore.php';require_once __DIR__.'/PublicationRunner.php';
$s=new \BotanikArt\CatalogStore(__DIR__.'/catalogue',dirname(__DIR__,2).'/data/catalog.json');$runner=new \BotanikArt\PublicationRunner($s,__DIR__);
require_once __DIR__.'/Assortments.php';(new \BotanikArt\Assortments($s))->migrate();
$result=$runner->process();
try{$report=$runner->reports();}catch(\Throwable){$report=['unavailable'=>true];}
echo json_encode(['processed'=>count($result['results']??[]),'errors'=>count(array_filter($result['results']??[],fn($r)=>$r['state']==='error')),'reports'=>$report],JSON_THROW_ON_ERROR)."\n";

if(is_file(__DIR__.'/config.php')){
    require_once __DIR__.'/PaymentService.php';
    try{$sent=(new \BotanikArt\MerchantOrders(require __DIR__.'/config.php'))->sendPending();echo 'Merchant notifications accepted: '.$sent."\n";}catch(\Throwable){fwrite(STDERR,"Merchant notifications will be retried.\n");}
}

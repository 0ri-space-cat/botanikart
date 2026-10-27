<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
umask(0077);
require_once __DIR__.'/CatalogStore.php';
if (empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR,"Expected a reviewed import manifest.\n");exit(1); }
$manifest=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (($manifest['schema']??0)!==1 || !preg_match('/^[a-z0-9-]{1,80}$/D',$manifest['batch']??'')) throw new RuntimeException('Invalid manifest');
$store=new \BotanikArt\CatalogStore(__DIR__.'/catalogue',dirname(__DIR__,2).'/data/catalog.json');
$result=$store->importBatch($manifest['items']??[],in_array('--apply',$argv,true));
unset($result['ids']);
echo json_encode(['batch'=>$manifest['batch']]+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";

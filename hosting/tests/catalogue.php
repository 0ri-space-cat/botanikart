<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/CatalogStore.php';
require_once dirname(__DIR__) . '/private/PaymentService.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$root = sys_get_temp_dir().'/botanikart-catalogue-'.bin2hex(random_bytes(8));
mkdir($root.'/data',0700,true);
mkdir($root.'/api/.private',0700,true);
mkdir($root.'/assets/uploads/catalogue',0700,true);
copy(dirname(__DIR__).'/private/CatalogStore.php',$root.'/api/.private/CatalogStore.php');
$snapshot=$root.'/data/catalog.json';
$source=['id'=>'flowwow-test','source'=>'flowwow','title'=>'Флорариум','price'=>5000,'available'=>true,'status'=>'ready','images'=>['assets/test.jpg']];
function snapshot(array $products): void { global $snapshot; file_put_contents($snapshot,json_encode(['products'=>$products,'featured'=>['flowwow-test']],JSON_THROW_ON_ERROR)); }
try {
    snapshot([$source]);
    $store=new \BotanikArt\CatalogStore($root.'/api/.private/catalogue',$snapshot);
    $p=$store->all(true)[0];
    $store->save(['id'=>$p['id'],'version'=>$p['version'],'hidden'=>true,'price'=>1,'title'=>'tampered']);
    check(!$store->publicCatalog()['products'],'Hidden product remained public');
    check(!$store->publicCatalog()['featured'],'Hidden product remained featured');
    check($store->all(true)[0]['price']===5000,'Flowwow content could be edited');
    $source['price']=6000; snapshot([$source]);
    $store=new \BotanikArt\CatalogStore($root.'/api/.private/catalogue',$snapshot);
    check($store->all(true)[0]['hidden'] && $store->all(true)[0]['price']===6000,'Visibility did not survive source refresh');
    try { $store->save(['id'=>$p['id'],'version'=>$p['version'],'hidden'=>false]); throw new RuntimeException('Stale edit accepted'); } catch (UnexpectedValueException) {}
    $image='assets/uploads/catalogue/'.str_repeat('a',32).'.jpg';file_put_contents($root.'/'.$image,'test');
    $manual=['id'=>'','source'=>'avito','title'=>'Маранта Керховена','description'=>"Первый абзац.\n\nВторой абзац.",'price'=>490,'catalogGroup'=>'plant','status'=>'ready','images'=>[$image],'url'=>'https://www.avito.ru/moskva/rasteniya/maranta_8303205392'];
    $manualId=$store->save($manual);
    $source['price']=6500;snapshot([$source]);
    $store=new \BotanikArt\CatalogStore($root.'/api/.private/catalogue',$snapshot);
    check(count($store->all(true))===2,'Manual product lost on refresh');
    check($store->publicCatalog()['products'][0]['id']===$manualId,'Hidden source card resurfaced');
    $manual=$store->publicCatalog()['products'][0];
    check($manual['avitoItemId']==='8303205392','Avito source ID lost');
    $manual['version']=$store->all(true)[1]['version'];$manual['price']=700;
    $store->save($manual);
    $service=(new ReflectionClass(\BotanikArt\PaymentService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($service,'config'))->setValue($service,['catalog_path'=>$snapshot]);
    $method=new ReflectionMethod($service,'product');
    check($method->invoke($service,$manualId)['price']===700,'Payments did not use the current database price');
    try { $method->invoke($service,'flowwow-test'); throw new RuntimeException('Hidden product could be purchased'); } catch (\BotanikArt\ApiError $error) { check($error->reason==='product_unavailable','Unexpected payment error'); }
    $bad=$manual;$bad['version']=$store->all(true)[1]['version'];$bad['images']=['https://attacker.example/file.svg'];
    try { $store->save($bad);throw new RuntimeException('Arbitrary upload reference accepted'); } catch (InvalidArgumentException) {}
    check(count(glob($root.'/api/.private/catalogue/backup-*.sqlite'))===1,'Backup was not created');
    $variant=['source'=>'avito','avitoItemId'=>'1234567890','avitoVariant'=>'one-litre','title'=>'Грунт, 1 л','description'=>'Фасовка 1 л','price'=>200,'catalogGroup'=>'material','status'=>'ready','images'=>[$image],'url'=>'https://www.avito.ru/1234567890'];
    $second=$variant;$second['avitoVariant']='three-litres';$second['title']='Грунт, 3 л';$second['price']=400;
    $before=count($store->all(true));
    $dry=$store->importBatch([$variant,$second]);
    check($dry['created']===2 && count($store->all(true))===$before,'Dry-run changed the catalog');
    $bad=$second;$bad['price']=0;
    try { $store->importBatch([$variant,$bad],true);throw new RuntimeException('Invalid batch was accepted'); } catch (InvalidArgumentException) {}
    check(count($store->all(true))===$before,'Failed batch was partially committed');
    $import=$store->importBatch([$variant,$second],true);
    check($import['created']===2,'Distinct variants of one Avito ad were rejected');
    $imported=array_values(array_filter($store->all(true),fn($p)=>($p['avitoVariant']??'')==='one-litre'))[0];
    $imported['price']=250;$imported['hidden']=true;unset($imported['avitoItemId'],$imported['avitoVariant']);
    $store->save($imported);
    $again=$store->importBatch([$variant,$second],true);
    check($again['created']===0 && $again['alreadyPresent']===2,'Batch was not idempotent');
    $edited=array_values(array_filter($store->all(true),fn($p)=>($p['avitoVariant']??'')==='one-litre'))[0];
    check($edited['price']===250 && $edited['hidden'],'Repeat import overwrote the owner edits');
    $assignment=[['avitoItemId'=>'8303205392','avitoVariant'=>'','from'=>'plant','to'=>'potted']];
    check($store->reviseImportedCategories('test-use', $assignment, false)===1,'Category dry run missed existing card');
    check($store->reviseImportedCategories('test-use', $assignment, true)===1,'Category correction was not applied');
    $recategorized=array_values(array_filter($store->all(true),fn($p)=>$p['id']===$manualId))[0];
    check($recategorized['catalogGroup']==='potted' && $recategorized['price']===700 && !$recategorized['hidden'],'Category correction changed price or visibility');
    $recategorized['catalogGroup']='plant';$store->save($recategorized);
    check($store->reviseImportedCategories('test-use', $assignment, true)===0,'Revision reapplied over an owner category edit');
    echo "Catalogue checks passed: hidden flags, Flowwow authority, local records, edit conflicts, photos, backups and payment price/visibility.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) { $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); } rmdir($root);
}

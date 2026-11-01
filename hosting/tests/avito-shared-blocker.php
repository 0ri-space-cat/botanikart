<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/CatalogStore.php';
require_once dirname(__DIR__).'/private/PublicationRunner.php';

use BotanikArt\{CatalogStore,PublicationRunner};
function checkBlocked(bool $ok,string $reason): void {
    if(!$ok)throw new RuntimeException($reason);
}
$root=sys_get_temp_dir().'/ba-feed-blocker-'.bin2hex(random_bytes(5));
mkdir($root.'/data',0700,true);
mkdir($root.'/catalogue',0700,true);
mkdir($root.'/assets/uploads/catalogue',0700,true);
copy(dirname(__DIR__,2).'/catalogue/avito-schema.json',$root.'/catalogue/avito-schema.json');
file_put_contents($root.'/data/catalog.json',json_encode(['products'=>[[
    'id'=>'fixture-seed','source'=>'manual','title'=>'Fixture seed','description'=>'Fixture seed',
    'price'=>500,'catalogGroup'=>'plant','status'=>'ready','available'=>true,
    'images'=>['assets/uploads/catalogue/'.str_repeat('d',32).'.jpg']
]]]));
$photo='assets/uploads/catalogue/'.str_repeat('d',32).'.jpg';
file_put_contents($root.'/'.$photo,'fixture');
try{
    $store=new CatalogStore($root.'/api/.private/catalogue',$root.'/data/catalog.json');
    $common=['source'=>'manual','description'=>'Описание товара','price'=>500,'catalogGroup'=>'plant','status'=>'ready','images'=>[$photo]];
    $good=$store->save($common+['title'=>'Здоровое растение','channels'=>['avito'=>[
        'enabled'=>true,'configure'=>true,'category'=>'rasteniya_drugoe','attributes'=>['AdType'=>'Товар от производителя']
    ]]]);
    $bad=$store->save($common+['title'=>'Растение без категории Авито','channels'=>['avito'=>[
        'enabled'=>true,'configure'=>true,'category'=>'','attributes'=>[]
    ]]]);
    $calls=[];
    $avito=function(string $method,string $path,?array $data)use(&$calls):array{
        $calls[]=$path;
        if($path==='/autoload/v2/profile')return ['feeds_data'=>[['feed_url'=>'https://botanikart.ru/api/avito-feed.php']],'autoload_enabled'=>true];
        if($path==='/autoload/v1/upload')return [];
        throw new RuntimeException('Unexpected Avito network call: '.$path);
    };
    $runner=new PublicationRunner($store,$root.'/api/.private',null,$avito);
    $runner->process(null,'avito');
    checkBlocked($store->channels($good)['avito']['state']==='blocked_by_feed','A valid Avito listing was wrongly reported as failed');
    checkBlocked($store->channels($good)['avito']['error']==='','Valid listing gained an error text');
    checkBlocked($store->channels($bad)['avito']['state']==='error','The actual invalid listing must report an error');
    $read=$store->db->prepare("SELECT state FROM publication_jobs WHERE product_id=? AND channel='avito'");
    $read->execute([$good]);checkBlocked($read->fetchColumn()==='blocked_by_feed','Blocked feed job must remain resumable');
    checkBlocked($calls===[],'Invalid shared feed must not trigger Avito upload');

    $badProduct=array_values(array_filter($store->all(true),fn($p)=>$p['id']===$bad))[0];
    $badProduct['channels']['avito']['category']='rasteniya_drugoe';
    $badProduct['channels']['avito']['attributes']=['AdType'=>'Товар от производителя'];
    $store->save($badProduct);
    $runner->process(null,'avito');
    checkBlocked($store->channels($good)['avito']['state']==='awaiting_report','Valid listing did not resume when blocker was fixed');
    checkBlocked($store->channels($bad)['avito']['state']==='awaiting_report','Fixed listing did not queue for processing');
    checkBlocked(count(array_filter($calls,fn($p)=>$p==='/autoload/v1/upload'))===1,'A repaired feed should cause one coalesced Avito upload');
    echo "Shared Avito feed checks passed: only invalid item fails, valid items wait and resume.\n";
}finally{
    if(is_dir($root)){
        $paths=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($paths as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($root);
    }
}

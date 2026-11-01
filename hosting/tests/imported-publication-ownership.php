<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/CatalogStore.php';
use BotanikArt\CatalogStore;
function ownCheck(bool $ok,string $reason): void {if(!$ok)throw new RuntimeException($reason);}
$root=sys_get_temp_dir().'/ba-import-ownership-'.bin2hex(random_bytes(5));
mkdir($root.'/data',0700,true);
$photo='https://content2.flowwow-images.com/test.jpg';
$input=['id'=>'flowwow-321','source'=>'flowwow','flowwowProductId'=>321,
    'title'=>'Растение с Flowwow','description'=>'Описание из Flowwow','price'=>700,
    'catalogGroup'=>'potted','status'=>'ready','available'=>true,'images'=>[$photo]];
file_put_contents($root.'/data/catalog.json',json_encode(['products'=>[$input]],JSON_UNESCAPED_UNICODE));
$directory=$root.'/api/.private/catalogue';
try {
    $store=new CatalogStore($directory,$root.'/data/catalog.json');
    $p=$store->all(true)[0];$originalVersion=$p['version'];
    ownCheck($p['channels']['flowwow']['management']==='external','Imported product must remain controlled by its source marketplace');

    // Website edits must not implicitly turn the remote product into an outgoing publication.
    $p['price']=750;$p['title']='Другое название на сайте';$p['description']='Отдельный текст для сайта';
    $c=$p['channels']['flowwow'];
    $p['channels']=['flowwow'=>['enabled'=>true,'configure'=>false,
       'price'=>null,'title'=>'','description'=>'','category'=>'','properties'=>[]]];
    $store->save($p);
    $c=$store->channels('flowwow-321')['flowwow'];
    ownCheck($c['management']==='external'&&$c['state']==='external'&&empty($c['publicationRequested']),
        'Website-only edit took control of imported Flowwow item');
    ownCheck((int)$store->db->query("SELECT COUNT(*) FROM publication_jobs WHERE product_id='flowwow-321'")->fetchColumn()===0,
        'Website-only edit queued an unrequested publication');

    // Simulate the previous bug, including a generated error and a retry job.
    $c['management']='site';$c['state']='error';$c['error']='Flowwow: publication failed';
    $c['properties']=['height'=>['val'=>12,'measure'=>2]];
    $set=$store->db->prepare("UPDATE product_channels SET document=? WHERE product_id='flowwow-321' AND channel='flowwow'");
    $set->execute([json_encode($c,JSON_UNESCAPED_UNICODE)]);
    $store->db->exec("INSERT INTO publication_jobs(product_id,channel,revision,state,updated_at)
        VALUES('flowwow-321','flowwow','old-phantom','retry','2026-10-01T00:00:00Z')");
    $store=new CatalogStore($directory,$root.'/data/catalog.json');
    $c=$store->channels('flowwow-321')['flowwow'];
    ownCheck($c['management']==='external'&&$c['state']==='external'&&$c['error']==='',
        'Historical accidental Flowwow error was not repaired');
    ownCheck((int)$store->db->query("SELECT COUNT(*) FROM publication_jobs WHERE product_id='flowwow-321'")->fetchColumn()===0,
        'Historical accidental publication job was not deleted');
    $p=$store->all(true)[0];
    ownCheck($p['title']==='Другое название на сайте'&&$p['price']==750,
        'Repair changed website title or price');

    // Once a user explicitly opts into managing the platform, retain that intent.
    $p['channels']=['flowwow'=>['enabled'=>true,'configure'=>true,
       'price'=>null,'title'=>'','description'=>'','category'=>'','properties'=>[]]];
    $store->save($p);
    $c=$store->channels('flowwow-321')['flowwow'];
    ownCheck($c['management']==='site'&&$c['publicationRequested']===true,
        'Explicit publication was not recorded');
    ownCheck((int)$store->db->query("SELECT COUNT(*) FROM publication_jobs WHERE product_id='flowwow-321'")->fetchColumn()===1,
        'Explicit publication did not create a job');
    $store=new CatalogStore($directory,$root.'/data/catalog.json');
    ownCheck($store->channels('flowwow-321')['flowwow']['management']==='site',
        'Historical repair cancelled a genuine publication');
    echo "Imported ownership checks passed: site-only edit, phantom-job repair, genuine publication.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
    rmdir($root);
}

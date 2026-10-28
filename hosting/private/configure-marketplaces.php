<?php
declare(strict_types=1);
// CLI provisioning only. Credentials arrive on stdin and never enter a public response.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if (!is_array($input) || empty($input['avito']['client_id']) || empty($input['avito']['client_secret'])) throw new RuntimeException('Missing marketplace credentials');
$lock=fopen(__DIR__.'/marketplaces-config.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Configuration locked');
$file=__DIR__.'/marketplaces-config.php';
$config=is_file($file)?require $file:[];
$config['site_url']='https://botanikart.ru';
foreach (['avito'=>['client_id','client_secret'],'flowwow'=>['token','shop_id']] as $channel=>$fields) {
    foreach ($fields as $field) if (!empty($input[$channel][$field])) $config[$channel][$field]=$input[$channel][$field];
}
$content="<?php\nreturn ".var_export($config,true).";\n";
if (!is_file($file) || file_get_contents($file)!==$content) {
    $temporary=$file.'.'.bin2hex(random_bytes(8));
    if (file_put_contents($temporary,$content)===false) throw new RuntimeException('Cannot save configuration');
    chmod($temporary,0600);
    if (!rename($temporary,$file)) { unlink($temporary);throw new RuntimeException('Cannot activate configuration'); }
}
flock($lock,LOCK_UN);fclose($lock);
$db=new PDO('sqlite:'.__DIR__.'/catalogue/catalogue.sqlite');
$db->exec('PRAGMA busy_timeout=10000');
// One-time withdrawal requested by the owner. Never seed or re-enable demonstration exports.
$check=$db->prepare('SELECT value FROM metadata WHERE name=?');$check->execute(['withdraw-demo-20261028']);
if($check->fetchColumn()===false){
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT document FROM product_channels WHERE product_id=? AND channel='avito'");$q->execute(['flowwow-76364430']);$raw=$q->fetchColumn();
        if($raw){$channel=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$channel['enabled']=false;$channel['externalId']='8372559354';$channel['management']='site';$channel['state']='hidden';$channel['error']='';
            $q=$db->prepare("UPDATE product_channels SET document=? WHERE product_id=? AND channel='avito'");$q->execute([json_encode($channel,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'flowwow-76364430']);
            $q=$db->prepare("DELETE FROM publication_jobs WHERE product_id=? AND channel='avito'");$q->execute(['flowwow-76364430']);}
        $q=$db->prepare('INSERT INTO metadata(name,value) VALUES(?,?)');$q->execute(['withdraw-demo-20261028','true']);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
$check=$db->prepare('SELECT value FROM metadata WHERE name=?');$check->execute(['outbound-review-20261028']);
if($check->fetchColumn()===false){
    $rows=$db->query("SELECT p.product_id,p.channel,p.document FROM product_channels p JOIN publication_jobs j ON j.product_id=p.product_id AND j.channel=p.channel WHERE j.state<>'confirmed'")->fetchAll(PDO::FETCH_ASSOC);
    $db->beginTransaction();
    try{
        foreach($rows as $row){$c=json_decode($row['document'],true);if(($c['management']??'')!=='site')continue;$c['enabled']=false;$c['state']='off';$c['error']='';$q=$db->prepare('UPDATE product_channels SET document=? WHERE product_id=? AND channel=?');$q->execute([json_encode($c,JSON_UNESCAPED_UNICODE),$row['product_id'],$row['channel']]);$q=$db->prepare("UPDATE publication_jobs SET state='cancelled' WHERE product_id=? AND channel=?");$q->execute([$row['product_id'],$row['channel']]);}
        $q=$db->prepare('INSERT INTO metadata(name,value) VALUES(?,?)');$q->execute(['outbound-review-20261028','true']);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
$query=$db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO NOTHING');
$query->execute(['avito:renewalDate',json_encode('2026-11-27')]);
$query=$db->query("SELECT value FROM metadata WHERE name='avito:renewalDate'");
echo json_encode(['configured'=>true,'renewalDate'=>json_decode($query->fetchColumn())]);

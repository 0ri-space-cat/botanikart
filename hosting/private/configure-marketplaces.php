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
$query=$db->prepare('INSERT INTO metadata(name,value) VALUES(?,?) ON CONFLICT(name) DO NOTHING');
$query->execute(['avito:renewalDate',json_encode('2026-11-27')]);
$query=$db->query("SELECT value FROM metadata WHERE name='avito:renewalDate'");
echo json_encode(['configured'=>true,'renewalDate'=>json_decode($query->fetchColumn())]);

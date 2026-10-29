<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!is_file(__DIR__.'/telegram-config.php'))exit;
require_once __DIR__.'/TelegramBot.php';require_once __DIR__.'/PaymentService.php';
$lock=fopen(__DIR__.'/telegram-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
 if(is_file(__DIR__.'/config.php'))(new \BotanikArt\MerchantOrders(require __DIR__.'/config.php'))->queueTelegram();
 $bot=\BotanikArt\TelegramBot::installed(__DIR__);$bot->workerHeartbeat();
 try{$bot->ensureWebhook();}catch(Throwable){error_log('BotanikArt: Telegram connection will retry');}
 $sent=$bot->drain(5);
 $q=$bot->db->prepare('DELETE FROM updates WHERE created<?');$q->execute([time()-604800]);
 $q=$bot->db->prepare("UPDATE outbox SET payload='{}' WHERE sent<>0 AND created<?");$q->execute([time()-2592000]);
 echo 'Telegram messages sent: '.$sent."\n";
}catch(Throwable $e){error_log('BotanikArt Telegram worker: '.get_class($e));exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}

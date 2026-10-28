<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$private=__DIR__.'/.private';
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo '{}';exit;}
 if(!is_file($private.'/telegram-config.php')){http_response_code(503);echo '{}';exit;}
 $cfg=require $private.'/telegram-config.php';
 if(!hash_equals($cfg['webhook_secret'],$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'')){http_response_code(403);echo '{}';exit;}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>262144){http_response_code(413);echo '{}';exit;}
 $raw=file_get_contents('php://input',false,null,0,262145);if($raw===false||strlen($raw)>262144)throw new InvalidArgumentException('Invalid update');
 $u=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($u))throw new InvalidArgumentException('Invalid update');
 require_once $private.'/TelegramBot.php';$bot=new \BotanikArt\TelegramBot($cfg,$private);$bot->accept($u);
 echo '{"ok":true}';
 if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
 try{set_time_limit(35);$bot->drain(3);}catch(Throwable){error_log('BotanikArt: Telegram outbox will retry');}
}catch(JsonException|InvalidArgumentException){http_response_code(400);echo '{}';}
catch(Throwable $e){error_log('BotanikArt Telegram: '.get_class($e));http_response_code(503);echo '{}';}

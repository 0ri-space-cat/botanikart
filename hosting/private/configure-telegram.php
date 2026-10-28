<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);require_once __DIR__.'/TelegramBot.php';
try{
 $file=__DIR__.'/telegram-config.php';$old=is_file($file)?require $file:[];
 if(($argv[1]??'')==='--resume'&&!empty($old['token']))$token=$old['token'];
 else{
 echo "Подключение бота БотаникАрт. Токен сохраняется только на Timeweb.\nТокен от BotFather (ввод скрыт, Backspace работает): ";
 $mode=trim((string)shell_exec('stty -g'));if(!$mode)throw new RuntimeException('Откройте интерактивный терминал.');
 shell_exec('stty -echo');try{$token=trim((string)fgets(STDIN));}finally{shell_exec('stty '.escapeshellarg($mode));echo "\n";}
 }
 if(!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{25,100}$/D',$token))throw new RuntimeException('Формат токена не подходит. Запустите ввод снова.');
 $cfg=['token'=>$token,'site_url'=>'https://botanikart.ru','webhook_secret'=>$old['webhook_secret']??bin2hex(random_bytes(32)),'context_secret'=>$old['context_secret']??bin2hex(random_bytes(32)),'owner_username'=>$old['owner_username']??'Ekaterina_Freed','created_at'=>$old['created_at']??time()];
 $bot=new \BotanikArt\TelegramBot($cfg,__DIR__);$me=$bot->request('getMe');$cfg['username']=$me['username'];
 if(!empty($old['username'])&&$old['username']!==$cfg['username'])throw new RuntimeException('Введён токен другого бота. Для замены нужен отдельный перенос настроек.');
 $tmp=$file.'.tmp';file_put_contents($tmp,"<?php\nreturn ".var_export($cfg,true).";\n");chmod($tmp,0600);rename($tmp,$file);
 $bot=new \BotanikArt\TelegramBot($cfg,__DIR__);
 $line='* * * * * /opt/php83/bin/php '.escapeshellarg(__DIR__.'/telegram-worker.php').' >/dev/null 2>&1 # botanikart-telegram';
 $current=(string)shell_exec('crontab -l 2>/dev/null');$lines=array_values(array_filter(explode("\n",$current),fn($s)=>trim($s)!==''&&!str_contains($s,'# botanikart-telegram')));$lines[]=$line;
 $cron=tempnam(sys_get_temp_dir(),'ba-cron-');file_put_contents($cron,implode("\n",$lines)."\n");exec('crontab '.escapeshellarg($cron),$ignored,$status);unlink($cron);
 $bot->ensureWebhook();
 if(!$bot->request('getMyCommands'))$bot->request('setMyCommands',['commands'=>[['command'=>'start','description'=>'Знакомство со студией'],['command'=>'catalog','description'=>'Каталог растений, флорариумов и материалов'],['command'=>'orders','description'=>'Заказы студии (для владелицы)']]]);
 echo "Бот подключён: https://t.me/".$cfg['username']."\n";
 if($status!==0)echo "Повторные отправки по расписанию не установились — сообщите об этом в чат.\n";else echo "Проверка очереди сообщений: каждую минуту.\n";
 echo "Отправьте сестре эту одноразовую ссылку. Она должна открыть её со своего аккаунта @".$cfg['owner_username']." и нажать Начать:\n".$bot->pairingLink()."\n";
}catch(Throwable $e){fwrite(STDERR,'Подключение не завершено: '.$e->getMessage()."\n");exit(1);}

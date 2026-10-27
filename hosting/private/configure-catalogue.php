<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
function ask(string $prompt, bool $secret = false): string {
    fwrite(STDOUT, $prompt);
    if ($secret) system('stty -echo');
    try { $answer = fgets(STDIN); }
    finally { if ($secret) { system('stty echo'); fwrite(STDOUT,"\n"); } }
    if ($answer === false) throw new RuntimeException('Ввод прерван');
    return $secret ? rtrim($answer,"\r\n") : trim($answer);
}
$username = isset($argv[1]) ? trim($argv[1]) : ask('Логин для БотаникАрт Каталога: ');
$password = ask('Пароль (минимум 12 символов, ввод скрыт): ',true);
$repeat = ask('Повторите пароль: ',true);
if (mb_strlen($username)<2 || mb_strlen($username)>80 || mb_strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0") || $repeat!==$password) {
    fwrite(STDERR,"Проверьте длину логина/пароля и совпадение двух вводов. Ничего не изменено.\n"); exit(1);
}
$config = ['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'revision'=>bin2hex(random_bytes(24))];
$target = __DIR__.'/catalogue-auth.php';
$temporary = $target.'.new-'.bin2hex(random_bytes(8));
file_put_contents($temporary,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX);
chmod($temporary,0600);
rename($temporary,$target);
fwrite(STDOUT,"Вход настроен. Адрес: https://botanikart.ru/catalogue/\n");

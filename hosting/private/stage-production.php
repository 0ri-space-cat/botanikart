<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
function hidden(string $label): string {
    echo $label;
    $mode = trim((string) shell_exec('stty -g'));
    if (!$mode) throw new RuntimeException('Use an interactive SSH terminal.');
    shell_exec('stty -echo');
    try { return trim((string) fgets(STDIN)); }
    finally { shell_exec('stty ' . escapeshellarg($mode)); echo PHP_EOL; }
}
try {
    if (!is_file(__DIR__ . '/config.php')) throw new RuntimeException('Сначала настройте и проверьте тестовый терминал.');
    if (is_file(__DIR__ . '/production.pending.php')) throw new RuntimeException('Рабочие ключи уже сохранены. Повторный ввод не нужен.');
    $config = require __DIR__ . '/config.php';
    $config['terminal_key'] = hidden('Terminal Key РАБОЧЕГО терминала (без DEMO): ');
    if (!$config['terminal_key'] || preg_match('/DEMO$/i', $config['terminal_key'])) throw new RuntimeException('Для рабочего режима нужен терминал без DEMO.');
    $config['password'] = hidden('Пароль РАБОЧЕГО терминала: ');
    if (!$config['password']) throw new RuntimeException('Пароль не может быть пустым.');
    $config['receipts_ready'] = false;
    $temporary = __DIR__ . '/.production-stage.php';
    file_put_contents($temporary, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    chmod($temporary, 0600);
    rename($temporary, __DIR__ . '/production.pending.php');
    echo "Рабочие ключи сохранены на Timeweb. Тестовый режим пока остаётся активным.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }

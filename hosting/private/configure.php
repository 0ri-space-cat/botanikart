<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
if (file_exists(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php already exists. Edit it privately instead of overwriting.\n"); exit(1); }
function hidden(string $label): string {
    echo $label;
    $mode = trim((string) shell_exec('stty -g'));
    if (!$mode) throw new RuntimeException('Use an interactive SSH terminal.');
    shell_exec('stty -echo');
    try { return trim((string) fgets(STDIN)); }
    finally { shell_exec('stty ' . escapeshellarg($mode)); echo PHP_EOL; }
}
try {
    $config = require __DIR__ . '/config.example.php';
    $site = trim(readline('HTTPS website URL [https://botanikart.ru]: ')) ?: 'https://botanikart.ru';
    if (!filter_var($site, FILTER_VALIDATE_URL) || parse_url($site, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('An HTTPS URL is required.');
    $config['site_url'] = rtrim($site, '/');
    $config['terminal_key'] = hidden('Test Terminal Key (DEMO): ');
    if (!preg_match('/DEMO$/i', $config['terminal_key'])) throw new RuntimeException('First setup only accepts a DEMO terminal.');
    $config['password'] = hidden('Test terminal password: ');
    if (!$config['password']) throw new RuntimeException('Password cannot be empty.');
    $config['order_secret'] = bin2hex(random_bytes(32));
    file_put_contents(__DIR__ . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    chmod(__DIR__ . '/config.php', 0600);
    echo "Private test configuration saved. No credentials were printed.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }

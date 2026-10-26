<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/PaymentService.php';
$command = $argv[1] ?? 'doctor';
try {
    if (!is_file(__DIR__ . '/config.php')) throw new RuntimeException('Create private config.php first.');
    $config = require __DIR__ . '/config.php';
    $service = new \BotanikArt\PaymentService($config);
    if ($command === 'doctor') {
        foreach (['curl', 'mbstring', 'openssl'] as $extension) {
            if (!extension_loaded($extension)) throw new RuntimeException('Missing PHP extension: ' . $extension);
        }
        if (!is_file($config['catalog_path'])) throw new RuntimeException('Catalog file is missing.');
        if (strlen($config['order_secret'] ?? '') < 32) throw new RuntimeException('Order signing secret is missing.');
        // A read-only request verifies bank TLS without creating any payment.
        $curl = curl_init('https://securepay.tinkoff.ru/v2/Init');
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false];
        if (!empty($config['ca_bundle'])) $options[CURLOPT_CAINFO] = $config['ca_bundle'];
        curl_setopt_array($curl, $options);
        if (curl_exec($curl) === false) throw new RuntimeException('Bank TLS connection failed, cURL code ' . curl_errno($curl));
        echo 'PHP, private storage and bank TLS: OK' . PHP_EOL;
        echo json_encode($service->health(), JSON_PRETTY_PRINT) . PHP_EOL;
    } elseif ($command === 'reconcile') {
        echo 'Orders checked: ' . $service->reconcile() . PHP_EOL;
    } elseif ($command === 'restock' && !empty($argv[2])) {
        $service->restock($argv[2]);
        echo 'Product released for a new purchase.' . PHP_EOL;
    } else throw new RuntimeException('Usage: console.php doctor | reconcile | restock PRODUCT_ID');
} catch (Throwable $error) {
    fwrite(STDERR, 'Operation failed (' . get_class($error) . '): ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

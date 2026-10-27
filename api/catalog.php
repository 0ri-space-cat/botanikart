<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/javascript; charset=utf-8');
try {
    require_once __DIR__ . '/.private/CatalogStore.php';
    $store = new \BotanikArt\CatalogStore(__DIR__ . '/.private/catalogue', dirname(__DIR__) . '/data/catalog.json');
    echo 'window.BOTANIK_CATALOG=' . json_encode($store->publicCatalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . ';';
} catch (\Throwable $error) {
    error_log('BotanikArt catalog: ' . get_class($error));
    http_response_code(503);
    echo 'window.BOTANIK_CATALOG={products:[],featured:[]};';
}

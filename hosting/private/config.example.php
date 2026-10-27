<?php
// Timeweb site isolation: install under public_html/api/.private with HTTP access denied.
// Every runtime file uses a .php extension; order data also starts with a PHP exit guard.
return [
    'site_url' => 'https://botanikart.ru',
    'terminal_key' => '',
    'password' => '',
    'order_secret' => '', // At least 32 random characters; keep stable across deployments.
    'catalog_path' => dirname(__DIR__, 2) . '/data/catalog.json',
    'data_dir' => __DIR__ . '/orders',
    'ca_bundle' => '', // Optional trusted CA bundle; TLS verification always stays enabled.
    'taxation' => 'usn_income',
    'tax' => '', // Confirm with the owner/cash register provider.
    'payment_method' => 'full_prepayment',
    'receipts_ready' => false,
];

<?php
require __DIR__ . '/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';
    exit;
}
try {
    [, $service] = apiContext();
    $health = $service->health();
    echo json_encode([
        'enabled' => $health['paymentConfigured'] && ($health['testMode'] || $health['receiptConfigured']),
        'testMode' => $health['testMode'],
    ]);
} catch (Throwable) {
    http_response_code(503);
    echo '{"enabled":false,"testMode":false}';
}

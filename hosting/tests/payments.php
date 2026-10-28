<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/PaymentService.php';
use BotanikArt\{ApiError, BankClient, PaymentService};

$directory = sys_get_temp_dir() . '/botanikart-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$assertions = 0;
function check(bool $value, string $message): void {
    global $assertions;
    if (!$value) throw new RuntimeException($message);
    $assertions++;
}
function rejects(callable $callback, string $reason): void {
    try { $callback(); } catch (ApiError $e) { check($e->reason === $reason, 'Wrong error: ' . $e->reason); return; }
    throw new RuntimeException('Expected ' . $reason);
}
function requestId(): string { return '12345678-1234-1234-1234-' . bin2hex(random_bytes(6)); }
try {
    $productId = 'flowwow-123';
    file_put_contents($directory . '/catalog.json', json_encode(['products' => [['id' => $productId, 'title' => 'Флорариум ' . str_repeat('🌿', 90), 'price' => 123, 'available' => true]]]));
    $config = ['site_url' => 'https://botanikart.ru', 'terminal_key' => 'TESTDEMO', 'password' => 'fixture-only',
        'order_secret' => str_repeat('a', 64), 'catalog_path' => $directory . '/catalog.json', 'data_dir' => $directory . '/data',
        'taxation' => 'usn_income', 'tax' => '', 'receipts_ready' => false];
    $calls = []; $bankStatus = 'NEW'; $bankAmount = 12300; $bankHost = 'pay.tbank-online.com'; $bankOrder = '';
    $bank = function (string $method, array $payload) use (&$calls, &$bankStatus, &$bankAmount, &$bankHost, &$bankOrder): array {
        $calls[] = [$method, $payload];
        if ($method === 'Init') {
            $bankOrder = $payload['OrderId'];
            return ['Success' => true, 'OrderId' => $bankOrder, 'PaymentId' => '77', 'PaymentURL' => 'https://' . $bankHost . '/payment'];
        }
        if ($method === 'Cancel') { $bankStatus = 'CANCELED'; return ['Success' => true, 'PaymentId' => '77', 'Status' => 'CANCELED']; }
        return ['Success' => true, 'OrderId' => $payload['OrderId'], 'Payments' => [['PaymentId' => '77', 'Amount' => $bankAmount, 'Status' => $bankStatus]]];
    };
    $service = new PaymentService($config, $bank);
    $input = ['productId' => $productId, 'requestId' => requestId(), 'expectedAmount' => 12300, 'amount' => 1, 'email' => 'test@example.com', 'phone' => '', 'consent' => true];
    rejects(fn() => $service->create(array_replace($input, ['expectedAmount' => 1])), 'price_changed');
    check(count($calls) === 0, 'Stale prices must not reach the bank');
    $live = new PaymentService(array_replace($config, ['terminal_key' => 'LIVE']), $bank);
    rejects(fn() => $live->create($input), 'receipt_not_configured');
    $originalCatalog = file_get_contents($directory . '/catalog.json');
    $assortmentCatalog = json_decode($originalCatalog, true);
    $assortmentCatalog['products'][0]['purchaseMode'] = 'inquiry';
    file_put_contents($directory . '/catalog.json', json_encode($assortmentCatalog));
    rejects(fn() => $service->create($input), 'product_unavailable');
    check(count($calls) === 0, 'Assortment choice must be agreed before any bank request');
    file_put_contents($directory . '/catalog.json', $originalCatalog);
    rejects(fn() => $service->create(array_replace($input, ['telegramContext'=>'forged'])), 'invalid_telegram_checkout');
    check(count($calls) === 0, 'Invalid Telegram identity never reaches the bank');
    $service = new PaymentService($config, $bank, function($token, $id) use($productId) {
        if($token !== 'signed-fixture' || $id !== $productId) throw new InvalidArgumentException();
        return '101';
    });
    $input['telegramContext'] = 'signed-fixture';
    $result = $service->create($input);
    check($calls[0][1]['Amount'] === 12300, 'The catalog controls the amount');
    check(strlen($calls[0][1]['SuccessURL']) <= 250 && strlen($calls[0][1]['FailURL']) <= 250, 'Return URLs must fit bank limits');
    check($calls[0][1]['NotificationURL'] === 'https://botanikart.ru/api/notify.php', 'Bank notifications are configured');
    check(!isset($calls[0][1]['Receipt']), 'No invented tax settings on DEMO');
    $repeat = $service->create($input);
    check($repeat === $result && count($calls) === 1, 'Retries reuse the existing bank payment');
    rejects(fn() => $service->create(array_replace($input, ['email' => 'other@example.com'])), 'invalid_order_details');
    rejects(fn() => $service->create(array_replace($input, ['requestId' => requestId()])), 'product_unavailable');
    rejects(fn() => $service->status(['reference' => $result['reference'] . 'x']), 'invalid_order');
    $bankStatus = 'CONFIRMED'; $bankAmount = 100;
    rejects(fn() => $service->status(['reference' => $result['reference']]), 'order_mismatch');
    $bankAmount = 12300;
    $paid = $service->status(['reference' => $result['reference']]);
    check($paid['paid'] === true && $paid['amount'] === 123 && $paid['testMode'] === true, 'Bank confirmation is checked with exact amount');
    check(!isset($paid['email'], $paid['phone'], $paid['paymentUrl']), 'Status endpoint does not disclose contacts');
    $count = count($calls);
    rejects(fn() => $service->notify(['TerminalKey' => 'TESTDEMO', 'Token' => str_repeat('0', 64), 'OrderId' => $result['orderId']]), 'invalid_notification');
    check(count($calls) === $count, 'Unsigned notification cannot trigger a bank request');
    $notification = ['TerminalKey' => 'TESTDEMO', 'OrderId' => $result['orderId'], 'PaymentId' => '77', 'Amount' => 12300, 'Status' => 'CONFIRMED', 'Success' => true];
    $notification['Token'] = BankClient::token($notification, $config['password']);
    $service->notify($notification); $service->notify($notification);
    $bankStatus = 'NEW';
    check($service->status(['reference' => $result['reference']])['paid'] === true, 'Old bank events do not downgrade a paid order');
    rejects(fn() => $service->create(array_replace($input, ['requestId' => requestId()])), 'product_unavailable');
    $bankStatus = 'REFUNDED';
    check($service->status(['reference' => $result['reference']])['paid'] === false, 'Refund changes customer payment status');
    rejects(fn() => $service->create(array_replace($input, ['requestId' => requestId()])), 'product_unavailable');
    $service->restock($productId);
    $bankStatus = 'NEW';
    $second = $service->create(array_replace($input, ['requestId' => requestId()]));
    check($second['orderId'] !== $result['orderId'], 'Restocking explicitly permits a new order');
    $statePath = $directory . '/data/test/orders.php';
    $guard = \BotanikArt\OrderStore::GUARD;
    check(str_starts_with(file_get_contents($statePath), $guard), 'Direct PHP access cannot print order data');
    $state = json_decode(substr(file_get_contents($statePath), strlen($guard)), true);
    check($state['orders'][$result['orderId']]['telegramChatId'] === '101', 'Verified Telegram buyer stays attached to the order');
    check(($state['orders'][$result['orderId']]['notificationCount'] ?? 0) === 2
        && ($state['orders'][$result['orderId']]['lastNotificationAt'] ?? 0) > 0,
        'Verified bank notifications are tracked independently of status polling');
    $state['orders'][$second['orderId']]['expiresAt'] = time() - 60;
    file_put_contents($statePath, $guard . json_encode($state));
    $service->reconcile();
    check($bankStatus === 'CANCELED', 'An expired payment is cancelled at the bank before releasing stock');
    check(!isset(json_decode(substr(file_get_contents($statePath), strlen($guard)), true)['reservations'][$productId]), 'A confirmed cancellation releases the product');
    $bankHost = 'attacker.example';
    rejects(fn() => $service->create(array_replace($input, ['requestId' => requestId()])), 'payment_init_failed');
    $scalarPayload = ['Success' => true, 'Amount' => 10, 'Nested' => ['x' => 1]];
    check(BankClient::token($scalarPayload, 'secret') === hash('sha256', '10secrettrue'), 'Bank signature uses sorted scalar values and lowercase booleans');
    check((fileperms($statePath) & 0777) === 0600, 'Orders are readable only by the hosting account');
    file_put_contents($statePath, $guard . '{');
    try { $service->reconcile(); throw new RuntimeException('Corrupt storage was accepted'); }
    catch (JsonException) { $assertions++; }
    echo "Payment tests passed: $assertions assertions.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($directory);
}

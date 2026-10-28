<?php
declare(strict_types=1);

namespace BotanikArt;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 400)
    {
        parent::__construct($reason);
    }
}

/** Small-shop storage. The lock is separate from the atomically replaced data file. */
final class OrderStore
{
    public const GUARD = "<?php exit; ?>\n";
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create private order directory');
        }
    }

    public function transaction(callable $callback): mixed
    {
        $lock = fopen($this->directory . '/orders.lock.php', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock orders');
        }
        try {
            $path = $this->directory . '/orders.php';
            $state = ['orders' => [], 'reservations' => []];
            if (is_file($path)) {
                $raw = (string) file_get_contents($path);
                if (!str_starts_with($raw, self::GUARD)) throw new \RuntimeException('Invalid order file guard');
                $state = json_decode(substr($raw, strlen(self::GUARD)), true, 512, JSON_THROW_ON_ERROR);
            }
            if (!isset($state['orders'], $state['reservations']) || !is_array($state['orders']) || !is_array($state['reservations'])) {
                throw new \RuntimeException('Invalid order store');
            }
            $result = $callback($state);
            $temporary = $this->directory . '/.orders-' . bin2hex(random_bytes(12)) . '.php';
            $temporaryFile = fopen($temporary, 'x');
            if ($temporaryFile === false) {
                throw new \RuntimeException('Cannot create order file');
            }
            fclose($temporaryFile);
            try {
                chmod($temporary, 0600);
                $json = self::GUARD . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                    throw new \RuntimeException('Cannot save orders');
                }
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

final class BankClient
{
    public function __construct(private readonly array $config) {}

    public static function token(array $payload, string $password): string
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if ($key === 'Token' || !is_scalar($value)) continue;
            $values[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        $values['Password'] = $password;
        ksort($values, SORT_STRING);
        return hash('sha256', implode('', $values));
    }

    public function __invoke(string $method, array $payload): array
    {
        if (!in_array($method, ['Init', 'CheckOrder', 'Cancel'], true)) throw new \LogicException('Unexpected bank method');
        $payload['Token'] = self::token($payload, $this->config['password']);
        $curl = curl_init('https://securepay.tinkoff.ru/v2/' . $method);
        $options = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if (!empty($this->config['ca_bundle'])) $options[CURLOPT_CAINFO] = $this->config['ca_bundle'];
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $code = curl_errno($curl);
        if ($raw === false || $http !== 200) {
            error_log('BotanikArt bank connection failed: HTTP ' . $http . ', cURL ' . $code);
            throw new ApiError('payment_service_unavailable', 502);
        }
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($result['Success'] ?? false) !== true) {
            // Never log request bodies, customer contacts, tokens or bank passwords.
            error_log('BotanikArt bank rejected ' . $method . ': ' . preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($result['ErrorCode'] ?? 'unknown')));
            throw new ApiError('payment_init_failed', 502);
        }
        return $result;
    }
}

final class PaymentService
{
    private readonly OrderStore $store;
    private readonly \Closure $bank;
    private const FINAL_UNPAID = ['REJECTED', 'CANCELED', 'DEADLINE_EXPIRED', 'REVERSED'];

    public function __construct(private readonly array $config, ?callable $bank = null)
    {
        $this->store = new OrderStore($config['data_dir'] . ($this->isTest() ? '/test' : '/live'));
        $this->bank = \Closure::fromCallable($bank ?? new BankClient($config));
    }

    public function isTest(): bool { return (bool) preg_match('/DEMO$/i', $this->config['terminal_key'] ?? ''); }

    public function health(): array
    {
        return ['ok' => true, 'paymentConfigured' => !empty($this->config['terminal_key']) && !empty($this->config['password']),
            'testMode' => $this->isTest(), 'receiptConfigured' => $this->receiptsReady()];
    }

    private function receiptsReady(): bool
    {
        return ($this->config['receipts_ready'] ?? false) === true
            && in_array($this->config['taxation'] ?? '', ['osn', 'usn_income', 'usn_income_outcome', 'esn', 'patent'], true)
            && in_array($this->config['tax'] ?? '', ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat20', 'vat22'], true)
            && in_array($this->config['payment_method'] ?? '', ['full_prepayment', 'full_payment'], true);
    }

    private function configured(): void
    {
        $site = parse_url($this->config['site_url'] ?? '');
        if (empty($this->config['terminal_key']) || empty($this->config['password']) || strlen($this->config['order_secret'] ?? '') < 32
            || ($site['scheme'] ?? '') !== 'https' || empty($site['host']) || !empty($site['query']) || !empty($site['fragment'])) {
            throw new ApiError('payment_not_configured', 503);
        }
    }

    private function reference(string $orderId): string
    {
        return $orderId . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $orderId, $this->config['order_secret'], true)), '+/', '-_'), '=');
    }

    private function readReference(mixed $reference): string
    {
        if (!is_string($reference) || !preg_match('/^(ba-[a-f0-9]{24})\.[A-Za-z0-9_-]{43}$/D', $reference, $match)
            || !hash_equals($this->reference($match[1]), $reference)) throw new ApiError('invalid_order');
        return $match[1];
    }

    private function product(string $id): array
    {
        $catalogRoot = dirname($this->config['catalog_path'], 2);
        $catalogService = $catalogRoot . '/api/.private/CatalogStore.php';
        if (is_file($catalogService)) {
            if (!class_exists(CatalogStore::class)) require_once $catalogService;
            $catalog = (new CatalogStore($catalogRoot . '/api/.private/catalogue', $this->config['catalog_path']))->publicCatalog();
        } else {
            $catalog = json_decode((string) file_get_contents($this->config['catalog_path']), true, 512, JSON_THROW_ON_ERROR);
        }
        foreach ($catalog['products'] ?? [] as $product) {
            if (($product['id'] ?? '') === $id && ($product['available'] ?? false) === true
                && ($product['purchaseMode'] ?? 'direct') === 'direct'
                && is_numeric($product['price'] ?? null) && (float) $product['price'] > 0) return $product;
        }
        throw new ApiError('product_unavailable', 409);
    }

    private function callBank(string $method, array $payload): array
    {
        return ($this->bank)($method, ['TerminalKey' => $this->config['terminal_key']] + $payload);
    }

    public function create(array $input, string $clientAddress = ''): array
    {
        $this->configured();
        if (!$this->isTest() && !$this->receiptsReady()) throw new ApiError('receipt_not_configured', 503);
        $id = is_string($input['productId'] ?? null) ? $input['productId'] : '';
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $digits = preg_replace('/\D/', '', is_string($input['phone'] ?? null) ? $input['phone'] : '');
        $phone = $digits ? '+' . (strlen($digits) === 11 && $digits[0] === '8' ? '7' . substr($digits, 1) : $digits) : '';
        $requestId = $input['requestId'] ?? '';
        if (!is_string($requestId) || !preg_match('/^[a-f0-9-]{36}$/Di', $requestId) || strlen($id) > 160 || !$id
            || ($input['consent'] ?? false) !== true || !is_int($input['expectedAmount'] ?? null) || $input['expectedAmount'] <= 0
            || (!$email && !$phone) || ($email && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 64))
            || ($phone && !preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone))) throw new ApiError('invalid_order_details');
        $product = $this->product($id);
        $amount = (int) round((float) $product['price'] * 100);
        if ($amount !== $input['expectedAmount']) throw new ApiError('price_changed', 409);
        $orderId = 'ba-' . substr(hash_hmac('sha256', $requestId, $this->config['order_secret']), 0, 24);
        $reference = $this->reference($orderId);
        $site = rtrim($this->config['site_url'], '/');
        $success = $site . '/order-success.html?reference=' . rawurlencode($reference);
        $failure = $site . '/order-error.html?reference=' . rawurlencode($reference);
        if (strlen($success) > 250 || strlen($failure) > 250) throw new ApiError('payment_not_configured', 503);
        $blocking = $this->store->transaction(fn(array &$s) => $s['reservations'][$id] ?? null);
        if ($blocking && $blocking !== $orderId) $this->refresh($blocking, true);
        $order = $this->store->transaction(function (array &$state) use ($orderId, $id, $product, $amount, $email, $phone, $clientAddress) {
            if (isset($state['orders'][$orderId])) {
                $existing = $state['orders'][$orderId];
                if ($existing['productId'] !== $id || $existing['amount'] !== $amount || $existing['email'] !== $email || $existing['phone'] !== $phone) throw new ApiError('invalid_order_details');
                return $existing + ['_new' => false];
            }
            if (isset($state['reservations'][$id])) throw new ApiError('product_unavailable', 409);
            if ($clientAddress !== '') {
                $key = hash_hmac('sha256', $clientAddress, $this->config['order_secret']);
                $state['rates'] = array_filter($state['rates'] ?? [], fn($rate) => $rate['since'] > time() - 600);
                $rate = $state['rates'][$key] ?? ['since' => time(), 'count' => 0];
                if ($rate['count'] >= 10) throw new ApiError('too_many_requests', 429);
                $rate['count']++;
                $state['rates'][$key] = $rate;
            }
            $order = ['orderId' => $orderId, 'productId' => $id, 'title' => mb_substr($product['title'], 0, 140),
                'amount' => $amount, 'email' => $email, 'phone' => $phone, 'testMode' => $this->isTest(),
                'createdAt' => time(), 'expiresAt' => time() + 1800, 'status' => 'CREATING', 'paid' => false, 'sold' => false];
            $state['orders'][$orderId] = $order;
            $state['reservations'][$id] = $orderId;
            return $order + ['_new' => true];
        });
        if (!empty($order['paymentUrl']) && !$order['paid'] && time() < $order['expiresAt']) return ['paymentUrl' => $order['paymentUrl'], 'orderId' => $orderId, 'reference' => $reference];
        if (!$order['_new']) throw new ApiError('payment_service_unavailable', 503);
        // A second request with the same key cannot call Init concurrently.
        $this->store->transaction(function (array &$s) use ($orderId) {
            if ($s['orders'][$orderId]['claimed'] ?? false) throw new ApiError('payment_service_unavailable', 503);
            $s['orders'][$orderId]['claimed'] = true;
        });
        $payload = ['Amount' => $amount, 'OrderId' => $orderId, 'Description' => $order['title'], 'Language' => 'ru', 'PayType' => 'O',
            'SuccessURL' => $success, 'FailURL' => $failure, 'NotificationURL' => $site . '/api/notify.php',
            'RedirectDueDate' => gmdate('Y-m-d\TH:i:s\Z', $order['expiresAt']), 'DATA' => ['OperationInitiatorType' => '0']];
        if ($this->receiptsReady()) {
            $receipt = ['Taxation' => $this->config['taxation'], 'Items' => [['Name' => mb_substr($order['title'], 0, 128),
                'Price' => $amount, 'Quantity' => 1, 'Amount' => $amount, 'Tax' => $this->config['tax'],
                'PaymentMethod' => $this->config['payment_method'], 'PaymentObject' => 'commodity']]];
            if ($email) $receipt['Email'] = $email;
            if ($phone) $receipt['Phone'] = $phone;
            $payload['Receipt'] = $receipt;
        }
        $result = $this->callBank('Init', $payload);
        $url = parse_url($result['PaymentURL'] ?? '');
        if (($url['scheme'] ?? '') !== 'https' || !in_array(strtolower($url['host'] ?? ''), ['securepay.tinkoff.ru', 'securepayments.tinkoff.ru', 'pay.tbank-online.com'], true)
            || isset($url['user']) || isset($url['pass']) || (isset($url['port']) && $url['port'] !== 443)
            || empty($result['PaymentId']) || (isset($result['OrderId']) && $result['OrderId'] !== $orderId)) throw new ApiError('payment_init_failed', 502);
        $this->store->transaction(function (array &$s) use ($orderId, $result) {
            $o =& $s['orders'][$orderId];
            $o['paymentId'] = (string) $result['PaymentId'];
            $o['paymentUrl'] = $result['PaymentURL'];
            if (!$o['paid']) $o['status'] = 'NEW';
        });
        return ['paymentUrl' => $result['PaymentURL'], 'orderId' => $orderId, 'reference' => $reference];
    }

    private function apply(string $id, string $status, int $amount, string $paymentId): void
    {
        $this->store->transaction(function (array &$s) use ($id, $status, $amount, $paymentId) {
            if (!isset($s['orders'][$id])) throw new ApiError('invalid_order');
            $o =& $s['orders'][$id];
            if ($o['amount'] !== $amount || (isset($o['paymentId']) && $o['paymentId'] !== $paymentId)) throw new ApiError('order_mismatch', 409);
            if ($status === 'CONFIRMED') {
                $o['paid'] = true; $o['sold'] = true; $o['status'] = $status;
                $o['paidAt'] ??= time();
                if (!($o['testMode'] ?? true)) $o['merchantMailPendingAt'] ??= time();
            } elseif (in_array($status, ['REFUNDED', 'PARTIAL_REFUNDED'], true)) {
                $o['paid'] = $status === 'PARTIAL_REFUNDED'; $o['sold'] = true; $o['status'] = $status;
            } elseif (!$o['sold']) {
                $o['status'] = $status;
                if (in_array($status, self::FINAL_UNPAID, true) && ($s['reservations'][$o['productId']] ?? '') === $id) unset($s['reservations'][$o['productId']]);
            }
            $o['updatedAt'] = time();
        });
        if (!$this->isTest()) {
            try { (new MerchantOrders($this->config))->sendPending(1); }
            catch (\Throwable) { error_log('BotanikArt: merchant email queued for retry'); }
        }
    }

    public function refresh(string $orderId, bool $cancelExpired = false): array
    {
        $this->configured();
        $order = $this->store->transaction(fn(array &$s) => $s['orders'][$orderId] ?? null);
        if (!$order || $order['testMode'] !== $this->isTest()) throw new ApiError('invalid_order');
        $result = $this->callBank('CheckOrder', ['OrderId' => $orderId]);
        if (($result['OrderId'] ?? '') !== $orderId) throw new ApiError('order_mismatch', 502);
        $payments = $result['Payments'] ?? [];
        $payment = null;
        foreach ($payments as $candidate) {
            if (isset($order['paymentId']) && (string) ($candidate['PaymentId'] ?? '') === $order['paymentId']) $payment = $candidate;
        }
        if ($payment) {
            $status = (string) ($payment['Status'] ?? 'NEW');
            $this->apply($orderId, $status, (int) ($payment['Amount'] ?? -1), (string) $payment['PaymentId']);
            if ($cancelExpired && !$order['sold'] && $status !== 'CONFIRMED' && !in_array($status, self::FINAL_UNPAID, true) && time() > $order['expiresAt']) {
                $cancel = $this->callBank('Cancel', ['PaymentId' => $order['paymentId']]);
                if ((string) ($cancel['PaymentId'] ?? '') === $order['paymentId'] && in_array($cancel['Status'] ?? '', self::FINAL_UNPAID, true)) {
                    $this->apply($orderId, $cancel['Status'], $order['amount'], $order['paymentId']);
                }
            }
        }
        return $this->store->transaction(fn(array &$s) => $s['orders'][$orderId]);
    }

    public function status(array $input): array
    {
        $this->configured();
        $o = $this->refresh($this->readReference($input['reference'] ?? null));
        return array_intersect_key($o, array_flip(['orderId', 'productId', 'title', 'paid', 'status', 'testMode'])) + ['amount' => $o['amount'] / 100];
    }

    public function notify(array $payload): void
    {
        $this->configured();
        if (($payload['TerminalKey'] ?? '') !== $this->config['terminal_key'] || !is_string($payload['Token'] ?? null)
            || !hash_equals(BankClient::token($payload, $this->config['password']), $payload['Token'])) throw new ApiError('invalid_notification', 403);
        $id = $payload['OrderId'] ?? '';
        if (!is_string($id) || !preg_match('/^ba-[a-f0-9]{24}$/D', $id)) throw new ApiError('invalid_order');
        // Query the bank rather than trust ordering of repeated notification deliveries.
        $this->refresh($id);
        $this->store->transaction(function (array &$state) use ($id) {
            $state['orders'][$id]['lastNotificationAt'] = time();
            $state['orders'][$id]['notificationCount'] = ($state['orders'][$id]['notificationCount'] ?? 0) + 1;
        });
    }

    public function reconcile(): int
    {
        $ids = $this->store->transaction(fn(array &$s) => array_values(array_unique($s['reservations'])));
        foreach ($ids as $id) $this->refresh($id, true);
        return count($ids);
    }

    public function restock(string $productId): void
    {
        $this->store->transaction(function (array &$s) use ($productId) {
            $id = $s['reservations'][$productId] ?? null;
            if ($id && !$s['orders'][$id]['sold']) throw new ApiError('active_payment_exists', 409);
            unset($s['reservations'][$productId]);
        });
    }
}

/** Owner-only fulfillment and durable, retryable purchase notifications. */
final class MerchantOrders
{
    private readonly OrderStore $store;
    public function __construct(private readonly array $config, private readonly mixed $mailer = null)
    {
        $this->store = new OrderStore($config['data_dir'].'/live');
    }
    public function all(): array
    {
        $orders=$this->store->transaction(function(array &$s):array {
            $result=[];
            foreach($s['orders'] as $o){
                if(($o['testMode']??true)||!($o['sold']??false)||!in_array($o['status']??'', ['CONFIRMED','REFUNDED','PARTIAL_REFUNDED'],true))continue;
                $row=array_intersect_key($o,array_flip(['orderId','productId','title','email','phone','status','createdAt','paidAt','completedAt','fulfillment','updatedAt']));
                $row['amount']=($o['amount']??0)/100;$row['fulfillment']=$o['fulfillment']??'open';
                $row['emailState']=isset($o['merchantMailSentAt'])?'accepted':(isset($o['merchantMailPendingAt'])?'pending':'');
                $result[]=$row;
            }
            return $result;
        });
        usort($orders,fn($a,$b)=>($b['paidAt']??$b['createdAt'])<=>($a['paidAt']??$a['createdAt']));
        return $orders;
    }
    public function complete(string $id, bool $completed): void
    {
        $this->store->transaction(function(array &$s)use($id,$completed):void {
            if(!isset($s['orders'][$id])||($s['orders'][$id]['testMode']??true)||!($s['orders'][$id]['sold']??false))throw new \OutOfBoundsException('Order missing');
            $o=&$s['orders'][$id];$o['fulfillment']=$completed?'completed':'open';$o['completedAt']=$completed?time():null;
        });
    }
    public function sendPending(int $limit=5): int
    {
        $sent=0;
        for($i=0;$i<$limit;$i++){
            $lease=bin2hex(random_bytes(12));
            $order=$this->store->transaction(function(array &$s)use($lease){
                foreach($s['orders'] as &$o){
                    if(($o['testMode']??true)||!($o['paid']??false)||!isset($o['merchantMailPendingAt'])||isset($o['merchantMailSentAt'])||($o['merchantMailRetryAt']??0)>time()||($o['merchantMailLeaseAt']??0)>time()-300)continue;
                    $o['merchantMailLeaseAt']=time();$o['merchantMailLease']=$lease;return $o;
                }
                return null;
            });
            if(!$order)break;
            $id=$order['orderId'];$subject='БотаникАрт — оплачен заказ '.$id;
            $body="Оплачен новый заказ\n\n".$order['title']."\nСумма: ".number_format($order['amount']/100,2,',',' ')." ₽\nЗаказ: ".$id."\nТелефон: ".($order['phone']?:'не указан')."\nПочта: ".$order['email']."\n\nОткрыть заказы: https://botanikart.ru/catalogue/#orders\nПосле передачи покупки отметьте заказ выполненным.\n";
            $recipient=$this->config['merchant_email']??'fridrikova@mail.ru';
            $headers=['Content-Type'=>'text/plain; charset=UTF-8','MIME-Version'=>'1.0','Message-ID'=>'<'.$id.'@botanikart.ru>'];
            try{$ok=$this->mailer?($this->mailer)($recipient,$subject,$body,$headers):@mail($recipient,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,$headers);}catch(\Throwable){$ok=false;}
            $this->store->transaction(function(array &$s)use($id,$lease,$ok):void {
                $o=&$s['orders'][$id];if(($o['merchantMailLease']??'')!==$lease)return;
                unset($o['merchantMailLease'],$o['merchantMailLeaseAt']);
                if($ok)$o['merchantMailSentAt']=time();else{$o['merchantMailRetryAt']=time()+300;$o['merchantMailAttempts']=($o['merchantMailAttempts']??0)+1;}
            });
            if($ok)$sent++;
        }
        return $sent;
    }
}

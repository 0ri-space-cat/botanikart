<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// A persistent, sliding 30-day session. Only the opaque session ID is kept
// in an HttpOnly cookie; credentials never leave the login request.
function renewCatalogueSessionCookie(bool $secure): void {
    $settings = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + 2592000,
        'path' => $settings['path'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

try {
    $private = dirname(__DIR__) . '/api/.private';
    $authFile = $private . '/catalogue-auth.php';
    if (!is_file($authFile)) respond(['error'=>'setup_required'], 503);
    $auth = require $authFile;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $localTest = getenv('CATALOGUE_LOCAL_TEST') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    if (!$secure && !$localTest) respond(['error'=>'https_required'], 403);
    $sessions = $private . '/catalogue/sessions';
    if (!is_dir($sessions)) mkdir($sessions, 0700, true);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '2592000');
    session_save_path($sessions);
    session_name('BOTANIKART_CATALOGUE');
    session_set_cookie_params(['lifetime'=>2592000,'path'=>'/catalogue/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (($_SESSION['lastSeen'] ?? 0) < time()-2592000 || ($_SESSION['revision'] ?? '') !== $auth['revision']) $_SESSION=[];
    $_SESSION['revision'] = $auth['revision'];
    $_SESSION['lastSeen'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if (isset($_SESSION['user'])) renewCatalogueSessionCookie($secure);
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') respond(['error'=>'method_not_allowed'],405);
    if ($method === 'GET' && !in_array($action,['session','products','connections','publication-preview','flowwow-editor','orders'],true)) respond(['error'=>'method_not_allowed'],405);
    if ($method === 'POST') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $expected = ($secure ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        if ($origin !== '' && $origin !== $expected) respond(['error'=>'forbidden'],403);
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error'=>'session_expired'],403);
    }
    if ($action === 'session') respond(['authenticated'=>isset($_SESSION['user']),'csrf'=>$_SESSION['csrf'],'user'=>$_SESSION['user'] ?? null]);
    require_once $private . '/CatalogStore.php';
    $store = new \BotanikArt\CatalogStore($private . '/catalogue', dirname(__DIR__) . '/data/catalog.json');
    $input = [];
    if ($method === 'POST' && $action !== 'upload') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 100000) respond(['error'=>'request_too_large'],413);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) respond(['error'=>'invalid_request'],400);
        $raw = file_get_contents('php://input', false, null, 0, 100001);
        if ($raw === false || strlen($raw) > 100000) respond(['error'=>'request_too_large'],413);
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || (array_is_list($input) && ($input!==[] || !str_starts_with(ltrim($raw),'{')))) respond(['error'=>'invalid_request'],400);
    }
    if ($action === 'login' && $method === 'POST') {
        $address = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $auth['revision']);
        $db = $store->db;
        $db->exec('BEGIN IMMEDIATE');
        $get = $db->prepare('SELECT * FROM login_attempts WHERE address=?');
        $get->execute([$address]);
        $attempt = $get->fetch(\PDO::FETCH_ASSOC);
        $active = $attempt && (int)$attempt['started'] > time()-900;
        if ($active && (int)$attempt['attempts'] >= 8) { $db->exec('COMMIT'); respond(['error'=>'too_many_attempts'],429); }
        $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $validPassword = strlen($password) <= 72 && password_verify($password, $auth['password_hash']);
        if (!hash_equals($auth['username'], $username) || !$validPassword) {
            $set = $db->prepare('INSERT INTO login_attempts(address,attempts,started) VALUES(?,?,?) ON CONFLICT(address) DO UPDATE SET attempts=excluded.attempts,started=excluded.started');
            $set->execute([$address,$active?(int)$attempt['attempts']+1:1,$active?(int)$attempt['started']:time()]);
            $db->exec('COMMIT');
            respond(['error'=>'invalid_credentials'],401);
        }
        $delete = $db->prepare('DELETE FROM login_attempts WHERE address=? OR started<?');
        $delete->execute([$address,time()-900]);
        $db->exec('COMMIT');
        session_regenerate_id(true);
        $_SESSION=['user'=>$auth['username'],'revision'=>$auth['revision'],'lastSeen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        renewCatalogueSessionCookie($secure);
        respond(['authenticated'=>true,'csrf'=>$_SESSION['csrf'],'user'=>$_SESSION['user']]);
    }
    if (!isset($_SESSION['user'])) respond(['error'=>'authentication_required'],401);
    $_SESSION['lastSeen']=time();
    if ($action === 'logout' && $method === 'POST') {
        $_SESSION=[];
        session_destroy();
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/catalogue/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
        respond(['ok'=>true]);
    }
    if (in_array($action,['orders','complete-order','confirm-sale','withdraw-sale'],true)) {
        require_once $private.'/PaymentService.php';
        if(!is_file($private.'/config.php')) { if($action!=='orders') respond(['error'=>'payments_not_configured'],503); respond(['orders'=>[]]); }
        $paymentConfig=require $private.'/config.php';
        $orders=new \BotanikArt\MerchantOrders($paymentConfig);
        if($action==='complete-order' && $method==='POST') {
            if(!is_string($input['id']??null)||!is_bool($input['completed']??null))respond(['error'=>'invalid_request'],400);
            $orders->complete($input['id'],$input['completed']);
        }
        $extra=[];
        if(in_array($action,['confirm-sale','withdraw-sale'],true)){
            if(!is_string($input['id']??null)||!preg_match('/^ba-[a-f0-9]{24}$/D',$input['id']))respond(['error'=>'invalid_request'],400);
            if($action==='confirm-sale')$extra['sale']=$orders->confirmSale($input['id'],$store);
            else{
                $channels=$input['channels']??null;if(!is_array($channels)||!array_is_list($channels)||count(array_filter($channels,fn($c)=>is_string($c)&&in_array($c,['flowwow','avito'],true)))!==count($channels))respond(['error'=>'invalid_request'],400);
                $productId=$orders->withdrawSale($input['id'],$store,$channels);session_write_close();
                require_once $private.'/PublicationRunner.php';set_time_limit(90);$extra['publication']=$channels?(new \BotanikArt\PublicationRunner($store,$private))->process($productId):['results'=>[]];
            }
        }
        $orderList=$orders->all();
        $byId=array_column($store->all(true),null,'id');
        foreach($orderList as &$row){$p=$byId[$row['productId']]??[];$row['singleItem']=in_array($p['catalogGroup']??$p['category']??'',['florarium','composition'],true);$row['salePlatforms']=[];foreach(['flowwow','avito'] as $key){$c=$p['channels'][$key]??[];if(!empty($c['enabled'])||!empty($c['externalId']))$row['salePlatforms'][$key]=['enabled'=>(bool)($c['enabled']??false),'url'=>$c['url']??''];}}unset($row);
        respond($extra+['orders'=>$orderList,'notificationReady'=>is_file($paymentConfig['data_dir'].'/notifications-setup.php')||count(array_filter($orderList,fn($o)=>$o['emailState']==='accepted'))>0]);
    }
    require_once $private . '/MarketplaceService.php';
    $marketplaces=new \BotanikArt\MarketplaceService($store,$private);
    require_once $private.'/PublicationRunner.php';
    $runner=new \BotanikArt\PublicationRunner($store,$private);
    if ($action==='flowwow-editor' && $method==='GET') {
        $id=(string)($_GET['id']??'');$all=$store->all(true);$found=array_values(array_filter($all,fn($p)=>$p['id']===$id));
        $p=$found[0]??['channels'=>['flowwow'=>['externalId'=>'']]];
        $category=isset($_GET['category'])&&ctype_digit((string)$_GET['category'])?(int)$_GET['category']:null;
        try { respond($runner->flowwow()->editor($p,$category)); } catch (\RuntimeException $e) {respond(['error'=>'marketplace_error','message'=>$e->getMessage()],422);}
    }
    if ($action==='sync-product' && $method==='POST') {session_write_close();set_time_limit(90);respond($runner->process((string)($input['id']??'')));}
    if ($action==='retry-publication' && $method==='POST') {
        $id=$input['id']??null;$channel=$input['channel']??null;
        if(!is_string($id)||strlen($id)>100||!is_string($channel)||!in_array($channel,['avito','flowwow'],true))respond(['error'=>'invalid_request'],400);
        $c=$store->channels($id)[$channel]??[];
        if(($c['management']??'')!=='site'||empty($c['enabled'])||($c['state']??'')!=='error')
            respond(['error'=>'publication_not_retryable','message'=>'Повторная отправка доступна только после ошибки публикации включённой площадки.'],409);
        $q=$store->db->prepare('SELECT state FROM publication_jobs WHERE product_id=? AND channel=?');
        $q->execute([$id,$channel]);
        if($q->fetchColumn()!=='retry')respond(['error'=>'publication_not_retryable','message'=>'Задание уже обработано или ожидает подтверждения. Обновите каталог.'],409);
        session_write_close();set_time_limit(90);respond($runner->process($id,$channel));
    }
    if ($action==='archive' && $method==='POST') {$store->archive((string)($input['id']??''),(int)($input['version']??0),($input['restore']??false)===true,($input['hideFlowwow']??false)===true);respond(['ok'=>true]);}
    if ($action==='check-connections' && $method==='POST') {session_write_close();set_time_limit(90);$connections=$marketplaces->checkConnections();if(($connections['avito']['health']['state']??'')==='ok'){try{$runner->reports();}catch(\Throwable){}}respond(['connections'=>$connections]);}
    if ($action==='connections' && $method==='GET') respond(['connections'=>$marketplaces->connections()]);
    if ($action==='publication-preview' && $method==='GET') respond($marketplaces->preview((string)($_GET['id']??''),(string)($_GET['channel']??'')));
    if ($action==='renewal-date' && $method==='POST') { $marketplaces->saveRenewalDate($input['date']??null);respond(['connections'=>$marketplaces->connections()]); }
    if ($action==='reminder-seen' && $method==='POST') { $marketplaces->acknowledgeReminder($input['id']??null);respond(['ok'=>true]); }
    if ($action === 'products' && $method === 'GET') respond(['products'=>array_map(fn($p)=>$marketplaces->productStatus($p),$store->all(true)),'connections'=>$marketplaces->connections()]);
    if ($action === 'save' && $method === 'POST') {
        if(($input['status']??'')==='ready'&&is_file($private.'/config.php')){
            foreach($store->all(true) as $p)if($p['id']===($input['id']??'')&&!empty($p['soldOnSite'])&&!empty($p['saleOrderId'])){
                if(($input['version']??0)!==$p['version'])throw new \UnexpectedValueException('edit_conflict');
                require_once $private.'/PaymentService.php';(new \BotanikArt\PaymentService(require $private.'/config.php'))->restock($p['id'],$p['saleOrderId']);break;
            }
        }
        respond(['id'=>$store->save($input)]);
    }
    if ($action === 'upload' && $method === 'POST') {
        $file = $_FILES['image'] ?? null;
        if (!$file || !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) respond(['error'=>'upload_failed'],400);
        if ($file['size'] > 12*1024*1024) respond(['error'=>'image_too_large'],413);
        $dimensions = @getimagesize($file['tmp_name']);
        if (!$dimensions || $dimensions[0]*$dimensions[1] > 30000000 || !in_array($dimensions[2], [IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) respond(['error'=>'invalid_image'],400);
        $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$image) respond(['error'=>'invalid_image'],400);
        $directory = dirname(__DIR__) . '/assets/uploads/catalogue';
        if (!is_dir($directory)) mkdir($directory,0755,true);
        $path = 'assets/uploads/catalogue/' . bin2hex(random_bytes(16)) . '.jpg';
        require_once $private.'/CatalogPhoto.php';
        $prepared=\BotanikArt\CatalogPhoto::write($image,dirname(__DIR__).'/'.$path);
        respond(['path'=>$path,'width'=>$prepared['width'],'height'=>$prepared['height'],'padded'=>$prepared['padded']]);
    }
    respond(['error'=>'unknown_action'],404);
} catch (\BotanikArt\ApiError $e) { respond(['error'=>$e->reason],$e->status);
} catch (\UnexpectedValueException) { respond(['error'=>'edit_conflict'],409);
} catch (\InvalidArgumentException|\JsonException) { respond(['error'=>'invalid_product'],400);
} catch (\OutOfBoundsException) { respond(['error'=>'product_missing'],404);
} catch (\PDOException $error) {
    if ($error->getCode() === '23000') respond(['error'=>'duplicate_source'],409);
    error_log('BotanikArt catalogue database unavailable');
    respond(['error'=>'service_unavailable'],503);
} catch (\Throwable $error) {
    error_log('BotanikArt catalogue: ' . get_class($error));
    respond(['error'=>'service_unavailable'],503);
}

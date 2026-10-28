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
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') respond(['error'=>'method_not_allowed'],405);
    if ($method === 'GET' && !in_array($action,['session','products','connections','publication-preview'],true)) respond(['error'=>'method_not_allowed'],405);
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
    require_once $private . '/MarketplaceService.php';
    $marketplaces=new \BotanikArt\MarketplaceService($store,$private);
    if ($action==='check-connections' && $method==='POST') respond(['connections'=>$marketplaces->checkConnections()]);
    if ($action==='connections' && $method==='GET') respond(['connections'=>$marketplaces->connections()]);
    if ($action==='publication-preview' && $method==='GET') respond($marketplaces->preview((string)($_GET['id']??''),(string)($_GET['channel']??'')));
    if ($action==='renewal-date' && $method==='POST') { $marketplaces->saveRenewalDate($input['date']??null);respond(['connections'=>$marketplaces->connections()]); }
    if ($action==='reminder-seen' && $method==='POST') { $marketplaces->acknowledgeReminder($input['id']??null);respond(['ok'=>true]); }
    if ($action === 'products' && $method === 'GET') respond(['products'=>array_map(fn($p)=>$marketplaces->productStatus($p),$store->all(true)),'connections'=>$marketplaces->connections()]);
    if ($action === 'save' && $method === 'POST') respond(['id'=>$store->save($input)]);
    if ($action === 'upload' && $method === 'POST') {
        $file = $_FILES['image'] ?? null;
        if (!$file || !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) respond(['error'=>'upload_failed'],400);
        if ($file['size'] > 12*1024*1024) respond(['error'=>'image_too_large'],413);
        $dimensions = @getimagesize($file['tmp_name']);
        if (!$dimensions || $dimensions[0]*$dimensions[1] > 30000000 || !in_array($dimensions[2], [IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) respond(['error'=>'invalid_image'],400);
        $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$image) respond(['error'=>'invalid_image'],400);
        $scale = min(1,2000/max($dimensions[0],$dimensions[1]));
        $output = imagecreatetruecolor(max(1,(int)round($dimensions[0]*$scale)),max(1,(int)round($dimensions[1]*$scale)));
        imagefill($output,0,0,imagecolorallocate($output,255,255,255));
        imagecopyresampled($output,$image,0,0,0,0,imagesx($output),imagesy($output),imagesx($image),imagesy($image));
        $directory = dirname(__DIR__) . '/assets/uploads/catalogue';
        if (!is_dir($directory)) mkdir($directory,0755,true);
        $path = 'assets/uploads/catalogue/' . bin2hex(random_bytes(16)) . '.jpg';
        if (!imagejpeg($output,dirname(__DIR__).'/'.$path,88)) throw new \RuntimeException('Image save failed');
        unset($image, $output);
        respond(['path'=>$path]);
    }
    respond(['error'=>'unknown_action'],404);
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

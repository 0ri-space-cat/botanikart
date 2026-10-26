<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function apiRun(callable $callback, bool $notification = false): void
{
    try {
        $private = getenv('BOTANIKART_PRIVATE_DIR') ?: dirname(__DIR__, 2) . '/botanikart-private';
        $private = realpath($private);
        $public = realpath(dirname(__DIR__));
        if (!$private || !$public || $private === $public || str_starts_with($private, $public . DIRECTORY_SEPARATOR)
            || !is_file($private . '/config.php') || !is_file($private . '/PaymentService.php')) {
            http_response_code(503);
            echo '{"error":"payment_not_configured"}';
            return;
        }
        require_once $private . '/PaymentService.php';
        $config = require $private . '/config.php';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $expected = parse_url($config['site_url'] ?? '');
        $siteOrigin = ($expected['scheme'] ?? '') . '://' . ($expected['host'] ?? '') . (isset($expected['port']) ? ':' . $expected['port'] : '');
        if (!$notification && $origin !== '' && $origin !== $siteOrigin) throw new \BotanikArt\ApiError('origin_not_allowed', 403);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \BotanikArt\ApiError('method_not_allowed', 405);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new \BotanikArt\ApiError('invalid_json', 400);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) throw new \BotanikArt\ApiError('request_too_large', 413);
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw === false || strlen($raw) > 16384) throw new \BotanikArt\ApiError('request_too_large', 413);
        try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \BotanikArt\ApiError('invalid_json', 400); }
        if (!is_array($input) || array_is_list($input)) throw new \BotanikArt\ApiError('invalid_json', 400);
        $service = new \BotanikArt\PaymentService($config);
        $result = $callback($service, $input);
        if ($notification) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OK';
        } else {
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } catch (\BotanikArt\ApiError $error) {
        http_response_code($error->status);
        echo json_encode(['error' => $error->reason]);
    } catch (\Throwable $error) {
        // Exception messages may contain paths, bank responses or contact data.
        error_log('BotanikArt payment service error: ' . get_class($error));
        http_response_code(503);
        echo '{"error":"payment_service_unavailable"}';
    }
}

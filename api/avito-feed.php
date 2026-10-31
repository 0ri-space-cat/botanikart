<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $private=__DIR__.'/.private';
    require_once $private.'/CatalogStore.php';
    require_once $private.'/AvitoFeed.php';
    $schema=json_decode(file_get_contents(dirname(__DIR__).'/catalogue/avito-schema.json'),true,512,JSON_THROW_ON_ERROR);
    $store=new \BotanikArt\CatalogStore($private.'/catalogue',dirname(__DIR__).'/data/catalog.json');
    require_once $private.'/PublicationRunner.php';
    $runner=new \BotanikArt\PublicationRunner($store,$private);$removed=$runner->reconcileAvitoRemovals();if($removed['disabled']>0)$runner->stopEmptyAvitoFeed();
    echo (new \BotanikArt\AvitoFeed($schema))->render($store->all(true),'https://botanikart.ru');
} catch (\Throwable $error) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<?xml version="1.0" encoding="UTF-8"?><FeedUnavailable>Каталог Авито ещё не готов к загрузке. Проверьте товары в приложении каталога.</FeedUnavailable>';
}

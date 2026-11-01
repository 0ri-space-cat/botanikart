<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
echo "This feed has been retired. The BotanikArt website catalogue is managed independently.\n";

<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = '1920x1080';

$date = date('Ymd');
$file = get_date_cached_file($RESOLUTION, $date);

if ($file !== null) {
    redirect_cache_image(basename($file), 60); // 302 跳转缓存 60s，过 0 点最多错 1 分钟
}

http_response_code(404);
exit('NOT FOUND');

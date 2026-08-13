<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = '1080x1920';

$date = date('Ymd');
$file = get_date_cached_file($RESOLUTION, $date);

if ($file !== null) {
    redirect_cache_image(basename($file), 60);
}

http_response_code(404);
exit('NOT FOUND');

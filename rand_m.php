<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = '1080x1920';

// 默认 30 = 过去 30 天（含今天）内随机；0 = 全量随机
$rawN = $_GET['n'] ?? '30';
$days = is_numeric($rawN) ? (int)$rawN : 10;
if ($days < 1) {
    $days = 3650;
}

$offset = random_int(0, $days - 1);
$date   = date('Ymd', time() - $offset * 86400);

$config   = bing_config();
$fileName = $config['cache_filename_prefix'] . '.' . $date . '.' . $RESOLUTION . '.jpg';

redirect_cache_image($fileName, 0);

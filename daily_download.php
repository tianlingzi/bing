<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$data = fetch_bing_data(0, 1);
if ($data === null || empty($data['images'][0]['urlbase'])) {
    exit;
}

$img     = $data['images'][0];
$urlbase = $img['urlbase'];
$today   = date('Ymd');

$config      = bing_config();
$resolutions = array_keys($config['resolutions']);
foreach ($resolutions as $res) {
    download_image_to_cache($urlbase, $res, $today, $img);
}

exit;

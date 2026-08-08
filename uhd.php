<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = 'uhd';

$local = get_today_cached_file($RESOLUTION);
if ($local !== null) {
    output_local_image($local);
}

$data = fetch_bing_data(0, 1);
if ($data !== null && !empty($data['images'][0]['urlbase'])) {
    $img     = $data['images'][0];
    $urlbase = $img['urlbase'];
    $enddate = $img['enddate'] ?? null;

    $saved = download_image_to_cache($urlbase, $RESOLUTION, $enddate);
    if ($saved !== null) {
        output_local_image($saved);
    }

    $imgUrl = get_bing_image_url($urlbase, $RESOLUTION);
    output_image_direct($imgUrl);
}

http_response_code(500);
exit('获取今日壁纸失败');

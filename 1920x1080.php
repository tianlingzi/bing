<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = '1920x1080';

// 1. 优先读本地"今日缓存"
$local = get_today_cached_file($RESOLUTION);
if ($local !== null) {
    output_local_image($local);
}

// 2. 缓存未命中：从 Bing 拉今日数据，保存到本地（带日期前缀），再输出
$data = fetch_bing_data(0, 1);
if ($data !== null && !empty($data['images'][0]['urlbase'])) {
    $img     = $data['images'][0];
    $urlbase = $img['urlbase'];
    $enddate = $img['enddate'] ?? null;

    $saved = download_image_to_cache($urlbase, $RESOLUTION, $enddate);
    if ($saved !== null) {
        output_local_image($saved);
    }

    // 保存失败则临时兜底：直接代理输出 Bing 原图
    $imgUrl = get_bing_image_url($urlbase, $RESOLUTION);
    output_image_direct($imgUrl);
}

http_response_code(500);
exit('获取今日壁纸失败');

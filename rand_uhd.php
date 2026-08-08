<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$RESOLUTION = 'uhd';

$local = get_random_cached_file($RESOLUTION);
if ($local !== null) {
    output_local_image($local);
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit(
    "本地暂无 UHD 分辨率的壁纸缓存。\n"
    . "请先访问 save_cache.php 批量抓取历史壁纸，或访问 uhd.php（今日壁纸）自动下载后，再使用本接口。"
);

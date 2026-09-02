<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// 通过 Content-Disposition: attachment 强制浏览器下载而非显示
// URL: download.php?d=YYYYMMDD&r=1920x1080 | download.php?d=YYYYMMDD&r=uhd

$date = (string)($_GET['d'] ?? '');
$res  = (string)($_GET['r'] ?? '');

if (!preg_match('/^\d{8}$/', $date)) {
    http_response_code(400);
    exit('Invalid date');
}

$allowedRes = ['1920x1080' => '1920x1080', 'uhd' => 'uhd'];
if (!isset($allowedRes[$res])) {
    http_response_code(400);
    exit('Invalid resolution');
}

$file = get_date_cached_file($res, $date);
if ($file === null) {
    http_response_code(404);
    exit('NOT FOUND');
}

$basename = basename($file);
header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="' . $basename . '"');
header('Content-Length: ' . (string)filesize($file));
readfile($file);

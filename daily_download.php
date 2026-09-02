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
save_wallpaper_record($img, '', '', '');

function fetch_bing_hp_model(): ?array
{
    $config = bing_config();
    $url = $config['bing_host'] . '/hp/api/model?mkt=' . urlencode($config['mkt']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

$modelData = fetch_bing_hp_model();
if ($modelData && isset($modelData['MediaContents'][0]['ImageContent'])) {
    $ic = $modelData['MediaContents'][0]['ImageContent'];
    $backstageRel = $ic['BackstageUrl'] ?? '';
    $quickFactText = $ic['QuickFact']['MainText'] ?? '';
    $fullBackstageUrl = '';
    if (!empty($backstageRel)) {
        $fullBackstageUrl = bing_config()['bing_host'] . $backstageRel;
    }
    $fullIntroText = '';
    if ($fullBackstageUrl !== '') {
        $fullIntroText = fetch_bing_full_intro($fullBackstageUrl, $ic);
    }
    update_wallpaper_extra_fields($today, $fullIntroText, $quickFactText, $fullBackstageUrl);
}

exit;

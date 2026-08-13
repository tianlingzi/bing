<?php

declare(strict_types=1);

// ===== 重要：以下配置可按需修改 =====
date_default_timezone_set('Asia/Shanghai'); // 修改站点时区，保证 date('Ymd') 与访问当天一致
// ===================================

function bing_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'bing_api'    => 'https://cn.bing.com/HPImageArchive.aspx',
        'bing_host'   => 'https://cn.bing.com',
        'mkt'         => 'zh-CN',
        'cache_dir'   => __DIR__ . '/cache',
        // ===== 重要：缓存 jpg 的文件名前缀，修改后所有新下载的图片名都会同步变更 =====
        // 命名规则：{cache_filename_prefix}.YYYYMMDD.分辨率.jpg
        // 示例：  tianlingzi.top.20260813.1920x1080.jpg
        'cache_filename_prefix' => 'tianlingzi.top',
        // ======================================================================
        'resolutions' => [
            '1920x1080' => '_1920x1080.jpg',
            '1366x768'  => '_1366x768.jpg',
            '1080x1920' => '_1080x1920.jpg',
            'uhd'       => '_UHD.jpg',
        ],
    ];

    return $config;
}

if (!function_exists('get_base_url')) {
    function get_base_url(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $path       = dirname($scriptName);
        $path       = str_replace('\\', '/', $path);

        if ($path !== '/' && !str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $scheme . '://' . $host . $path;
    }
}

if (!function_exists('fetch_bing_data')) {
    function fetch_bing_data(int $idx = 0, int $n = 1): ?array
    {
        $config = bing_config();

        $url = $config['bing_api'] . '?' . http_build_query([
            'format' => 'js',
            'idx'    => $idx,
            'n'      => $n,
            'mkt'    => $config['mkt'],
        ]);

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
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $err !== '') {
            return null;
        }

        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('get_bing_image_url')) {
    function get_bing_image_url(string $urlbase, string $resolution): string
    {
        $config = bing_config();
        $suffix = $config['resolutions'][$resolution] ?? '_1920x1080.jpg';
        return $config['bing_host'] . $urlbase . $suffix;
    }
}

if (!function_exists('ensure_cache_dir')) {
    function ensure_cache_dir(): bool
    {
        $config   = bing_config();
        $cacheDir = $config['cache_dir'];
        if (is_dir($cacheDir)) {
            return true;
        }
        return @mkdir($cacheDir, 0755, true);
    }
}

if (!function_exists('get_cache_file_path')) {
    // 公共工具：按 日期 + 分辨率 拼出 cache 中精确的 jpg 绝对路径
    function get_cache_file_path(string $resolution, string $date): ?string
    {
        if (!preg_match('/^\d{8}$/', $date)) {
            return null;
        }
        $config = bing_config();
        return $config['cache_dir'] . '/'
            . $config['cache_filename_prefix'] . '.' . $date . '.' . $resolution . '.jpg';
    }
}

if (!function_exists('download_image_to_cache')) {
    function download_image_to_cache(string $urlbase, string $resolution, ?string $enddate = null): ?string
    {
        if (!ensure_cache_dir()) {
            return null;
        }

        $date = ($enddate !== null && $enddate !== '') ? $enddate : date('Ymd');
        if (!preg_match('/^\d{8}$/', (string)$date)) {
            $date = date('Ymd');
        }

        $filePath = get_cache_file_path($resolution, (string)$date);
        if ($filePath === null) {
            return null;
        }

        if (file_exists($filePath) && filesize($filePath) > 0) {
            return $filePath;
        }

        $imgUrl = get_bing_image_url($urlbase, $resolution);
        $ch = curl_init($imgUrl);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $imgUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $imgData  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($imgData === false || $httpCode !== 200) {
            return null;
        }

        $written = @file_put_contents($filePath, $imgData);
        return $written !== false ? $filePath : null;
    }
}

if (!function_exists('get_today_cached_file')) {
    function get_today_cached_file(string $resolution): ?string
    {
        return get_date_cached_file($resolution, date('Ymd'));
    }
}

if (!function_exists('get_date_cached_file')) {
    // 按固定命名精确查找（不依赖 glob），性能更高
    function get_date_cached_file(string $resolution, string $date): ?string
    {
        $filePath = get_cache_file_path($resolution, $date);
        if ($filePath === null) {
            return null;
        }
        if (file_exists($filePath) && is_readable($filePath) && filesize($filePath) > 0) {
            return $filePath;
        }
        return null;
    }
}

if (!function_exists('output_local_image')) {
    function output_local_image(string $filePath, string $cacheControl = 'no-cache, must-revalidate'): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('NOT FOUND');
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        header('Content-Length: ' . (string)filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if (!function_exists('redirect_cache_image')) {
    // 302 跳转到 /cache/xxx.jpg。Location: 建议用绝对 URL，兼容子目录部署。
    function redirect_cache_image(string $fileName, int $maxAgeSeconds): void
    {
        $location = get_base_url() . 'cache/' . rawurlencode($fileName);
        header('Cache-Control: public, max-age=' . $maxAgeSeconds . ', must-revalidate');
        header('Location: ' . $location, true, 302);
        exit;
    }
}

<?php

declare(strict_types=1);

/**
 * 获取配置数组（使用静态变量缓存，只解析一次）
 *
 * @return array{bing_api:string, bing_host:string, mkt:string, cache_dir:string, resolutions:array<string,string>}
 */
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
        // 分辨率 => Bing 官方 URL 后缀
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
    /**
     * 获取当前脚本的基础 URL（自动处理根目录/子目录）
     * 例如：
     *   https://www.tianlingzi.top/index.php  => https://www.tianlingzi.top/
     *   https://www.tianlingzi.top/bing/index.php => https://www.tianlingzi.top/bing/
     */
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
    /**
     * 从 Bing API 获取壁纸数据
     *
     * @param int $idx 0=今天, 1=昨天, ...
     * @param int $n   获取数量
     * @return array|null 解码后的数组，失败返回 null
     */
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
    /**
     * 根据分辨率获取 Bing 图片完整 URL
     */
    function get_bing_image_url(string $urlbase, string $resolution): string
    {
        $config = bing_config();
        $suffix = $config['resolutions'][$resolution] ?? '_1920x1080.jpg';
        return $config['bing_host'] . $urlbase . $suffix;
    }
}

if (!function_exists('output_image_direct')) {
    /**
     * 通过 PHP 直接输出图片（占用服务器带宽）
     * 仅作为「本地缓存未就绪」的临时兜底使用
     */
    function output_image_direct(string $imgUrl): void
    {
        $ch = curl_init($imgUrl);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $imgUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
        ]);

        $imgData     = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($imgData === false || $httpCode !== 200) {
            http_response_code(500);
            exit('Failed to fetch image');
        }

        header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
        header('Cache-Control: public, max-age=3600');
        echo $imgData;
        exit;
    }
}

if (!function_exists('redirect_image')) {
    /**
     * 302 跳转到 Bing 图片直链（不占用服务器带宽）
     */
    function redirect_image(string $imgUrl): void
    {
        header('Location: ' . $imgUrl, true, 302);
        exit;
    }
}

if (!function_exists('ensure_cache_dir')) {
    /**
     * 确保 cache 目录存在，不存在则创建
     */
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

if (!function_exists('download_image_to_cache')) {
    /**
     * 下载 Bing 图片到本地 cache 目录
     * 文件名格式：`日期(bing enddate)_时间戳(urlbase内的名称)_分辨率后缀`
     *   例如：20260809_OHR.XXXXX_zh-CN_1920x1080.jpg
     * 传入 $enddate 可选，保证每天只有一份"今日"缓存的主记录
     *
     * @return string|null 成功返回本地绝对路径，失败返回 null
     */
    function download_image_to_cache(string $urlbase, string $resolution, ?string $enddate = null): ?string
    {
        if (!ensure_cache_dir()) {
            return null;
        }

        $config   = bing_config();
        $cacheDir = $config['cache_dir'];
        $suffix   = $config['resolutions'][$resolution] ?? '_1920x1080.jpg';

        // 从 urlbase 提取 Bing 的文件名（包含时间戳/图片标识）
        $parts    = explode('/', trim($urlbase, '/'));
        $namePart = end($parts);

        // 如果提供了 enddate，前缀带上"YYYYMMDD_"方便人工识别
        $prefix = ($enddate !== null && $enddate !== '') ? ($enddate . '_') : '';
        $fileName = $prefix . $namePart . $suffix;

        $filePath = $cacheDir . '/' . $fileName;

        // 已存在（同一张同一天同分辨率）直接返回，避免重复下载
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
        if ($written === false) {
            return null;
        }

        return $filePath;
    }
}

if (!function_exists('get_today_cached_file')) {
    /**
     * 本地 CDN 模式：查找「今日」对应分辨率的缓存文件
     * 匹配规则：文件名以"今天日期 YYYYMMDD_"开头，并且以对应分辨率后缀结尾
     *
     * @return string|null 命中返回本地路径，未命中返回 null
     */
    function get_today_cached_file(string $resolution): ?string
    {
        $config   = bing_config();
        $cacheDir = $config['cache_dir'];
        if (!is_dir($cacheDir)) {
            return null;
        }

        $today   = date('Ymd');
        $suffix  = $config['resolutions'][$resolution] ?? '_1920x1080.jpg';
        $pattern = $cacheDir . '/' . $today . '_*' . $suffix;
        $files   = glob($pattern);

        if (empty($files)) {
            return null;
        }

        // 取第一张（一天一份，一般只有一张；如果有多张取最新修改的）
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }
}

if (!function_exists('output_local_image')) {
    /**
     * 输出本地文件为图片（Content-Type / Cache-Control / Content-Length 齐全）
     */
    function output_local_image(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('Image not found in cache');
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if (!function_exists('get_random_cached_file')) {
    /**
     * 随机模式：从 cache 目录中所有同分辨率后缀的 jpg 里随机一张
     * 不再做任何时间限制/删除，只要存在就进入随机池
     *
     * @return string|null 有则返回本地路径，无则返回 null
     */
    function get_random_cached_file(string $resolution): ?string
    {
        $config   = bing_config();
        $cacheDir = $config['cache_dir'];

        if (!is_dir($cacheDir)) {
            return null;
        }

        $suffix = $config['resolutions'][$resolution] ?? '_1920x1080.jpg';
        $files  = glob($cacheDir . '/*' . $suffix);

        if (empty($files)) {
            return null;
        }

        $randomKey = array_rand($files);
        return $files[$randomKey];
    }
}

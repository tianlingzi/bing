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
        'db_path'     => __DIR__ . '/data/wallpapers.db',
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

if (!function_exists('parse_bing_metadata')) {
    // 解析 Bing 图片元数据
    function parse_bing_metadata(array $img): array
    {

        $rawCopyright = (string)($img['copyright'] ?? '');
        $desc     = $rawCopyright;          // 描述部分（括号前）
        $crNotice = '';                     // 版权声明（括号内，含 ©）
        $author   = '';                     // 作者（去掉 © 前缀）
        if (preg_match('/^(.*?)\s*[（(]\s*(©.+?)[）)]\s*$/u', $rawCopyright, $m)) {
            $desc     = trim($m[1]);
            $crNotice = $m[2];
            $author   = preg_replace('/^©\s*/u', '', $crNotice);
        }

        $title = !empty($img['title']) ? (string)$img['title'] : $desc;

        $keywords = ['Bing'];
        if ($desc !== '') {
            foreach (preg_split('/[，,]\s*/u', $desc) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $keywords[] = $p;
                }
            }
        }

        return [
            'title'            => $title,
            'description'      => $desc,
            'copyright_notice' => $crNotice,
            'author'           => $author,
            'raw_copyright'    => $rawCopyright,
            'keywords'         => $keywords,
        ];
    }
}

if (!function_exists('build_xmp_packet')) {
    // 构建 XMP 数据包
    function build_xmp_packet(array $img): string
    {
        $p        = parse_bing_metadata($img);
        $title    = $p['title'];
        $desc     = $p['description'];
        $crNotice = $p['copyright_notice'];
        $author   = $p['author'];
        $keywords = $p['keywords'];

        // 日期格式化：YYYYMMDD → YYYY-MM-DDTHH:MM:SS
        $dateStr = '';
        if (!empty($img['enddate']) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string)$img['enddate'], $m)) {
            $dateStr = $m[1] . '-' . $m[2] . '-' . $m[3] . 'T00:00:00';
        }

        $esc = fn ($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $titleXml    = $title    !== '' ? '   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $esc($title)    . '</rdf:li></rdf:Alt></dc:title>' . "\n" : '';
        $descXml     = $desc     !== '' ? '   <dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $esc($desc) . '</rdf:li></rdf:Alt></dc:description>' . "\n" : '';
        $rightsXml   = $crNotice !== '' ? '   <dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . $esc($crNotice) . '</rdf:li></rdf:Alt></dc:rights>' . "\n" : '';
        $creatorXml  = $author   !== '' ? '   <dc:creator><rdf:Seq><rdf:li>' . $esc($author) . '</rdf:li></rdf:Seq></dc:creator>' . "\n" : '';
        $keywordsXml = '';
        if (!empty($keywords)) {
            $items = array_map(fn ($k) => '<rdf:li>' . $esc($k) . '</rdf:li>', $keywords);
            $keywordsXml = '   <dc:subject><rdf:Bag>' . implode('', $items) . '</rdf:Bag></dc:subject>' . "\n";
        }
        $dateXml = $dateStr !== '' ? '   <xmp:CreateDate>' . $esc($dateStr) . '</xmp:CreateDate>' . "\n" : '';

        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d?"?>' . "\n"
             . '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . "\n"
             . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
             . ' <rdf:Description rdf:about=""' . "\n"
             . '   xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n"
             . '   xmlns:xmp="http://ns.adobe.com/xap/1.0/">' . "\n"
             . $titleXml
             . $descXml
             . $keywordsXml
             . $creatorXml
             . $rightsXml
             . $dateXml
             . ' </rdf:Description>' . "\n"
             . '</rdf:RDF>' . "\n"
             . '</x:xmpmeta>' . "\n"
             . '<?xpacket end="w"?>';
    }
}

if (!function_exists('embed_xmp_in_jpeg')) {
    // 将 XMP 数据包作为 APP1 段插入 JPEG（紧随 SOI 标记 FF D8 之后）
    function embed_xmp_in_jpeg(string $jpegData, string $xmpPacket): string
    {
        if (strlen($jpegData) < 2 || substr($jpegData, 0, 2) !== "\xFF\xD8") {
            return $jpegData; // 非 JPEG，原样返回
        }
        $ns      = "http://ns.adobe.com/xap/1.0/\x00";
        $payload = $ns . $xmpPacket;
        $segLen  = strlen($payload) + 2;
        if ($segLen > 65533) {
            return $jpegData;
        }
        $app1 = "\xFF\xE1" . chr(($segLen >> 8) & 0xFF) . chr($segLen & 0xFF) . $payload;
        return "\xFF\xD8" . $app1 . substr($jpegData, 2);
    }
}

if (!function_exists('download_image_to_cache')) {
    function download_image_to_cache(string $urlbase, string $resolution, ?string $enddate = null, array $img = []): ?string
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

        // 在内存中嵌入 XMP 元数据（标题/描述/版权/作者/日期/关键词）
        if (!empty($img)) {
            $xmp = build_xmp_packet($img);
            if ($xmp !== '') {
                $imgWithXmp = embed_xmp_in_jpeg($imgData, $xmp);
                if ($imgWithXmp !== $imgData) {
                    $imgData = $imgWithXmp;
                }
            }
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

// ==================== SQLite 元数据存储 ====================

if (!function_exists('init_db')) {
    // 初始化数据库表结构
    function init_db(): bool
    {
        $dbPath = bing_config()['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS wallpapers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL UNIQUE,
            bing_enddate TEXT,
            title TEXT,
            description TEXT,
            raw_copyright TEXT,
            copyright_notice TEXT,
            author TEXT,
            urlbase TEXT,
            file_1920x1080 TEXT,
            file_1366x768 TEXT,
            file_1080x1920 TEXT,
            file_uhd TEXT,
            keywords TEXT,
            created_at INTEGER
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wallpapers_date ON wallpapers(date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wallpapers_enddate ON wallpapers(bing_enddate)");
        return true;
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            init_db();
            $pdo = new PDO('sqlite:' . bing_config()['db_path']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return $pdo;
    }
}

if (!function_exists('save_wallpaper_record')) {
    // 将 Bing 图片元数据写入/更新到 DB（INSERT OR REPLACE）
    // $img 参数为 Bing API 返回的 images[0] 元素
    function save_wallpaper_record(array $img): bool
    {
        $p        = parse_bing_metadata($img);
        $date     = date('Ymd');
        $config   = bing_config();
        $prefix   = $config['cache_filename_prefix'];

        $files = [];
        foreach (array_keys($config['resolutions']) as $res) {
            $files[$res] = $prefix . '.' . $date . '.' . $res . '.jpg';
        }

        $stmt = db()->prepare("INSERT OR REPLACE INTO wallpapers
            (date, bing_enddate, title, description, raw_copyright, copyright_notice, author, urlbase,
             file_1920x1080, file_1366x768, file_1080x1920, file_uhd, keywords, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $date,
            (string)($img['enddate'] ?? ''),
            $p['title'],
            $p['description'],
            $p['raw_copyright'],
            $p['copyright_notice'],
            $p['author'],
            (string)($img['urlbase'] ?? ''),
            $files['1920x1080'] ?? null,
            $files['1366x768']  ?? null,
            $files['1080x1920'] ?? null,
            $files['uhd']       ?? null,
            json_encode($p['keywords'], JSON_UNESCAPED_UNICODE),
            time(),
        ]);
    }
}

if (!function_exists('query_latest_wallpaper')) {
    function query_latest_wallpaper(): ?array
    {
        $stmt = db()->query("SELECT * FROM wallpapers ORDER BY date DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('query_wallpaper_by_date')) {
    function query_wallpaper_by_date(string $date): ?array
    {
        $stmt = db()->prepare("SELECT * FROM wallpapers WHERE date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('query_wallpapers_by_month')) {
    // $year='2026', $month='08' → 返回该月所有记录，按日期倒序
    function query_wallpapers_by_month(string $year, string $month): array
    {
        $prefix = $year . $month;
        $stmt = db()->prepare("SELECT * FROM wallpapers WHERE date LIKE ? ORDER BY date DESC");
        $stmt->execute([$prefix . '%']);
        return $stmt->fetchAll();
    }
}

if (!function_exists('query_latest_wallpaper_of_month')) {
    function query_latest_wallpaper_of_month(string $year, string $month): ?array
    {
        $prefix = $year . $month;
        $stmt = db()->prepare("SELECT * FROM wallpapers WHERE date LIKE ? ORDER BY date DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('query_all_months')) {
    // 返回所有月份分组，含每月壁纸数量和最新日期
    function query_all_months(): array
    {
        $stmt = db()->query("SELECT
            substr(date, 1, 4) AS year,
            substr(date, 5, 2) AS month,
            COUNT(*) AS count,
            MAX(date) AS latest_date
            FROM wallpapers
            GROUP BY year, month
            ORDER BY year DESC, month DESC");
        return $stmt->fetchAll();
    }
}

if (!function_exists('query_all_wallpapers')) {
    function query_all_wallpapers(): array
    {
        $stmt = db()->query("SELECT * FROM wallpapers ORDER BY date DESC");
        return $stmt->fetchAll();
    }
}

<?php
/**
 * api.php — 为 Windows 壁纸客户端与第三方应用提供 JSON 接口
 *
 * 支持的查询参数（任选其一）：
 *   action=latest                          获取最新一条壁纸（默认）
 *   action=date&date=YYYYMMDD              按日期精确查找
 *   action=month&year=YYYY&month=MM        按月份列表（倒序）
 *   action=months                          所有月份分组（含每月数量）
 *   action=all                             所有壁纸（倒序）
 *
 * 返回 JSON：
 *   成功 → {"ok": true, "data": ...}
 *   失败 → {"ok": false, "error": "..."}
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// 关闭错误显示，所有异常走 JSON 输出
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// 异常兜底
set_exception_handler(static function (\Throwable $e): void {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

/**
 * 把一条 DB 记录包装成客户端友好的结构：
 * - 补全各分辨率图片的完整 URL（基于本服务端的 base URL）
 * - keywords 由 JSON 字符串解码回数组
 */
function normalize_wallpaper(array $w): array
{
    $base = rtrim(get_base_url(), '/') . '/cache/';
    $pick = static function (?string $file) use ($base): ?string {
        return ($file !== null && $file !== '') ? $base . rawurlencode($file) : null;
    };
    return [
        'date'             => $w['date'],
        'bing_enddate'     => $w['bing_enddate'],
        'title'            => $w['title'],
        'description'      => $w['description'],
        'description_web'  => $w['description_web'] ?? $w['description'],
        'author'           => $w['author'],
        'copyright_notice' => $w['copyright_notice'],
        'raw_copyright'    => $w['raw_copyright'],
        'keywords'         => json_decode($w['keywords'] ?? '[]', true) ?: [],
        'images'           => [
            '1920x1080' => $pick($w['file_1920x1080']),
            '1366x768'  => $pick($w['file_1366x768']),
            '1080x1920' => $pick($w['file_1080x1920']),
            'uhd'       => $pick($w['file_uhd']),
        ],
    ];
}

// 取查询参数
$action = $_GET['action'] ?? 'latest';

switch ($action) {
    case 'latest':
        $row = query_latest_wallpaper();
        if ($row === null) {
            echo json_encode(['ok' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['ok' => true, 'data' => normalize_wallpaper($row)], JSON_UNESCAPED_UNICODE);
        break;

    case 'date':
        $date = (string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{8}$/', $date)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'date must be YYYYMMDD'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $row = query_wallpaper_by_date($date);
        if ($row === null) {
            echo json_encode(['ok' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['ok' => true, 'data' => normalize_wallpaper($row)], JSON_UNESCAPED_UNICODE);
        break;

    case 'month':
        $year  = (string)($_GET['year'] ?? '');
        $month = (string)($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'year=YYYY & month=MM required'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $rows = query_wallpapers_by_month($year, $month);
        $data = array_map('normalize_wallpaper', $rows);
        echo json_encode(['ok' => true, 'data' => $data, 'count' => count($data)], JSON_UNESCAPED_UNICODE);
        break;

    case 'months':
        $rows = query_all_months();
        // 标准化为 {year, month, count, latest_date, label: "YYYY-MM"}
        $out = array_map(static function ($r): array {
            return [
                'year'        => $r['year'],
                'month'       => $r['month'],
                'count'       => (int)$r['count'],
                'latest_date' => $r['latest_date'],
                'label'       => $r['year'] . '-' . $r['month'],
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
        break;

    case 'all':
        $rows = query_all_wallpapers();
        $data = array_map('normalize_wallpaper', $rows);
        echo json_encode(['ok' => true, 'data' => $data, 'count' => count($data)], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action], JSON_UNESCAPED_UNICODE);
        break;
}

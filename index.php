<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$baseUrl = get_base_url();

$resolutionOptions = [
    '1920x1080' => '1920×1080 (1080P 高清横版)',
    '1366x768'  => '1366×768 (笔记本横版)',
    '1080x1920' => '1080×1920 (手机竖版)',
    'uhd'       => 'UHD (超高清原图)',
];

$resolutionKeys = [
    '1920x1080' => '1920x1080',
    '1366x768'  => '1366x768',
    '1080x1920' => 'm',
    'uhd'       => 'uhd',
];

$rangeOptions = [
    'today'  => '今日壁纸',
    'random' => '随机历史壁纸（由本站缓存随机输出）',
];

$outputOptions = [
    'cdn' => '本站CDN（使用阿里云ESA全球加速）',
    '302' => 'Bing官方直链（与Bing官方访问速度一致）',
];

$generatedUrl   = '';
$previewUrl     = '';
$copiedScript   = '';

// ===== 可修改：URL 拼接器的默认值 =====
$defaultRange      = 'today';
$defaultOutput     = 'cdn';
$defaultResolution = '1920x1080';
// ====================================

$range      = $_POST['range']      ?? $defaultRange;
$output     = $_POST['output']     ?? $defaultOutput;
$resolution = $_POST['resolution'] ?? $defaultResolution;

if (!array_key_exists($range, $rangeOptions))           { $range      = $defaultRange; }
if (!array_key_exists($output, $outputOptions))         { $output     = $defaultOutput; }
if (!array_key_exists($resolution, $resolutionOptions)) { $resolution = $defaultResolution; }

$resKey  = $resolutionKeys[$resolution] ?? '1920x1080';
$fileKey = ($resKey === 'm' ? 'm' : $resKey);

$fileName = match (true) {
    $range === 'random'               => 'rand_' . $fileKey . '.php',
    $range === 'today' && $output === 'cdn' => $resKey . '.php',
    default                           => $resKey . '_302.php',
};

$generatedUrl = $baseUrl . $fileName;

$previewFile = match ($range) {
    'random' => 'rand_' . $fileKey . '.php',
    default  => $resKey . '.php',
};
$previewUrl = $baseUrl . $previewFile;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bing 每日壁纸 代理服务</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background-image: url('1920x1080.php');
            background-size: cover;
            background-position: center center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            min-height: 100vh;
            padding: 30px 15px;
            line-height: 1.7;
            color: #333;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #0078d4 0%, #50a7f2 100%);
            color: #fff;
            padding: 32px 35px;
            display: flex;
            flex-wrap: wrap;
            gap: 28px;
            align-items: center;
            justify-content: space-between;
        }
        .header-text {
            flex: 1 1 360px;
            min-width: 0;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .header p {
            opacity: 0.92;
            font-size: 15px;
            margin: 0;
        }

        /* ===== header 右侧按钮组（上下两个） ===== */
        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex-shrink: 0;
        }
        .header-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 10px;
            color: #fff;
            text-decoration: none;
            transition: background 0.18s, transform 0.18s, box-shadow 0.18s, border-color 0.18s;
            backdrop-filter: blur(6px);
            min-width: 230px;
        }
        .header-btn:hover {
            background: rgba(255, 255, 255, 0.24);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
        }
        .header-btn-icon {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.18));
        }
        .header-btn-body {
            display: flex;
            flex-direction: column;
            line-height: 1.25;
            min-width: 0;
        }
        .header-btn-title {
            font-size: 14.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        .header-btn-sub {
            font-size: 11.5px;
            opacity: 0.88;
            margin-top: 2px;
            white-space: nowrap;
        }
        /* 主操作按钮：更亮的底色（客户端下载） */
        .header-btn.primary {
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.55);
        }
        .header-btn.primary:hover {
            background: rgba(255, 255, 255, 0.32);
        }
        .size-badge {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 6px;
            background: rgba(255, 255, 255, 0.28);
            border-radius: 10px;
            font-size: 10.5px;
            font-weight: 500;
            vertical-align: 1px;
        }
        .content {
            padding: 35px;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            font-size: 20px;
            color: #0078d4;
            border-left: 4px solid #0078d4;
            padding-left: 12px;
            margin-bottom: 18px;
        }
        .section h3 {
            font-size: 16px;
            color: #555;
            margin: 18px 0 10px;
        }
        p { margin-bottom: 10px; }
        ul, ol { padding-left: 22px; margin-bottom: 12px; }
        li { margin-bottom: 6px; }
        code {
            background: #f4f6fa;
            color: #d63384;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: "Consolas", "Monaco", monospace;
            font-size: 13px;
        }
        pre {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px 18px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.6;
            margin: 10px 0 15px;
        }
        pre code {
            background: transparent;
            padding: 0;
            color: #333;
        }

        /* ========== 表单 ========== */
        .generator {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid #e0e7ff;
            border-radius: 12px;
            padding: 25px;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 18px;
        }
        .form-group {
            flex: 1;
            min-width: 220px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
            color: #444;
            font-size: 14px;
        }
        select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccd4e0;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
            transition: border 0.2s;
        }
        select:focus {
            outline: none;
            border-color: #0078d4;
            box-shadow: 0 0 0 3px rgba(0,120,212,0.12);
        }
        .btn-generate { display: none; }

        /* ========== 结果区域 ========== */
        .result-box {
            margin-top: 22px;
            padding: 20px;
            background: #fff;
            border: 1px solid #e0e7ff;
            border-radius: 10px;
            display: block;
            animation: fadeIn 0.35s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .result-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .url-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 15px;
        }
        .url-input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #ccd4e0;
            border-radius: 8px;
            font-size: 13px;
            font-family: "Consolas", monospace;
            background: #fafbff;
            color: #222;
            word-break: break-all;
        }
        .btn-copy {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .btn-copy:hover { background: #218838; }
        .btn-copy.copied {
            background: #20c997;
        }
        .copy-tip {
            color: #28a745;
            font-size: 13px;
            margin-left: 10px;
            display: none;
        }
        .copy-tip.show { display: inline; }

        .preview-wrap {
            margin-top: 15px;
            text-align: center;
        }
        .preview-wrap img {
            max-width: 100%;
            max-height: 320px;
            border-radius: 8px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
            cursor: pointer;
        }
        .preview-note {
            font-size: 12px;
            color: #888;
            margin-top: 6px;
        }

        /* ========== 文件列表 ========== */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .file-card {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid #7ea1f2;
            border-radius: 8px;
            padding: 14px;
            transition: border 0.15s, box-shadow 0.15s;
        }
        .file-card:hover {
            border-color: #0078d4;
            box-shadow: 0 4px 14px rgba(0, 120, 212, 0.75);
        }
        .file-card a {
            color: #1198ff;
            text-decoration: none;
            font-weight: 600;
            font-family: "Consolas", monospace;
            font-size: 13px;
            display: block;
            word-break: break-all;
        }
        .file-card a:hover { text-decoration: underline; }
        .file-card small {
            display: block;
            color: #777;
            margin-top: 5px;
            font-size: 12px;
            line-height: 1.5;
        }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 4px;
            margin-top: 5px;
        }
        .tag-302 { background: #e7f6ee; color: #28a745; }
        .tag-cdn { background: #e8f0fe; color: #1a73e8; }
        .tag-today { background: #fff3e0; color: #e65100; }
        .tag-rand { background: #f3e5f5; color: #7b1fa2; }
        .tag-local { background: #e0f7fa; color: #00695c; }

        .tip-box {
            background: #fff8e1;
            border-left: 4px solid #ffb300;
            padding: 14px 18px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .tip-box.ok {
            background: #e8f5e9;
            border-left-color: #4caf50;
        }
        .tip-box.info {
            background: #e3f2fd;
            border-left-color: #2196f3;
        }

        /* ========== 入口卡片行：壁纸墙 + 客户端下载 ========== */
        .entry-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 40px;
        }
        .entry-card {
            flex: 1 1 280px;
            min-width: 260px;
            position: relative;
            border-radius: 14px;
            padding: 24px 26px 24px 96px;
            cursor: pointer;
            overflow: hidden;
            transition: transform 0.22s cubic-bezier(.2,.8,.2,1), box-shadow 0.22s, border-color 0.22s;
            border: 1px solid rgba(255,255,255,0.6);
            background: rgba(255,255,255,0.75);
            color: inherit;
            text-decoration: none;
            display: block;
        }
        .entry-card::before {
            /* 左侧图标色块 */
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 72px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .entry-card-wall::before {
            background: linear-gradient(160deg, #0078d4 0%, #2ea4ff 100%);
        }
        .entry-card-win::before {
            background: linear-gradient(160deg, #00ADEF 0%, #5cc6ff 100%);
        }
        .entry-card-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            z-index: 1;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.2));
        }
        .entry-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 120, 212, 0.22);
            border-color: rgba(0, 120, 212, 0.35);
        }
        .entry-card h2 {
            font-size: 18px;
            color: #0f3b6d;
            margin: 0 0 6px;
            border: none;
            padding: 0;
            letter-spacing: 0.5px;
        }
        .entry-card p {
            font-size: 13.5px;
            color: #666;
            margin: 0;
            line-height: 1.55;
        }
        .entry-card .entry-action {
            display: inline-block;
            margin-top: 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #0078d4;
        }
        .entry-card .entry-action::after {
            content: ' →';
            transition: transform 0.18s;
            display: inline-block;
        }
        .entry-card:hover .entry-action::after {
            transform: translateX(4px);
        }
        /* 下载大小小徽章 */
        .entry-card .size-tag {
            display: inline-block;
            margin-left: 8px;
            padding: 1px 7px;
            background: #e8f0fe;
            color: #1a73e8;
            font-size: 11px;
            border-radius: 10px;
            vertical-align: middle;
            font-weight: 500;
        }

        .footer {
            text-align: center;
            padding: 20px 35px 30px;
            color: #999;
            font-size: 13px;
            border-top: 1px solid #f0f0f0;
        }
        .footer a {
            color: #0078d4;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- 头部 -->
    <div class="header">
        <div class="header-text">
            <h1>🌄 Bing 每日壁纸 代理服务</h1>
            <p>基于 Bing 首页每日壁纸的开源 API，支持多种分辨率、302 直链跳转、历史随机等功能。</p>
        </div>
        <div class="header-actions">
            <!-- ① 壁纸墙 -->
            <a class="header-btn" href="dashboard.php" target="_blank" rel="noopener" title="浏览服务器中保存的全部历史壁纸">
                <svg class="header-btn-icon" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="5" y="9" width="54" height="46" rx="5" fill="#ffffff" opacity="0.96"/>
                    <circle cx="18" cy="22" r="3.8" fill="#ffb74d"/>
                    <path d="M5 45l13-11 10 9 9-7 22 10v5a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-4z" fill="#81d4fa"/>
                    <rect x="5" y="9" width="54" height="46" rx="5" stroke="#ffffff" stroke-width="2" fill="none"/>
                </svg>
                <div class="header-btn-body">
                    <div class="header-btn-title">查看壁纸墙</div>
                    <div class="header-btn-sub">浏览服务器保存的历史壁纸</div>
                </div>
            </a>
            <!-- ② Windows 客户端（主操作） -->
            <a class="header-btn primary" href="https://r2.tianlingzi.ccwu.cc/BingWallpaper.exe" title="立即下载 Windows 桌面客户端">
                <svg class="header-btn-icon" viewBox="-0.5 0 257 257" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M0 36.357L104.62 22.11l.045 100.914-104.57.595L0 36.358zm104.57 98.293l.08 101.002L.081 221.275l-.006-87.302 104.494.677zm12.682-114.405L255.968 0v121.74l-138.716 1.1V20.246zM256 135.6l-.033 121.191-138.716-19.578-.194-101.84L256 135.6z" fill="#ffffff"/>
                </svg>
                <div class="header-btn-body">
                    <div class="header-btn-title">下载客户端<span class="size-badge">绿色 · 17MB</span></div>
                    <div class="header-btn-sub">Windows · 每日壁纸 · 随机壁纸</div>
                </div>
            </a>
        </div>
    </div>

    <div class="content">

        <!-- 项目介绍与使用说明 -->
        <div class="section">
            <h2>📖 项目介绍 & 使用方法</h2>

            <p>本项目可以让您快速搭建自己的 Bing 每日壁纸 API 服务。共 <strong>三种模式</strong>：</p>

            <ol>
                <li>
                    <strong>🎯 今日壁纸 · Bing 官方直链</strong>
                    <ul>
                        <li>访问接口后，HTTP 302 重定向到 Bing 官方图片 URL</li>
                        <li>✅ 图片加载速度 = Bing 官方速度</li>
                        <li>✅ 适合绝大多数场景（网页背景图、桌面壁纸接口等）</li>
                    </ul>
                </li>
                <li>
                    <strong>🖥️ 今日壁纸 · 本站 CDN 模式</strong>
                    <ul>
                        <li>由本站使用阿里云 EAS 边缘加速提供全球访问</li>
                        <li>本项目本地输出模式与其他项目不同，本项目是缓存到本地再从本地读取，其他项目大多是是作为桥梁访问bing。</li>
                        <li>使用可预测性命名，最大限度使用各级缓存与加速，加快访问速度，降低流量损耗。</li>
                    </ul>
                </li>
                <li>
                    <strong>🎲 随机历史壁纸</strong>
                    <ul>
                        <li>从本站的历史缓存中随机输出符合的壁纸（默认是过去30天内的壁纸）</li>
                    </ul>
                </li>
            </ol>

            <h3>📌 使用示例</h3>
            <p>在网页或 Markdown 中直接将生成的 URL 作为图片地址即可：</p>
<pre><code>&lt;!-- HTML 示例 --&gt;
&lt;img src="<?= htmlspecialchars($baseUrl) ?>1920x1080_302.php" alt="Bing 每日壁纸" /&gt;

&lt;!-- Markdown 示例 --&gt;
![Bing 壁纸](<?= htmlspecialchars($baseUrl) ?>uhd.php?d=<?= htmlspecialchars(date('Ymd')) ?>)</code></pre>
        </div>

        <!-- URL 生成工具 -->
        <div class="section">
            <h2>🛠️ URL 拼接工具（修改选项自动生成 · 一键复制）</h2>

            <div class="generator">
                <form method="post" action="" id="genForm" onsubmit="return false;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="output">① 选择访问形式</label>
                            <select id="output" name="output">
                                <?php foreach ($outputOptions as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= (($_POST['output'] ?? '302') === $val) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="range">② 选择日期范围</label>
                            <select id="range" name="range">
                                <?php foreach ($rangeOptions as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= (($_POST['range'] ?? 'today') === $val) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="resolution">③ 选择分辨率</label>
                            <select id="resolution" name="resolution">
                                <?php foreach ($resolutionOptions as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= (($_POST['resolution'] ?? '1920x1080') === $val) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!--「生成链接」按钮已移除：修改下拉框即时生成 URL -->
                </form>

                <!-- 结果输出 -->
                <div class="result-box" id="resultBox">
                    <div class="result-label">✅ 当前配置生成的链接：</div>
                    <div class="url-wrap">
                        <input type="text" class="url-input" id="urlInput"
                               value="<?= htmlspecialchars($generatedUrl) ?>" readonly>
                        <button type="button" class="btn-copy" id="btnCopy" onclick="copyUrl()">📋 复制</button>
                        <span class="copy-tip" id="copyTip">已复制！</span>
                    </div>

                    <div class="preview-wrap" id="previewWrap">
                        <div class="result-label">🖼️ 效果预览（点击图片在新窗口打开）：</div>
                        <a id="previewLink" href="#" target="_blank" rel="noopener">
                            <img id="previewImg" src="#" alt="预览图"
                                 onerror="this.onerror=null; this.style.display='none'; document.getElementById('previewNote').style.display='block';">
                        </a>
                        <div class="preview-note" id="previewNote" style="display:none;">
                            预览图加载失败（可能是本地缓存尚未生成，或选择的随机接口当前暂无素材），但上方生成的链接依然有效，请直接复制使用。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 所有接口文件一览 -->
        <div class="section">
            <h2>📂 全部接口链接一览</h2>

            <div class="tip-box ok">
                <strong>📎 当前站点基础 URL：</strong> <code><?= htmlspecialchars($baseUrl) ?></code>
                <br>所有接口都基于此 URL 拼接生成。
            </div>

            <!-- 今日壁纸 -->
            <h3>🌞 今日壁纸</h3>
            <div class="file-grid">
                <?php foreach ($resolutionKeys as $resVal => $resKey): ?>
                    <?php $label = $resolutionOptions[$resVal]; ?>
                    <div class="file-card">
                        <a href="<?= htmlspecialchars($baseUrl . $resKey . '_302.php') ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($resKey) ?>_302.php
                        </a>
                        <small><?= htmlspecialchars($label) ?><br><span class="tag tag-302">302 直链</span><span class="tag tag-today">今日</span></small>
                    </div>
                    <div class="file-card">
                        <a href="<?= htmlspecialchars($baseUrl . $resKey . '.php') ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($resKey) ?>.php
                        </a>
                        <small><?= htmlspecialchars($label) ?><br><span class="tag tag-cdn">本地CDN</span><span class="tag tag-today">今日</span></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 随机历史（本地全量，不限天数） -->
            <h3>🎲 随机历史壁纸（从本站的历史缓存中随机输出符合的壁纸）</h3>
            <div class="file-grid">
                <?php foreach ($resolutionKeys as $resVal => $resKey): ?>
                    <?php $label = $resolutionOptions[$resVal]; ?>
                    <?php $fileKey = ($resKey === 'm' ? 'm' : $resKey); ?>
                    <div class="file-card">
                        <a href="<?= htmlspecialchars($baseUrl . 'rand_' . $fileKey . '.php') ?>" target="_blank" rel="noopener">
                            rand_<?= htmlspecialchars($fileKey) ?>.php
                        </a>
                        <small><?= htmlspecialchars($label) ?><br><span class="tag tag-local">本地随机（30天）</span></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="footer">
         Powered by <a href="https://www.tianlingzi.top" target="_blank" rel="noopener"
                        style="color:#3362FD;text-decoration:none;">灵感小屋</a>
    </div>
</div>

<script>
// 复制链接
function copyUrl() {
    const input = document.getElementById('urlInput');
    if (!input || !input.value) return;

    input.select();
    input.setSelectionRange(0, input.value.length);

    try {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(showCopyTip);
        } else {
            document.execCommand('copy');
            showCopyTip();
        }
    } catch (e) {
        document.execCommand('copy');
        showCopyTip();
    }
}

function showCopyTip() {
    const btn = document.getElementById('btnCopy');
    const tip = document.getElementById('copyTip');
    if (btn) {
        const original = btn.textContent;
        btn.textContent = '✅ 已复制';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.textContent = original;
            btn.classList.remove('copied');
        }, 1500);
    }
    if (tip) {
        tip.classList.add('show');
        setTimeout(() => tip.classList.remove('show'), 1500);
    }
}

// 实时生成 URL + 预览（下拉修改即触发，无需点按钮）
(function(){
    const $output = document.getElementById('output');
    const $range  = document.getElementById('range');
    const $res    = document.getElementById('resolution');
    const urlInput    = document.getElementById('urlInput');
    const previewImg  = document.getElementById('previewImg');
    const previewLink = document.getElementById('previewLink');
    const previewNote = document.getElementById('previewNote');

    function liveGenerate() {
        const output     = $output.value;
        const range      = $range.value;
        const resolution = $res.value;
        const resKeys = {
            '1920x1080': '1920x1080',
            '1366x768':  '1366x768',
            '1080x1920': 'm',
            'uhd':       'uhd'
        };
        const resKey  = resKeys[resolution] || '1920x1080';
        const fileKey = (resKey === 'm' ? 'm' : resKey);

        let fileName;
        if (range === 'random') {
            // 随机：本地 cache 全量随机，只有直输
            fileName = 'rand_' + fileKey + '.php';
        } else if (output === 'cdn') {
            // 今日 · 本地 CDN：会写 cache
            fileName = resKey + '.php';
        } else {
            // 今日 · 302 官方直链：不占带宽，不写本地
            fileName = resKey + '_302.php';
        }

        const base = '<?= htmlspecialchars(rtrim($baseUrl, '/')) ?>/';
        const url  = base + fileName;

        // 更新 URL 输入框
        urlInput.value = url;

        // 预览：始终使用"本地会输出图片"的脚本作为 src（避免 302 的防盗链/跨域问题）
        let previewFileName;
        if (range === 'random') {
            previewFileName = 'rand_' + fileKey + '.php';   // 随机：用随机直输脚本预览
        } else {
            previewFileName = resKey + '.php';               // 今日：用本地 CDN 直输脚本预览
        }
        const previewUrl = base + previewFileName;

        // 重置预览显示状态（onerror 里会重新隐藏）
        if (previewImg) {
            previewImg.style.display = '';
        }
        if (previewNote) {
            previewNote.style.display = 'none';
        }
        if (previewLink) {
            previewLink.href = url;            // 点击跳转：用户"生成的链接"（不是预览脚本）
        }
        if (previewImg) {
            previewImg.src = previewUrl;       // 图片显示：走"会输出图片"的脚本
        }
    }

    $output.addEventListener('change', liveGenerate);
    $range.addEventListener('change', liveGenerate);
    $res.addEventListener('change', liveGenerate);

    // 页面加载立即执行一次，保证首次进入就有 URL 和预览
    window.addEventListener('DOMContentLoaded', liveGenerate);
})();
</script>

</body>
</html>

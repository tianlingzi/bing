<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// 获取基础 URL（自动处理根目录和子目录）
$baseUrl = get_base_url();

// 分辨率选项
$resolutionOptions = [
    '1920x1080' => '1920×1080 (1080P 高清横版)',
    '1366x768'  => '1366×768 (笔记本横版)',
    '1080x1920' => '1080×1920 (手机竖版)',
    'uhd'       => 'UHD (超高清原图)',
];

// 分辨率对应的 key（用于文件名）
$resolutionKeys = [
    '1920x1080' => '1920x1080',
    '1366x768'  => '1366x768',
    '1080x1920' => 'm',
    'uhd'       => 'uhd',
];

// 模式选项：日期范围
$rangeOptions = [
    'today'  => '今日壁纸',
    'random' => '随机历史壁纸（由本站缓存随机输出）',
];

// 访问形式选项（output type）
$outputOptions = [
    'cdn' => '本站CDN（使用阿里云EAS全球加速）',
    '302' => 'Bing官方直链（与Bing官方访问速度一致）',
];

// 处理表单提交，生成 URL
$generatedUrl = '';
$previewUrl   = '';
$copiedScript = '';

// 所有默认值统一声明（GET / POST 都走同一套默认）
$defaultRange      = 'today';
$defaultOutput     = 'cdn';      // 默认：本地 CDN，与用户期望一致
$defaultResolution = '1920x1080';

// 读当前值：POST 有就用 POST，否则走默认
$range      = $_POST['range']      ?? $defaultRange;
$output     = $_POST['output']     ?? $defaultOutput;
$resolution = $_POST['resolution'] ?? $defaultResolution;

// 白名单校验，防止前端被改出非法值
if (!array_key_exists($range, $rangeOptions))               { $range      = $defaultRange; }
if (!array_key_exists($output, $outputOptions))             { $output     = $defaultOutput; }
if (!array_key_exists($resolution, $resolutionOptions))     { $resolution = $defaultResolution; }

$resKey  = $resolutionKeys[$resolution] ?? '1920x1080';
$fileKey = ($resKey === 'm' ? 'm' : $resKey);

// 根据选项组合文件名（GET 也会生成一个默认 URL，保证页面一打开就显示）
$fileName = match (true) {
    // 随机：从本地 cache 随机（全历史，不限天数），只有直输模式
    $range === 'random' => 'rand_' . $fileKey . '.php',

    // 今日 - 本地 CDN：首次访问会把图片写入 cache/，下次就从本地读
    $range === 'today' && $output === 'cdn' => $resKey . '.php',

    // 今日 - 官方直链：302 跳 Bing 官方
    default => $resKey . '_302.php',
};

$generatedUrl = $baseUrl . $fileName;

// 预览图统一用"会输出图片的脚本"（避免 302 防盗链/跨域问题）
$previewFile = match ($range) {
    'random' => 'rand_' . $fileKey . '.php',
    default  => $resKey . '.php',     // 今日走本地 CDN 脚本
};
$previewUrl = $baseUrl . $previewFile;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bing 每日壁纸 API 代理服务</title>
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
            /* 给页面背景增加一层淡淡的暗色蒙版，保证前景文字可读 */
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #0078d4 0%, #50a7f2 100%);
            color: #fff;
            padding: 40px 35px;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .header p {
            opacity: 0.92;
            font-size: 15px;
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
            background: #f4f6fa;
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
            background: #f8faff;
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
            display: block;   /* 移除按钮后，结果区默认直接显示 */
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
            border: 1px solid #e5e9f2;
            border-radius: 8px;
            padding: 14px;
            transition: border 0.15s, box-shadow 0.15s;
        }
        .file-card:hover {
            border-color: #0078d4;
            box-shadow: 0 4px 14px rgba(0,120,212,0.1);
        }
        .file-card a {
            color: #0078d4;
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
        <h1>🌄 Bing 每日壁纸 API 代理服务</h1>
        <p>基于 Bing 首页每日壁纸的开源 API，支持多种分辨率、302 直链跳转、历史随机等功能。</p>
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
                        <li>由本站先缓存壁纸，再提供壁纸服务</li>
                        <li>本站使用阿里云EAS提供全球加速服务</li>
                    </ul>
                </li>
                <li>
                    <strong>🎲 随机历史壁纸</strong>
                    <ul>
                        <li>从本站的历史缓存中随机输出符合的壁纸</li>
                    </ul>
                </li>
            </ol>

            <h3>📌 使用示例</h3>
            <p>在网页或 Markdown 中直接将生成的 URL 作为图片地址即可：</p>
<pre><code>&lt;!-- HTML 示例 --&gt;
&lt;img src="<?= htmlspecialchars($baseUrl) ?>1920x1080_302.php" alt="Bing 每日壁纸" /&gt;

&lt;!-- Markdown 示例 --&gt;
![Bing 壁纸](<?= htmlspecialchars($baseUrl) ?>uhd_302.php)</code></pre>
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
                        <small><?= htmlspecialchars($label) ?><br><span class="tag tag-local">本地随机</span></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="footer">
         Powered by <a href="https://www.tianlingzi.top" target="_blank" rel="noopener"
                        style="color:#fff;text-decoration:none;border-bottom:1px solid rgba(255,255,255,0.5);">灵感小屋</a>
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

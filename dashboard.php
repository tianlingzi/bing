<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$baseUrl   = rtrim(get_base_url(), '/');
$cacheBase = $baseUrl . '/cache/';

// 视图模式：默认 / 月份（?month=YYYY-MM）
$monthParam = (string)($_GET['month'] ?? '');
$isMonth    = preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $monthMatch);

if ($isMonth) {
    $year          = $monthMatch[1];
    $month         = $monthMatch[2];
    $heroWallpaper = query_latest_wallpaper_of_month($year, $month);
    $wallpapers    = query_wallpapers_by_month($year, $month);
    $pageTitle     = ((int)$year) . '年' . ((int)$month) . '月壁纸';
} else {
    $heroWallpaper = query_latest_wallpaper();
    $wallpapers    = query_all_wallpapers();
    $pageTitle     = 'Bing 每日壁纸 Dashboard';
}

$months = query_all_months();

// 辅助函数
function format_date_chinese(string $date): string
{
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
        return ((int)$m[1]) . '年' . ((int)$m[2]) . '月' . ((int)$m[3]) . '日';
    }
    return $date;
}

function wallpaper_thumb_url(array $w, string $cacheBase): string
{
    $file = $w['file_1366x768'] ?? $w['file_1920x1080'] ?? '';
    return $file !== null && $file !== '' ? $cacheBase . rawurlencode($file) : '';
}

function wallpaper_hero_url(array $w, string $cacheBase): string
{
    $file = $w['file_1920x1080'] ?? $w['file_1366x768'] ?? '';
    return $file !== null && $file !== '' ? $cacheBase . rawurlencode($file) : '';
}

// 构造 JS 端使用的清爽数据结构
$wallpapersJS = array_map(static function ($w) {
    return [
        'date'             => $w['date'],
        'title'            => $w['title'],
        'description'      => $w['description'],
        'author'           => $w['author'],
        'copyright_notice' => $w['copyright_notice'],
        'raw_copyright'    => $w['raw_copyright'],
        'keywords'         => json_decode($w['keywords'] ?? '[]', true) ?: [],
        'file_1920x1080'   => $w['file_1920x1080'],
        'file_1366x768'    => $w['file_1366x768'],
        'file_uhd'         => $w['file_uhd'],
    ];
}, $wallpapers);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root { --accent: #50a7f2; --bg: #0a0a0a; --card: #1a1a1a; --border: #2a2a2a; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: var(--bg);
    color: #f0f0f0;
    line-height: 1.6;
}
a { color: inherit; text-decoration: none; }

/* ===== Hero 区 ===== */
.hero {
    position: relative;
    min-height: 100vh;
    background-size: cover;
    background-position: center center;
    background-color: #000;
    display: flex;
    align-items: flex-end;
    padding: 60px 8% 80px;
}
.hero::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.9) 100%);
    pointer-events: none;
}
.hero-info { position: relative; z-index: 1; max-width: 720px; text-shadow: 0 2px 12px rgba(0,0,0,0.85); }
.hero-title { font-size: clamp(22px, 4vw, 40px); font-weight: 700; margin-bottom: 6px; }
.hero-subtitle { font-size: clamp(14px, 2vw, 20px); font-weight: 400; color: #ddd; margin-bottom: 14px; opacity: 0.92; }
.hero-meta { font-size: 15px; color: #ddd; }
.hero-meta p { margin-bottom: 4px; }
.hero-copyright { color: #bbb; font-size: 13px; margin-top: 8px; }
.scroll-hint {
    position: absolute; bottom: 24px; left: 50%;
    transform: translateX(-50%); z-index: 1;
    color: rgba(255,255,255,0.55); font-size: 13px;
    animation: bounce 2s infinite;
}
@keyframes bounce { 0%,100% { transform: translate(-50%, 0); } 50% { transform: translate(-50%, 8px); } }

/* ===== 照片墙 ===== */
.wall { max-width: 1600px; margin: 0 auto; padding: 60px 6% 40px; }
.wall h2 { font-size: 22px; margin-bottom: 24px; color: var(--accent); }
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}
.card {
    background: var(--card); border-radius: 8px;
    overflow: hidden; cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}
.card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.6); }
.card-img { aspect-ratio: 16 / 9; background: #222; overflow: hidden; }
.card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
.card:hover .card-img img { transform: scale(1.06); }
.card-date { padding: 8px 12px; font-size: 13px; color: #ccc; text-align: center; }

/* ===== 月份导航 ===== */
.months {
    max-width: 1600px; margin: 0 auto;
    padding: 40px 6% 80px;
    border-top: 1px solid var(--border);
}
.months h2 { font-size: 22px; margin-bottom: 20px; color: var(--accent); }
.month-list { display: flex; flex-wrap: wrap; gap: 12px; }
.month-item {
    display: inline-flex; flex-direction: column; align-items: center;
    padding: 12px 20px; background: var(--card);
    border-radius: 8px; border: 1px solid var(--border);
    transition: all 0.2s; min-width: 100px;
}
.month-item:hover { border-color: var(--accent); transform: translateY(-2px); }
.month-item.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.month-item small { font-size: 11px; opacity: 0.7; margin-top: 2px; }

/* ===== 详情模态框 ===== */
.modal {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.92);
    z-index: 100; overflow: auto;
}
.modal.open { display: flex; align-items: center; justify-content: center; padding: 24px; }
.modal-content {
    display: grid;
    grid-template-columns: 1fr 340px;
    max-width: 1400px; width: 100%;
    background: var(--card); border-radius: 12px;
    overflow: hidden; position: relative; max-height: 90vh;
}
.modal-img {
    width: 100%; height: 100%;
    object-fit: contain; max-height: 90vh;
    background: #000; min-height: 400px;
}
.modal-info {
    padding: 30px 24px;
    display: flex; flex-direction: column; gap: 12px;
    overflow-y: auto; max-height: 90vh;
    border-left: 1px solid var(--border);
}
.modal-info h2 { font-size: 22px; line-height: 1.4; }
.meta-row { font-size: 13px; }
.meta-label { color: #888; display: inline-block; min-width: 50px; }
.meta-value { color: #fff; }
.keywords { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.keywords span {
    background: var(--border); padding: 4px 10px;
    border-radius: 12px; font-size: 12px;
}
.modal-downloads { margin-top: auto; padding-top: 16px; display: flex; flex-direction: column; gap: 10px; }
.btn-dl {
    padding: 12px 16px; background: var(--accent); color: #fff;
    border-radius: 6px; text-align: center; font-weight: 600;
    transition: background 0.15s;
}
.btn-dl:hover { background: #0078d4; }
.btn-dl small { display: block; font-weight: 400; font-size: 11px; opacity: 0.8; margin-top: 2px; }
.modal-close {
    position: absolute; top: 12px; right: 12px;
    background: rgba(0,0,0,0.5); color: #fff;
    border: none; width: 36px; height: 36px;
    border-radius: 50%; font-size: 20px; cursor: pointer; z-index: 10;
}
.modal-close:hover { background: rgba(0,0,0,0.85); }

/* ===== 空状态 ===== */
.empty { text-align: center; padding: 120px 20px; color: #888; }
.empty code { background: var(--card); padding: 3px 8px; border-radius: 4px; color: var(--accent); }

@media (max-width: 768px) {
    .modal-content { grid-template-columns: 1fr; max-height: 95vh; }
    .modal-img { max-height: 50vh; min-height: 200px; }
    .modal-info { padding: 20px; max-height: none; border-left: none; border-top: 1px solid var(--border); }
    .hero { padding: 40px 6% 60px; }
    .wall, .months { padding-left: 4%; padding-right: 4%; }
}
</style>
</head>
<body>

<?php if ($heroWallpaper === null): ?>
<section class="empty">
    <h2 style="font-size: 22px; margin-bottom: 12px;">暂无壁纸数据</h2>
    <p>请等待 <code>daily_download.php</code> 下次执行，<br>壁纸元数据会在下载时自动写入数据库。</p>
</section>
<?php else: ?>

<!-- Hero 区：满屏壁纸 + 左下角信息 -->
<section class="hero" style="background-image:url('<?= htmlspecialchars(wallpaper_hero_url($heroWallpaper, $cacheBase)) ?>')">
    <div class="hero-info">
        <h1 class="hero-title"><?= htmlspecialchars($heroWallpaper['title'] ?: $heroWallpaper['description']) ?></h1>
        <?php $heroDesc = $heroWallpaper['description']; ?>
        <?php $heroTitleVal = $heroWallpaper['title'] ?: $heroWallpaper['description']; ?>
        <?php if ($heroDesc !== '' && $heroDesc !== $heroTitleVal): ?>
            <p class="hero-subtitle"><?= htmlspecialchars($heroDesc) ?></p>
        <?php endif; ?>
        <div class="hero-meta">
            <p><?= htmlspecialchars(format_date_chinese($heroWallpaper['date'])) ?></p>
            <?php if (!empty($heroWallpaper['author'])): ?>
                <p>作者：<?= htmlspecialchars($heroWallpaper['author']) ?></p>
            <?php endif; ?>
        </div>
        <?php $heroCr = $heroWallpaper['copyright_notice'] ?: $heroWallpaper['raw_copyright']; ?>
        <?php if ($heroCr !== ''): ?>
            <p class="hero-copyright"><?= htmlspecialchars($heroCr) ?></p>
        <?php endif; ?>
    </div>
    <div class="scroll-hint">↓ 滑动浏览</div>
</section>

<!-- 照片墙 -->
<section class="wall">
    <h2>
        <?= $isMonth ? htmlspecialchars(((int)$year) . '年' . ((int)$month) . '月壁纸') : '全部壁纸' ?>
        · 共 <?= count($wallpapers) ?> 张
    </h2>
    <div class="grid">
        <?php foreach ($wallpapers as $w): ?>
            <?php
                $thumb = wallpaper_thumb_url($w, $cacheBase);
                if ($thumb === '') continue;
            ?>
            <div class="card" data-date="<?= htmlspecialchars($w['date']) ?>">
                <div class="card-img">
                    <img src="<?= htmlspecialchars($thumb) ?>" loading="lazy"
                         alt="<?= htmlspecialchars($w['title'] ?: $w['description']) ?>">
                </div>
                <div class="card-date"><?= htmlspecialchars(format_date_chinese($w['date'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php endif; ?>

<!-- 月份导航 -->
<?php if (!empty($months)): ?>
<section class="months">
    <h2>按月份浏览</h2>
    <div class="month-list">
        <?php foreach ($months as $m): ?>
            <?php
                $mKey = $m['year'] . '-' . $m['month'];
                $active = $isMonth && $monthMatch[1] === $m['year'] && $monthMatch[2] === $m['month'];
            ?>
            <a href="?month=<?= htmlspecialchars($mKey) ?>" class="month-item <?= $active ? 'active' : '' ?>">
                <span><?= htmlspecialchars(((int)$m['year']) . '年' . ((int)$m['month']) . '月') ?></span>
                <small><?= (int)$m['count'] ?> 张</small>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- 详情模态框 -->
<div class="modal" id="modal" aria-hidden="true" role="dialog">
    <div class="modal-content">
        <img class="modal-img" id="modalImg" src="" alt="">
        <div class="modal-info">
            <h2 id="modalTitle"></h2>
            <div class="meta-row"><span class="meta-label">日期</span><span class="meta-value" id="modalDate"></span></div>
            <div class="meta-row"><span class="meta-label">作者</span><span class="meta-value" id="modalAuthor"></span></div>
            <div class="meta-row"><span class="meta-label">版权</span><span class="meta-value" id="modalCopyright"></span></div>
            <div class="meta-row"><span class="meta-label">描述</span><span class="meta-value" id="modalDesc"></span></div>
            <div id="modalKeywordsWrap" style="display:none;">
                <div class="meta-row" style="margin-bottom:4px;"><span class="meta-label">关键词</span></div>
                <div class="keywords" id="modalKeywords"></div>
            </div>
            <div class="modal-downloads">
                <a class="btn-dl" id="dl1080p" href="#" download>下载 1080P · 1920×1080
                    <small>JPG · 约 500 KB</small>
                </a>
                <a class="btn-dl" id="dl4k" href="#" download>下载 4K · UHD 原图
                    <small>JPG · 约 5 MB</small>
                </a>
            </div>
        </div>
        <button class="modal-close" id="modalClose" aria-label="关闭">×</button>
    </div>
</div>

<script>
const BASE = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
const WALLPAPERS = <?= json_encode(array_values($wallpapersJS), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const modal            = document.getElementById('modal');
const modalImg         = document.getElementById('modalImg');
const modalTitle       = document.getElementById('modalTitle');
const modalDate        = document.getElementById('modalDate');
const modalAuthor      = document.getElementById('modalAuthor');
const modalCopyright   = document.getElementById('modalCopyright');
const modalDesc        = document.getElementById('modalDesc');
const modalKeywordsWrap= document.getElementById('modalKeywordsWrap');
const modalKeywords    = document.getElementById('modalKeywords');
const dl1080p          = document.getElementById('dl1080p');
const dl4k             = document.getElementById('dl4k');

function formatDateCN(date) {
    const m = /^(\d{4})(\d{2})(\d{2})$/.exec(date);
    if (!m) return date;
    return parseInt(m[1], 10) + '年' + parseInt(m[2], 10) + '月' + parseInt(m[3], 10) + '日';
}

function cacheUrl(file) {
    return file ? (BASE + '/cache/' + encodeURIComponent(file)) : '';
}

function openModal(date) {
    const w = WALLPAPERS.find(x => x.date === date);
    if (!w) return;

    modalImg.src = cacheUrl(w.file_1920x1080 || w.file_1366x768);
    modalImg.alt = w.title || w.description || '';
    modalTitle.textContent   = w.title || w.description || '';
    modalDate.textContent    = formatDateCN(w.date);
    modalAuthor.textContent  = w.author || '—';
    modalCopyright.textContent = w.copyright_notice || w.raw_copyright || '';
    modalDesc.textContent    = w.description || '';

    modalKeywords.innerHTML = '';
    if (Array.isArray(w.keywords) && w.keywords.length > 0) {
        modalKeywordsWrap.style.display = '';
        w.keywords.forEach(k => {
            const span = document.createElement('span');
            span.textContent = k;
            modalKeywords.appendChild(span);
        });
    } else {
        modalKeywordsWrap.style.display = 'none';
    }

    const dlBase = 'download.php?d=' + encodeURIComponent(w.date) + '&r=';
    dl1080p.href = dlBase + '1920x1080';
    dl4k.href    = dlBase + 'uhd';

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    modalImg.src = '';
    document.body.style.overflow = '';
}

document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('click', () => openModal(card.dataset.date));
});
document.getElementById('modalClose').addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
</script>

</body>
</html>

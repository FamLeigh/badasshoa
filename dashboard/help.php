<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$page_title = 'Help — ' . $association['name'];

$role = viewing_role();
$rank = ROLE_RANK[$role] ?? 0;

// Load topics from DB, fall back to the PHP function if the table is empty or missing.
$all = [];
try {
    $rows = db()->query(
        'SELECT slug, title, category, min_role, body, youtube_url, images
         FROM help_topics WHERE active = 1 ORDER BY sort_order, id'
    )->fetchAll();
    if (!empty($rows)) {
        $all = $rows;
    }
} catch (Throwable $e) {
    // Table not yet created — fall through to PHP fallback.
}

if (empty($all)) {
    require_once __DIR__ . '/../includes/help_topics.php';
    $all = help_topics();
}

// Filter by role.
$visible = array_filter($all, function(array $t) use ($rank): bool {
    $minRank = ROLE_RANK[$t['min_role']] ?? PHP_INT_MAX;
    return $rank >= $minRank;
});

// Active topic.
$slug        = trim((string)($_GET['topic'] ?? ''));
$activeTopic = null;
foreach ($visible as $t) {
    if ($t['slug'] === $slug) { $activeTopic = $t; break; }
}
if (!$activeTopic) {
    $activeTopic = array_values($visible)[0] ?? null;
}

// Group by category for sidebar.
$grouped = [];
foreach ($visible as $t) {
    $grouped[$t['category']][] = $t;
}

/** Extract a YouTube embed URL from a watch URL or share URL, or return null. */
function help_youtube_embed(?string $url): ?string
{
    if (!$url) return null;
    $url = trim($url);
    $id  = null;
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m))                  $id = $m[1];
    elseif (preg_match('~[?&/](?:v=|embed/)([A-Za-z0-9_-]{11})~', $url, $m))      $id = $m[1];
    elseif (preg_match('~^[A-Za-z0-9_-]{11}$~', $url))                             $id = $url;
    return $id ? 'https://www.youtube.com/embed/' . $id : null;
}

require __DIR__ . '/../includes/header.php';
?>

<style>
.help-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 0;
    min-height: calc(100vh - 120px);
    max-width: 1100px;
    margin: var(--sp-8) auto;
    padding: 0 var(--sp-4);
}
@media (max-width: 760px) {
    .help-layout { grid-template-columns: 1fr; }
    .help-sidebar { display: none; }
}

/* Sidebar */
.help-sidebar {
    padding-right: var(--sp-6);
    border-right: 1px solid var(--color-border);
}
.help-sidebar__search {
    display: flex; align-items: center; gap: 8px;
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-md); padding: 6px 12px;
    margin-bottom: var(--sp-5);
}
.help-sidebar__search input {
    border: 0; background: transparent; outline: 0;
    font: inherit; font-size: var(--fs-sm); width: 100%;
}
.help-sidebar__group { margin-bottom: var(--sp-5); }
.help-sidebar__group-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--color-text-soft);
    padding: 0 var(--sp-2); margin-bottom: var(--sp-1);
}
.help-sidebar__link {
    display: block; padding: 6px var(--sp-2);
    border-radius: var(--radius-sm);
    font-size: var(--fs-sm); color: var(--color-text);
    text-decoration: none; line-height: 1.35;
    transition: background 80ms ease, color 80ms ease;
}
.help-sidebar__link:hover { background: var(--color-surface); color: var(--color-navy); text-decoration: none; }
.help-sidebar__link.active {
    background: var(--color-navy); color: #fff; font-weight: 600;
}

/* Content panel */
.help-content {
    padding-left: var(--sp-8);
    padding-bottom: var(--sp-12);
}
.help-content h1 {
    font-size: var(--fs-2xl); font-weight: 800; color: var(--color-navy);
    margin: 0 0 var(--sp-1);
}
.help-content .help-category-tag {
    font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.07em;
    color: var(--color-text-soft); font-weight: 600;
    margin-bottom: var(--sp-6); display: block;
}
.help-content h3 {
    font-size: var(--fs-base); font-weight: 700; color: var(--color-navy);
    margin: var(--sp-6) 0 var(--sp-2);
}
.help-content p, .help-content li { font-size: var(--fs-base); line-height: 1.7; color: var(--color-text); }
.help-content ul, .help-content ol { padding-left: var(--sp-5); margin: 0 0 var(--sp-3); }
.help-content li { margin-bottom: var(--sp-1); }
.help-content a { color: var(--color-orange); }
.help-content code {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: 4px; padding: 1px 5px; font-size: 0.9em;
}

/* YouTube embed */
.help-video {
    margin: var(--sp-8) 0;
}
.help-video-wrap {
    position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;
    border-radius: var(--radius-lg); background: #000;
    max-width: 720px;
}
.help-video-wrap iframe {
    position: absolute; top: 0; left: 0; width: 100%; height: 100%;
    border: 0;
}

/* Images grid */
.help-images {
    display: flex; flex-wrap: wrap; gap: var(--sp-3);
    margin: var(--sp-8) 0;
}
.help-images a {
    display: block; border-radius: var(--radius-md);
    overflow: hidden; border: 1px solid var(--color-border);
    transition: box-shadow 120ms ease;
}
.help-images a:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); }
.help-images img {
    display: block; width: 200px; height: 140px;
    object-fit: cover;
}

/* Mobile topic picker */
.help-mobile-select {
    display: none; margin-bottom: var(--sp-5);
}
@media (max-width: 760px) {
    .help-mobile-select { display: block; }
    .help-content { padding-left: 0; }
}
</style>

<div class="help-layout">

    <!-- Sidebar -->
    <aside class="help-sidebar" aria-label="Help topics">
        <div class="help-sidebar__search">
            <span style="color: var(--color-text-soft); font-size: 14px;">🔎</span>
            <input type="search" id="help-search" placeholder="Search help…" autocomplete="off" value="<?= e((string)($_GET['q'] ?? '')) ?>">
        </div>
        <?php foreach ($grouped as $category => $topics): ?>
        <div class="help-sidebar__group" data-group>
            <div class="help-sidebar__group-label"><?= e($category) ?></div>
            <?php foreach ($topics as $t): ?>
            <a class="help-sidebar__link<?= $activeTopic && $activeTopic['slug'] === $t['slug'] ? ' active' : '' ?>"
               href="/dashboard/help.php?topic=<?= e($t['slug']) ?>"
               data-topic-title="<?= e(strtolower(strip_tags($t['title']))) ?>">
                <?= e($t['title']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </aside>

    <!-- Mobile topic picker -->
    <div class="help-mobile-select">
        <select class="input" onchange="location.href='/dashboard/help.php?topic='+this.value">
            <?php foreach ($grouped as $category => $topics): ?>
            <optgroup label="<?= e($category) ?>">
                <?php foreach ($topics as $t): ?>
                <option value="<?= e($t['slug']) ?>"<?= $activeTopic && $activeTopic['slug'] === $t['slug'] ? ' selected' : '' ?>>
                    <?= e($t['title']) ?>
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Content -->
    <div class="help-content" id="help-content-panel">
        <?php if ($activeTopic): ?>
        <span class="help-category-tag"><?= e($activeTopic['category']) ?></span>
        <h1><?= e($activeTopic['title']) ?></h1>
        <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--sp-4) 0 var(--sp-6);">

        <?= $activeTopic['body'] ?>

        <?php
        // YouTube embed
        $embedUrl = help_youtube_embed($activeTopic['youtube_url'] ?? null);
        if ($embedUrl):
        ?>
        <div class="help-video">
            <div class="help-video-wrap">
                <iframe src="<?= e($embedUrl) ?>?rel=0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen loading="lazy" title="Help video"></iframe>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Images grid
        $imgs = [];
        if (!empty($activeTopic['images'])) {
            $imgs = is_array($activeTopic['images'])
                ? $activeTopic['images']
                : (json_decode((string)$activeTopic['images'], true) ?: []);
        }
        if ($imgs):
        ?>
        <div class="help-images">
            <?php foreach ($imgs as $fn): ?>
            <?php $fn = basename((string)$fn); ?>
            <a href="/help-image.php?f=<?= e(urlencode($fn)) ?>" target="_blank" rel="noopener">
                <img src="/help-image.php?f=<?= e(urlencode($fn)) ?>" alt="Help screenshot" loading="lazy">
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <p class="muted">No help topics available for your role.</p>
        <?php endif; ?>
    </div>

</div>

<script>
(function () {
    var input = document.getElementById('help-search');
    if (!input) return;
    function runFilter() {
        var q = input.value.trim().toLowerCase();
        document.querySelectorAll('[data-group]').forEach(function (group) {
            var links     = group.querySelectorAll('.help-sidebar__link');
            var anyVisible = false;
            links.forEach(function (link) {
                var match = !q || link.dataset.topicTitle.indexOf(q) !== -1;
                link.style.display = match ? '' : 'none';
                if (match) anyVisible = true;
            });
            group.style.display = anyVisible ? '' : 'none';
        });
    }
    input.addEventListener('input', runFilter);
    if (input.value.trim()) runFilter();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

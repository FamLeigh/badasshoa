<?php
// Public attractions directory — /{slug}/attractions → here via .htaccess
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));

// Detect custom domain
$_reqHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$_reqHost = preg_replace('/^www\./', '', $_reqHost);
$_isBadasshoa = $_reqHost === 'badasshoa.com'
    || $_reqHost === 'localhost'
    || str_starts_with($_reqHost, '127.')
    || str_ends_with($_reqHost, '.badasshoa.com');

$assoc = null;
if (!$_isBadasshoa && $slug === '') {
    $stmt = db()->prepare('SELECT * FROM associations WHERE custom_domain = ? LIMIT 1');
    $stmt->execute([$_reqHost]);
    $assoc = $stmt->fetch() ?: null;
} elseif ($slug !== '') {
    $stmt = db()->prepare('SELECT * FROM associations WHERE subdomain = ? LIMIT 1');
    $stmt->execute([$slug]);
    $assoc = $stmt->fetch() ?: null;
}

$_landingUrl = $slug !== '' ? '/' . $slug . '/' : '/';

if (!$assoc || $assoc['status'] === 'inactive' || (int)$assoc['public_landing_enabled'] !== 1) {
    redirect($slug !== '' ? '/' . $slug : '/');
}

$primary = preg_match('/^#[0-9a-f]{6}$/i', (string)$assoc['primary_color']) ? $assoc['primary_color'] : '#0f1f3d';
$hasLogo = !empty($assoc['logo_path']);
$aid     = (int)$assoc['id'];

$stmt = db()->prepare(
    'SELECT * FROM association_attractions WHERE association_id=? AND active=1 ORDER BY sort_order, name'
);
$stmt->execute([$aid]);
$attractions = $stmt->fetchAll();

if (!$attractions) {
    redirect($_landingUrl . '#attractions');
}

$assocLat = isset($assoc['latitude'])  && $assoc['latitude']  !== null ? (float)$assoc['latitude']  : null;
$assocLon = isset($assoc['longitude']) && $assoc['longitude'] !== null ? (float)$assoc['longitude'] : null;

$ATTR_CATS = [
    'dining'        => ['emoji' => '🍽️',  'label' => 'Dining'],
    'shopping'      => ['emoji' => '🛍️',  'label' => 'Shopping'],
    'entertainment' => ['emoji' => '🎭',  'label' => 'Entertainment'],
    'outdoor'       => ['emoji' => '🌿',  'label' => 'Outdoor'],
    'culture'       => ['emoji' => '🎨',  'label' => 'Culture'],
    'services'      => ['emoji' => '🔧',  'label' => 'Services'],
    'other'         => ['emoji' => '📍',  'label' => 'Other'],
];

// Which categories actually have entries?
$activeCats = [];
foreach ($attractions as $a) {
    $activeCats[$a['category']] = true;
}

$cssDir = __DIR__ . '/assets/css';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Area Attractions — <?= e($assoc['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e((string)(@filemtime("$cssDir/tokens.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/base.css?v=<?= e((string)(@filemtime("$cssDir/base.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= e((string)(@filemtime("$cssDir/app.css") ?: '')) ?>">
    <style>
        :root { --assoc-color: <?= e($primary) ?>; }

        .attr-topbar {
            background: var(--color-navy);
            color: rgba(255,255,255,.85);
            padding: var(--sp-3) 0;
            position: sticky; top: 0; z-index: 100;
        }
        .attr-topbar__inner {
            display: flex; align-items: center; gap: var(--sp-4);
        }
        .attr-topbar__logo {
            height: 36px; width: auto; display: block; flex-shrink: 0;
        }
        .attr-topbar__name {
            font-weight: 700; font-size: var(--fs-md); color: #fff; flex: 1; min-width: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .attr-topbar__back {
            font-size: var(--fs-sm); color: rgba(255,255,255,.7);
            text-decoration: none; white-space: nowrap; flex-shrink: 0;
            transition: color .15s;
        }
        .attr-topbar__back:hover { color: #fff; }

        .attr-hero {
            background: <?= e($primary) ?>;
            color: #fff;
            padding: var(--sp-12) 0 var(--sp-10);
            text-align: center;
        }
        .attr-hero h1 {
            font-size: var(--fs-3xl); font-weight: 900;
            letter-spacing: -.03em; margin: 0 0 var(--sp-2); color: #fff;
        }
        .attr-hero p { margin: 0; opacity: .8; font-size: var(--fs-md); }

        .attr-filters {
            background: #fff;
            border-bottom: 1px solid var(--color-border);
            position: sticky; top: 57px; z-index: 90;
        }
        .attr-filters__list {
            display: flex; gap: 0; list-style: none; margin: 0; padding: 0;
            overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none;
        }
        .attr-filters__list::-webkit-scrollbar { display: none; }
        .attr-filters__list button {
            background: none; border: none; cursor: pointer;
            padding: 14px 18px;
            font-size: var(--fs-sm); font-weight: 600;
            color: var(--color-text-soft); white-space: nowrap;
            border-bottom: 2px solid transparent;
            transition: color .15s, border-color .15s;
            font-family: var(--font-body);
        }
        .attr-filters__list button:hover { color: <?= e($primary) ?>; border-bottom-color: <?= e($primary) ?>; }
        .attr-filters__list button.active { color: <?= e($primary) ?>; border-bottom-color: <?= e($primary) ?>; }

        .attr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--sp-5);
        }
        .attr-card {
            display: flex; flex-direction: column;
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: var(--r-lg);
            overflow: hidden;
            transition: box-shadow .15s, transform .15s;
        }
        .attr-card:hover { box-shadow: 0 4px 20px rgba(15,31,61,.1); transform: translateY(-2px); }
        .attr-card__img {
            height: 180px; overflow: hidden; flex-shrink: 0;
        }
        .attr-card__img img { width:100%; height:100%; object-fit:cover; display:block; }
        .attr-card__img--placeholder {
            height: 180px; background: var(--color-surface-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem; flex-shrink: 0;
        }
        .attr-card__body { padding: var(--sp-4); flex: 1; display: flex; flex-direction: column; }
        .attr-card__cat {
            font-size: var(--fs-xs); font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--color-text-muted); margin-bottom: var(--sp-2);
            display: flex; align-items: center; gap: var(--sp-1);
        }
        .attr-card__name { font-size: var(--fs-md); font-weight: 700; margin: 0 0 var(--sp-2); line-height: 1.3; }
        .attr-card__desc { font-size: var(--fs-sm); color: var(--color-text-soft); margin: 0 0 var(--sp-3); flex: 1; line-height: 1.55; }
        .attr-card__meta {
            font-size: var(--fs-xs); color: var(--color-text-muted);
            display: flex; flex-wrap: wrap; align-items: center; gap: var(--sp-2);
            margin-bottom: var(--sp-3);
        }
        .attr-card__dist {
            background: var(--color-surface-2); border-radius: 99px;
            padding: 2px 10px; font-weight: 700; color: var(--color-navy);
            white-space: nowrap;
        }
        .attr-card__links { display: flex; gap: var(--sp-3); margin-top: auto; flex-wrap: wrap; }
        .attr-card__links a {
            font-size: var(--fs-sm); font-weight: 600; text-decoration: none;
            color: <?= e($primary) ?>; transition: opacity .15s;
        }
        .attr-card__links a:hover { opacity: .75; }

        .attr-card[data-hidden="1"] { display: none; }

        .attr-empty {
            text-align: center; padding: var(--sp-16) var(--sp-6);
            color: var(--color-text-muted); display: none;
        }
    </style>
</head>
<body class="page-public">

<!-- Topbar -->
<nav class="attr-topbar">
    <div class="container attr-topbar__inner">
        <?php if ($hasLogo): ?>
            <img class="attr-topbar__logo" src="/branding.php?id=<?= $aid ?>&kind=logo" alt="<?= e($assoc['name']) ?>">
        <?php endif; ?>
        <span class="attr-topbar__name"><?= e($assoc['name']) ?></span>
        <a class="attr-topbar__back" href="<?= e($_landingUrl) ?>">← Community page</a>
    </div>
</nav>

<!-- Hero -->
<div class="attr-hero">
    <div class="container">
        <h1>Area Attractions</h1>
        <p><?= count($attractions) ?> local spot<?= count($attractions) !== 1 ? 's' : '' ?> worth exploring near <?= e($assoc['name']) ?></p>
    </div>
</div>

<!-- Category filter tabs -->
<?php if (count($activeCats) > 1): ?>
<div class="attr-filters">
    <div class="container" style="padding-top:0; padding-bottom:0;">
        <ul class="attr-filters__list" role="tablist">
            <li><button class="active" data-filter="all" onclick="filterAttr(this,'all')">All <span style="opacity:.6;">(<?= count($attractions) ?>)</span></button></li>
            <?php foreach ($ATTR_CATS as $key => $info):
                if (!isset($activeCats[$key])) continue;
                $count = count(array_filter($attractions, fn($a) => $a['category'] === $key));
            ?>
            <li><button data-filter="<?= e($key) ?>" onclick="filterAttr(this,'<?= e($key) ?>')"><?= $info['emoji'] ?> <?= e($info['label']) ?> <span style="opacity:.6;">(<?= $count ?>)</span></button></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- Cards -->
<section style="padding: var(--sp-10) 0 var(--sp-16); background: var(--color-surface);">
    <div class="container">
        <div class="attr-grid" id="attr-grid">
        <?php foreach ($attractions as $a):
            $cat     = $a['category'] ?? 'other';
            $catInfo = $ATTR_CATS[$cat] ?? $ATTR_CATS['other'];
            $distLabel = '';
            if ($assocLat !== null && $assocLon !== null && !empty($a['latitude']) && !empty($a['longitude'])) {
                $mi = haversine_miles($assocLat, $assocLon, (float)$a['latitude'], (float)$a['longitude']);
                $distLabel = $mi < 0.1 ? '< 0.1 mi away' : round($mi, 1) . ' mi away';
            }
            $mapUrl = '';
            if (!empty($a['latitude']) && !empty($a['longitude'])) {
                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . $a['latitude'] . ',' . $a['longitude'];
            } elseif (!empty($a['address'])) {
                $mapUrl = 'https://maps.google.com/?q=' . rawurlencode((string)$a['address']);
            }
        ?>
        <div class="attr-card" data-cat="<?= e($cat) ?>">
            <?php if (!empty($a['photo_path'])): ?>
                <div class="attr-card__img">
                    <img src="/public-attraction.php?id=<?= (int)$a['id'] ?>&aid=<?= $aid ?>"
                         alt="<?= e((string)$a['name']) ?>" loading="lazy">
                </div>
            <?php else: ?>
                <div class="attr-card__img--placeholder"><?= $catInfo['emoji'] ?></div>
            <?php endif; ?>
            <div class="attr-card__body">
                <div class="attr-card__cat"><?= $catInfo['emoji'] ?> <?= e($catInfo['label']) ?></div>
                <div class="attr-card__name"><?= e((string)$a['name']) ?></div>
                <?php if (!empty($a['description'])): ?>
                    <p class="attr-card__desc"><?= e((string)$a['description']) ?></p>
                <?php endif; ?>
                <?php if (!empty($a['address']) || $distLabel): ?>
                    <div class="attr-card__meta">
                        <?php if (!empty($a['address'])): ?>
                            <span><?= e((string)$a['address']) ?></span>
                        <?php endif; ?>
                        <?php if ($distLabel): ?>
                            <span class="attr-card__dist"><?= e($distLabel) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($mapUrl || !empty($a['website_url'])): ?>
                    <div class="attr-card__links">
                        <?php if ($mapUrl): ?>
                            <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener">Map &nearr;</a>
                        <?php endif; ?>
                        <?php if (!empty($a['website_url'])): ?>
                            <a href="<?= e((string)$a['website_url']) ?>" target="_blank" rel="noopener">Visit website &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <p class="attr-empty" id="attr-empty">No attractions in this category yet.</p>
    </div>
</section>

<footer class="landing-foot">
    <div class="container landing-foot__inner">
        <div class="muted">&copy; <?= (int)date('Y') ?> <?= e($assoc['name']) ?>.</div>
        <div class="muted" style="font-size: var(--fs-xs);">
            Powered by <a href="/" style="color: var(--color-orange);">BadassHOA</a>
        </div>
    </div>
</footer>

<script>
function filterAttr(btn, cat) {
    document.querySelectorAll('.attr-filters__list button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    var cards = document.querySelectorAll('#attr-grid .attr-card');
    var visible = 0;
    cards.forEach(function(card) {
        var show = cat === 'all' || card.dataset.cat === cat;
        card.dataset.hidden = show ? '0' : '1';
        if (show) visible++;
    });
    document.getElementById('attr-empty').style.display = visible === 0 ? 'block' : 'none';
}
</script>

</body>
</html>

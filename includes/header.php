<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$page_title  = $page_title  ?? 'BadassHOA — Transparent, Simplified and Built for Your Community';
$page_class  = $page_class  ?? 'page-public';
$page_layout = $page_layout ?? 'public'; // 'public' | 'app' | 'admin'
$user        = current_user();

// --- inline SVG icons (lucide-style) ----------------------------------
function nav_icon(string $name): string
{
    $base = 'width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    switch ($name) {
        case 'home':           return "<svg $base><path d='M3 12l9-9 9 9'/><path d='M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10'/></svg>";
        case 'documents':      return "<svg $base><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/><line x1='16' y1='13' x2='8' y2='13'/><line x1='16' y1='17' x2='8' y2='17'/></svg>";
        case 'rules':          return "<svg $base><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>";
        case 'directory':      return "<svg $base><path d='M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2'/><circle cx='9' cy='7' r='4'/><path d='M23 21v-2a4 4 0 0 0-3-3.87'/><path d='M16 3.13a4 4 0 0 1 0 7.75'/></svg>";
        case 'committees':     return "<svg $base><path d='M16 4a4 4 0 1 1-8 0'/><path d='M2 22v-2a6 6 0 0 1 6-6h8a6 6 0 0 1 6 6v2'/><circle cx='12' cy='8' r='4'/></svg>";
        case 'communications': return "<svg $base><path d='M3 11l18-8-8 18-2-8-8-2z'/></svg>";
        case 'media':          return "<svg $base><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>";
        case 'settings':       return "<svg $base><circle cx='12' cy='12' r='3'/><path d='M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z'/></svg>";
        case 'overview':       return "<svg $base><rect x='3' y='3' width='7' height='7'/><rect x='14' y='3' width='7' height='7'/><rect x='14' y='14' width='7' height='7'/><rect x='3' y='14' width='7' height='7'/></svg>";
        case 'associations':   return "<svg $base><path d='M3 21h18'/><path d='M5 21V7l8-4v18'/><path d='M19 21V11l-6-4'/><path d='M9 9v.01M9 12v.01M9 15v.01M9 18v.01'/></svg>";
        case 'activity':       return "<svg $base><polyline points='22 12 18 12 15 21 9 3 6 12 2 12'/></svg>";
        case 'locations':      return "<svg $base><path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/></svg>";
        case 'menu':           return "<svg $base><line x1='3' y1='12' x2='21' y2='12'/><line x1='3' y1='6' x2='21' y2='6'/><line x1='3' y1='18' x2='21' y2='18'/></svg>";
        case 'collapse':       return "<svg $base><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><line x1='9' y1='3' x2='9' y2='21'/></svg>";
        case 'logout':         return "<svg $base><path d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/><polyline points='16 17 21 12 16 7'/><line x1='21' y1='12' x2='9' y2='12'/></svg>";
    }
    return '';
}

// --- active-link detection from URL -----------------------------------
function active_nav_key(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#/+$#', '', $path); // strip trailing slash
    if ($path === '') $path = '/';

    static $map = [
        '/dashboard'                    => 'home',
        '/dashboard/index.php'          => 'home',
        '/dashboard/documents.php'      => 'documents',
        '/dashboard/search.php'         => 'rules',
        '/dashboard/directory.php'      => 'directory',
        '/dashboard/committees.php'     => 'committees',
        '/dashboard/events.php'         => 'events',
        '/dashboard/communications.php' => 'communications',
        '/dashboard/media.php'          => 'media',
        '/dashboard/faq.php'            => 'faq',
        '/dashboard/settings.php'       => 'settings',
        '/admin'                        => 'overview',
        '/admin/index.php'              => 'overview',
        '/admin/associations.php'       => 'associations',
        '/admin/users.php'              => 'users',
        '/admin/activity.php'           => 'activity',
        '/admin/changelog.php'          => 'changelog',
    ];
    return $map[$path] ?? '';
}

function nav_link(string $href, string $iconKey, string $label, string $key, string $active): string
{
    $cls  = ($key === $active) ? 'side-nav__link active' : 'side-nav__link';
    $icon = nav_icon($iconKey);
    return '<a class="' . $cls . '" href="' . e($href) . '">'
         . $icon
         . '<span class="side-nav__label">' . e($label) . '</span>'
         . '</a>';
}

$active     = active_nav_key();
$shellClass = $page_layout === 'app' || $page_layout === 'admin' ? 'app-shell' : '';
$userInitial = strtoupper(substr(trim((string)($_SESSION['name'] ?? $_SESSION['email'] ?? '?')), 0, 1) ?: '?');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <?php $cssDir = __DIR__ . '/../assets/css'; ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e((string)(@filemtime("$cssDir/tokens.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/base.css?v=<?= e((string)(@filemtime("$cssDir/base.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= e((string)(@filemtime("$cssDir/app.css") ?: '')) ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230f1f3d'/%3E%3Cpath d='M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z' fill='%23f05a28'/%3E%3C/svg%3E">
    <?= $page_extra_head ?? '' ?>
    <?php if ($shellClass): ?>
    <!-- Pre-paint sidebar collapsed-state hint (avoids flash) -->
    <script>
    (function () {
        try {
            if (localStorage.getItem('sideNavCollapsed') === '1') {
                document.documentElement.dataset.navCollapsed = '1';
            }
        } catch (_) {}
    })();
    </script>
    <?php endif; ?>
</head>
<body class="<?= e($page_class) ?> <?= e($shellClass) ?>" data-layout="<?= e($page_layout) ?>">

<?php if (is_viewing_as()): ?>
<div class="view-as-banner" role="status">
    <div class="container row row--between" style="gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
        <div>
            <strong>👁 Viewing as <?= e($_SESSION['view_as_role'] === 'resident' ? 'a homeowner' : 'a renter') ?></strong>
            <span style="opacity: 0.85; font-size: var(--fs-sm);">
                ·  manage controls hidden ·  your real role is <?= e(str_replace('_', ' ', (string)($_SESSION['role'] ?? ''))) ?>
            </span>
        </div>
        <form method="post" action="/dashboard/view-as.php" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="role" value="exit">
            <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '/dashboard/')) ?>">
            <button class="btn btn--ghost" type="submit" style="background: rgba(255,255,255,0.18); color: var(--color-white); border-color: rgba(255,255,255,0.3);">
                Exit view-as →
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['real_user_id'])): ?>
<div class="impersonation-banner" role="alert">
    <div class="container row row--between" style="gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
        <div>
            <strong>👁 Viewing as <?= e((string)($_SESSION['name'] ?? 'a user')) ?></strong>
            <span style="opacity: 0.85; font-size: var(--fs-sm);">
                ·  <?= e(str_replace('_', ' ', (string)($_SESSION['role'] ?? ''))) ?>
                ·  super admin <?= e((string)($_SESSION['real_user_name'] ?? '')) ?>
            </span>
        </div>
        <form method="post" action="/admin/return-to-admin.php" style="margin: 0;">
            <?= csrf_field() ?>
            <button class="btn btn--ghost" type="submit" style="background: rgba(255,255,255,0.15); color: var(--color-white); border-color: rgba(255,255,255,0.3);">
                Return to admin →
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($page_layout === 'public'): ?>
<header class="site-nav">
    <div class="container site-nav__inner">
        <a class="brand" href="/" aria-label="BadassHOA home">
            <img src="/assets/images/logo.png" alt="BadassHOA" class="brand__logo" width="200" height="50">
        </a>
        <button class="nav-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="site-nav__links" id="site-nav-links">
            <a href="/#features">Features</a>
            <a href="/pricing.php">Pricing</a>
            <a href="/#faq">FAQ</a>
            <?php if ($user): ?>
                <a class="btn btn--ghost" href="<?= e(landing_for($user['role'])) ?>">Dashboard</a>
                <a class="btn btn--primary" href="/logout.php">Sign out</a>
            <?php else: ?>
                <a class="btn btn--ghost" href="/login.php">Sign in</a>
                <a class="btn btn--primary" href="/signup.php">Get started</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php elseif ($page_layout === 'app' || $page_layout === 'admin'): ?>

<!-- Mobile top bar (only visible < 900px) -->
<div class="mobile-topbar">
    <button type="button" class="mobile-topbar__menu" id="mobile-menu-btn" aria-label="Open menu">
        <?= nav_icon('menu') ?>
    </button>
    <strong class="mobile-topbar__title">
        <?= $page_layout === 'admin'
            ? 'BadassHOA Admin'
            : e((isset($association) && $association ? (string)$association['name'] : 'Dashboard')) ?>
    </strong>
    <span style="width:38px;"></span>
</div>

<?php
// Tenant dashboard gets a persistent top app-bar showing the ASSOCIATION's
// branding (logo + name), not BadassHOA's. The collapse toggle lives here so
// it stays clickable when the sidebar is collapsed. Admin layout keeps the
// existing in-sidebar branding.
if ($page_layout === 'app' && isset($association) && $association):
    $assocLogoSrc = !empty($association['logo_path'])
        ? '/branding.php?id=' . (int)$association['id']
        : null;
?>
<header class="app-topbar" role="banner">
    <a href="/dashboard/" class="app-topbar__brand" aria-label="<?= e((string)$association['name']) ?> dashboard home">
        <?php if ($assocLogoSrc): ?>
            <img src="<?= e($assocLogoSrc) ?>" alt="<?= e((string)$association['name']) ?>" class="app-topbar__logo">
        <?php else: ?>
            <span class="app-topbar__avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string)$association['name'], 0, 1))) ?></span>
        <?php endif; ?>
        <span class="app-topbar__text">
            <strong class="app-topbar__name"><?= e((string)$association['name']) ?></strong>
            <?php if (!empty($association['address']) || !empty($association['city'])): ?>
                <small class="app-topbar__sub">
                    <?php
                    $bits = array_filter([
                        $association['address'] ?? null,
                        $association['city']    ?? null,
                        $association['state_region'] ?? null,
                    ]);
                    echo e(implode(', ', $bits));
                    ?>
                </small>
            <?php endif; ?>
        </span>
    </a>
    <button type="button" class="app-topbar__toggle side-nav__toggle" id="side-nav-toggle" aria-label="Collapse sidebar" title="Collapse sidebar">
        <?= nav_icon('collapse') ?>
    </button>
</header>
<?php endif; ?>

<nav class="side-nav" id="side-nav" aria-label="<?= $page_layout === 'admin' ? 'Admin' : 'Dashboard' ?> navigation">
    <div class="side-nav__inner">

        <?php if ($page_layout === 'admin'): /* admin keeps the BadassHOA brand row */ ?>
        <div class="side-nav__brand-row">
            <a href="/admin/" class="side-nav__brand" aria-label="BadassHOA home">
                <img src="/assets/images/logo-white.png" alt="BadassHOA" class="side-nav__logo">
                <img src="/assets/images/logo-mark.png" alt="BadassHOA" class="side-nav__mark">
            </a>
            <button type="button" class="side-nav__toggle" id="side-nav-toggle" aria-label="Collapse sidebar" title="Collapse sidebar">
                <?= nav_icon('collapse') ?>
            </button>
        </div>
        <div class="side-nav__brand-sub">
            <span class="side-nav__brand-tag">Admin</span>
        </div>
        <?php endif; ?>

        <div class="side-nav__links">
        <?php if ($page_layout === 'app'): ?>
            <?= nav_link('/dashboard/',                'home',           'Home',           'home',           $active) ?>
            <?= nav_link('/dashboard/documents.php',   'documents',      'Documents',      'documents',      $active) ?>
            <?= nav_link('/dashboard/search.php',      'rules',          'Rules',          'rules',          $active) ?>
            <?= nav_link('/dashboard/directory.php',   'directory',      'Directory',      'directory',      $active) ?>
            <?= nav_link('/dashboard/committees.php',  'committees',     'Committees',     'committees',     $active) ?>
            <?= nav_link('/dashboard/events.php',      'committees',     'Events',         'events',         $active) ?>
            <?= nav_link('/dashboard/communications.php', 'communications', 'Communications', 'communications', $active) ?>
            <?= nav_link('/dashboard/media.php',       'media',          'Media',          'media',          $active) ?>
            <?= nav_link('/dashboard/faq.php',         'rules',          'FAQ',            'faq',            $active) ?>
            <?php if (role_can_manage(viewing_role())): ?>
                <?= nav_link('/dashboard/locations.php',   'locations',      'Locations',      'locations',      $active) ?>
                <?= nav_link('/dashboard/activity.php',    'activity',       'Activity',       'activity',       $active) ?>
            <?php endif; ?>
            <?= nav_link('/dashboard/settings.php',    'settings',       'Settings',       'settings',       $active) ?>
        <?php else: /* admin */ ?>
            <?= nav_link('/admin/',                    'overview',       'Overview',       'overview',       $active) ?>
            <?= nav_link('/admin/associations.php',    'associations',   'Associations',   'associations',   $active) ?>
            <?= nav_link('/admin/users.php',           'directory',      'Users',          'users',          $active) ?>
            <?= nav_link('/admin/activity.php',        'activity',       'Activity',       'activity',       $active) ?>
            <?= nav_link('/admin/changelog.php',       'documents',      'Changelog',      'changelog',      $active) ?>
        <?php endif; ?>
        </div>

        <div class="side-nav__bottom">
            <div class="side-nav__user">
                <span class="side-nav__avatar"><?= e($userInitial) ?></span>
                <div class="side-nav__user-text">
                    <strong class="side-nav__name"><?= e($_SESSION['name'] ?? 'Account') ?></strong>
                    <small class="side-nav__role"><?= e(str_replace('_', ' ', (string)($_SESSION['role'] ?? ''))) ?></small>
                </div>
            </div>
            <?php if ($page_layout === 'app' && role_can_manage((string)($_SESSION['role'] ?? '')) && !is_viewing_as()): ?>
                <div class="side-nav__view-as side-nav__hide-when-collapsed">
                    <small style="display:block; opacity: 0.55; font-size: var(--fs-xs); margin-bottom: 4px; padding: 0 var(--sp-3);">View as</small>
                    <form method="post" action="/dashboard/view-as.php" style="display:flex; gap: 4px; padding: 0 var(--sp-3);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '/dashboard/')) ?>">
                        <button class="side-nav__view-as-btn" type="submit" name="role" value="resident" title="View as a homeowner">Homeowner</button>
                        <button class="side-nav__view-as-btn" type="submit" name="role" value="renter" title="View as a renter">Renter</button>
                    </form>
                </div>
            <?php endif; ?>
            <a class="side-nav__signout" href="/logout.php" title="Sign out">
                <?= nav_icon('logout') ?><span class="side-nav__label">Sign out</span>
            </a>
        </div>

    </div>
</nav>
<div class="side-nav__overlay" id="side-nav-overlay"></div>
<?php endif; ?>

<?php
$_flashes = flash_take();
if ($_flashes): ?>
<div class="flash-stack container">
    <?php foreach ($_flashes as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<main class="page">

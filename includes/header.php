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
        case 'units':          return "<svg $base><rect x='3' y='3' width='7' height='7'/><rect x='14' y='3' width='7' height='7'/><rect x='3' y='14' width='7' height='7'/><rect x='14' y='14' width='7' height='7'/></svg>";
        case 'concerns':       return "<svg $base><path d='M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'/></svg>";
        case 'violations':     return "<svg $base><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/><line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/></svg>";
        case 'minutes':        return "<svg $base><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/><line x1='16' y1='13' x2='8' y2='13'/><line x1='16' y1='17' x2='8' y2='17'/><line x1='10' y1='9' x2='8' y2='9'/></svg>";
        case 'permissions':    return "<svg $base><rect x='3' y='11' width='18' height='11' rx='2' ry='2'/><path d='M7 11V7a5 5 0 0 1 10 0v4'/></svg>";
        case 'voting':         return "<svg $base><circle cx='12' cy='12' r='10'/><polyline points='8 12 11 15 16 9'/></svg>";
        case 'marketplace':    return "<svg $base><path d='M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 5h12M10 18a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z'/></svg>";
        case 'legal':          return "<svg $base><line x1='12' y1='3' x2='12' y2='21'/><polyline points='3 6 12 3 21 6'/><path d='M6 6L3 12a3 3 0 0 0 6 0'/><path d='M18 6l-3 6a3 3 0 0 0 6 0'/><line x1='3' y1='20' x2='21' y2='20'/></svg>";
        case 'contacts':       return "<svg $base><path d='M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z'/></svg>";
        case 'parking':        return "<svg $base><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><path d='M9 17V7h4a3 3 0 0 1 0 6H9'/></svg>";
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
        '/dashboard/violations.php'     => 'violations',
        '/dashboard/minutes.php'        => 'minutes',
        '/dashboard/voting.php'         => 'voting',
        '/dashboard/marketplace.php'    => 'marketplace',
        '/dashboard/legal.php'          => 'legal',
        '/admin/legal.php'              => 'legal',
        '/dashboard/permissions.php'    => 'settings',
        '/dashboard/locations.php'      => 'settings',
        '/admin'                        => 'overview',
        '/admin/index.php'              => 'overview',
        '/admin/associations.php'       => 'associations',
        '/admin/users.php'              => 'users',
        '/admin/activity.php'           => 'activity',
        '/admin/changelog.php'          => 'changelog',
        '/dashboard/help.php'           => 'help',
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

$active      = active_nav_key();
$shellClass  = $page_layout === 'app' || $page_layout === 'admin' ? 'app-shell' : '';
$userInitial = strtoupper(substr(trim((string)($_SESSION['name'] ?? $_SESSION['email'] ?? '?')), 0, 1) ?: '?');

// Map active page key → group id, so JS can force that group open even if the
// user previously collapsed it.
$_groupForActive = [
    'home' => 'community', 'communications' => 'community', 'events' => 'community', 'faq' => 'community', 'marketplace' => 'community',
    'documents' => 'resources', 'forms' => 'resources', 'rules' => 'resources',
    'minutes' => 'resources', 'media' => 'resources', 'directory' => 'resources', 'contacts' => 'resources', 'legal' => 'resources',
    'committees' => 'governance', 'concerns' => 'governance', 'arc' => 'governance',
    'violations' => 'governance', 'work-orders' => 'governance', 'voting' => 'governance',
    'units' => 'operations', 'parking' => 'operations', 'employees' => 'operations', 'insurance' => 'operations',
    'activity' => 'configuration', 'settings' => 'configuration',
][$active] ?? '';

// Active emergency announcements — queried once here, rendered as full-width
// banner(s) before the nav so they're visible on every dashboard page.
$_emergencies = [];
if ($page_layout === 'app' && isset($assocId) && function_exists('db')) {
    try {
        $role = function_exists('viewing_role') ? (string)viewing_role() : (string)($_SESSION['role'] ?? '');
        $allowedAud = ['all'];
        if ($role === 'renter') {
            $allowedAud[] = 'renters';
        } elseif ($role === 'owner' || $role === 'staff') {
            $allowedAud[] = 'owners';
        } elseif (in_array($role, ['board_member','board_admin','super_admin','property_manager'], true)) {
            $allowedAud[] = 'owners';
            $allowedAud[] = 'renters';
            $allowedAud[] = 'board';
        }
        $_audPh = implode(',', array_fill(0, count($allowedAud), '?'));
        $_emStmt = db()->prepare(
            "SELECT id, title, body FROM announcements
              WHERE association_id = ?
                AND type = 'emergency'
                AND published_at <= NOW()
                AND (expires_at IS NULL OR expires_at > NOW())
                AND audience IN ($_audPh)
              ORDER BY published_at DESC
              LIMIT 3"
        );
        $_emStmt->execute(array_merge([$assocId], $allowedAud));
        $_emergencies = $_emStmt->fetchAll();
    } catch (Throwable $_) {}
}
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
    <style>
    .emergency-banner{background:#b91c1c;color:#fff;padding:11px 20px;position:relative;z-index:1100}
    .emergency-banner+.emergency-banner{border-top:1px solid rgba(255,255,255,.2)}
    .emergency-banner__inner{max-width:1500px;margin:0 auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .emergency-banner__icon{font-size:20px;flex-shrink:0;animation:emerg-pulse 1.6s ease-in-out infinite}
    @keyframes emerg-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.25)}}
    .emergency-banner__content{flex:1;min-width:0}
    .emergency-banner__title{font-weight:800;font-size:14px;font-family:inherit;letter-spacing:.01em}
    .emergency-banner__sep{opacity:.55;margin:0 4px}
    .emergency-banner__body{font-size:13px;opacity:.9}
    .emergency-banner__link{flex-shrink:0;color:#fff;font-size:12px;font-weight:700;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.3);border-radius:4px;padding:5px 12px;text-decoration:none;white-space:nowrap;letter-spacing:.02em}
    .emergency-banner__link:hover{background:rgba(0,0,0,.4);text-decoration:none}
    </style>
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

<?php
// Email-paused warning: visible only to super admins (cross-tenant operators)
// when the mail driver is 'log' in production. Reminds Kevin (and any future
// admin) that no outbound mail is being sent until config is flipped back.
$_mailDriver = (string)(config()['mail']['driver'] ?? '');
$_envProd    = (string)(config()['env'] ?? '') === 'production';
if ($_envProd && $_mailDriver === 'log' && ($_SESSION['role'] ?? '') === 'super_admin'):
?>
<div class="mail-paused-banner" role="status">
    <div class="container row row--between" style="gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
        <div>
            <strong>📨 Email sending is paused.</strong>
            <span style="opacity: 0.85; font-size: var(--fs-sm);">
                Outbound mail (invitations, password resets, concern notifications, etc.) is going to <code>storage/logs/mail.log</code> instead of inboxes. Flip <code>config.php → mail.driver</code> back to <code>msmtp</code> on the server when you're ready to resume.
            </span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (is_viewing_as()): ?>
<div class="view-as-banner" role="status">
    <div class="container row row--between" style="gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
        <div>
            <strong>👁 Viewing as <?= e($_SESSION['view_as_role'] === 'owner' ? 'an owner' : 'a renter') ?></strong>
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

<?php foreach ($_emergencies as $_emerg): ?>
<div class="emergency-banner" role="alert" aria-live="assertive">
    <div class="emergency-banner__inner">
        <span class="emergency-banner__icon" aria-hidden="true">🚨</span>
        <div class="emergency-banner__content">
            <span class="emergency-banner__title"><?= e((string)$_emerg['title']) ?></span>
            <?php $_body = trim(strip_tags((string)$_emerg['body'])); if ($_body !== ''): ?>
                <span class="emergency-banner__sep" aria-hidden="true">—</span>
                <span class="emergency-banner__body"><?= e(mb_strimwidth($_body, 0, 220, '…')) ?></span>
            <?php endif; ?>
        </div>
        <a href="/dashboard/communications.php?type=emergency" class="emergency-banner__link">Full announcement →</a>
    </div>
</div>
<?php endforeach; ?>

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

<style>
    .topbar-search {
        display:flex; align-items:center; gap: 8px;
        margin-left: auto; margin-right: 12px;
        background: var(--color-surface, #f4f3ed);
        border: 1px solid var(--color-border, #d8d4c2);
        border-radius: 999px; padding: 4px 14px;
        max-width: 360px; flex: 1 1 320px;
        transition: border-color 120ms ease, box-shadow 120ms ease, background 120ms ease;
    }
    .topbar-search:focus-within { background: #fff; border-color: var(--color-navy, #0f1f3d); box-shadow: 0 0 0 3px rgba(15,31,61,0.10); }
    .topbar-search__icon { color: var(--color-text-soft, #5a5a6e); font-size: 14px; }
    .topbar-search input[type="search"] {
        flex: 1; border: 0; background: transparent; outline: 0;
        font: inherit; font-size: 14px; padding: 6px 0; color: var(--color-text, #111);
    }
    .topbar-search input[type="search"]::placeholder { color: var(--color-text-soft, #5a5a6e); }
    @media (max-width: 800px) { .topbar-search { display: none; } }
</style>

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

    // Alert counts — management only; skip for owners/renters.
    $_alerts = [];
    if (isset($assocId) && role_can_manage(viewing_role())) {
        $_q = function(string $sql, array $params) { $s = db()->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); };
        $_aid = $assocId;

        $_pending_rules = $_q(
            "SELECT COUNT(*) FROM rule_suggestions WHERE association_id=? AND status='pending'",
            [$_aid]
        ) + $_q("SELECT COUNT(*) FROM rules WHERE association_id=? AND review_flag=1", [$_aid]);

        $_open_concerns = $_q(
            "SELECT COUNT(*) FROM concerns WHERE association_id=? AND status NOT IN ('closed','resolved')",
            [$_aid]
        );
        $_open_wo = $_q(
            "SELECT COUNT(*) FROM work_orders WHERE association_id=? AND status IN ('open','in_progress','blocked')",
            [$_aid]
        );
        $_pending_arc = $_q(
            "SELECT COUNT(*) FROM arc_requests WHERE association_id=? AND status IN ('submitted','under_review')",
            [$_aid]
        );
        $_open_vio = $_q(
            "SELECT COUNT(*) FROM violations WHERE association_id=? AND status NOT IN ('cured','closed')",
            [$_aid]
        );

        if ($_pending_rules) $_alerts[] = ['label' => 'Pending rule changes', 'count' => $_pending_rules, 'href' => '/dashboard/search.php'];
        if ($_open_concerns)  $_alerts[] = ['label' => 'Open concerns',        'count' => $_open_concerns,  'href' => '/dashboard/concerns.php'];
        if ($_open_wo)        $_alerts[] = ['label' => 'Open work orders',     'count' => $_open_wo,        'href' => '/dashboard/work-orders.php'];
        if ($_pending_arc)    $_alerts[] = ['label' => 'ARC requests',         'count' => $_pending_arc,    'href' => '/dashboard/arc.php'];
        if ($_open_vio)       $_alerts[] = ['label' => 'Open violations',      'count' => $_open_vio,       'href' => '/dashboard/violations.php'];
    }
    $_alertTotal = array_sum(array_column($_alerts, 'count'));
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
    <?php if ($page_layout === 'app'): ?>
    <form action="/dashboard/find.php" method="get" class="topbar-search" role="search">
        <span class="topbar-search__icon" aria-hidden="true">🔎</span>
        <input type="search" name="q" placeholder="Search everything…" autocomplete="off" minlength="2" required value="<?= e((string)($_GET['q'] ?? '')) ?>">
    </form>
    <?php endif; ?>

    <?php
    // Avatar for the topbar user cluster.
    $_tbAvatar = null;
    if (!empty($_SESSION['user_id'])) {
        $_chk2 = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $_chk2->execute([(int)$_SESSION['user_id']]);
        $_p2 = (string)($_chk2->fetchColumn() ?: '');
        if ($_p2 !== '') $_tbAvatar = '/user-avatar.php?id=' . (int)$_SESSION['user_id'] . '&v=' . substr(md5($_p2), 0, 8);
    }
    ?>

    <?php if ($_alerts): ?>
    <div class="topbar-alerts" id="topbar-alerts">
        <button class="topbar-alerts__btn" type="button" id="topbar-alerts-btn" aria-label="<?= (int)$_alertTotal ?> pending items" aria-expanded="false" aria-controls="topbar-alerts-dropdown">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="topbar-alerts__badge"><?= (int)$_alertTotal ?></span>
        </button>
        <div class="topbar-alerts__dropdown" id="topbar-alerts-dropdown" role="menu" hidden>
            <div class="topbar-alerts__head">Needs attention</div>
            <?php foreach ($_alerts as $_a): ?>
            <a class="topbar-alerts__item" href="<?= e($_a['href']) ?>" role="menuitem">
                <span class="topbar-alerts__item-label"><?= e($_a['label']) ?></span>
                <span class="topbar-alerts__item-count"><?= (int)$_a['count'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('topbar-alerts-btn');
        var drop = document.getElementById('topbar-alerts-dropdown');
        if (!btn || !drop) return;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var open = !drop.hidden;
            drop.hidden = open;
            btn.setAttribute('aria-expanded', String(!open));
        });
        document.addEventListener('click', function() { drop.hidden = true; btn.setAttribute('aria-expanded','false'); });
        drop.addEventListener('click', function(e) { e.stopPropagation(); });
    })();
    </script>
    <?php endif; ?>

    <div class="topbar-user">
        <?php if (role_can_manage((string)($_SESSION['role'] ?? '')) && !is_viewing_as()): ?>
        <form class="topbar-view-as" method="post" action="/dashboard/view-as.php">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '/dashboard/')) ?>">
            <span class="topbar-view-as__label">View as</span>
            <button class="topbar-view-as__btn" type="submit" name="role" value="owner">Owner</button>
            <button class="topbar-view-as__btn" type="submit" name="role" value="renter">Renter</button>
        </form>
        <?php endif; ?>
        <a class="topbar-user__profile" href="/dashboard/profile.php" title="Edit profile">
            <span class="topbar-user__avatar">
                <?php if ($_tbAvatar): ?>
                    <img src="<?= e($_tbAvatar) ?>" alt="">
                <?php else: ?>
                    <?= e($userInitial) ?>
                <?php endif; ?>
            </span>
            <span class="topbar-user__name"><?= e(trim(explode(' ', (string)($_SESSION['name'] ?? ''))[0])) ?: e($userInitial) ?></span>
        </a>
        <a class="topbar-user__help" href="/dashboard/help.php" title="Help">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </a>
        <a class="topbar-user__signout" href="/logout.php" title="Sign out">
            <?= nav_icon('logout') ?>
            <span class="topbar-user__signout-label">Sign out</span>
        </a>
    </div>

</header>
<?php endif; ?>

<?php if ($page_layout === 'app'): ?>
<button type="button" class="side-nav__edge-toggle" id="side-nav-toggle" aria-label="Collapse sidebar" title="Collapse sidebar">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
</button>
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

            <?php
            // Tiny helper — emits an open/close-able group.
            // Groups default OPEN; user can collapse, state saved in localStorage.
            // The active page's group is always forced open regardless of saved state.
            $navGroup = function(string $id, string $label, string $content) use ($_groupForActive): void {
                $chevron = '<svg class="side-nav__group-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
                echo '<div class="side-nav__group" data-group="' . e($id) . '"' . ($id === $_groupForActive ? ' data-force-open' : '') . '>'
                   . '<button class="side-nav__group-toggle" type="button" aria-expanded="true">'
                   . '<span class="side-nav__group-label">' . e($label) . '</span>'
                   . $chevron
                   . '</button>'
                   . '<div class="side-nav__group-links">' . $content . '</div>'
                   . '</div>';
            };
            ob_start(); ?>
                <?= nav_link('/dashboard/',                   'home',           'Home',          'home',           $active) ?>
                <?= nav_link('/dashboard/communications.php', 'communications', 'Announcements', 'communications', $active) ?>
                <?= nav_link('/dashboard/events.php',         'committees',     'Events',        'events',         $active) ?>
                <?= nav_link('/dashboard/faq.php',         'rules',       'FAQ',         'faq',         $active) ?>
                <?= nav_link('/dashboard/marketplace.php', 'marketplace', 'Marketplace', 'marketplace', $active) ?>
            <?php $navGroup('community', 'Community', ob_get_clean()); ?>

            <?php ob_start(); ?>
                <?= nav_link('/dashboard/documents.php', 'documents', 'Documents', 'documents', $active) ?>
                <?= nav_link('/dashboard/forms.php',     'documents', 'Forms',     'forms',     $active) ?>
                <?= nav_link('/dashboard/search.php',    'rules',     'Rules & Bylaws', 'rules',     $active) ?>
                <?= nav_link('/dashboard/legal.php',     'legal',     'Legal',     'legal',     $active) ?>
                <?php if (can_do('read_minutes')): ?>
                    <?= nav_link('/dashboard/minutes.php', 'minutes', 'Minutes', 'minutes', $active) ?>
                <?php endif; ?>
                <?= nav_link('/dashboard/media.php',     'media',     'Media',     'media',     $active) ?>
                <?php if (can_do('read_full_directory')): ?>
                    <?= nav_link('/dashboard/directory.php', 'directory', 'Directory', 'directory', $active) ?>
                <?php endif; ?>
                <?php if (can_do('read_contacts')): ?>
                    <?= nav_link('/dashboard/contacts.php', 'contacts', 'Contacts', 'contacts', $active) ?>
                <?php endif; ?>
            <?php $navGroup('resources', 'Resources', ob_get_clean()); ?>

            <?php ob_start(); ?>
                <?php if (viewing_role() !== 'renter'): ?>
                    <?= nav_link('/dashboard/committees.php', 'committees', 'Committees', 'committees', $active) ?>
                <?php endif; ?>
                <?= nav_link('/dashboard/concerns.php', 'concerns', 'Feedback', 'concerns', $active) ?>
                <?php if (viewing_role() !== 'renter'): ?>
                    <?= nav_link('/dashboard/arc.php', 'documents', 'Arch. review', 'arc', $active) ?>
                <?php endif; ?>
                <?php if (role_can_manage(viewing_role()) || can_do('read_violations')): ?>
                    <?= nav_link('/dashboard/violations.php', 'violations', 'Violations', 'violations', $active) ?>
                <?php endif; ?>
                <?php if (role_can_manage(viewing_role()) || can_do('read_work_orders')): ?>
                    <?= nav_link('/dashboard/work-orders.php', 'concerns', 'Work orders', 'work-orders', $active) ?>
                <?php endif; ?>
                <?php if (!in_array(viewing_role(), ['renter', 'staff'], true)): ?>
                    <?= nav_link('/dashboard/voting.php', 'voting', 'Voting', 'voting', $active) ?>
                <?php endif; ?>
            <?php $navGroup('governance', 'Governance', ob_get_clean()); ?>

            <?php if (role_can_manage(viewing_role())): ?>
            <?php ob_start(); ?>
                <?= nav_link('/dashboard/units.php',     'units',     'Units',     'units',     $active) ?>
                <?= nav_link('/dashboard/parking.php',   'parking',   'Parking',   'parking',   $active) ?>
                <?= nav_link('/dashboard/employees.php', 'directory', 'Employees', 'employees', $active) ?>
                <?= nav_link('/dashboard/insurance.php', 'documents', 'Insurance', 'insurance', $active) ?>
            <?php $navGroup('operations', 'Operations', ob_get_clean()); ?>

            <?php ob_start(); ?>
                <?= nav_link('/dashboard/activity.php', 'activity', 'Activity', 'activity', $active) ?>
                <?= nav_link('/dashboard/settings.php',   'settings', 'Settings', 'settings', $active) ?>
                <?php if (!empty($association['subdomain'])): ?>
                <a class="side-nav__link" href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener" title="Open the public community landing in a new tab">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <span class="side-nav__label">Public site ↗</span>
                </a>
                <?php endif; ?>
            <?php $navGroup('configuration', 'Configuration', ob_get_clean()); ?>

            <?php else: ?>
            <?php if (viewing_role() !== 'renter'): ?>
            <div class="side-nav__group">
                <?= nav_link('/dashboard/settings.php', 'settings', 'Settings', 'settings', $active) ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        <?php else: /* admin */ ?>
            <?= nav_link('/admin/',                    'overview',       'Overview',       'overview',       $active) ?>
            <?= nav_link('/admin/associations.php',    'associations',   'Associations',   'associations',   $active) ?>
            <?= nav_link('/admin/users.php',           'directory',      'Users',          'users',          $active) ?>
            <?= nav_link('/admin/activity.php',        'activity',       'Activity',       'activity',       $active) ?>
            <?= nav_link('/admin/changelog.php',       'documents',      'Changelog',      'changelog',      $active) ?>
            <?= nav_link('/admin/legal.php',           'legal',          'Legal / Laws',   'legal',          $active) ?>
        <?php endif; ?>
        </div>

        <!-- Help link — always visible at the bottom of the nav -->
        <?php if ($page_layout === 'app'): ?>
        <a class="side-nav__link side-nav__help-link<?= ($active === 'help') ? ' active' : '' ?>"
           href="/dashboard/help.php" style="margin-top: auto; border-top: 1px solid var(--color-border); padding-top: var(--sp-3); margin-top: var(--sp-2);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span class="side-nav__label">Help</span>
        </a>
        <?php elseif ($page_layout === 'admin'): ?>
        <a class="side-nav__link" href="/logout.php" style="border-top: 1px solid rgba(255,255,255,0.12); padding-top: var(--sp-3); margin-top: var(--sp-2);">
            <?= nav_icon('logout') ?>
            <span class="side-nav__label">Sign out</span>
        </a>
        <?php endif; ?>

        <!-- Scroll-fade hint — fades in when links overflow the sidebar; click scrolls down -->
        <button type="button" class="side-nav__scroll-hint" id="side-nav-scroll-hint" aria-label="Scroll down for more">↓ more</button>

    </div>
</nav>
<div class="side-nav__overlay" id="side-nav-overlay"></div>
<?php if ($page_layout === 'app'): ?>
<script>
(function () {
    var STORE = 'bhoa_nav_groups_v2';
    var nav   = document.getElementById('side-nav');
    if (!nav) return;
    // saved = map of groupId → false (user collapsed it). Absent = open (default).
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(STORE) || '{}'); } catch (_) {}

    nav.querySelectorAll('.side-nav__group[data-group]').forEach(function (grp) {
        var id      = grp.dataset.group;
        var forced  = grp.hasAttribute('data-force-open');
        var toggle  = grp.querySelector('.side-nav__group-toggle');
        var links   = grp.querySelector('.side-nav__group-links');
        if (!toggle || !links) return;

        // Open unless user has explicitly collapsed it (and it's not the active group).
        var collapsed = !forced && saved[id] === false;
        if (collapsed) {
            grp.classList.add('side-nav__group--collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            var isCollapsed = grp.classList.toggle('side-nav__group--collapsed');
            toggle.setAttribute('aria-expanded', String(!isCollapsed));
            if (isCollapsed) { saved[id] = false; } else { delete saved[id]; }
            try { localStorage.setItem(STORE, JSON.stringify(saved)); } catch (_) {}
        });
    });

    // Scroll-fade hint: show/hide based on overflow; click scrolls the links panel down.
    var links = nav.querySelector('.side-nav__links');
    var hint  = document.getElementById('side-nav-scroll-hint');
    if (links && hint) {
        var update = function () {
            var atBottom = links.scrollTop + links.clientHeight >= links.scrollHeight - 8;
            hint.style.opacity = atBottom ? '0' : '1';
        };
        links.addEventListener('scroll', update, { passive: true });
        nav.addEventListener('click', function () { setTimeout(update, 250); });
        hint.addEventListener('click', function (e) {
            e.stopPropagation();
            links.scrollBy({ top: 120, behavior: 'smooth' });
        });
        update();
    }
})();
</script>
<?php endif; ?>
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

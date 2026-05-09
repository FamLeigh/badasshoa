<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$page_title  = $page_title  ?? 'BadassHOA — Run Your Condo Like a Boss';
$page_class  = $page_class  ?? 'page-public';
$page_layout = $page_layout ?? 'public'; // 'public' | 'app'
$user        = current_user();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230f1f3d'/%3E%3Cpath d='M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z' fill='%23f05a28'/%3E%3C/svg%3E">
</head>
<body class="<?= e($page_class) ?>" data-layout="<?= e($page_layout) ?>">

<?php if ($page_layout === 'public'): ?>
<header class="site-nav">
    <div class="container site-nav__inner">
        <a class="brand" href="/">
            <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true">
                <rect width="32" height="32" rx="7" fill="var(--color-navy)"/>
                <path d="M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z" fill="var(--color-orange)"/>
            </svg>
            <span class="brand__word">BadassHOA</span>
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
<?php elseif ($page_layout === 'app'): ?>
<header class="app-nav">
    <div class="app-nav__inner">
        <a class="brand brand--small" href="/dashboard/">
            <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true">
                <rect width="32" height="32" rx="7" fill="var(--color-navy)"/>
                <path d="M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z" fill="var(--color-orange)"/>
            </svg>
            <span class="brand__word">BadassHOA</span>
        </a>
        <button class="nav-toggle nav-toggle--app" type="button" aria-label="Toggle menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="app-nav__links" id="app-nav-links">
            <a href="/dashboard/">Home</a>
            <a href="/dashboard/documents.php">Documents</a>
            <a href="/dashboard/search.php">Rules</a>
            <a href="/dashboard/directory.php">Directory</a>
            <a href="/dashboard/communications.php">Communications</a>
            <a href="/dashboard/media.php">Media</a>
            <a href="/dashboard/settings.php">Settings</a>
            <span class="app-nav__user">
                <?= e($_SESSION['name'] ?? 'Account') ?>
                <a class="app-nav__signout" href="/logout.php">Sign out</a>
            </span>
        </nav>
    </div>
</header>
<?php elseif ($page_layout === 'admin'): ?>
<header class="app-nav">
    <div class="app-nav__inner">
        <a class="brand brand--small" href="/admin/">
            <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true">
                <rect width="32" height="32" rx="7" fill="var(--color-navy)"/>
                <path d="M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z" fill="var(--color-orange)"/>
            </svg>
            <span class="brand__word">BadassHOA <span class="badge badge--orange" style="margin-left: var(--sp-2);">Admin</span></span>
        </a>
        <button class="nav-toggle nav-toggle--app" type="button" aria-label="Toggle menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="app-nav__links" id="app-nav-links">
            <a href="/admin/">Overview</a>
            <a href="/admin/associations.php">Associations</a>
            <a href="/admin/users.php">Users</a>
            <span class="app-nav__user">
                <?= e($_SESSION['name'] ?? 'Account') ?>
                <a class="app-nav__signout" href="/logout.php">Sign out</a>
            </span>
        </nav>
    </div>
</header>
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

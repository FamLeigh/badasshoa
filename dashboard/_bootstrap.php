<?php
// Shared bootstrap for every dashboard page. Enforces auth + tenant context.
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Super admins belong in /admin/, not the tenant dashboard.
if (($_SESSION['role'] ?? '') === 'super_admin') {
    redirect('/admin/');
}

// Every dashboard page is tenant-scoped.
$assocId = (int)($_SESSION['association_id'] ?? 0);
if ($assocId === 0) {
    flash('error', 'Your account is not linked to an association yet.');
    redirect('/login.php');
}

// Load association once for all pages.
$assocStmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
$assocStmt->execute([$assocId]);
$association = $assocStmt->fetch();
if (!$association) {
    logout_user();
    redirect('/login.php');
}

$page_layout = 'app';

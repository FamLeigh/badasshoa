<?php
// Stop super-admin impersonation and restore the original super-admin session.
// CANNOT use admin/_bootstrap.php here — the active session role is the
// impersonated user's, not super_admin. Our gate is `real_user_id` being set,
// PLUS that real user actually being an active super_admin in the DB.
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/');
csrf_check();

$realId = (int)($_SESSION['real_user_id'] ?? 0);
if ($realId === 0) {
    flash('error', 'You are not currently impersonating anyone.');
    redirect('/');
}

// Audit the stop BEFORE swapping back. The actor (current $_SESSION['user_id'])
// is the impersonated user; audit() will auto-tag with impersonated_by, so the
// trail back to the real super admin is preserved on the impersonated user's
// activity feed.
audit('impersonate.stop', ['returning_to_user_id' => $realId]);

// Look up the real super admin and verify they're still allowed to be one.
$stmt = db()->prepare(
    'SELECT id, first_name, last_name, email, role, association_id, status
       FROM users WHERE id = ? AND role = "super_admin" LIMIT 1'
);
$stmt->execute([$realId]);
$real = $stmt->fetch();

if (!$real || $real['status'] !== 'active') {
    // Their account was demoted/deactivated mid-session — boot fully.
    logout_user();
    flash('error', 'Your super-admin account is no longer active. Please sign in again.');
    redirect('/login.php');
}

// Restore session.
session_regenerate_id(true);
$_SESSION['user_id']        = (int)$real['id'];
$_SESSION['association_id'] = $real['association_id'] !== null ? (int)$real['association_id'] : null;
$_SESSION['role']           = $real['role'];
$_SESSION['email']          = $real['email'];
$_SESSION['name']           = trim(($real['first_name'] ?? '') . ' ' . ($real['last_name'] ?? ''));
unset($_SESSION['real_user_id'], $_SESSION['real_user_name'], $_SESSION['real_user_email']);

flash('success', 'Returned to your admin session.');
redirect('/admin/users.php');

<?php
// Super-admin impersonation: temporarily swap the session to another user
// so you can see the app the way they see it (training, support, debugging).
// "Return to admin" lives in /admin/return-to-admin.php and is reachable from
// the banner that shows on every page during impersonation.
require __DIR__ . '/_bootstrap.php';   // require_role('super_admin')

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/users.php');
csrf_check();

$targetId = (int)($_POST['user_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT id, first_name, last_name, email, role, association_id, status
       FROM users WHERE id = ?'
);
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'User not found.');
    redirect('/admin/users.php');
}
if ($target['role'] === 'super_admin') {
    flash('error', "Can't sign in as another super admin — too easy to lose track of who's actually doing what.");
    redirect('/admin/users.php');
}
if ($target['status'] === 'inactive') {
    flash('error', "Can't sign in as an inactive user. Reactivate them first.");
    redirect('/admin/users.php');
}

// Capture real super admin's session breadcrumbs BEFORE swapping.
$realId    = (int)$_SESSION['user_id'];
$realName  = (string)($_SESSION['name'] ?? 'Super admin');
$realEmail = (string)($_SESSION['email'] ?? '');

// Audit the start BEFORE swapping so actor=super admin (no impersonated_by tag yet).
audit('impersonate.start',
    [
        'target_user_id' => (int)$target['id'],
        'target_email'   => $target['email'],
        'target_role'    => $target['role'],
    ],
    (int)$target['id'], 'user'
);

// Swap session to target user. Regenerate id to prevent fixation.
session_regenerate_id(true);
$_SESSION['user_id']         = (int)$target['id'];
$_SESSION['association_id']  = $target['association_id'] !== null ? (int)$target['association_id'] : null;
$_SESSION['role']            = $target['role'];
$_SESSION['email']           = $target['email'];
$_SESSION['name']            = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
// These are the breadcrumbs the banner uses + audit() uses to tag actions.
$_SESSION['real_user_id']    = $realId;
$_SESSION['real_user_name']  = $realName;
$_SESSION['real_user_email'] = $realEmail;

flash('success', 'Now signed in as ' . $_SESSION['name'] . '. Click "Return to admin" in the orange banner when done.');
redirect(landing_for($target['role']));

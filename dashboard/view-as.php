<?php
// "View as" lens — let a manager (PM, board_member, board_admin) preview the
// app the way a resident or renter sees it. NOT a privilege change: this only
// flips a session flag that UI/permission helpers read via viewing_role(); the
// underlying $_SESSION['role'] is untouched.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_management();   // server-side gate uses the REAL session role

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/dashboard/');
csrf_check();

$role = (string)($_POST['role'] ?? '');

if ($role === '' || $role === 'exit') {
    if (!empty($_SESSION['view_as_role'])) {
        audit('view_as.exited', ['previously' => (string)$_SESSION['view_as_role']]);
        unset($_SESSION['view_as_role']);
    }
    flash('success', 'Returned to your normal view.');
} elseif (in_array($role, ['resident', 'renter'], true)) {
    $_SESSION['view_as_role'] = $role;
    audit('view_as.started', ['as' => $role]);
    flash('success', 'Now viewing as ' . ($role === 'resident' ? 'a homeowner' : 'a renter') . '. Click "Exit view-as" in the banner when done.');
} else {
    flash('error', 'Unknown view-as role.');
}

// Bounce back to where they came from when possible.
$back = (string)($_POST['back'] ?? '/dashboard/');
if (!preg_match('#^/[\w\-/.?=&]*$#', $back)) $back = '/dashboard/';
redirect($back);

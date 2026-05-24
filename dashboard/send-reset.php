<?php
// POST-only handler: admin sends a password-reset link to any member with a real email.
// Called from directory.php edit view.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
if (!role_can_manage(viewing_role())) { http_response_code(403); die('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('Method not allowed'); }
csrf_check();

$admin    = current_user();
$userId   = (int)($_POST['user_id'] ?? 0);
$redirect = (string)($_POST['redirect'] ?? '/dashboard/directory.php');

// Whitelist redirect targets to prevent open redirect.
$redirectBase = strtok($redirect, '?');
$allowed = ['/dashboard/activity.php', '/dashboard/directory.php'];
if (!in_array($redirectBase, $allowed, true)) {
    $redirect = '/dashboard/directory.php';
}

if (!$userId) { flash('error', 'Invalid member.'); redirect($redirect); }

$stmt = db()->prepare(
    'SELECT id, first_name, last_name, email FROM users
      WHERE id = ? AND association_id = ? AND status = "active"'
);
$stmt->execute([$userId, $assocId]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'Member not found.');
    redirect($redirect);
}
if (is_placeholder_email((string)$target['email'])) {
    flash('error', trim($target['first_name'] . ' ' . $target['last_name']) . ' has no email address on file — add one first.');
    redirect($redirect);
}

// Invalidate any outstanding unused tokens for this user.
db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
    ->execute([$userId]);

$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
db()->prepare(
    'INSERT INTO password_resets (user_id, token_hash, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
)->execute([$userId, $tokenHash]);

$scheme     = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'badasshoa.com';
$resetUrl   = "$scheme://$host/reset.php?token=$rawToken";
$memberName = trim($target['first_name'] . ' ' . $target['last_name']) ?: (string)$target['email'];
$adminName  = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));

send_mail(
    (string)$target['email'],
    'Reset your BadassHOA password',
    "Hi {$target['first_name']},\n\n"
    . ($adminName ? "$adminName from {$association['name']}" : $association['name'])
    . " has sent you a link to reset your BadassHOA password. The link expires in 1 hour.\n\n"
    . "Reset your password:\n$resetUrl\n\n"
    . "If you didn't request this, you can ignore this email — your password won't change."
);

audit('user.password_reset_sent', ['email' => $target['email'], 'sent_by' => $admin['id']], $userId, 'user');
flash('success', "Password reset link sent to $memberName.");
redirect($redirect);

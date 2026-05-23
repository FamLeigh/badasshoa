<?php
// Public unsubscribe endpoint — no login required.
// Handles both one-click POST (mail clients) and GET (link in email footer).
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$uid   = (int)($_REQUEST['uid']   ?? 0);
$token = (string)($_REQUEST['token'] ?? '');
$bid   = (int)($_REQUEST['bid']   ?? 0);

// Look up the user (no session needed — token is the credential)
$stmt = db()->prepare('SELECT id, email, first_name, email_broadcast_opt_out FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$valid = $user && $token && verify_unsub_token((int)$user['id'], (string)$user['email'], $token);

if (!$valid) {
    http_response_code(400);
    $page_title = 'Invalid link';
    require __DIR__ . '/includes/header-public.php';
    echo '<div class="container" style="padding:var(--sp-12) var(--sp-6); text-align:center;"><p>This unsubscribe link is invalid or has expired.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// One-click POST (RFC 8058 / Gmail/Yahoo requirement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    db()->prepare('UPDATE users SET email_broadcast_opt_out = 1 WHERE id = ?')->execute([$uid]);
    http_response_code(200);
    exit;
}

// GET: show confirmation form
$alreadyOut = (bool)$user['email_broadcast_opt_out'];
$confirmed  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_unsub'])) {
    db()->prepare('UPDATE users SET email_broadcast_opt_out = 1 WHERE id = ?')->execute([$uid]);
    $confirmed = true;
}

$page_title = 'Unsubscribe';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($page_title) ?> — BadassHOA</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { background: var(--color-bg); }
        .unsub-card { max-width: 480px; margin: var(--sp-16) auto; padding: 0 var(--sp-4); }
    </style>
</head>
<body>
<div class="unsub-card">
    <div class="card card--padded" style="text-align:center;">
        <p style="font-size:2rem; margin:0 0 var(--sp-3);">✉️</p>
        <h1 style="font-size:var(--fs-xl); margin:0 0 var(--sp-2);">Unsubscribe</h1>

        <?php if ($confirmed || $alreadyOut): ?>
            <p style="color:var(--color-success);">
                <strong><?= $confirmed ? 'You\'ve been unsubscribed.' : 'You\'re already unsubscribed.' ?></strong>
            </p>
            <p class="muted" style="font-size:var(--fs-sm);">
                You won't receive optional broadcast emails from this community.
                Required notices (legal, dues, emergencies) may still be sent.
            </p>
        <?php else: ?>
            <p class="muted" style="font-size:var(--fs-sm);">
                You'll stop receiving optional broadcast emails for this community.
                Required notices (legal, dues, emergencies) will still be delivered.
            </p>
            <form method="post" style="margin-top:var(--sp-4);">
                <input type="hidden" name="confirm_unsub" value="1">
                <button class="btn btn--primary" type="submit">Confirm unsubscribe</button>
            </form>
        <?php endif; ?>

        <p style="margin-top:var(--sp-5); font-size:var(--fs-xs); color:var(--color-text-muted);">
            <a href="https://badasshoa.com" style="color:var(--color-text-muted);">BadassHOA</a>
        </p>
    </div>
</div>
</body>
</html>

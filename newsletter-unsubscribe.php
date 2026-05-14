<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$token = trim((string)($_GET['token'] ?? ''));
$done  = false;
$error = false;

if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM newsletter_subscribers WHERE token=?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        if ($row['status'] === 'active') {
            db()->prepare("UPDATE newsletter_subscribers SET status='unsubscribed', unsubscribed_at=NOW() WHERE id=?")
                ->execute([(int)$row['id']]);
        }
        $done = true;
    } else {
        $error = true;
    }
} else {
    $error = true;
}

$page_title  = 'Unsubscribe — BadassHOA';
$page_layout = 'plain';
require_once __DIR__ . '/includes/header.php';
?>
<div style="min-height: 60vh; display:flex; align-items:center; justify-content:center; padding: var(--sp-8);">
    <div class="card" style="max-width: 480px; width:100%; text-align:center;">
        <?php if ($done): ?>
            <div style="font-size: 2.5rem; margin-bottom: var(--sp-3);">✅</div>
            <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-3);">You're unsubscribed</h1>
            <p class="muted">You'll no longer receive community newsletter updates from this association.</p>
        <?php elseif ($error): ?>
            <div style="font-size: 2.5rem; margin-bottom: var(--sp-3);">🔗</div>
            <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-3);">Link not found</h1>
            <p class="muted">This unsubscribe link may have already been used or is invalid.</p>
        <?php else: ?>
            <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-3);">Unsubscribe</h1>
            <p class="muted">Provide your unsubscribe token.</p>
        <?php endif; ?>
        <a href="/" class="btn btn--primary" style="margin-top: var(--sp-5);">Back to BadassHOA</a>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

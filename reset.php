<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Token comes from the email link. Hash it and look up.
$rawToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$rawToken = preg_match('/^[a-f0-9]{64}$/', $rawToken) ? $rawToken : '';
$tokenHash = $rawToken !== '' ? hash('sha256', $rawToken) : '';

$record = null;
$user   = null;
$tokenError = null;

if ($tokenHash !== '') {
    // Let MySQL be the source of truth for "now" so we don't trip on PHP/MySQL TZ drift.
    $stmt = db()->prepare(
        'SELECT pr.id AS pr_id, pr.user_id,
                (pr.used_at IS NOT NULL) AS is_used,
                (NOW() > pr.expires_at) AS is_expired,
                u.id, u.first_name, u.email, u.status
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch();
}

if (!$record) {
    $tokenError = 'This reset link is invalid. Request a new one.';
} elseif ((int)$record['is_used'] === 1) {
    $tokenError = 'This reset link has already been used. Request a new one.';
} elseif ((int)$record['is_expired'] === 1) {
    $tokenError = 'This reset link has expired. Request a new one.';
} elseif (($record['status'] ?? '') === 'inactive') {
    $tokenError = 'This account is inactive. Contact your association admin.';
}

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenError) {
    csrf_check();
    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');

    if (strlen($pw1) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($pw1 !== $pw2) {
        $errors[] = 'Passwords don&rsquo;t match.';
    }

    if (!$errors) {
        $newHash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->beginTransaction();
        try {
            // Update password. If the user was 'pending' (invited but never
            // signed in), promote to 'active' now that they've proven ownership
            // of the email by clicking the link and chosen a password.
            db()->prepare(
                "UPDATE users
                    SET password_hash = ?,
                        status = IF(status = 'pending', 'active', status)
                  WHERE id = ?"
            )->execute([$newHash, (int)$record['user_id']]);
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([(int)$record['pr_id']]);
            // Best-effort: also clear any other outstanding tokens for this user.
            db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL AND id <> ?')
                ->execute([(int)$record['user_id'], (int)$record['pr_id']]);
            db()->commit();

            audit('password_reset.completed', [], (int)$record['user_id'], 'user');
            $done = true;
        } catch (Throwable $e) {
            db()->rollBack();
            $errors[] = 'Something went wrong. Please request a new reset link.';
        }
    }
}

$page_title = 'Reset password — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-shell">
    <div class="auth-card">
        <h1>Set a new password</h1>

        <?php if ($tokenError): ?>
            <div class="flash flash--error"><?= e($tokenError) ?></div>
            <div style="margin-top: var(--sp-4);">
                <a class="btn btn--primary btn--block" href="/forgot.php">Request a new link</a>
            </div>
        <?php elseif ($done): ?>
            <div class="flash flash--success">Your password has been updated. You can sign in now.</div>
            <div style="margin-top: var(--sp-4);">
                <a class="btn btn--primary btn--block" href="/login.php">Sign in</a>
            </div>
        <?php else: ?>
            <p class="muted">For <strong><?= e((string)$record['email']) ?></strong>. Choose at least 8 characters.</p>

            <?php foreach ($errors as $err): ?>
                <div class="flash flash--error"><?= $err ?></div>
            <?php endforeach; ?>

            <form class="form" method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($rawToken) ?>">
                <div class="field">
                    <label class="field__label" for="password">New password</label>
                    <input class="input" type="password" id="password" name="password" required autocomplete="new-password" minlength="8" autofocus>
                </div>
                <div class="field">
                    <label class="field__label" for="password_confirm">Confirm new password</label>
                    <input class="input" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" minlength="8">
                </div>
                <button type="submit" class="btn btn--primary btn--lg btn--block">Update password</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

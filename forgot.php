<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$email = trim((string)($_POST['email'] ?? ''));
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$rateLimited = false;

if ($submitted) {
    csrf_check();

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // IP rate limit: 5 reset requests per IP per hour.
    $rateCheck = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
          WHERE kind = 'password_reset' AND ip_address = ?
            AND attempted_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $rateCheck->execute([$ip]);
    if ((int)$rateCheck->fetchColumn() >= 5) {
        $rateLimited = true;
    }

    if (!$rateLimited) {
        db()->prepare("INSERT INTO login_attempts (email, kind, ip_address, succeeded) VALUES (?, 'password_reset', ?, 1)")
            ->execute([$email ?: 'unknown', $ip]);
    }

    if (!$rateLimited && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Look up user — but always behave the same way externally (anti-enumeration).
        $stmt = db()->prepare('SELECT id, first_name, email, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] !== 'inactive') {
            // Invalidate any prior unused tokens for this user.
            db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
                ->execute([(int)$user['id']]);

            // 32-byte random token; store the SHA-256 hash, send the raw token via email.
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            db()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, (NOW() + INTERVAL 1 HOUR))'
            )->execute([(int)$user['id'], $tokenHash]);

            $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'badasshoa.com';
            $resetUrl = "$scheme://$host/reset.php?token=$rawToken";

            send_mail(
                $user['email'],
                'Reset your BadassHOA password',
                "Hi " . ($user['first_name'] ?: 'there') . ",\n\n"
                . "Someone (hopefully you) asked to reset the password for your BadassHOA account.\n"
                . "If that was you, set a new password here — link expires in 1 hour:\n\n"
                . $resetUrl . "\n\n"
                . "If it wasn't you, you can safely ignore this email — your password is unchanged.\n\n"
                . "— BadassHOA"
            );

            audit('password_reset.requested', ['email' => $email], (int)$user['id'], 'user');
        }
    } // end !$rateLimited

    if ($rateLimited) {
        flash('error', 'Too many reset requests from your connection. Try again in an hour.');
        redirect('/forgot.php');
    }

    // Same response regardless of whether the email exists or is valid.
    flash('success', 'If we have an account for that email, we sent a reset link. Check your inbox in the next minute.');
    redirect('/forgot.php?sent=1');
}

$sent = isset($_GET['sent']);
$page_title = 'Forgot password — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-shell">
    <div class="auth-card">
        <h1>Forgot your password?</h1>
        <p class="muted">Enter your email and we&rsquo;ll send a reset link. Links expire after 1 hour.</p>

        <?php if (!$sent): ?>
        <form class="form" method="post" action="/forgot.php" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label class="field__label" for="email">Email</label>
                <input class="input" type="email" id="email" name="email" required autocomplete="email" autofocus value="<?= e($email) ?>">
            </div>
            <button type="submit" class="btn btn--primary btn--lg btn--block">Send reset link</button>
        </form>

        <div style="margin-top: var(--sp-6); display:flex; justify-content: space-between; font-size: var(--fs-sm);">
            <a href="/login.php">← Back to sign in</a>
            <span class="muted">No account? <a href="/signup.php">Sign up</a></span>
        </div>
        <?php else: ?>
            <div class="flash flash--success" style="margin-top: var(--sp-4);">
                If we have an account for that email, a reset link is on its way. Check your inbox &mdash; the link expires in 1 hour.
            </div>
            <div style="margin-top: var(--sp-4); font-size: var(--fs-sm);">
                <a href="/login.php">← Back to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

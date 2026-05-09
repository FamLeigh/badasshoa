<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Bounce to landing.
if (!empty($_SESSION['user_id'])) {
    redirect(landing_for($_SESSION['role'] ?? 'resident'));
}

$email  = $_POST['email']  ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } elseif (login_attempt_blocked($email, $ip)) {
        $errors[] = 'Too many failed attempts. Try again in 15 minutes.';
        record_login_attempt($email, $ip, false);
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            record_login_attempt($email, $ip, true);
            login_user($user);
            audit('user.login');
            redirect(landing_for($user['role']));
        } else {
            record_login_attempt($email, $ip, false);
            // Generic error — don't leak whether the email exists.
            $errors[] = 'Invalid email or password.';
        }
    }
}

$page_title = 'Sign in — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-shell">
    <div class="auth-card">
        <h1>Sign in</h1>
        <p class="muted">Welcome back. Sign in to your association.</p>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form class="form" method="post" action="/login.php" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label class="field__label" for="email">Email</label>
                <input class="input" type="email" id="email" name="email" required autocomplete="email" autofocus value="<?= e($email) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="password">Password</label>
                <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn--primary btn--lg btn--block">Sign in</button>
        </form>

        <div style="margin-top: var(--sp-6); display:flex; justify-content: space-between; font-size: var(--fs-sm);">
            <a href="/forgot.php">Forgot password?</a>
            <span class="muted">No account? <a href="/signup.php">Sign up</a></span>
        </div>

        <?php if ((config()['env'] ?? 'production') === 'local'): ?>
        <div class="muted" style="margin-top: var(--sp-6); font-size: var(--fs-xs); border-top: 1px solid var(--color-border); padding-top: var(--sp-4);">
            <strong>Demo logins (local only):</strong><br>
            admin@badasshoa.com / changeme!  →  super admin<br>
            board@demo.badasshoa.com / changeme!  →  demo board
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

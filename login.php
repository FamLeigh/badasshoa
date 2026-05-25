<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Bounce to landing.
if (!empty($_SESSION['user_id'])) {
    redirect(landing_for($_SESSION['role'] ?? 'owner'));
}

// One-time pre-fill from the invite acceptance flow — read and clear immediately.
$prefillEmail = '';
if (!empty($_SESSION['invite_prefill_email'])) {
    $prefillEmail = (string)$_SESSION['invite_prefill_email'];
    unset($_SESSION['invite_prefill_email']);
}

$email  = $_POST['email'] ?? $prefillEmail;
$errors = [];
$showWelcome = isset($_GET['welcome']) && $prefillEmail !== '';

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

// If the visitor came in through a custom domain (e.g. bellaircondos.com),
// look up the matching association and brand the login page with its logo
// + hero image. Falls back to the generic BadassHOA look on badasshoa.com
// itself, localhost, or any unknown host.
$brandAssoc = null;
$_reqHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$_reqHost = preg_replace('/^www\./', '', $_reqHost);
$_isBadasshoa = $_reqHost === 'badasshoa.com'
    || $_reqHost === 'localhost'
    || str_starts_with($_reqHost, '127.')
    || str_ends_with($_reqHost, '.badasshoa.com');
if (!$_isBadasshoa && $_reqHost !== '') {
    $bStmt = db()->prepare(
        'SELECT id, name, subdomain, logo_path, hero_image_path, primary_color
           FROM associations WHERE custom_domain = ? LIMIT 1'
    );
    $bStmt->execute([$_reqHost]);
    $brandAssoc = $bStmt->fetch() ?: null;
}

$page_title = $brandAssoc
    ? ('Sign in — ' . $brandAssoc['name'])
    : 'Sign in — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<?php if ($brandAssoc && !empty($brandAssoc['hero_image_path'])): ?>
<style>
    .auth-shell--branded {
        position: relative;
        min-height: calc(100vh - 60px);
        background: url('/branding.php?id=<?= (int)$brandAssoc['id'] ?>&kind=hero') center/cover no-repeat var(--color-navy);
        display: flex; align-items: center; justify-content: center;
        padding: var(--sp-6);
    }
    .auth-shell--branded::before {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(180deg, rgba(15,31,61,0.55) 0%, rgba(15,31,61,0.75) 100%);
        pointer-events: none;
    }
    .auth-shell--branded .auth-card { position: relative; z-index: 1; }
</style>
<?php endif; ?>

<section class="auth-shell <?= $brandAssoc && !empty($brandAssoc['hero_image_path']) ? 'auth-shell--branded' : '' ?>">
    <div class="auth-card">
        <?php if ($brandAssoc && !empty($brandAssoc['logo_path'])): ?>
            <a href="/" style="display:block; text-align:center; margin: 0 0 var(--sp-4); text-decoration: none;">
                <img src="/branding.php?id=<?= (int)$brandAssoc['id'] ?>&kind=logo"
                     alt="<?= e((string)$brandAssoc['name']) ?>"
                     style="display:inline-block; max-height: 80px; max-width: 100%;">
            </a>
        <?php endif; ?>

        <?php if ($showWelcome): ?>
        <div class="flash flash--success" style="margin-bottom: var(--sp-4);">Password set! Enter it below to get started.</div>
        <?php endif; ?>
        <h1><?= $brandAssoc ? 'Sign in to ' . e((string)$brandAssoc['name']) : 'Sign in' ?></h1>
        <p class="muted"><?= $brandAssoc
            ? 'Welcome back. Sign in to access your portal.'
            : 'Welcome back. Sign in to your association.' ?></p>

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

        <?php if ($brandAssoc): ?>
        <div style="margin-top: var(--sp-6); padding-top: var(--sp-4); border-top: 1px solid var(--color-border); text-align: center; font-size: var(--fs-xs);" class="muted">
            <a href="/" style="text-decoration: none;">← Back to <?= e((string)$brandAssoc['name']) ?></a>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

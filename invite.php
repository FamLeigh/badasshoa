<?php
// Public page: resident clicks their invite link, confirms unit number,
// sets a new password, then gets bounced to login with their email pre-filled.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Already logged in? No need to be here.
if (!empty($_SESSION['user_id'])) {
    redirect(landing_for($_SESSION['role'] ?? 'owner'));
}

$rawToken   = trim((string)($_GET['token'] ?? ''));
$flashError = null;
$inviteUser = null;

if ($rawToken !== '') {
    $tokenHash = hash('sha256', $rawToken);
    $stmt = db()->prepare(
        'SELECT u.*, a.name AS assoc_name
           FROM users u
           JOIN associations a ON a.id = u.association_id
          WHERE u.invite_token = ? AND u.invite_expires_at > NOW() AND u.status = "active"
          LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $inviteUser = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $postToken  = trim((string)($_POST['token'] ?? ''));
    $unitInput  = trim((string)($_POST['unit_number'] ?? ''));
    $pw1        = (string)($_POST['password'] ?? '');
    $pw2        = (string)($_POST['password_confirm'] ?? '');

    if ($postToken !== $rawToken || !$inviteUser) {
        $flashError = 'This invite link is invalid or has already been used.';
    } elseif ($unitInput === '' || strcasecmp(trim((string)$inviteUser['unit_number']), $unitInput) !== 0) {
        $flashError = 'Unit number doesn\'t match our records. Double-check and try again.';
    } elseif (strlen($pw1) < 8) {
        $flashError = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $flashError = 'Passwords don\'t match.';
    } else {
        $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare(
            'UPDATE users
                SET password_hash = ?, invite_token = NULL, invite_expires_at = NULL
              WHERE id = ?'
        )->execute([$hash, (int)$inviteUser['id']]);
        // Clear any outstanding password reset tokens too.
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int)$inviteUser['id']]);

        audit('user.invite_accepted', ['assoc_id' => $inviteUser['association_id']], (int)$inviteUser['id'], 'user');

        // One-time session value — login.php reads and clears it to pre-fill the email field.
        $_SESSION['invite_prefill_email'] = $inviteUser['email'];

        flash('success', 'You\'re all set! Sign in with your new password.');
        redirect('/login.php?welcome=1');
    }
}

$expired = ($rawToken !== '' && !$inviteUser);
$page_title = 'Join your community — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-shell">
    <div class="auth-card">

    <?php if ($expired): ?>
        <h1>Link expired</h1>
        <p class="muted">This invite link has expired or already been used.</p>
        <p style="margin-top: var(--sp-4);">Ask your board to send a new invitation, or use <a href="/forgot.php">forgot password</a> if you already have an account.</p>

    <?php elseif (!$inviteUser): ?>
        <h1>Invalid link</h1>
        <p class="muted">We couldn't find a valid invite for this link.</p>
        <p style="margin-top: var(--sp-4);">Check that you copied the full link from your email, or ask your board to resend the invitation.</p>

    <?php else: ?>
        <h1>Welcome to <?= e((string)$inviteUser['assoc_name']) ?></h1>
        <p class="muted">Confirm your unit number and choose a password to finish setting up your account.</p>

        <?php if ($flashError): ?>
            <div class="flash flash--error"><?= e($flashError) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="/invite.php?token=<?= urlencode($rawToken) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($rawToken) ?>">

            <div class="field">
                <label class="field__label" for="unit">Your unit number</label>
                <input class="input" type="text" id="unit" name="unit_number" required
                       autocomplete="off" placeholder="e.g. 101"
                       value="<?= e((string)($_POST['unit_number'] ?? '')) ?>"
                       style="font-size: var(--fs-lg); letter-spacing: 0.05em;">
                <div class="field__hint">Enter the unit number exactly as it appears on your door or mailbox.</div>
            </div>

            <div class="field">
                <label class="field__label" for="pw1">Choose a password</label>
                <input class="input" type="password" id="pw1" name="password" required
                       minlength="8" autocomplete="new-password"
                       placeholder="At least 8 characters">
            </div>

            <div class="field">
                <label class="field__label" for="pw2">Confirm password</label>
                <input class="input" type="password" id="pw2" name="password_confirm" required
                       minlength="8" autocomplete="new-password"
                       placeholder="Same password again">
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block">Set my password</button>
        </form>

        <p style="margin-top: var(--sp-4); font-size: var(--fs-sm); color: var(--color-text-muted); text-align: center;">
            Already have a password? <a href="/login.php">Sign in</a>
        </p>

    <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

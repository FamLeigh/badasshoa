<?php
// Self-service profile editor — any signed-in user can update their own
// name, phone, mailing address, headshot, and bio. Distinct from
// /dashboard/settings.php (which manages association-wide settings).
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    csrf_check();

    $first   = trim((string)($_POST['first_name'] ?? ''));
    $last    = trim((string)($_POST['last_name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $bio     = trim((string)($_POST['bio'] ?? ''));
    $mAddr   = trim((string)($_POST['mailing_address'] ?? ''));
    $mCity   = trim((string)($_POST['mailing_city'] ?? ''));
    $mState  = trim((string)($_POST['mailing_state_region'] ?? ''));
    $mPostal = trim((string)($_POST['mailing_postal_code'] ?? ''));
    $mCtry   = strtoupper(trim((string)($_POST['mailing_country'] ?? '')));
    if ($mCtry !== '' && !preg_match('/^[A-Z]{2}$/', $mCtry)) $mCtry = '';
    $removeAvatar = isset($_POST['remove_avatar']);

    // --- Avatar upload (optional) ---
    $avatarPath = $user['avatar_path'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['avatar']['size'] > 4 * 1024 * 1024) {
            $flashError = 'Headshot must be 4 MB or smaller.';
        } else {
            $orig = $_FILES['avatar']['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
            if (!isset($allowed[$ext])) {
                $flashError = 'Headshot must be PNG, JPG, or WEBP.';
            } else {
                $newName = uuid_filename($orig);
                $relDir  = "uploads/$assocId/avatars";
                $absDir  = storage_path($relDir);
                ensure_dir($absDir);
                $relPath = "$relDir/$newName";
                $absPath = "$absDir/$newName";
                if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $absPath)) {
                    $flashError = 'Could not save the headshot. Try again.';
                } else {
                    // Best-effort: remove the previous avatar file so the storage
                    // dir doesn't accumulate orphans.
                    if (!empty($user['avatar_path'])) {
                        $prevAbs = storage_path((string)$user['avatar_path']);
                        if (is_file($prevAbs)) @unlink($prevAbs);
                    }
                    $avatarPath = $relPath;
                }
            }
        }
    } elseif ($removeAvatar && !empty($user['avatar_path'])) {
        $prevAbs = storage_path((string)$user['avatar_path']);
        if (is_file($prevAbs)) @unlink($prevAbs);
        $avatarPath = null;
    }

    if (!$flashError) {
        db()->prepare(
            'UPDATE users
                SET first_name = ?, last_name = ?, phone = ?, bio = ?, avatar_path = ?,
                    mailing_address = ?, mailing_city = ?, mailing_state_region = ?,
                    mailing_postal_code = ?, mailing_country = ?
              WHERE id = ?'
        )->execute([
            $first, $last, $phone ?: null, $bio ?: null, $avatarPath,
            $mAddr ?: null, $mCity ?: null, $mState ?: null, $mPostal ?: null, $mCtry ?: null,
            (int)$user['id'],
        ]);
        // Keep the session display name fresh.
        $_SESSION['name'] = trim($first . ' ' . $last);
        audit('profile.updated', ['has_avatar' => !empty($avatarPath), 'has_bio' => $bio !== '']);
        flash('success', 'Profile updated.');
        redirect('/dashboard/profile.php');
    }
}

// Refresh user data after possible save
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$me = $stmt->fetch();

$active = 'profile';
$page_title = 'My profile — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 800px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-2);">My profile</h1>
    <p class="muted" style="margin-bottom: var(--sp-6);">
        Your name, phone, mailing address, headshot, and a short bio. Anything you put here is visible to others in your association (and on the public landing if you're on the board and opted in to "show on public landing" on your account).
    </p>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="save">

        <!-- Headshot -->
        <div style="display:flex; gap: var(--sp-4); align-items: flex-start; margin-bottom: var(--sp-4);">
            <div>
                <?php if (!empty($me['avatar_path'])): ?>
                    <img src="/user-avatar.php?id=<?= (int)$me['id'] ?>&v=<?= e(substr(md5((string)$me['avatar_path']), 0, 8)) ?>"
                         alt="Your headshot"
                         style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 2px solid var(--color-border);">
                <?php else: ?>
                    <span class="side-nav__avatar" style="width: 96px; height: 96px; flex: 0 0 96px; font-size: var(--fs-2xl);"><?= e(strtoupper(mb_substr((string)$me['first_name'], 0, 1) ?: '?')) ?></span>
                <?php endif; ?>
            </div>
            <div style="flex: 1;">
                <label class="field__label" for="avatar">Headshot</label>
                <input class="input" type="file" id="avatar" name="avatar" accept="image/png,image/jpeg,image/webp">
                <div class="field__hint">PNG, JPG, or WEBP. 4 MB max. Square works best — auto-cropped to a circle on display.</div>
                <?php if (!empty($me['avatar_path'])): ?>
                <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-sm);">
                    <input type="checkbox" name="remove_avatar"> Remove current headshot
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="pf-first">First name</label>
                <input class="input" id="pf-first" name="first_name" value="<?= e((string)$me['first_name']) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="pf-last">Last name</label>
                <input class="input" id="pf-last" name="last_name" value="<?= e((string)$me['last_name']) ?>">
            </div>
        </div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="pf-email">Email</label>
                <input class="input" type="email" id="pf-email" value="<?= e((string)$me['email']) ?>" disabled>
                <div class="field__hint">Email changes are handled by an admin — ask the board.</div>
            </div>
            <div class="field">
                <label class="field__label" for="pf-phone">Phone</label>
                <input class="input" type="tel" id="pf-phone" name="phone" value="<?= e((string)($me['phone'] ?? '')) ?>">
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="pf-bio">About me</label>
            <textarea class="textarea" id="pf-bio" name="bio" rows="4" placeholder="A short bio. Shown to other members of your association — and on the public landing if you're on the board."><?= e((string)($me['bio'] ?? '')) ?></textarea>
            <div class="field__hint">Plain text. Keep it brief — a sentence or two works great.</div>
        </div>

        <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
            <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Mailing address (optional — only set this if your mail should go somewhere other than the unit)</legend>
            <div class="field">
                <label class="field__label" for="pf-maddr">Street address</label>
                <input class="input" id="pf-maddr" name="mailing_address" value="<?= e((string)($me['mailing_address'] ?? '')) ?>" placeholder="123 Main St">
            </div>
            <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                <div class="field"><label class="field__label" for="pf-mcity">City</label>
                    <input class="input" id="pf-mcity" name="mailing_city" value="<?= e((string)($me['mailing_city'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="pf-mstate">State / Province</label>
                    <input class="input" id="pf-mstate" name="mailing_state_region" list="us-ca-states" value="<?= e((string)($me['mailing_state_region'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="pf-mpostal">ZIP / Postal</label>
                    <input class="input" id="pf-mpostal" name="mailing_postal_code" value="<?= e((string)($me['mailing_postal_code'] ?? '')) ?>"></div>
            </div>
            <?= function_exists('us_ca_states_datalist') ? us_ca_states_datalist() : '' ?>
            <div class="field">
                <label class="field__label" for="pf-mctry">Country</label>
                <select class="select" id="pf-mctry" name="mailing_country" style="max-width: 280px;">
                    <option value="">— Same as unit —</option>
                    <?php $cur = (string)($me['mailing_country'] ?? '');
                    foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                        <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>

        <div class="row" style="justify-content: flex-end;">
            <button class="btn btn--primary" type="submit">Save profile</button>
        </div>
    </form>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

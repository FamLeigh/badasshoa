<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canEdit = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_admin'];
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); die('Forbidden'); }
    $name        = trim((string)($_POST['name'] ?? ''));
    $address     = trim((string)($_POST['address'] ?? ''));
    $units       = (int)($_POST['unit_count'] ?? 0);
    $primary     = trim((string)($_POST['primary_color'] ?? '#0f1f3d'));
    if (!preg_match('/^#[0-9a-f]{6}$/i', $primary)) $primary = '#0f1f3d';

    if ($name === '') {
        $flashError = 'Association name is required.';
    } else {
        db()->prepare('UPDATE associations SET name = ?, address = ?, unit_count = ?, primary_color = ? WHERE id = ?')
            ->execute([$name, $address, $units, $primary, $assocId]);
        audit('association.updated', ['name' => $name]);
        flash('success', 'Settings saved.');
        redirect('/dashboard/settings.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'change_password') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new1    = (string)($_POST['new_password'] ?? '');
    $new2    = (string)($_POST['new_password_confirm'] ?? '');

    $row = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $row->execute([(int)$user['id']]);
    $hash = $row->fetchColumn();

    if (!$hash || !password_verify($current, $hash)) {
        $flashError = 'Current password is incorrect.';
    } elseif (strlen($new1) < 8) {
        $flashError = 'New password must be at least 8 characters.';
    } elseif ($new1 !== $new2) {
        $flashError = "New passwords don't match.";
    } else {
        $newHash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, (int)$user['id']]);
        // Invalidate any outstanding password reset tokens for this user.
        db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
            ->execute([(int)$user['id']]);
        audit('password.changed', [], (int)$user['id'], 'user');
        flash('success', 'Password updated.');
        redirect('/dashboard/settings.php');
    }
}

// reload after update or for fresh display
$assocStmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
$assocStmt->execute([$assocId]);
$association = $assocStmt->fetch();

$page_title = 'Settings — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container container--narrow" style="padding: var(--sp-8) var(--sp-6) var(--sp-12);">

    <h1 style="font-size: var(--fs-3xl); margin: 0;">Settings</h1>
    <p class="muted">Edit your association profile.</p>

    <?php if ($flashError): ?><div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($flashError) ?></div><?php endif; ?>

    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">Association profile</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="update">
            <fieldset style="border:0; padding:0; margin:0;" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field">
                    <label class="field__label" for="aname">Name</label>
                    <input class="input" id="aname" name="name" required value="<?= e($association['name']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="aaddr">Address</label>
                    <input class="input" id="aaddr" name="address" value="<?= e((string)$association['address']) ?>">
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="aunits">Unit count</label>
                        <input class="input" type="number" id="aunits" name="unit_count" min="0" value="<?= (int)$association['unit_count'] ?>">
                    </div>
                    <div class="field">
                        <label class="field__label" for="acolor">Primary color</label>
                        <input class="input" type="color" id="acolor" name="primary_color" value="<?= e((string)$association['primary_color']) ?>">
                    </div>
                </div>
                <?php if ($canEdit): ?>
                    <div class="row" style="justify-content: flex-end;">
                        <button class="btn btn--primary" type="submit">Save changes</button>
                    </div>
                <?php else: ?>
                    <p class="muted" style="font-size: var(--fs-sm);">Only board admins can edit settings.</p>
                <?php endif; ?>
            </fieldset>
        </form>
    </div>

    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">Change your password</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="change_password">
            <div class="field">
                <label class="field__label" for="cpw">Current password</label>
                <input class="input" type="password" id="cpw" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="npw">New password</label>
                    <input class="input" type="password" id="npw" name="new_password" required autocomplete="new-password" minlength="8">
                    <div class="field__hint">At least 8 characters.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="npw2">Confirm new password</label>
                    <input class="input" type="password" id="npw2" name="new_password_confirm" required autocomplete="new-password" minlength="8">
                </div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Update password</button>
            </div>
        </form>
    </div>

    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">Subscription</h3>
        <p>
            <strong>Plan:</strong> <?= e(ucfirst((string)$association['plan'])) ?>
            <?php if ($association['status'] === 'trial'): ?><span class="badge badge--warning">Trial</span><?php endif; ?>
        </p>
        <p class="muted" style="font-size: var(--fs-sm); margin: 0;">
            Plan upgrades and billing are coming in Phase 2. Email <a href="mailto:billing@badasshoa.com">billing@badasshoa.com</a> for changes.
        </p>
    </div>

    <div class="card card--padded" style="margin-top: var(--sp-6); border-color: var(--color-error); background: var(--color-error-bg);">
        <h3 class="card__title" style="color: var(--color-error);">Danger zone</h3>
        <p>Deactivating an association suspends all access. Only super admins can perform this action.</p>
        <button class="btn btn--danger" type="button" disabled aria-disabled="true">Deactivate association</button>
        <span class="muted" style="font-size: var(--fs-xs); margin-left: var(--sp-3);">Available to super admins only.</span>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

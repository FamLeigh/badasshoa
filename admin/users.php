<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    $uid = (int)($_POST['id'] ?? 0);
    $st  = $_POST['status'] ?? 'active';
    if (in_array($st, ['active','inactive','pending'], true)) {
        // Self-lockout guard
        if ($uid === (int)($_SESSION['user_id'] ?? 0) && $st !== 'active') {
            flash('error', "You can't change your own status.");
        } else {
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$st, $uid]);
            audit('user.status_changed_admin', ['status' => $st], $uid, 'user');
            flash('success', "User #$uid → $st");
        }
    }
    redirect('/admin/users.php');
}

// --- Full edit (super admin only) ---
$editError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_user') {
    csrf_check();
    $uid     = (int)($_POST['id'] ?? 0);
    $first   = trim((string)($_POST['first_name'] ?? ''));
    $last    = trim((string)($_POST['last_name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $role    = $_POST['role'] ?? 'resident';
    $status  = $_POST['status'] ?? 'active';
    $unit    = trim((string)($_POST['unit_number'] ?? ''));
    $assocId = ($_POST['association_id'] ?? '') === '' ? null : (int)$_POST['association_id'];
    $isOwner = isset($_POST['is_owner']) ? 1 : 0;

    $allowedRoles  = ['super_admin','board_admin','board_member','property_manager','resident','renter'];
    $allowedStatus = ['active','pending','inactive'];
    if (!in_array($role, $allowedRoles, true))   $role   = 'resident';
    if (!in_array($status, $allowedStatus, true)) $status = 'active';

    // super_admin role implies no association; everything else needs one
    if ($role === 'super_admin') $assocId = null;

    $check = db()->prepare('SELECT * FROM users WHERE id = ?');
    $check->execute([$uid]);
    $existing = $check->fetch();
    if (!$existing) {
        $editError = 'User not found.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $editError = 'Valid email required.';
    } else {
        $dupe = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $dupe->execute([$email, $uid]);
        if ($dupe->fetchColumn()) {
            $editError = 'Another user already has that email.';
        } elseif ($assocId !== null) {
            $a = db()->prepare('SELECT 1 FROM associations WHERE id = ?');
            $a->execute([$assocId]);
            if (!$a->fetchColumn()) {
                $editError = 'Association does not exist.';
            }
        }
        // Self-lockout guard: can't demote or deactivate yourself
        if (!$editError && $uid === (int)($_SESSION['user_id'] ?? 0)) {
            if ($role !== $existing['role'] || $status !== 'active') {
                $editError = "You can't change your own role or status. Ask another super admin.";
            }
        }
        if (!$editError) {
            // Optional password change (only if both fields provided and matched)
            $pw1 = (string)($_POST['new_password'] ?? '');
            $pw2 = (string)($_POST['new_password_confirm'] ?? '');
            $passwordChanged = false;
            if ($pw1 !== '' || $pw2 !== '') {
                if ($pw1 !== $pw2) {
                    $editError = 'New passwords don\'t match.';
                } elseif (strlen($pw1) < 8) {
                    $editError = 'New password must be at least 8 characters.';
                } else {
                    $passwordChanged = true;
                }
            }

            if (!$editError) {
                db()->beginTransaction();
                try {
                    db()->prepare(
                        'UPDATE users
                         SET first_name = ?, last_name = ?, email = ?, phone = ?,
                             role = ?, status = ?, unit_number = ?, association_id = ?, is_owner = ?
                         WHERE id = ?'
                    )->execute([
                        $first, $last, $email, $phone ?: null,
                        $role, $status, $unit ?: null, $assocId, $isOwner,
                        $uid,
                    ]);

                    if ($passwordChanged) {
                        $newHash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
                        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $uid]);
                        // Invalidate any outstanding reset tokens for this user
                        db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$uid]);
                    }

                    db()->commit();
                } catch (Throwable $e) {
                    db()->rollBack();
                    $editError = 'Update failed: ' . $e->getMessage();
                }

                if (!$editError) {
                    audit('user.edited_admin', ['email' => $email, 'role' => $role, 'status' => $status, 'association_id' => $assocId, 'password_changed' => $passwordChanged], $uid, 'user');
                    if ($passwordChanged) audit('user.password_changed_admin', [], $uid, 'user');
                    flash('success', $passwordChanged
                        ? "User \"$email\" updated. Password changed — share it with the user via a secure channel."
                        : "User \"$email\" updated.");
                    redirect('/admin/users.php');
                }
            }
        }
    }
}

$qSearch = trim((string)($_GET['q'] ?? ''));
$qAssoc  = (int)($_GET['association_id'] ?? 0);

$sql = 'SELECT u.*, a.name AS assoc_name
        FROM users u LEFT JOIN associations a ON a.id = u.association_id
        WHERE 1';
$params = [];
if ($qSearch !== '') {
    $sql .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = "%$qSearch%";
    array_push($params, $like, $like, $like);
}
if ($qAssoc) { $sql .= ' AND u.association_id = ?'; $params[] = $qAssoc; }
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$assocs = db()->query('SELECT id, name FROM associations ORDER BY name')->fetchAll();

// Edit target
$editUser = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$eid]);
    $editUser = $stmt->fetch() ?: null;
}

$page_title = 'Users — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">
    <h1 style="font-size: var(--fs-3xl); margin: 0;">Users</h1>
    <p class="muted">Across every association.</p>

    <?php if ($editError): ?><div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($editError) ?></div><?php endif; ?>

    <?php if ($editUser): ?>
    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="card__head">
            <h3 class="card__title">Edit user</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/users.php">← Back to list</a>
        </div>
        <?php if ((int)$editUser['id'] === (int)$_SESSION['user_id']): ?>
            <div class="flash flash--info" style="margin-bottom: var(--sp-4);">
                You're editing your own account. Role and status are locked to prevent self-lockout.
            </div>
        <?php endif; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit_user">
            <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu-first">First name</label>
                    <input class="input" id="eu-first" name="first_name" value="<?= e((string)$editUser['first_name']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="eu-last">Last name</label>
                    <input class="input" id="eu-last" name="last_name" value="<?= e((string)$editUser['last_name']) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu-email">Email</label>
                    <input class="input" type="email" id="eu-email" name="email" required value="<?= e((string)$editUser['email']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="eu-phone">Phone</label>
                    <input class="input" id="eu-phone" name="phone" value="<?= e((string)($editUser['phone'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu-assoc">Association</label>
                    <select class="select" id="eu-assoc" name="association_id">
                        <option value="">— None (super admin) —</option>
                        <?php foreach ($assocs as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= (int)$editUser['association_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e((string)$a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Set to "None" only for super admins.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="eu-unit">Unit #</label>
                    <input class="input" id="eu-unit" name="unit_number" value="<?= e((string)($editUser['unit_number'] ?? '')) ?>" placeholder="101A">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu-role">Role</label>
                    <?php $isSelf = (int)$editUser['id'] === (int)$_SESSION['user_id']; ?>
                    <select class="select" id="eu-role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach (['super_admin'=>'Super admin','board_admin'=>'Board admin','board_member'=>'Board member','property_manager'=>'Property manager','resident'=>'Resident','renter'=>'Renter'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $editUser['role'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e((string)$editUser['role']) ?>"><?php endif; ?>
                </div>
                <div class="field">
                    <label class="field__label" for="eu-status">Status</label>
                    <select class="select" id="eu-status" name="status" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach (['active'=>'Active','pending'=>'Pending','inactive'=>'Inactive'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $editUser['status'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?><input type="hidden" name="status" value="active"><?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="is_owner" <?= $editUser['is_owner'] ? 'checked' : '' ?>> Owner (uncheck for renter)
                </label>
            </div>

            <!-- Change-password section -->
            <div style="margin-top: var(--sp-4); padding: var(--sp-4); background: var(--color-warning-bg); border: 1px solid rgba(182,130,42,0.25); border-radius: var(--r-md);">
                <div class="row row--between" style="margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <strong style="color: var(--color-warning);">🔑 Change password (optional)</strong>
                    <button type="button" class="btn btn--ghost" id="eu-gen-pw" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Generate random</button>
                </div>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">
                    Leave blank to keep the existing password. If set, the new password is hashed (bcrypt cost 12) and any outstanding reset links for this user are invalidated.
                    <strong>You'll need to share the new password with the user yourself</strong> — it's never shown again after save.
                </p>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="eu-pw1">New password</label>
                        <input class="input" type="text" id="eu-pw1" name="new_password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field__label" for="eu-pw2">Confirm</label>
                        <input class="input" type="text" id="eu-pw2" name="new_password_confirm" minlength="8" autocomplete="new-password" spellcheck="false">
                    </div>
                </div>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/admin/users.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
        <script>
            document.getElementById('eu-gen-pw')?.addEventListener('click', function () {
                // 14-char password from a curated charset (no ambiguous I/l/O/0)
                var chars = 'abcdefghjkmnpqrstuvwxyz' + 'ABCDEFGHJKMNPQRSTUVWXYZ' + '23456789' + '!@#$%';
                var pw = '';
                if (window.crypto && window.crypto.getRandomValues) {
                    var bytes = new Uint8Array(14);
                    crypto.getRandomValues(bytes);
                    for (var i = 0; i < bytes.length; i++) pw += chars.charAt(bytes[i] % chars.length);
                } else {
                    for (var i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('eu-pw1').value = pw;
                document.getElementById('eu-pw2').value = pw;
            });
        </script>
    </div>
    <?php endif; ?>

    <form method="get" class="row" style="margin: var(--sp-6) 0 var(--sp-4);">
        <input class="input" type="search" name="q" placeholder="Search name or email" value="<?= e($qSearch) ?>" style="max-width: 320px;">
        <select class="select" name="association_id" style="max-width: 280px;">
            <option value="0">All associations</option>
            <?php foreach ($assocs as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $qAssoc===(int)$a['id']?'selected':'' ?>><?= e((string)$a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
    </form>

    <?php if (!$users): ?>
        <p class="muted">No users match.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Association</th><th>Role</th><th>Status</th><th>Last login</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e(trim($u['first_name'] . ' ' . $u['last_name']) ?: '—') ?></strong></td>
                <td><?= e((string)$u['email']) ?></td>
                <td><?= e((string)($u['assoc_name'] ?? '—')) ?></td>
                <td><?= e(str_replace('_',' ',(string)$u['role'])) ?></td>
                <td>
                    <?php $cls = $u['status']==='active' ? 'badge--success' : ($u['status']==='pending' ? 'badge--warning' : 'badge--error'); ?>
                    <span class="badge <?= $cls ?>"><?= e((string)$u['status']) ?></span>
                </td>
                <td><?= $u['last_login_at'] ? e(date('M j', strtotime((string)$u['last_login_at']))) : '<span class="muted">never</span>' ?></td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$u['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <select name="status" class="select" onchange="this.form.submit()" style="padding: 0.4rem 0.5rem; font-size: var(--fs-xs);">
                            <option value="active"   <?= $u['status']==='active'?'selected':'' ?>>active</option>
                            <option value="pending"  <?= $u['status']==='pending'?'selected':'' ?>>pending</option>
                            <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>>inactive</option>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

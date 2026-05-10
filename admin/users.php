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

// --- Send password reset on behalf of an existing user ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'send_reset') {
    csrf_check();
    $uid = (int)($_POST['id'] ?? 0);
    $sent = send_password_link($uid, 'reset');
    if ($sent) {
        flash('success', "Password reset link emailed (expires in 1 hour).");
    } else {
        flash('error', 'Could not send reset — user not found or marked inactive.');
    }
    redirect('/admin/users.php');
}

// --- Create user (super admin direct entry) ---
$createError = null;
$createDefaults = [
    'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
    'role' => 'resident', 'status' => 'active', 'unit_number' => '',
    'association_id' => '', 'is_owner' => 1, 'send_welcome' => 1,
];
// If linked from /admin/associations.php (Invite a user), prefill association.
if (($_GET['action'] ?? '') === 'new' && isset($_GET['association_id'])) {
    $createDefaults['association_id'] = (int)$_GET['association_id'];
    $createDefaults['role'] = 'board_admin';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_user') {
    csrf_check();
    $first   = trim((string)($_POST['first_name'] ?? ''));
    $last    = trim((string)($_POST['last_name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $role    = $_POST['role'] ?? 'resident';
    $status  = $_POST['status'] ?? 'active';
    $unit    = trim((string)($_POST['unit_number'] ?? ''));
    $assocIdRaw = $_POST['association_id'] ?? '';
    $assocId = ($assocIdRaw === '') ? null : (int)$assocIdRaw;
    $isOwner = isset($_POST['is_owner']) ? 1 : 0;
    $sendWelcome = isset($_POST['send_welcome']) ? 1 : 0;
    $pw1 = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password_confirm'] ?? '');

    $allowedRoles  = ['super_admin','board_admin','board_member','property_manager','resident','renter'];
    $allowedStatus = ['active','pending','inactive'];
    if (!in_array($role, $allowedRoles, true))   $role   = 'resident';
    if (!in_array($status, $allowedStatus, true)) $status = 'active';
    if ($role === 'super_admin') $assocId = null;

    // Blank password = "send invitation" mode: random placeholder password,
    // status=pending, user gets a setup link via email.
    $invite = ($pw1 === '' && $pw2 === '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createError = 'Valid email required.';
    } elseif ($role !== 'super_admin' && $assocId === null) {
        $createError = 'Pick an association (only super admins can have none).';
    } elseif (!$invite && $pw1 !== $pw2) {
        $createError = 'Passwords don\'t match.';
    } elseif (!$invite && strlen($pw1) < 8) {
        $createError = 'Password must be at least 8 characters.';
    } else {
        $dupe = db()->prepare('SELECT id FROM users WHERE email = ?');
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) {
            $createError = 'A user with that email already exists.';
        } elseif ($assocId !== null) {
            $a = db()->prepare('SELECT 1 FROM associations WHERE id = ?');
            $a->execute([$assocId]);
            if (!$a->fetchColumn()) {
                $createError = 'Association does not exist.';
            }
        }
        if (!$createError) {
            // Invite mode: random placeholder, force status=pending.
            // Direct mode:  use the admin-supplied password and the chosen status.
            $effectiveStatus = $invite ? 'pending' : $status;
            $hash = password_hash(
                $invite ? bin2hex(random_bytes(16)) : $pw1,
                PASSWORD_BCRYPT, ['cost' => 12]
            );
            db()->prepare(
                'INSERT INTO users
                 (association_id, first_name, last_name, email, phone, password_hash, role, status, unit_number, is_owner)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $assocId, $first, $last, $email, $phone ?: null, $hash, $role, $effectiveStatus, $unit ?: null, $isOwner,
            ]);
            $newId = (int)db()->lastInsertId();
            audit('user.created_admin', ['email' => $email, 'role' => $role, 'association_id' => $assocId, 'invite' => $invite], $newId, 'user');

            if ($invite) {
                send_password_link($newId, 'invite');
                flash('success', "Invited \"$email\". They'll get an email with a link to set their password (expires in 1 hour).");
            } elseif ($sendWelcome) {
                $name = trim($first) ?: 'there';
                // Look up association name (if any) so we can frame the email
                // as coming from the association rather than from BadassHOA generically.
                $assocName = '';
                if ($assocId) {
                    $aStmt = db()->prepare('SELECT name FROM associations WHERE id = ?');
                    $aStmt->execute([$assocId]);
                    $assocName = (string)($aStmt->fetchColumn() ?: '');
                }
                if ($assocName !== '') {
                    $subject = "Your {$assocName} portal account is ready";
                    $body    = "Hi $name,\n\n"
                             . "{$assocName} has added you to their HOA portal on BadassHOA.\n\n"
                             . "Sign in: https://badasshoa.com/login.php\nEmail: $email\nTemporary password: $pw1\n\n"
                             . "Please change your password after signing in (Settings → Change password).\n\n"
                             . "— {$assocName} (via BadassHOA)";
                } else {
                    $subject = 'Your BadassHOA account is ready';
                    $body    = "Hi $name,\n\nA BadassHOA account has been created for you.\n\nSign in: https://badasshoa.com/login.php\nEmail: $email\nTemporary password: $pw1\n\nPlease change your password after signing in (Settings → Change password).\n";
                }
                send_mail($email, $subject, $body);
                flash('success', "Created user \"$email\". Welcome email sent with the temporary password.");
            } else {
                flash('success', "Created user \"$email\". Share the password through a secure channel — it's not shown again.");
            }
            redirect('/admin/users.php?action=edit&id=' . $newId);
        }
    }
    // Preserve form values on error
    $createDefaults = [
        'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
        'role' => $role, 'status' => $status, 'unit_number' => $unit,
        'association_id' => $assocIdRaw === '' ? '' : (int)$assocIdRaw,
        'is_owner' => $isOwner, 'send_welcome' => $sendWelcome,
    ];
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
    $mAddr   = trim((string)($_POST['mailing_address'] ?? ''));
    $mCity   = trim((string)($_POST['mailing_city'] ?? ''));
    $mState  = trim((string)($_POST['mailing_state_region'] ?? ''));
    $mPostal = trim((string)($_POST['mailing_postal_code'] ?? ''));
    $mCtry   = strtoupper(trim((string)($_POST['mailing_country'] ?? '')));
    if ($mCtry !== '' && !preg_match('/^[A-Z]{2}$/', $mCtry)) $mCtry = '';
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
                             mailing_address = ?, mailing_city = ?, mailing_state_region = ?,
                             mailing_postal_code = ?, mailing_country = ?,
                             role = ?, status = ?, unit_number = ?, association_id = ?, is_owner = ?
                         WHERE id = ?'
                    )->execute([
                        $first, $last, $email, $phone ?: null,
                        $mAddr ?: null, $mCity ?: null, $mState ?: null, $mPostal ?: null, $mCtry ?: null,
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
$qRole   = trim((string)($_GET['role'] ?? ''));

$allowedRoleFilters = ['super_admin','board_admin','board_member','property_manager','resident','renter'];
if ($qRole !== '' && !in_array($qRole, $allowedRoleFilters, true)) $qRole = '';

$sql = 'SELECT u.*, a.name AS assoc_name
        FROM users u LEFT JOIN associations a ON a.id = u.association_id
        WHERE 1';
$params = [];
if ($qSearch !== '') {
    $sql .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = "%$qSearch%";
    array_push($params, $like, $like, $like);
}
if ($qAssoc)        { $sql .= ' AND u.association_id = ?'; $params[] = $qAssoc; }
if ($qRole !== '')  { $sql .= ' AND u.role = ?';           $params[] = $qRole; }
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$assocs = db()->query('SELECT id, name FROM associations ORDER BY name')->fetchAll();

// Show create form when ?action=new (or after a failed create POST)
$showCreate = ($_GET['action'] ?? '') === 'new' || $createError !== null;

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
    <div class="row row--between" style="align-items: flex-start; flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Users</h1>
            <p class="muted">Across every association.</p>
        </div>
        <?php if (!$showCreate && !$editUser): ?>
            <a class="btn btn--primary" href="?action=new">+ New user</a>
        <?php endif; ?>
    </div>

    <?php if ($editError):   ?><div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($editError) ?></div><?php endif; ?>
    <?php if ($createError): ?><div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($createError) ?></div><?php endif; ?>

    <?php if ($showCreate): ?>
    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="card__head">
            <h3 class="card__title">New user</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/users.php">← Back to list</a>
        </div>
        <p class="muted" style="margin-bottom: var(--sp-4); font-size: var(--fs-sm);">
            Direct super-admin creation. Use this for board admins, property managers, or to manually add residents.
            Set an initial password — the user can change it after signing in.
        </p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="create_user">

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="nu-first">First name</label>
                    <input class="input" id="nu-first" name="first_name" value="<?= e((string)$createDefaults['first_name']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="nu-last">Last name</label>
                    <input class="input" id="nu-last" name="last_name" value="<?= e((string)$createDefaults['last_name']) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="nu-email">Email</label>
                    <input class="input" type="email" id="nu-email" name="email" required value="<?= e((string)$createDefaults['email']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="nu-phone">Phone</label>
                    <input class="input" id="nu-phone" name="phone" value="<?= e((string)$createDefaults['phone']) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="nu-assoc">Association</label>
                    <select class="select" id="nu-assoc" name="association_id">
                        <option value="">— None (super admin) —</option>
                        <?php foreach ($assocs as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= (string)$createDefaults['association_id'] === (string)$a['id'] ? 'selected' : '' ?>><?= e((string)$a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Required unless the role is Super admin.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="nu-unit">Unit #</label>
                    <input class="input" id="nu-unit" name="unit_number" value="<?= e((string)$createDefaults['unit_number']) ?>" placeholder="101A">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="nu-role">Role</label>
                    <select class="select" id="nu-role" name="role">
                        <?php foreach (['board_admin'=>'Board admin','board_member'=>'Board member','property_manager'=>'Property manager','resident'=>'Resident','renter'=>'Renter','super_admin'=>'Super admin'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $createDefaults['role'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="nu-status">Status</label>
                    <select class="select" id="nu-status" name="status">
                        <?php foreach (['active'=>'Active','pending'=>'Pending','inactive'=>'Inactive'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $createDefaults['status'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="is_owner" value="1" <?= (int)$createDefaults['is_owner'] === 1 ? 'checked' : '' ?>> Owner (uncheck for renter)
                </label>
            </div>

            <!-- Password / invitation -->
            <div style="margin-top: var(--sp-4); padding: var(--sp-4); background: var(--color-warning-bg); border: 1px solid rgba(182,130,42,0.25); border-radius: var(--r-md);">
                <div class="row row--between" style="margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <strong style="color: var(--color-warning);">🔑 Password</strong>
                    <button type="button" class="btn btn--ghost" id="nu-gen-pw" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Generate random</button>
                </div>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">
                    <strong>Leave blank to send an invitation</strong> — the user will receive an email with a one-time link to set their own password (status starts as <code>pending</code>). Or set a password here and (optionally) email it to them as a temporary credential.
                </p>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="nu-pw1">Password</label>
                        <input class="input" type="text" id="nu-pw1" name="new_password" minlength="8" autocomplete="new-password" placeholder="Leave blank to invite" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field__label" for="nu-pw2">Confirm</label>
                        <input class="input" type="text" id="nu-pw2" name="new_password_confirm" minlength="8" autocomplete="new-password" spellcheck="false">
                    </div>
                </div>
                <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-3);">
                    <input type="checkbox" name="send_welcome" value="1" <?= (int)$createDefaults['send_welcome'] === 1 ? 'checked' : '' ?>>
                    <span>If a password is set above, also email it to the user as a welcome message <span class="muted">(ignored when blank — invitations always email the link)</span></span>
                </label>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/admin/users.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Create user</button>
            </div>
        </form>
        <script>
            document.getElementById('nu-gen-pw')?.addEventListener('click', function () {
                var chars = 'abcdefghjkmnpqrstuvwxyz' + 'ABCDEFGHJKMNPQRSTUVWXYZ' + '23456789' + '!@#$%';
                var pw = '';
                if (window.crypto && window.crypto.getRandomValues) {
                    var bytes = new Uint8Array(14);
                    crypto.getRandomValues(bytes);
                    for (var i = 0; i < bytes.length; i++) pw += chars.charAt(bytes[i] % chars.length);
                } else {
                    for (var i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('nu-pw1').value = pw;
                document.getElementById('nu-pw2').value = pw;
            });
        </script>
    </div>
    <?php endif; ?>

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

            <!-- Mailing address: optional. Leave blank if their HOA correspondence
                 should go to the unit address (the common case). Owners renting
                 their unit out fill this in so they get correspondence at home. -->
            <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Mailing address (optional — for absentee owners)</legend>
                <div class="field">
                    <label class="field__label" for="eu-maddr">Street address</label>
                    <input class="input" id="eu-maddr" name="mailing_address" value="<?= e((string)($editUser['mailing_address'] ?? '')) ?>" placeholder="123 Main St">
                </div>
                <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                    <div class="field">
                        <label class="field__label" for="eu-mcity">City</label>
                        <input class="input" id="eu-mcity" name="mailing_city" value="<?= e((string)($editUser['mailing_city'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label class="field__label" for="eu-mstate">State / Province</label>
                        <input class="input" id="eu-mstate" name="mailing_state_region" list="us-ca-states" value="<?= e((string)($editUser['mailing_state_region'] ?? '')) ?>" autocomplete="address-level1">
                    </div>
                    <div class="field">
                        <label class="field__label" for="eu-mpostal">ZIP / Postal</label>
                        <input class="input" id="eu-mpostal" name="mailing_postal_code" value="<?= e((string)($editUser['mailing_postal_code'] ?? '')) ?>" autocomplete="postal-code">
                    </div>
                </div>
                <?= function_exists('us_ca_states_datalist') ? us_ca_states_datalist() : '' ?>
                <div class="field">
                    <label class="field__label" for="eu-mctry">Country</label>
                    <select class="select" id="eu-mctry" name="mailing_country" style="max-width: 280px;">
                        <option value="">— Same as unit —</option>
                        <?php $cur = (string)($editUser['mailing_country'] ?? '');
                        foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                            <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

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

    <form method="get" class="row" style="margin: var(--sp-6) 0 var(--sp-4); gap: var(--sp-2); flex-wrap: wrap;">
        <input class="input" type="search" name="q" placeholder="Search name or email" value="<?= e($qSearch) ?>" style="max-width: 280px;">
        <select class="select" name="association_id" style="max-width: 240px;">
            <option value="0">All associations</option>
            <?php foreach ($assocs as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $qAssoc===(int)$a['id']?'selected':'' ?>><?= e((string)$a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="role" style="max-width: 200px;">
            <option value="">All roles</option>
            <option value="super_admin"      <?= $qRole==='super_admin'?'selected':'' ?>>BadassHOA admins</option>
            <option value="board_admin"      <?= $qRole==='board_admin'?'selected':'' ?>>Board admin</option>
            <option value="board_member"     <?= $qRole==='board_member'?'selected':'' ?>>Board member</option>
            <option value="property_manager" <?= $qRole==='property_manager'?'selected':'' ?>>Property manager</option>
            <option value="resident"         <?= $qRole==='resident'?'selected':'' ?>>Resident</option>
            <option value="renter"           <?= $qRole==='renter'?'selected':'' ?>>Renter</option>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
        <?php if ($qSearch !== '' || $qAssoc || $qRole !== ''): ?>
            <a class="btn btn--ghost" href="/admin/users.php">Clear</a>
        <?php endif; ?>
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
                    <?php if ($u['role'] !== 'super_admin' && $u['status'] !== 'inactive'): ?>
                    <form method="post" action="/admin/impersonate.php" style="display:inline;" onsubmit="return confirm('Sign in as <?= e((string)$u['email']) ?>? You\'ll see the app the way they do. An orange banner stays at the top until you click “Return to admin.”');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" title="Sign in as this user (training/support)">Log in as</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($u['status'] !== 'inactive'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Send a password reset email to <?= e((string)$u['email']) ?>? Their current password will keep working until they click the link and set a new one.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="send_reset">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" title="Email a password reset link">Reset</button>
                    </form>
                    <?php endif; ?>
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

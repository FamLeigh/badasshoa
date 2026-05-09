<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_admin'];
$flashError = null;

// --- Invite a new user ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'invite') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last  = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role  = $_POST['role'] ?? 'resident';
    $unit  = trim((string)($_POST['unit_number'] ?? ''));
    $isOwner = isset($_POST['is_owner']) ? 1 : 0;

    $allowedRoles = ['resident','renter','board_member','board_admin','property_manager'];
    if (!in_array($role, $allowedRoles, true)) $role = 'resident';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Valid email is required.';
    } else {
        $existing = db()->prepare('SELECT id FROM users WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetchColumn()) {
            $flashError = 'A user with that email already exists.';
        } else {
            // Generate a temp password; user resets via email link in real flow.
            $tempPass = bin2hex(random_bytes(6));
            $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = db()->prepare(
                'INSERT INTO users (association_id, first_name, last_name, email, password_hash, role, unit_number, is_owner, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")'
            );
            $stmt->execute([$assocId, $first, $last, $email, $hash, $role, $unit ?: null, $isOwner]);
            $newId = (int)db()->lastInsertId();
            send_mail($email, "You've been invited to {$association['name']}",
                "Hi $first,\n\n{$user['first_name']} added you to {$association['name']} on BadassHOA.\n\nSign in with email: $email\nTemporary password: $tempPass\n(Change it on first sign-in.)\n");
            audit('user.invited', ['email' => $email, 'role' => $role], $newId, 'user');
            flash('success', "Invited $email.");
            redirect('/dashboard/directory.php');
        }
    }
}

// --- Deactivate handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'deactivate') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id !== (int)$user['id']) { // can't deactivate self
        db()->prepare('UPDATE users SET status = "inactive" WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
        audit('user.deactivated', [], $id, 'user');
        flash('success', 'User deactivated.');
    }
    redirect('/dashboard/directory.php');
}

// Board members
$boardStmt = db()->prepare(
    "SELECT * FROM users
     WHERE association_id = ? AND role IN ('board_admin','board_member','property_manager') AND status='active'
     ORDER BY FIELD(role,'board_admin','board_member','property_manager'), last_name, first_name"
);
$boardStmt->execute([$assocId]);
$board = $boardStmt->fetchAll();

// Residents
$qSearch = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT * FROM users WHERE association_id = ? AND status <> 'inactive'";
$params = [$assocId];
if ($qSearch !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR unit_number LIKE ?)";
    $like = "%$qSearch%";
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY unit_number+0, last_name, first_name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$residents = $stmt->fetchAll();

$showInvite = ($_GET['action'] ?? '') === 'invite' && $canManage;
$page_title = 'Directory — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Directory</h1>
            <p class="muted">Board members and residents.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=invite">+ Invite member</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showInvite): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">Invite a member</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="invite">
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="iname">First name</label><input class="input" id="iname" name="first_name"></div>
                <div class="field"><label class="field__label" for="ilast">Last name</label><input class="input" id="ilast" name="last_name"></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="iemail">Email</label><input class="input" type="email" id="iemail" name="email" required></div>
                <div class="field"><label class="field__label" for="iunit">Unit #</label><input class="input" id="iunit" name="unit_number" placeholder="101"></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="irole">Role</label>
                    <select class="select" id="irole" name="role">
                        <option value="resident">Resident</option>
                        <option value="renter">Renter</option>
                        <option value="board_member">Board member</option>
                        <option value="board_admin">Board admin</option>
                        <option value="property_manager">Property manager</option>
                    </select>
                </div>
                <div class="field" style="justify-content: flex-end;">
                    <label class="field__label">&nbsp;</label>
                    <label style="display:flex; align-items:center; gap: var(--sp-2);">
                        <input type="checkbox" name="is_owner" checked> Owner (uncheck for renter)
                    </label>
                </div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/directory.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Send invite</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <h2 style="font-size: var(--fs-xl); margin-top: var(--sp-2);">Board</h2>
    <?php if (!$board): ?>
        <p class="muted">No board members on file yet.</p>
    <?php else: ?>
    <div class="grid grid--3" style="margin-bottom: var(--sp-8);">
        <?php foreach ($board as $b): ?>
            <div class="card">
                <div class="row" style="margin-bottom: var(--sp-2);">
                    <span class="badge badge--navy"><?= e(str_replace('_',' ',$b['role'])) ?></span>
                </div>
                <strong><?= e(trim($b['first_name'] . ' ' . $b['last_name']) ?: $b['email']) ?></strong>
                <div class="muted" style="font-size: var(--fs-sm);">
                    <?= e($b['email']) ?><?php if ($b['phone']): ?> &middot; <?= e($b['phone']) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size: var(--fs-xl);">Residents</h2>
    <form method="get" class="row" style="margin-bottom: var(--sp-4);">
        <input class="input" type="search" name="q" placeholder="Search name, email, unit" value="<?= e($qSearch) ?>" style="max-width: 320px;">
        <button class="btn btn--ghost" type="submit">Search</button>
    </form>

    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th><th>Unit</th><th>Role</th><th>Owner / Renter</th><th>Email</th><th>Phone</th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($residents as $r): ?>
            <tr>
                <td><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></strong></td>
                <td><?= e($r['unit_number'] ?: '—') ?></td>
                <td><?= e(str_replace('_',' ',$r['role'])) ?></td>
                <td><?= $r['is_owner'] ? '<span class="badge badge--success">Owner</span>' : '<span class="badge">Renter</span>' ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= e($r['phone'] ?: '—') ?></td>
                <?php if ($canManage): ?>
                <td style="text-align:right;">
                    <?php if ((int)$r['id'] !== (int)$user['id']): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this user?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="deactivate">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn--ghost" type="submit">Deactivate</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

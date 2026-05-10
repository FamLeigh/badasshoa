<?php
// Unit detail — specs, occupants (owner / co_owner / tenant), per-unit docs.
// Manager-only. Accepts ?id=<unit_id> (canonical) or ?n=<unit_number> (bridge
// from /dashboard/directory.php which only knows the string label).
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

// --- Resolve target unit ---
$unitId  = (int)($_GET['id'] ?? 0);
$unitNum = trim((string)($_GET['n'] ?? ''));

if ($unitId === 0 && $unitNum !== '') {
    $stmt = db()->prepare('SELECT id FROM units WHERE association_id = ? AND unit_number = ?');
    $stmt->execute([$assocId, $unitNum]);
    $unitId = (int)($stmt->fetchColumn() ?: 0);
}

// --- Auto-register from ?n= if requested ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'register_from_string') {
    csrf_check();
    $num = trim((string)($_POST['unit_number'] ?? ''));
    if ($num !== '') {
        try {
            db()->prepare('INSERT INTO units (association_id, unit_number) VALUES (?, ?)')->execute([$assocId, $num]);
            $unitId = (int)db()->lastInsertId();
            audit('unit.added', ['unit_number' => $num, 'via' => 'directory_link'], $unitId, 'unit');
            redirect('/dashboard/unit.php?id=' . $unitId);
        } catch (PDOException $e) {
            $flashError = "Unit \"$num\" already exists.";
        }
    }
}

if ($unitId === 0) {
    // Empty state — give a path to register the unit they tried to open.
    if ($unitNum !== '') {
        $page_title = 'Unit not registered — ' . $association['name'];
        require __DIR__ . '/../includes/header.php';
        ?>
        <div class="container" style="padding: var(--sp-8) var(--sp-6); max-width: 720px;">
            <h1>Unit "<?= e($unitNum) ?>" isn't registered yet</h1>
            <p class="muted">You probably came here from the directory. To track occupants and unit-scoped documents, register this unit first.</p>
            <form method="post" class="row" style="gap: var(--sp-2); margin-top: var(--sp-4);">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="register_from_string">
                <input type="hidden" name="unit_number" value="<?= e($unitNum) ?>">
                <button class="btn btn--primary" type="submit">Register unit "<?= e($unitNum) ?>"</button>
                <a class="btn btn--ghost" href="/dashboard/units.php">Or pick from the list</a>
            </form>
        </div>
        <?php
        require __DIR__ . '/../includes/footer.php';
        exit;
    }
    redirect('/dashboard/units.php');
}

// --- Load unit ---
$stmt = db()->prepare('SELECT * FROM units WHERE id = ? AND association_id = ?');
$stmt->execute([$unitId, $assocId]);
$unit = $stmt->fetch();
if (!$unit) { http_response_code(404); die('Unit not found'); }

// --- Save unit specs ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_unit') {
    csrf_check();
    $num   = trim((string)($_POST['unit_number'] ?? ''));
    $type  = $_POST['type'] ?? 'condo';
    $beds  = $_POST['bedrooms'] !== '' ? (int)$_POST['bedrooms'] : null;
    $baths = $_POST['baths'] !== '' ? (float)$_POST['baths'] : null;
    $sqft  = $_POST['square_footage'] !== '' ? (int)$_POST['square_footage'] : null;
    $pct   = $_POST['ownership_percent'] !== '' ? (float)$_POST['ownership_percent'] : null;
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($type, ['condo','townhouse','single_family','apartment','other'], true)) $type = 'condo';

    if ($num === '') {
        $flashError = 'Unit number is required.';
    } else {
        try {
            db()->prepare(
                'UPDATE units
                    SET unit_number = ?, type = ?, bedrooms = ?, baths = ?, square_footage = ?, ownership_percent = ?, notes = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([$num, $type, $beds, $baths, $sqft, $pct, $notes ?: null, $unitId, $assocId]);
            audit('unit.edited', ['unit_number' => $num], $unitId, 'unit');
            flash('success', 'Unit specs updated.');
            redirect('/dashboard/unit.php?id=' . $unitId);
        } catch (PDOException $e) {
            $flashError = "Unit number \"$num\" is already in use.";
        }
    }
}

// --- Add occupant ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'occupant_add') {
    csrf_check();
    $tgtUserId = (int)($_POST['user_id'] ?? 0);
    $role      = $_POST['role'] ?? 'owner';
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    $since     = trim((string)($_POST['since'] ?? ''));
    $note      = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($role, ['owner','co_owner','tenant'], true)) $role = 'owner';
    if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) $since = '';

    // Validate target user belongs to this association
    $check = db()->prepare("SELECT id FROM users WHERE id = ? AND association_id = ? AND status <> 'inactive'");
    $check->execute([$tgtUserId, $assocId]);
    if (!$check->fetchColumn()) {
        $flashError = 'Pick an active member of this association.';
    } else {
        // Single primary per unit: clear other primaries if needed.
        if ($isPrimary) {
            db()->prepare('UPDATE unit_occupants SET is_primary = 0 WHERE unit_id = ?')->execute([$unitId]);
        }
        try {
            db()->prepare(
                'INSERT INTO unit_occupants (unit_id, user_id, role, is_primary, since, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$unitId, $tgtUserId, $role, $isPrimary, $since ?: null, $note ?: null]);
            audit('occupant.added', ['unit_id' => $unitId, 'role' => $role], (int)db()->lastInsertId(), 'unit_occupant');
            flash('success', 'Occupant added.');
            redirect('/dashboard/unit.php?id=' . $unitId);
        } catch (PDOException $e) {
            $flashError = 'That user is already linked to this unit. Edit the existing entry instead.';
        }
    }
}

// --- Edit occupant ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'occupant_edit') {
    csrf_check();
    $oid       = (int)($_POST['id'] ?? 0);
    $role      = $_POST['role'] ?? 'owner';
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    $since     = trim((string)($_POST['since'] ?? ''));
    $note      = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($role, ['owner','co_owner','tenant'], true)) $role = 'owner';
    if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) $since = '';

    if ($isPrimary) {
        db()->prepare('UPDATE unit_occupants SET is_primary = 0 WHERE unit_id = ? AND id <> ?')->execute([$unitId, $oid]);
    }
    db()->prepare(
        'UPDATE unit_occupants SET role = ?, is_primary = ?, since = ?, notes = ?
          WHERE id = ? AND unit_id = ?'
    )->execute([$role, $isPrimary, $since ?: null, $note ?: null, $oid, $unitId]);
    audit('occupant.edited', ['role' => $role, 'is_primary' => $isPrimary], $oid, 'unit_occupant');
    flash('success', 'Occupant updated.');
    redirect('/dashboard/unit.php?id=' . $unitId);
}

// --- Remove occupant ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'occupant_remove') {
    csrf_check();
    $oid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM unit_occupants WHERE id = ? AND unit_id = ?')->execute([$oid, $unitId]);
    audit('occupant.removed', [], $oid, 'unit_occupant');
    flash('success', 'Occupant removed from this unit.');
    redirect('/dashboard/unit.php?id=' . $unitId);
}

// --- Load occupants ---
$occStmt = db()->prepare(
    'SELECT o.*,
            u.first_name, u.last_name, u.email, u.phone, u.role AS user_role, u.status AS user_status
       FROM unit_occupants o
       JOIN users u ON u.id = o.user_id
      WHERE o.unit_id = ?
      ORDER BY o.is_primary DESC, FIELD(o.role,"owner","co_owner","tenant"), u.last_name, u.first_name'
);
$occStmt->execute([$unitId]);
$occupants = $occStmt->fetchAll();

// --- Load per-unit documents (manager sees all) ---
$docStmt = db()->prepare(
    'SELECT d.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS uploader
       FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.association_id = ? AND d.unit_id = ?
      ORDER BY d.created_at DESC'
);
$docStmt->execute([$assocId, $unitId]);
$unitDocs = $docStmt->fetchAll();

// --- Candidates for "Add occupant" dropdown: active members not already linked ---
$candStmt = db()->prepare(
    "SELECT id, first_name, last_name, email
       FROM users
      WHERE association_id = ? AND status = 'active'
        AND id NOT IN (SELECT user_id FROM unit_occupants WHERE unit_id = ?)
      ORDER BY last_name, first_name"
);
$candStmt->execute([$assocId, $unitId]);
$candidates = $candStmt->fetchAll();

$showEditUnit  = ($_GET['action'] ?? '') === 'edit_unit';
$editOccupant  = null;
if (($_GET['action'] ?? '') === 'edit_occupant') {
    $oid = (int)($_GET['oid'] ?? 0);
    $stmt = db()->prepare('SELECT o.*, u.first_name, u.last_name, u.email FROM unit_occupants o JOIN users u ON u.id = o.user_id WHERE o.id = ? AND o.unit_id = ?');
    $stmt->execute([$oid, $unitId]);
    $editOccupant = $stmt->fetch() ?: null;
}

$active = 'units';
$page_title = 'Unit ' . $unit['unit_number'] . ' — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4); align-items: flex-start;">
        <div>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/units.php">← All units</a>
            <h1 style="font-size: var(--fs-3xl); margin: var(--sp-2) 0 0;">Unit <?= e((string)$unit['unit_number']) ?></h1>
            <p class="muted">
                <?= e(str_replace('_',' ', (string)$unit['type'])) ?>
                <?php if ($unit['bedrooms'] !== null || $unit['baths'] !== null): ?>
                    · <?= $unit['bedrooms'] !== null ? (int)$unit['bedrooms'] . ' bd' : '' ?>
                    <?php if ($unit['baths'] !== null): ?>/ <?= rtrim(rtrim(number_format((float)$unit['baths'], 1, '.', ''), '0'), '.') ?> ba<?php endif; ?>
                <?php endif; ?>
                <?php if ($unit['square_footage']): ?> · <?= number_format((int)$unit['square_footage']) ?> sqft<?php endif; ?>
                <?php if ($unit['ownership_percent'] !== null): ?> · <?= rtrim(rtrim(number_format((float)$unit['ownership_percent'], 4, '.', ''), '0'), '.') ?>% ownership<?php endif; ?>
            </p>
            <?php if (!empty($unit['notes'])): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);"><?= e((string)$unit['notes']) ?></p>
            <?php endif; ?>
        </div>
        <a class="btn btn--ghost" href="?id=<?= (int)$unitId ?>&action=edit_unit">Edit unit</a>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showEditUnit): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Edit unit</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?id=<?= (int)$unitId ?>">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save_unit">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu-num">Unit number</label>
                    <input class="input" id="eu-num" name="unit_number" required maxlength="20" value="<?= e((string)$unit['unit_number']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="eu-type">Type</label>
                    <select class="select" id="eu-type" name="type">
                        <?php foreach (['condo'=>'Condo','townhouse'=>'Townhouse','single_family'=>'Single family','apartment'=>'Apartment','other'=>'Other'] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>" <?= $unit['type']===$v?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1.2fr; gap: var(--sp-3);">
                <div class="field"><label class="field__label" for="eu-bd">Bedrooms</label>
                    <input class="input" type="number" min="0" max="20" id="eu-bd" name="bedrooms" value="<?= e((string)($unit['bedrooms'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="eu-ba">Baths</label>
                    <input class="input" type="number" step="0.5" min="0" max="20" id="eu-ba" name="baths" value="<?= e((string)($unit['baths'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="eu-sf">Sq ft</label>
                    <input class="input" type="number" min="0" max="50000" id="eu-sf" name="square_footage" value="<?= e((string)($unit['square_footage'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="eu-pct">Ownership %</label>
                    <input class="input" type="number" step="0.0001" min="0" max="100" id="eu-pct" name="ownership_percent" value="<?= e((string)($unit['ownership_percent'] ?? '')) ?>"></div>
            </div>
            <div class="field">
                <label class="field__label" for="eu-notes">Notes</label>
                <textarea class="textarea" id="eu-notes" name="notes" rows="2"><?= e((string)($unit['notes'] ?? '')) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="?id=<?= (int)$unitId ?>">Cancel</a>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Occupants -->
    <h2 style="font-size: var(--fs-xl); margin-top: var(--sp-6);">Occupants <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— owners, co-owners, and tenants linked to this unit</span></h2>

    <?php if (!$occupants): ?>
        <p class="muted" style="margin-bottom: var(--sp-4);">No one linked yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto; margin-bottom: var(--sp-4);">
    <table class="table">
        <thead><tr><th>Name</th><th>Role</th><th>Primary</th><th>Since</th><th>Email</th><th>Phone</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($occupants as $o):
            $roleClass = match ($o['role']) {
                'owner'    => 'badge--success',
                'co_owner' => 'badge--info',
                'tenant'   => 'badge--warning',
                default    => '',
            };
        ?>
            <tr>
                <td><strong><?= e(trim((string)$o['first_name'] . ' ' . (string)$o['last_name']) ?: (string)$o['email']) ?></strong></td>
                <td><span class="badge <?= $roleClass ?>"><?= e(str_replace('_',' ',(string)$o['role'])) ?></span></td>
                <td><?= (int)$o['is_primary'] === 1 ? '<span class="badge badge--orange">primary</span>' : '<span class="muted">—</span>' ?></td>
                <td><?= $o['since'] ? e(date('M j, Y', strtotime((string)$o['since']))) : '<span class="muted">—</span>' ?></td>
                <td><?= e((string)$o['email']) ?></td>
                <td><?= $o['phone'] ? e((string)$o['phone']) : '<span class="muted">—</span>' ?></td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?id=<?= (int)$unitId ?>&action=edit_occupant&oid=<?= (int)$o['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove this person from the unit?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="occupant_remove">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($editOccupant): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Edit occupant — <?= e(trim((string)$editOccupant['first_name'] . ' ' . (string)$editOccupant['last_name'])) ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?id=<?= (int)$unitId ?>">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="occupant_edit">
            <input type="hidden" name="id" value="<?= (int)$editOccupant['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eo-role">Role</label>
                    <select class="select" id="eo-role" name="role">
                        <?php foreach (['owner'=>'Owner','co_owner'=>'Co-owner','tenant'=>'Tenant'] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>" <?= $editOccupant['role']===$v?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="eo-since">Since</label>
                    <input class="input" type="date" id="eo-since" name="since" value="<?= e((string)($editOccupant['since'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="is_primary" <?= (int)$editOccupant['is_primary'] === 1 ? 'checked' : '' ?>>
                    <span>Primary on title (only one per unit — checking this will demote any other primary)</span>
                </label>
            </div>
            <div class="field">
                <label class="field__label" for="eo-notes">Notes</label>
                <textarea class="textarea" id="eo-notes" name="notes" rows="2"><?= e((string)($editOccupant['notes'] ?? '')) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="?id=<?= (int)$unitId ?>">Cancel</a>
                <button class="btn btn--primary" type="submit">Save</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Add-occupant form (only if not editing one) -->
    <?php if (!$editOccupant): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-8);">
        <h3 class="card__title">+ Add occupant</h3>
        <?php if (!$candidates): ?>
            <p class="muted" style="font-size: var(--fs-sm);">Every active member is already linked to this unit. Invite a new user via <a href="/dashboard/directory.php?action=invite">Directory → Invite</a>.</p>
        <?php else: ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="occupant_add">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="oa-user">User</label>
                    <select class="select" id="oa-user" name="user_id" required>
                        <option value="">— pick a member —</option>
                        <?php foreach ($candidates as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e(trim((string)$c['first_name'] . ' ' . (string)$c['last_name']) ?: (string)$c['email']) ?> &lt;<?= e((string)$c['email']) ?>&gt;</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Don't see them? <a href="/dashboard/directory.php?action=invite">Invite a new user</a> first.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="oa-role">Role</label>
                    <select class="select" id="oa-role" name="role">
                        <option value="owner">Owner</option>
                        <option value="co_owner">Co-owner</option>
                        <option value="tenant">Tenant</option>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="oa-since">Since (optional)</label>
                    <input class="input" type="date" id="oa-since" name="since">
                </div>
                <div class="field">
                    <label style="display:flex; align-items:center; gap: var(--sp-2); padding-top: var(--sp-5);">
                        <input type="checkbox" name="is_primary"> Primary on title
                    </label>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="oa-notes">Notes (optional)</label>
                <textarea class="textarea" id="oa-notes" name="notes" rows="2" placeholder="e.g. lease ends 2026-08-31"></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Add to this unit</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Per-unit documents -->
    <h2 style="font-size: var(--fs-xl);">Documents for this unit <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— rental agreements, deeds, anything tied to <?= e((string)$unit['unit_number']) ?></span></h2>
    <p style="margin-bottom: var(--sp-4);">
        <a class="btn btn--primary" href="/dashboard/documents.php?action=new&unit_id=<?= (int)$unitId ?>">+ Upload to unit <?= e((string)$unit['unit_number']) ?></a>
    </p>

    <?php if (!$unitDocs): ?>
        <p class="muted">No unit-scoped documents yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Title</th><th>Category</th><th>Access</th><th>Uploaded</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($unitDocs as $d):
            $accessClass = match ($d['access_level']) {
                'public'        => 'badge--success',
                'board_only'    => 'badge--navy',
                'unit_only'     => 'badge--orange',
                default         => 'badge--info',
            };
        ?>
            <tr>
                <td><strong><?= e((string)$d['title']) ?></strong>
                    <?php if ($d['description']): ?><div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$d['description'], 0, 80, '…')) ?></div><?php endif; ?>
                </td>
                <td><?= e((string)($d['category'] ?? '—')) ?></td>
                <td><span class="badge <?= $accessClass ?>"><?= e(str_replace('_',' ',(string)$d['access_level'])) ?></span></td>
                <td>
                    <?= e(date('M j, Y', strtotime((string)$d['created_at']))) ?>
                    <div class="muted" style="font-size: var(--fs-xs);">v<?= e((string)$d['version']) ?> · <?= e(trim((string)$d['uploader']) ?: 'unknown') ?></div>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" href="/dashboard/file.php?type=document&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

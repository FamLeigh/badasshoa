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
    $num     = trim((string)($_POST['unit_number'] ?? ''));
    $type    = $_POST['type'] ?? 'condo';
    $beds    = $_POST['bedrooms'] !== '' ? (int)$_POST['bedrooms'] : null;
    $baths   = $_POST['baths'] !== '' ? (float)$_POST['baths'] : null;
    $sqft    = $_POST['square_footage'] !== '' ? (int)$_POST['square_footage'] : null;
    $pct     = $_POST['ownership_percent'] !== '' ? (float)$_POST['ownership_percent'] : null;
    $hoaA    = $_POST['annual_hoa_assessment']    !== '' ? (float)$_POST['annual_hoa_assessment']    : null;
    $garA    = $_POST['annual_garage_assessment'] !== '' ? (float)$_POST['annual_garage_assessment'] : null;
    $notes   = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($type, ['condo','townhouse','single_family','apartment','business','main_office','other'], true)) $type = 'condo';

    if ($num === '') {
        $flashError = 'Unit number is required.';
    } else {
        try {
            db()->prepare(
                'UPDATE units
                    SET unit_number = ?, type = ?, bedrooms = ?, baths = ?, square_footage = ?, ownership_percent = ?,
                        annual_hoa_assessment = ?, annual_garage_assessment = ?, notes = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([$num, $type, $beds, $baths, $sqft, $pct, $hoaA, $garA, $notes ?: null, $unitId, $assocId]);
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

// --- Unit-doc archive / unarchive ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['form'] ?? ''), ['unit_doc_archive','unit_doc_unarchive'], true)) {
    csrf_check();
    $did = (int)($_POST['doc_id'] ?? 0);
    $row = db()->prepare('SELECT title FROM documents WHERE id = ? AND association_id = ? AND unit_id = ?');
    $row->execute([$did, $assocId, $unitId]);
    $drow = $row->fetch();
    if ($drow) {
        $isArchive = ($_POST['form'] === 'unit_doc_archive');
        db()->prepare('UPDATE documents SET archived_at = ? WHERE id = ? AND association_id = ?')
            ->execute([$isArchive ? date('Y-m-d H:i:s') : null, $did, $assocId]);
        audit('document.' . ($isArchive ? 'archived' : 'unarchived'), ['title' => $drow['title']], $did, 'document');
        flash('success', $isArchive ? "Archived \"{$drow['title']}\"." : "Restored \"{$drow['title']}\".");
    }
    redirect('/dashboard/unit.php?id=' . $unitId . (($_POST['form'] === 'unit_doc_unarchive') ? '&doc_archived=1' : ''));
}

// --- Unit media upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'unit_media_upload') {
    csrf_check();
    $title = trim((string)($_POST['media_title'] ?? ''));
    if (!isset($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'Upload failed — no file received.';
    } elseif ($_FILES['media_file']['size'] > 10 * 1024 * 1024) {
        $flashError = 'Max image size is 10 MB.';
    } elseif (storage_over_quota_by($association, (int)$_FILES['media_file']['size'])) {
        $flashError = 'Storage quota reached. Delete some files or contact us.';
    } else {
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $ext = strtolower(pathinfo((string)$_FILES['media_file']['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            $flashError = 'Only JPG, PNG, WEBP, and GIF images are allowed.';
        } else {
            $newName = uuid_filename((string)$_FILES['media_file']['name']);
            $relDir  = "uploads/$assocId/unit_media/$unitId";
            $absDir  = storage_path($relDir);
            ensure_dir($absDir);
            if (!move_uploaded_file($_FILES['media_file']['tmp_name'], "$absDir/$newName")) {
                $flashError = 'Could not save image.';
            } else {
                db()->prepare(
                    'INSERT INTO unit_media (association_id, unit_id, title, file_path, mime_type, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$assocId, $unitId, $title ?: $newName, "$relDir/$newName", $allowed[$ext], (int)$user['id']]);
                audit('unit_media.uploaded', ['unit_id' => $unitId, 'title' => $title], (int)db()->lastInsertId(), 'unit_media');
                flash('success', 'Image added.');
                redirect('/dashboard/unit.php?id=' . $unitId . '#unit-media');
            }
        }
    }
}

// --- Unit media delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'unit_media_delete') {
    csrf_check();
    $mid = (int)($_POST['media_id'] ?? 0);
    $mrow = db()->prepare('SELECT file_path FROM unit_media WHERE id = ? AND association_id = ? AND unit_id = ?');
    $mrow->execute([$mid, $assocId, $unitId]);
    $mdata = $mrow->fetch();
    if ($mdata) {
        $abs = storage_path((string)$mdata['file_path']);
        if (is_file($abs)) @unlink($abs);
        db()->prepare('DELETE FROM unit_media WHERE id = ? AND association_id = ?')->execute([$mid, $assocId]);
        audit('unit_media.deleted', [], $mid, 'unit_media');
        flash('success', 'Image removed.');
    }
    redirect('/dashboard/unit.php?id=' . $unitId . '#unit-media');
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

// --- Load parking spots assigned to this unit ---
$psStmt = db()->prepare(
    "SELECT * FROM parking_spots
      WHERE association_id = ? AND assigned_unit_id = ?
      ORDER BY FIELD(kind,'garage','surface','covered','tandem','other'),
               CAST(number AS UNSIGNED), number"
);
$psStmt->execute([$assocId, $unitId]);
$unitSpots = $psStmt->fetchAll();

// --- Load floor plan doc (association-level, referenced by FK on unit) ---
$floorPlanDoc = null;
if (!empty($unit['floor_plan_doc_id'])) {
    $fpStmt = db()->prepare('SELECT id, title FROM documents WHERE id = ? AND association_id = ?');
    $fpStmt->execute([(int)$unit['floor_plan_doc_id'], $assocId]);
    $floorPlanDoc = $fpStmt->fetch() ?: null;
}

// --- Load per-unit documents (archive toggle) ---
$showDocArchived = ($_GET['doc_archived'] ?? '') === '1';
$docStmt = db()->prepare(
    'SELECT d.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS uploader
       FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.association_id = ? AND d.unit_id = ?
        AND d.archived_at ' . ($showDocArchived ? 'IS NOT NULL' : 'IS NULL') . '
      ORDER BY d.created_at DESC'
);
$docStmt->execute([$assocId, $unitId]);
$unitDocs = $docStmt->fetchAll();

// Archived doc count for the toggle link.
$archivedDocCount = 0;
if (!$showDocArchived) {
    $adcStmt = db()->prepare('SELECT COUNT(*) FROM documents WHERE association_id = ? AND unit_id = ? AND archived_at IS NOT NULL');
    $adcStmt->execute([$assocId, $unitId]);
    $archivedDocCount = (int)$adcStmt->fetchColumn();
}

// --- Load unit media ---
$mediaStmt = db()->prepare(
    'SELECT m.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS uploader
       FROM unit_media m LEFT JOIN users u ON u.id = m.uploaded_by
      WHERE m.association_id = ? AND m.unit_id = ?
      ORDER BY m.created_at DESC'
);
$mediaStmt->execute([$assocId, $unitId]);
$unitMedia = $mediaStmt->fetchAll();

// --- Recent forms filed for this unit ---
$formStmt = db()->prepare(
    'SELECT f.id, f.form_type, f.title, f.confirmation_code, f.status, f.starts_at, f.ends_at, f.created_at
       FROM form_submissions f
      WHERE f.association_id = ? AND f.unit_id = ?
      ORDER BY f.created_at DESC LIMIT 10'
);
$formStmt->execute([$assocId, $unitId]);
$unitForms = $formStmt->fetchAll();

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

$showEditUnit     = ($_GET['action'] ?? '') === 'edit_unit';
$showAddOccupant  = ($_GET['action'] ?? '') === 'add_occupant';
$editOccupant     = null;
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
            <?php
            $aHoa = $unit['annual_hoa_assessment']    !== null ? (float)$unit['annual_hoa_assessment']    : null;
            $aGar = $unit['annual_garage_assessment'] !== null ? (float)$unit['annual_garage_assessment'] : null;
            if ($aHoa !== null || $aGar !== null):
                $monthlyTotal = ($aHoa ?? 0) / 12 + ($aGar ?? 0) / 12;
            ?>
            <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);">
                <strong>$<?= number_format($monthlyTotal, 2) ?>/mo</strong>
                <?php if ($aHoa !== null): ?>
                    · HOA $<?= number_format($aHoa, 2) ?>/yr ($<?= number_format($aHoa / 12, 2) ?>/mo)
                <?php endif; ?>
                <?php if ($aGar !== null): ?>
                    · Garage $<?= number_format($aGar, 2) ?>/yr ($<?= number_format($aGar / 12, 2) ?>/mo)
                <?php endif; ?>
            </p>
            <?php endif; ?>
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
                        <?php foreach (['condo'=>'Condo','townhouse'=>'Townhouse','single_family'=>'Single family','apartment'=>'Apartment','business'=>'Business','main_office'=>'Main office','other'=>'Other'] as $v=>$lbl): ?>
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
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="eu-hoa">Annual HOA assessment ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="eu-hoa" name="annual_hoa_assessment" value="<?= e((string)($unit['annual_hoa_assessment'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="eu-gara">Annual garage assessment ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="eu-gara" name="annual_garage_assessment" value="<?= e((string)($unit['annual_garage_assessment'] ?? '')) ?>"></div>
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
                <td><?= $o['since'] ? e(udate('M j, Y', strtotime((string)$o['since']))) : '<span class="muted">—</span>' ?></td>
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

    <!-- Add-occupant button (collapsed by default) -->
    <?php if (!$editOccupant && !$showAddOccupant): ?>
        <p style="margin-bottom: var(--sp-6);">
            <a class="btn btn--ghost" href="?id=<?= (int)$unitId ?>&action=add_occupant">+ Add occupant</a>
        </p>
    <?php endif; ?>

    <!-- Add-occupant form (only when explicitly requested) -->
    <?php if (!$editOccupant && $showAddOccupant): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-8);">
        <div class="card__head">
            <h3 class="card__title">Add occupant</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?id=<?= (int)$unitId ?>">← Cancel</a>
        </div>
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

    <!-- Parking spots assigned to this unit -->
    <h2 style="font-size: var(--fs-xl); margin-top: var(--sp-6);">Parking <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— garages and parking spots assigned to this unit</span></h2>
    <?php if (!$unitSpots): ?>
        <p class="muted" style="margin-bottom: var(--sp-4);">No spots assigned. <a href="/dashboard/parking.php?action=new&unit_id=<?= (int)$unitId ?>">Assign one →</a></p>
    <?php else: ?>
    <div style="overflow-x:auto; margin-bottom: var(--sp-4);">
    <table class="table">
        <thead><tr><th>Kind</th><th>Number</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unitSpots as $s): ?>
            <tr>
                <td><span class="badge"><?= e(ucfirst((string)$s['kind'])) ?></span></td>
                <td><strong><?= e((string)$s['number']) ?></strong></td>
                <td><span class="muted" style="font-size: var(--fs-sm);"><?= !empty($s['notes']) ? e((string)$s['notes']) : '—' ?></span></td>
                <td style="text-align:right;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/parking.php?action=edit&id=<?= (int)$s['id'] ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin-bottom: var(--sp-6);"><a class="btn btn--ghost" href="/dashboard/parking.php?action=new&unit_id=<?= (int)$unitId ?>">+ Assign another spot</a></p>
    <?php endif; ?>

    <!-- Forms for this unit -->
    <h2 style="font-size: var(--fs-xl);">Forms <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— file a new one or see what's been submitted</span></h2>
    <?php $TYPES = form_types(); ?>
    <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
        File a form for Unit <?= e((string)$unit['unit_number']) ?>:
        <?php $i = 0; foreach ($TYPES as $key => $meta): if ($key === 'other') continue; ?>
            <?= $i++ > 0 ? ' · ' : '' ?><a href="/dashboard/forms.php?action=new&type=<?= e($key) ?>&unit_id=<?= (int)$unitId ?>"><?= e($meta['icon']) ?> <?= e($meta['label']) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if ($unitForms): ?>
        <div style="overflow-x:auto; margin-bottom: var(--sp-6);">
        <table class="table">
            <thead><tr><th>Type</th><th>Title</th><th>Window</th><th>Code</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($unitForms as $f):
                $m = $TYPES[$f['form_type']] ?? ['label' => $f['form_type'], 'icon' => '📝'];
                $expired = !empty($f['ends_at']) && strtotime((string)$f['ends_at']) < strtotime(date('Y-m-d'));
                $statusBadge = $f['status'] === 'revoked' ? 'badge--error' : ($expired ? '' : 'badge--success');
                $statusLabel = $f['status'] === 'revoked' ? 'revoked' : ($expired ? 'expired' : 'active');
            ?>
                <tr style="cursor:pointer;" onclick="window.location='/dashboard/forms.php?id=<?= (int)$f['id'] ?>'">
                    <td><?= e($m['icon']) ?> <?= e($m['label']) ?></td>
                    <td><a href="/dashboard/forms.php?id=<?= (int)$f['id'] ?>"><strong><?= e((string)$f['title']) ?></strong></a></td>
                    <td style="font-size: var(--fs-sm);">
                        <?= !empty($f['starts_at']) ? e(udate('M j', strtotime((string)$f['starts_at']))) : '' ?>
                        <?= !empty($f['ends_at'])   ? ' – ' . e(udate('M j', strtotime((string)$f['ends_at']))) : '' ?>
                    </td>
                    <td><code style="font-size: var(--fs-xs);"><?= e((string)$f['confirmation_code']) ?></code></td>
                    <td><span class="badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted" style="margin-bottom: var(--sp-6);">No forms filed for this unit yet.</p>
    <?php endif; ?>

    <!-- Floor plan -->
    <?php if ($floorPlanDoc): ?>
    <h2 style="font-size: var(--fs-xl); margin-top: var(--sp-6);">Floor Plan</h2>
    <div style="margin-bottom: var(--sp-6);">
        <a href="/dashboard/file.php?type=document&id=<?= (int)$floorPlanDoc['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;">
            <img src="/dashboard/file.php?type=document&id=<?= (int)$floorPlanDoc['id'] ?>"
                 alt="<?= e((string)$floorPlanDoc['title']) ?>"
                 style="max-width: 480px; width: 100%; border-radius: var(--r-lg); border: 1px solid var(--color-border); display:block;">
        </a>
        <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2);">
            <?= e((string)$floorPlanDoc['title']) ?> — <a href="/dashboard/file.php?type=document&id=<?= (int)$floorPlanDoc['id'] ?>" target="_blank" rel="noopener">open full size</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Per-unit documents -->
    <div class="row row--between" style="margin-top: var(--sp-6); margin-bottom: var(--sp-3); align-items: center; flex-wrap: wrap; gap: var(--sp-2);">
        <h2 style="font-size: var(--fs-xl); margin: 0;">Documents <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— rental agreements, deeds, anything specific to unit <?= e((string)$unit['unit_number']) ?></span></h2>
        <div class="row" style="gap: var(--sp-2);">
            <?php if ($showDocArchived): ?>
                <a class="btn btn--ghost" href="/dashboard/unit.php?id=<?= (int)$unitId ?>#unit-docs" style="font-size: var(--fs-sm);">← Active docs</a>
            <?php elseif ($archivedDocCount > 0): ?>
                <a class="btn btn--ghost" href="/dashboard/unit.php?id=<?= (int)$unitId ?>&doc_archived=1#unit-docs" style="font-size: var(--fs-sm);">Archived (<?= $archivedDocCount ?>)</a>
            <?php endif; ?>
            <a class="btn btn--primary" href="/dashboard/documents.php?action=new&unit_id=<?= (int)$unitId ?>">+ Upload doc</a>
        </div>
    </div>
    <div id="unit-docs">
    <?php if (!$unitDocs): ?>
        <p class="muted"><?= $showDocArchived ? 'No archived documents.' : 'No documents uploaded for this unit yet.' ?></p>
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
            <tr<?= !empty($d['archived_at']) ? ' style="opacity:.6;"' : '' ?>>
                <td><strong><?= e((string)$d['title']) ?></strong>
                    <?php if (!empty($d['archived_at'])): ?><span class="badge" style="font-size: var(--fs-xs); margin-left: 4px;">archived</span><?php endif; ?>
                    <?php if ($d['description']): ?><div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$d['description'], 0, 80, '…')) ?></div><?php endif; ?>
                </td>
                <td><?= e((string)($d['category'] ?? '—')) ?></td>
                <td><span class="badge <?= $accessClass ?>"><?= e(str_replace('_',' ',(string)$d['access_level'])) ?></span></td>
                <td>
                    <?= e(udate('M j, Y', strtotime((string)$d['created_at']))) ?>
                    <div class="muted" style="font-size: var(--fs-xs);"><?= e(trim((string)$d['uploader']) ?: 'unknown') ?></div>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <?php if (empty($d['archived_at']) && !empty($d['file_path'])): ?>
                        <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/file.php?type=document&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">View</a>
                    <?php endif; ?>
                    <?php if (empty($d['archived_at'])): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Archive this document?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="unit_doc_archive">
                            <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Archive</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="unit_doc_unarchive">
                            <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Restore</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>

    <!-- Unit media gallery -->
    <div class="row row--between" style="margin-top: var(--sp-8); margin-bottom: var(--sp-3); align-items: center;">
        <h2 id="unit-media" style="font-size: var(--fs-xl); margin: 0;">Photos &amp; Images <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— floor plans, interior photos, renovation photos</span></h2>
    </div>

    <?php if ($unitMedia): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: var(--sp-3); margin-bottom: var(--sp-6);">
        <?php foreach ($unitMedia as $m): ?>
        <div style="position: relative; border-radius: var(--r-lg); overflow: hidden; border: 1px solid var(--color-border); background: var(--color-surface);">
            <a href="/dashboard/file.php?type=unit_media&id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                <img src="/dashboard/file.php?type=unit_media&id=<?= (int)$m['id'] ?>"
                     alt="<?= e((string)$m['title']) ?>"
                     style="width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block;">
            </a>
            <div style="padding: var(--sp-2) var(--sp-2) var(--sp-1);">
                <div style="font-size: var(--fs-xs); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e((string)$m['title']) ?>"><?= e((string)$m['title']) ?></div>
                <div class="muted" style="font-size: var(--fs-xs);"><?= e(udate('M j, Y', strtotime((string)$m['created_at']))) ?></div>
            </div>
            <form method="post" style="position: absolute; top: var(--sp-1); right: var(--sp-1);" onsubmit="return confirm('Remove this image?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="unit_media_delete">
                <input type="hidden" name="media_id" value="<?= (int)$m['id'] ?>">
                <button type="submit" style="background: rgba(0,0,0,.55); border: none; border-radius: 50%; width: 24px; height: 24px; color: #fff; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center; padding: 0;" title="Remove">×</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="muted" style="margin-bottom: var(--sp-4);">No photos uploaded yet.</p>
    <?php endif; ?>

    <div class="card card--padded" style="margin-bottom: var(--sp-6); max-width: 480px;">
        <h3 class="card__title" style="margin-bottom: var(--sp-3);">Add photo or image</h3>
        <form method="post" enctype="multipart/form-data" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="unit_media_upload">
            <div class="field">
                <label class="field__label" for="media_title">Title (optional)</label>
                <input class="input" id="media_title" name="media_title" maxlength="255" placeholder="Floor plan, Living room, etc.">
            </div>
            <div class="field">
                <label class="field__label" for="media_file">Image (JPG, PNG, WEBP, GIF · max 10 MB)</label>
                <input class="input" type="file" id="media_file" name="media_file" accept="image/jpeg,image/png,image/webp,image/gif" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Upload</button>
            </div>
        </form>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

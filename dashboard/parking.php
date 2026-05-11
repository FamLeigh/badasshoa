<?php
// Parking spots / garages — per-association directory with assignment to units.
// Manager-only CRUD. Each spot has a kind (garage / surface / covered /
// tandem / other), a number, an optional unit assignment, notes, and an
// active flag.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

$KINDS = [
    'garage'  => 'Garage',
    'surface' => 'Surface',
    'covered' => 'Covered',
    'tandem'  => 'Tandem',
    'other'   => 'Other',
];

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    $kind   = $_POST['kind'] ?? 'garage';
    $number = trim((string)($_POST['number'] ?? ''));
    $uid    = ($_POST['assigned_unit_id'] ?? '') === '' ? null : (int)$_POST['assigned_unit_id'];
    $notes  = trim((string)($_POST['notes'] ?? ''));
    if (!array_key_exists($kind, $KINDS)) $kind = 'garage';
    if ($uid) {
        $check = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $check->execute([$uid, $assocId]);
        if (!$check->fetchColumn()) $uid = null;
    }

    if ($number === '') {
        $flashError = 'Spot number is required.';
    } else {
        try {
            db()->prepare(
                'INSERT INTO parking_spots (association_id, kind, number, assigned_unit_id, notes, sort_order)
                 VALUES (?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM parking_spots AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $kind, $number, $uid, $notes ?: null, $assocId]);
            audit('parking_spot.added', ['kind' => $kind, 'number' => $number, 'assigned_unit_id' => $uid], (int)db()->lastInsertId(), 'parking_spot');
            flash('success', "$kind $number added.");
            redirect('/dashboard/parking.php');
        } catch (PDOException $e) {
            $flashError = "A {$KINDS[$kind]} numbered \"$number\" already exists.";
        }
    }
}

// --- Edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    $sid    = (int)($_POST['id'] ?? 0);
    $kind   = $_POST['kind'] ?? 'garage';
    $number = trim((string)($_POST['number'] ?? ''));
    $uid    = ($_POST['assigned_unit_id'] ?? '') === '' ? null : (int)$_POST['assigned_unit_id'];
    $notes  = trim((string)($_POST['notes'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    if (!array_key_exists($kind, $KINDS)) $kind = 'garage';
    if ($uid) {
        $check = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $check->execute([$uid, $assocId]);
        if (!$check->fetchColumn()) $uid = null;
    }
    if ($number === '') {
        $flashError = 'Spot number is required.';
    } else {
        try {
            db()->prepare(
                'UPDATE parking_spots
                    SET kind = ?, number = ?, assigned_unit_id = ?, notes = ?, is_active = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([$kind, $number, $uid, $notes ?: null, $active, $sid, $assocId]);
            audit('parking_spot.edited', ['kind' => $kind, 'number' => $number, 'assigned_unit_id' => $uid, 'active' => (bool)$active], $sid, 'parking_spot');
            flash('success', 'Spot updated.');
            redirect('/dashboard/parking.php');
        } catch (PDOException $e) {
            $flashError = "Another {$KINDS[$kind]} is already numbered \"$number\".";
        }
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM parking_spots WHERE id = ? AND association_id = ?')->execute([$sid, $assocId]);
    audit('parking_spot.deleted', [], $sid, 'parking_spot');
    flash('success', 'Spot deleted.');
    redirect('/dashboard/parking.php');
}

// Load all spots + their assigned unit (and the unit's primary owner if any).
$rows = db()->prepare(
    "SELECT s.*,
            u.unit_number,
            (SELECT TRIM(CONCAT(IFNULL(usr.first_name,''), ' ', IFNULL(usr.last_name,'')))
               FROM unit_occupants uo
               JOIN users usr ON usr.id = uo.user_id
              WHERE uo.unit_id = u.id AND uo.is_primary = 1
              LIMIT 1) AS primary_owner_name
       FROM parking_spots s
       LEFT JOIN units u ON u.id = s.assigned_unit_id
      WHERE s.association_id = ?
      ORDER BY FIELD(s.kind,'garage','surface','covered','tandem','other'),
               CAST(s.number AS UNSIGNED), s.number"
);
$rows->execute([$assocId]);
$spots = $rows->fetchAll();

// All units for the assign dropdown.
$unitsAll = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
$unitsAll->execute([$assocId]);
$unitsList = $unitsAll->fetchAll();

$preselectUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$showAdd  = ($_GET['action'] ?? '') === 'new';
$editSpot = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM parking_spots WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editSpot = $stmt->fetch() ?: null;
}

// Group spots by kind for sectioned listing.
$byKind = array_fill_keys(array_keys($KINDS), []);
foreach ($spots as $s) $byKind[$s['kind']][] = $s;

$active = 'parking';
$page_title = 'Parking — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Parking</h1>
            <p class="muted">Garages, surface spots, covered, tandem — numbered and (optionally) assigned to a unit.</p>
        </div>
        <?php if (!$showAdd && !$editSpot): ?>
            <a class="btn btn--primary" href="?action=new">+ New spot</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showAdd || $editSpot):
        $isEdit = $editSpot !== null;
        $vals = $editSpot ?? [
            'kind' => 'garage', 'number' => '', 'assigned_unit_id' => $preselectUnit ?: null,
            'notes' => '', 'is_active' => 1, 'id' => 0,
        ];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit spot' : 'New parking spot' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/parking.php">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="sp-kind">Kind</label>
                    <select class="select" id="sp-kind" name="kind">
                        <?php foreach ($KINDS as $k => $lbl): ?>
                            <option value="<?= e($k) ?>" <?= $vals['kind']===$k?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="sp-num">Number</label>
                    <input class="input" id="sp-num" name="number" required maxlength="20" value="<?= e((string)$vals['number']) ?>" placeholder="64 · P-7 · A12">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="sp-unit">Assigned to unit (optional)</label>
                <select class="select" id="sp-unit" name="assigned_unit_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($unitsList as $u_): ?>
                        <option value="<?= (int)$u_['id'] ?>" <?= (int)($vals['assigned_unit_id'] ?? 0) === (int)$u_['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u_['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field__hint">A spot can be assigned to one unit. Leave unassigned for visitor or unallocated spots.</div>
            </div>
            <div class="field">
                <label class="field__label" for="sp-notes">Notes</label>
                <textarea class="textarea" id="sp-notes" name="notes" rows="2" placeholder="Anything specific — EV charging, oversized vehicles, accessibility, etc."><?= e((string)($vals['notes'] ?? '')) ?></textarea>
            </div>
            <?php if ($isEdit): ?>
            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="is_active" <?= (int)$vals['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Active — appears in lists and dropdowns</span>
                </label>
            </div>
            <?php endif; ?>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/parking.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save' : 'Create spot' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php foreach ($KINDS as $kind => $kindLabel):
        $items = $byKind[$kind];
    ?>
    <div style="margin-bottom: var(--sp-6);">
        <div class="row row--between" style="margin-bottom: var(--sp-3); align-items: baseline;">
            <h2 style="font-size: var(--fs-xl); margin: 0;">
                <?= e($kindLabel) ?>
                <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— <?= count($items) ?></span>
            </h2>
            <a class="muted" style="font-size: var(--fs-sm);" href="?action=new&kind=<?= e($kind) ?>">+ Add <?= e(strtolower($kindLabel)) ?></a>
        </div>

        <?php if (!$items): ?>
            <p class="muted" style="font-size: var(--fs-sm);">— none yet —</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Number</th><th>Assigned to unit</th><th>Primary owner</th><th>Notes</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($items as $s): ?>
                <tr style="<?= (int)$s['is_active'] === 0 ? 'opacity: 0.55;' : '' ?>">
                    <td><strong><?= e((string)$s['number']) ?></strong></td>
                    <td>
                        <?php if (!empty($s['unit_number'])): ?>
                            <a href="/dashboard/unit.php?id=<?= (int)$s['assigned_unit_id'] ?>">Unit <?= e((string)$s['unit_number']) ?></a>
                        <?php else: ?>
                            <span class="muted">— unassigned —</span>
                        <?php endif; ?>
                    </td>
                    <td><?= !empty($s['primary_owner_name']) ? e((string)$s['primary_owner_name']) : '<span class="muted">—</span>' ?></td>
                    <td><span class="muted" style="font-size: var(--fs-sm);"><?= !empty($s['notes']) ? e(mb_strimwidth((string)$s['notes'], 0, 60, '…')) : '—' ?></span></td>
                    <td><?= (int)$s['is_active'] === 1 ? '<span class="badge badge--success">active</span>' : '<span class="badge">inactive</span>' ?></td>
                    <td style="text-align:right; white-space: nowrap;">
                        <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$s['id'] ?>">Edit</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?= e($kindLabel) ?> <?= e((string)$s['number']) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

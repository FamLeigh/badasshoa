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

// --- CSV bulk import ---
$importSummary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import') {
    csrf_check();
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'CSV upload failed.';
    } elseif ($_FILES['csv']['size'] > 1 * 1024 * 1024) {
        $flashError = 'Max CSV size is 1 MB.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $flashError = 'Could not read CSV.';
        } else {
            // Cache unit_number → id for fast lookup
            $unitMap = [];
            $u = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ?');
            $u->execute([$assocId]);
            foreach ($u->fetchAll() as $row) $unitMap[strtolower((string)$row['unit_number'])] = (int)$row['id'];

            $added = 0; $skipped = 0; $errors = []; $row = 0; $headerMap = null;
            $allowedKinds = array_keys($KINDS);

            while (($cols = fgetcsv($fh)) !== false) {
                $row++;
                if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

                if ($headerMap === null) {
                    $headerMap = [];
                    foreach ($cols as $i => $name) {
                        $key = strtolower(trim(str_replace([' ', '-'], '_', (string)$name)));
                        $headerMap[$key] = $i;
                    }
                    if (!isset($headerMap['number'])) {
                        $flashError = 'Missing required column: number. Required: number. Optional: kind (garage/surface/covered/tandem/other), unit_number, notes, is_active.';
                        break;
                    }
                    continue;
                }

                $get  = fn($k) => isset($headerMap[$k], $cols[$headerMap[$k]]) ? trim((string)$cols[$headerMap[$k]]) : '';
                $num    = $get('number');
                $kind   = strtolower($get('kind')) ?: 'garage';
                $unitNo = $get('unit_number');
                $rNotes = $get('notes');
                $activeRaw = strtolower($get('is_active'));
                $isActive = in_array($activeRaw, ['','1','y','yes','active','true'], true) ? 1
                          : (in_array($activeRaw, ['0','n','no','inactive','false'], true) ? 0 : 1);

                if ($num === '') { $errors[] = "Row $row: missing number"; continue; }
                if (!in_array($kind, $allowedKinds, true)) $kind = 'garage';

                // Look up assigned unit by unit_number; leave NULL if no match (log a soft warning).
                $assignedUid = null;
                if ($unitNo !== '') {
                    $key = strtolower($unitNo);
                    if (isset($unitMap[$key])) {
                        $assignedUid = $unitMap[$key];
                    } else {
                        $errors[] = "Row $row: unit \"$unitNo\" not found — spot created unassigned";
                    }
                }

                // Skip if a spot with this (kind, number) already exists.
                $check = db()->prepare('SELECT id FROM parking_spots WHERE association_id = ? AND kind = ? AND number = ?');
                $check->execute([$assocId, $kind, $num]);
                if ($check->fetchColumn()) { $skipped++; continue; }

                db()->prepare(
                    'INSERT INTO parking_spots (association_id, kind, number, assigned_unit_id, notes, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM parking_spots AS x WHERE x.association_id = ?), 0) + 10)'
                )->execute([$assocId, $kind, $num, $assignedUid, $rNotes ?: null, $isActive, $assocId]);
                $added++;
            }
            fclose($fh);
            $importSummary = ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
            audit('parking_spots.imported', $importSummary);
        }
    }
}

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

// --- CSV export ---
if (($_GET['export'] ?? '') === 'csv') {
    audit('parking_spots.exported', ['count' => count($spots)]);
    $filename = 'parking-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower((string)$association['name'])) . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['kind','number','unit_number','primary_owner','notes','is_active']);
    foreach ($spots as $s) {
        fputcsv($out, [
            $s['kind'], $s['number'],
            $s['unit_number'] ?? '',
            $s['primary_owner_name'] ?? '',
            $s['notes'] ?? '',
            (int)$s['is_active'],
        ]);
    }
    fclose($out);
    exit;
}

// All units for the assign dropdown.
$unitsAll = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
$unitsAll->execute([$assocId]);
$unitsList = $unitsAll->fetchAll();

$preselectUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$showAdd    = ($_GET['action'] ?? '') === 'new';
$showImport = ($_GET['action'] ?? '') === 'import';
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
        <?php if (!$showAdd && !$editSpot && !$showImport): ?>
            <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                <a class="btn btn--ghost" href="/dashboard/parking-print.php" target="_blank" rel="noopener" title="Single-column list printout">🖨 Print list</a>
                <a class="btn btn--ghost" href="/dashboard/parking-print.php?cols=3" target="_blank" rel="noopener" title="Compact three-column printout">🖨 3-column</a>
                <a class="btn btn--ghost" href="?export=csv" title="Download every spot as CSV">⬇ Export CSV</a>
                <a class="btn btn--ghost" href="?action=import">⬆ Import CSV</a>
                <a class="btn btn--primary" href="?action=new">+ New spot</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($importSummary): ?>
        <div class="flash flash--success">
            Imported <strong><?= (int)$importSummary['added'] ?></strong> new spot<?= $importSummary['added']===1?'':'s' ?>.
            <?php if ((int)$importSummary['skipped'] > 0): ?>
                Skipped <strong><?= (int)$importSummary['skipped'] ?></strong> row<?= $importSummary['skipped']===1?'':'s' ?> with kind+number combinations that already exist.
            <?php endif; ?>
            <?php if (!empty($importSummary['errors'])): ?>
                <details style="margin-top: var(--sp-2);">
                    <summary><?= count($importSummary['errors']) ?> row<?= count($importSummary['errors'])===1?'':'s' ?> with warnings</summary>
                    <ul style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">
                        <?php foreach ($importSummary['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($showImport): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Import parking spots from CSV</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/parking.php">← Back</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm);">
            Required column: <code>number</code>. Optional:
            <code>kind</code> (<?= e(implode(' / ', array_keys($KINDS))) ?>; defaults to <code>garage</code>),
            <code>unit_number</code> (matches the unit_number on a registered unit; leave blank for unassigned),
            <code>notes</code>,
            <code>is_active</code> (1/0 or yes/no; defaults to 1).
            <strong>Existing <em>kind</em>+<em>number</em> combinations are skipped</strong> — re-running the same CSV is safe and won't overwrite hand-edits. To change a spot, edit it from the parking list. Header row required.
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">kind,number,unit_number,notes,is_active
garage,64,421,Roof access,1
garage,12,101A,,1
surface,P-7,101A,,1
covered,A12,205,EV charging,1
tandem,T-3,,Visitor / unassigned,1</pre>

        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import">
            <div class="field">
                <label class="field__label" for="csv">CSV file (max 1 MB)</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/parking.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Import</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

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

    <?php
    // Optional ?kind= filter chip. When set, narrow the table to just that kind.
    $kindFilter = $_GET['kind'] ?? '';
    if (!array_key_exists($kindFilter, $KINDS)) $kindFilter = '';
    $rowsToShow = $kindFilter === '' ? $spots : array_values(array_filter($spots, fn($s) => $s['kind'] === $kindFilter));
    ?>
    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-3); flex-wrap: wrap;">
        <a class="badge <?= $kindFilter === '' ? 'badge--navy' : '' ?>"
           href="/dashboard/parking.php" style="text-decoration:none; <?= $kindFilter !== '' ? 'opacity: 0.6;' : '' ?>">
            All <span style="margin-left: 4px;"><?= count($spots) ?></span>
        </a>
        <?php foreach ($KINDS as $k => $lbl):
            $n = count($byKind[$k]);
            if ($n === 0) continue; // hide empty kinds — Kevin doesn't want placeholders
            $active = $kindFilter === $k;
        ?>
            <a class="badge <?= $active ? 'badge--navy' : '' ?>"
               href="?kind=<?= e($k) ?>"
               style="text-decoration:none; <?= !$active ? 'opacity: 0.6;' : '' ?>">
                <?= e($lbl) ?> <span style="margin-left: 4px;"><?= $n ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rowsToShow): ?>
        <div class="card card--padded center" style="padding: var(--sp-8) var(--sp-6);">
            <p class="muted">No parking spots yet. <a href="?action=new">Add the first one</a> or <a href="?action=import">import a CSV</a>.</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Kind</th>
                <th>Number</th>
                <th>Assigned to unit</th>
                <th>Primary owner</th>
                <th>Notes</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rowsToShow as $s):
            $kindClass = match ($s['kind']) {
                'garage'  => 'badge--navy',
                'surface' => 'badge--info',
                'covered' => 'badge--success',
                'tandem'  => 'badge--warning',
                default   => '',
            };
        ?>
            <tr style="<?= (int)$s['is_active'] === 0 ? 'opacity: 0.55;' : '' ?>">
                <td><span class="badge <?= $kindClass ?>"><?= e((string)$KINDS[$s['kind']]) ?></span></td>
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
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?= e($KINDS[$s['kind']]) ?> <?= e((string)$s['number']) ?>?');">
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

<?php require __DIR__ . '/../includes/footer.php'; ?>

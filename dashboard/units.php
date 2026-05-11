<?php
// Units list — every registered unit in this association. Manager-only entry
// point; click a row for the unit detail page (occupants, per-unit docs).
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;
$importSummary = null;

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    $num     = trim((string)($_POST['unit_number'] ?? ''));
    $type    = $_POST['type'] ?? 'condo';
    $beds    = $_POST['bedrooms'] !== '' ? (int)$_POST['bedrooms'] : null;
    $baths   = $_POST['baths'] !== '' ? (float)$_POST['baths'] : null;
    $sqft    = $_POST['square_footage'] !== '' ? (int)$_POST['square_footage'] : null;
    $pct     = $_POST['ownership_percent'] !== '' ? (float)$_POST['ownership_percent'] : null;
    $hoaA    = $_POST['annual_hoa_assessment']    !== '' ? (float)$_POST['annual_hoa_assessment']    : null;
    $garA    = $_POST['annual_garage_assessment'] !== '' ? (float)$_POST['annual_garage_assessment'] : null;
    $garage  = trim((string)($_POST['garage_number'] ?? ''));
    $parking = trim((string)($_POST['parking_spot'] ?? ''));
    $notes   = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($type, ['condo','townhouse','single_family','apartment','other'], true)) $type = 'condo';

    if ($num === '') {
        $flashError = 'Unit number is required.';
    } else {
        try {
            db()->prepare(
                'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage, ownership_percent, annual_hoa_assessment, annual_garage_assessment, garage_number, parking_spot, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$assocId, $num, $type, $beds, $baths, $sqft, $pct, $hoaA, $garA, $garage ?: null, $parking ?: null, $notes ?: null]);
            $newId = (int)db()->lastInsertId();
            audit('unit.added', ['unit_number' => $num], $newId, 'unit');
            flash('success', "Unit \"$num\" registered.");
            redirect('/dashboard/unit.php?id=' . $newId);
        } catch (PDOException $e) {
            $flashError = "Unit \"$num\" already exists.";
        }
    }
}

// --- CSV bulk import ---
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
            $added = 0; $updated = 0; $skipped = 0; $errors = []; $row = 0; $headerMap = null;
            $allowedTypes = ['condo','townhouse','single_family','apartment','other'];

            while (($cols = fgetcsv($fh)) !== false) {
                $row++;
                if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

                if ($headerMap === null) {
                    $headerMap = [];
                    foreach ($cols as $i => $name) {
                        $key = strtolower(trim(str_replace([' ', '-'], '_', (string)$name)));
                        $headerMap[$key] = $i;
                    }
                    if (!isset($headerMap['unit_number'])) {
                        $flashError = 'Missing required column: unit_number. Required: unit_number. Optional: type, bedrooms, baths, square_footage, ownership_percent, garage_number, parking_spot, notes.';
                        break;
                    }
                    continue;
                }

                $get = fn($k) => isset($headerMap[$k], $cols[$headerMap[$k]]) ? trim((string)$cols[$headerMap[$k]]) : '';
                $num     = $get('unit_number');
                $typeVal = strtolower($get('type')) ?: 'condo';
                $beds    = $get('bedrooms');
                $baths   = $get('baths');
                $sqft    = $get('square_footage');
                $pct     = $get('ownership_percent');
                $hoaA    = $get('annual_hoa_assessment');
                $garA    = $get('annual_garage_assessment');
                $garage  = $get('garage_number');
                $parking = $get('parking_spot');
                $rNotes  = $get('notes');

                if ($num === '') { $errors[] = "Row $row: missing unit_number"; continue; }
                if (!in_array($typeVal, $allowedTypes, true)) $typeVal = 'condo';

                // Upsert: if unit_number exists, update; else insert.
                $check = db()->prepare('SELECT id FROM units WHERE association_id = ? AND unit_number = ?');
                $check->execute([$assocId, $num]);
                $existingId = (int)($check->fetchColumn() ?: 0);

                $params = [
                    $typeVal,
                    $beds  !== '' ? (int)$beds : null,
                    $baths !== '' ? (float)$baths : null,
                    $sqft  !== '' ? (int)$sqft : null,
                    $pct   !== '' ? (float)$pct : null,
                    $hoaA  !== '' ? (float)$hoaA : null,
                    $garA  !== '' ? (float)$garA : null,
                    $garage  ?: null,
                    $parking ?: null,
                    $rNotes  ?: null,
                ];

                if ($existingId) {
                    // Skip — don't overwrite an existing unit's data. To change
                    // an existing unit, edit it directly via /dashboard/unit.php.
                    $skipped++;
                } else {
                    db()->prepare(
                        'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage,
                                            ownership_percent, annual_hoa_assessment, annual_garage_assessment,
                                            garage_number, parking_spot, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute(array_merge([$assocId, $num], $params));
                    $added++;
                }
            }
            fclose($fh);
            $importSummary = ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
            audit('units.imported', $importSummary);
        }
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $uid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM units WHERE id = ? AND association_id = ?')->execute([$uid, $assocId]);
    audit('unit.deleted', [], $uid, 'unit');
    flash('success', 'Unit deleted. Linked occupant rows were removed; unit-scoped documents reverted to association-wide.');
    redirect('/dashboard/units.php');
}

// Listing — units with occupant counts.
$rows = db()->prepare(
    'SELECT u.*,
            (SELECT COUNT(*) FROM unit_occupants WHERE unit_id = u.id) AS occupant_count,
            (SELECT GROUP_CONCAT(DISTINCT role ORDER BY role) FROM unit_occupants WHERE unit_id = u.id) AS roles
       FROM units u
      WHERE u.association_id = ?
      ORDER BY CAST(u.unit_number AS UNSIGNED), u.unit_number'
);
$rows->execute([$assocId]);
$units = $rows->fetchAll();

$showAdd    = ($_GET['action'] ?? '') === 'new';
$showImport = ($_GET['action'] ?? '') === 'import';

$active = 'units';
$page_title = 'Units — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Units</h1>
            <p class="muted">Every registered unit in <?= e((string)$association['name']) ?>. Click a row for occupants, ownership, and unit-scoped documents.</p>
        </div>
        <?php if (!$showAdd && !$showImport): ?>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="?action=import">⬆ Import CSV</a>
                <a class="btn btn--primary" href="?action=new">+ Register a unit</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($importSummary): ?>
        <div class="flash flash--success">
            Imported <strong><?= (int)$importSummary['added'] ?></strong> new unit<?= $importSummary['added']===1?'':'s' ?>.
            <?php if ((int)$importSummary['skipped'] > 0): ?>
                Skipped <strong><?= (int)$importSummary['skipped'] ?></strong> row<?= $importSummary['skipped']===1?'':'s' ?> with unit numbers that already exist — edit those units one at a time from the units list to change them.
            <?php endif; ?>
            <?php if (!empty($importSummary['errors'])): ?>
                <details style="margin-top: var(--sp-2);">
                    <summary><?= count($importSummary['errors']) ?> row<?= count($importSummary['errors'])===1?'':'s' ?> errored</summary>
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
            <h3 class="card__title">Import units from CSV</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/units.php">← Back</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm);">
            Required column: <code>unit_number</code>. Optional: <code>type</code> (condo / townhouse / single_family / apartment / other),
            <code>bedrooms</code>, <code>baths</code> (e.g. 2.5), <code>square_footage</code>, <code>ownership_percent</code>
            (e.g. 0.4521), <code>annual_hoa_assessment</code>, <code>annual_garage_assessment</code>,
            <code>garage_number</code>, <code>parking_spot</code>, <code>notes</code>.
            <strong>Existing unit numbers are skipped</strong> — only new ones get inserted, so re-running the same CSV is safe and won't overwrite hand-edits. To change a unit, edit it from <a href="/dashboard/units.php">the units list</a>. Header row required.
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">unit_number,type,bedrooms,baths,square_footage,ownership_percent,annual_hoa_assessment,annual_garage_assessment,garage_number,parking_spot,notes
101A,condo,2,2.0,1100,0.4521,4800.00,600.00,12,P-7,Corner unit
421,condo,3,2.5,1450,0.6800,5400.00,720.00,64,,Roof access</pre>

        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import">
            <div class="field">
                <label class="field__label" for="csv">CSV file (max 1 MB)</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/units.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Import</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showAdd): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Register a unit</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/units.php">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="add">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="u-num">Unit number</label>
                    <input class="input" id="u-num" name="unit_number" required maxlength="20" placeholder="101A">
                </div>
                <div class="field">
                    <label class="field__label" for="u-type">Type</label>
                    <select class="select" id="u-type" name="type">
                        <?php foreach (['condo'=>'Condo','townhouse'=>'Townhouse','single_family'=>'Single family','apartment'=>'Apartment','other'=>'Other'] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>"><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1.2fr; gap: var(--sp-3);">
                <div class="field">
                    <label class="field__label" for="u-bd">Bedrooms</label>
                    <input class="input" type="number" min="0" max="20" id="u-bd" name="bedrooms" value="">
                </div>
                <div class="field">
                    <label class="field__label" for="u-ba">Baths</label>
                    <input class="input" type="number" step="0.5" min="0" max="20" id="u-ba" name="baths" value="">
                </div>
                <div class="field">
                    <label class="field__label" for="u-sf">Sq ft</label>
                    <input class="input" type="number" min="0" max="50000" id="u-sf" name="square_footage" value="">
                </div>
                <div class="field">
                    <label class="field__label" for="u-pct">Ownership %</label>
                    <input class="input" type="number" step="0.0001" min="0" max="100" id="u-pct" name="ownership_percent" value="">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="u-garage">Garage #</label>
                    <input class="input" id="u-garage" name="garage_number" maxlength="20" placeholder="64">
                    <div class="field__hint">If different from the unit number — common in mid-rise condos.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="u-parking">Parking spot</label>
                    <input class="input" id="u-parking" name="parking_spot" maxlength="20" placeholder="P-7">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="u-hoa">Annual HOA assessment ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="u-hoa" name="annual_hoa_assessment" placeholder="4800.00">
                </div>
                <div class="field">
                    <label class="field__label" for="u-gara">Annual garage assessment ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="u-gara" name="annual_garage_assessment" placeholder="600.00">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="u-notes">Notes</label>
                <textarea class="textarea" id="u-notes" name="notes" rows="2" placeholder="Anything specific to this unit"></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/units.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Register unit</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$units): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No units registered yet.</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">Register the first unit</a></p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Unit</th><th>Type</th><th>Bd / Ba / Sqft</th><th>Garage / Parking</th><th>Own. %</th>
                <th>Occupants</th><th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($units as $u): ?>
            <tr>
                <td><a href="/dashboard/unit.php?id=<?= (int)$u['id'] ?>"><strong><?= e((string)$u['unit_number']) ?></strong></a></td>
                <td><?= e(str_replace('_',' ',(string)$u['type'])) ?></td>
                <td>
                    <?= $u['bedrooms'] !== null ? (int)$u['bedrooms'] : '—' ?> /
                    <?= $u['baths'] !== null ? rtrim(rtrim(number_format((float)$u['baths'], 1, '.', ''), '0'), '.') : '—' ?> /
                    <?= $u['square_footage'] !== null ? number_format((int)$u['square_footage']) : '—' ?>
                </td>
                <td>
                    <?php if (!empty($u['garage_number'])): ?>
                        <span title="Garage">G:<?= e((string)$u['garage_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($u['parking_spot'])): ?>
                        <span title="Parking" style="margin-left: 6px;">P:<?= e((string)$u['parking_spot']) ?></span>
                    <?php endif; ?>
                    <?php if (empty($u['garage_number']) && empty($u['parking_spot'])): ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['ownership_percent'] !== null ? rtrim(rtrim(number_format((float)$u['ownership_percent'], 4, '.', ''), '0'), '.') . '%' : '—' ?></td>
                <td>
                    <?php if ((int)$u['occupant_count'] === 0): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <strong><?= (int)$u['occupant_count'] ?></strong>
                        <span class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace(',', ' · ', str_replace('_',' ',(string)$u['roles']))) ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/unit.php?id=<?= (int)$u['id'] ?>">Open</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete unit <?= e((string)$u['unit_number']) ?>? Occupant links will be removed; documents tagged to this unit will revert to association-wide.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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

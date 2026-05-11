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
    $notes   = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($type, ['condo','townhouse','single_family','apartment','business','main_office','other'], true)) $type = 'condo';

    if ($num === '') {
        $flashError = 'Unit number is required.';
    } else {
        try {
            db()->prepare(
                'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage, ownership_percent, annual_hoa_assessment, annual_garage_assessment, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$assocId, $num, $type, $beds, $baths, $sqft, $pct, $hoaA, $garA, $notes ?: null]);
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
            $allowedTypes = ['condo','townhouse','single_family','apartment','business','main_office','other'];

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
                    $rNotes  ?: null,
                ];

                if ($existingId) {
                    // Skip — don't overwrite an existing unit's data. To change
                    // an existing unit, edit it directly via /dashboard/unit.php.
                    $skipped++;
                } else {
                    db()->prepare(
                        'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage,
                                            ownership_percent, annual_hoa_assessment, annual_garage_assessment, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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

// Listing — units with occupant counts, optionally filtered by a search term
// that matches either unit_number or any occupant's name/email.
$qSearch = trim((string)($_GET['q'] ?? ''));

// Listing query — pulls everything needed for the column layout:
//   Unit | Primary owner | Occupants | Parking | Own.% | Monthly fee
// Plus a flag for rental status (any tenant occupant → rented).
$sql = "SELECT u.*,
               (SELECT COUNT(*) FROM unit_occupants WHERE unit_id = u.id) AS occupant_count,
               (SELECT TRIM(CONCAT(IFNULL(usr.first_name,''), ' ', IFNULL(usr.last_name,'')))
                  FROM unit_occupants uo JOIN users usr ON usr.id = uo.user_id
                 WHERE uo.unit_id = u.id AND uo.is_primary = 1
                 LIMIT 1) AS primary_owner_name,
               (SELECT GROUP_CONCAT(CONCAT(ps.kind, ':', ps.number) SEPARATOR ', ')
                  FROM parking_spots ps
                 WHERE ps.assigned_unit_id = u.id
                 ORDER BY FIELD(ps.kind,'garage','surface','covered','tandem','other'),
                          CAST(ps.number AS UNSIGNED), ps.number) AS parking_list,
               EXISTS (SELECT 1 FROM unit_occupants uo
                        WHERE uo.unit_id = u.id AND uo.role = 'tenant') AS is_rented
          FROM units u
         WHERE u.association_id = ?";
$params = [$assocId];
if ($qSearch !== '') {
    $like = "%$qSearch%";
    $sql .= ' AND (
        u.unit_number LIKE ?
        OR EXISTS (
            SELECT 1 FROM parking_spots ps
             WHERE ps.assigned_unit_id = u.id
               AND (ps.number LIKE ? OR CONCAT(ps.kind, " ", ps.number) LIKE ?)
        )
        OR EXISTS (
            SELECT 1 FROM unit_occupants uo
              JOIN users mu ON mu.id = uo.user_id
             WHERE uo.unit_id = u.id
               AND (mu.first_name LIKE ?
                    OR mu.last_name LIKE ?
                    OR mu.email LIKE ?
                    OR CONCAT(mu.first_name, " ", mu.last_name) LIKE ?)
        )
    )';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY CAST(u.unit_number AS UNSIGNED), u.unit_number';

$rows = db()->prepare($sql);
$rows->execute($params);
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
            Required column: <code>unit_number</code>. Optional: <code>type</code> (condo / townhouse / single_family / apartment / business / main_office / other),
            <code>bedrooms</code>, <code>baths</code> (e.g. 2.5), <code>square_footage</code>, <code>ownership_percent</code>
            (e.g. 0.4521), <code>annual_hoa_assessment</code>, <code>annual_garage_assessment</code>, <code>notes</code>.
            Garage and parking-spot assignments live on the <a href="/dashboard/parking.php">Parking page</a> — assign them there instead of here.
            <strong>Existing unit numbers are skipped</strong> — only new ones get inserted, so re-running the same CSV is safe and won't overwrite hand-edits. To change a unit, edit it from <a href="/dashboard/units.php">the units list</a>. Header row required.
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">unit_number,type,bedrooms,baths,square_footage,ownership_percent,annual_hoa_assessment,annual_garage_assessment,notes
101A,condo,2,2.0,1100,0.4521,4800.00,600.00,Corner unit
421,condo,3,2.5,1450,0.6800,5400.00,720.00,Roof access</pre>

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
                        <?php foreach (['condo'=>'Condo','townhouse'=>'Townhouse','single_family'=>'Single family','apartment'=>'Apartment','business'=>'Business','main_office'=>'Main office','other'=>'Other'] as $v=>$lbl): ?>
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

    <form method="get" class="row" style="margin-bottom: var(--sp-4); gap: var(--sp-2); flex-wrap: wrap;">
        <input class="input" type="search" name="q" placeholder="Search unit #, owner name, garage/parking…" value="<?= e($qSearch) ?>" style="max-width: 360px; flex: 1;">
        <button class="btn btn--ghost" type="submit">Search</button>
        <?php if ($qSearch !== ''): ?>
            <a class="btn btn--ghost" href="/dashboard/units.php">Clear</a>
            <span class="muted" style="align-self:center; font-size: var(--fs-sm);"><?= count($units) ?> match<?= count($units)===1?'':'es' ?></span>
        <?php endif; ?>
    </form>

    <?php if (!$units && $qSearch === ''): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No units registered yet.</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">Register the first unit</a></p>
        </div>
    <?php elseif (!$units): ?>
        <div class="card card--padded center" style="padding: var(--sp-6);">
            <p class="muted">No units match &ldquo;<?= e($qSearch) ?>&rdquo;.</p>
            <p style="margin-top: var(--sp-3);"><a class="btn btn--ghost" href="/dashboard/units.php">Clear search</a></p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Unit</th>
                <th>Primary owner</th>
                <th>Occupants</th>
                <th>Parking</th>
                <th>Own. %</th>
                <th title="Monthly = (annual HOA + annual garage) / 12">Monthly fee</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($units as $u):
            $annualHoa = $u['annual_hoa_assessment']    !== null ? (float)$u['annual_hoa_assessment']    : null;
            $annualGar = $u['annual_garage_assessment'] !== null ? (float)$u['annual_garage_assessment'] : null;
            $monthlyTotal = ($annualHoa ?? 0) / 12 + ($annualGar ?? 0) / 12;
            $hasAssessment = $annualHoa !== null || $annualGar !== null;
            $isRented = (int)$u['is_rented'] === 1;
            // Format parking_list (kind:number,kind:number) for compact display
            $parkingDisplay = '';
            if (!empty($u['parking_list'])) {
                $bits = explode(', ', (string)$u['parking_list']);
                $fmt = [];
                foreach ($bits as $b) {
                    [$k, $n] = array_pad(explode(':', $b, 2), 2, '');
                    $abbr = strtoupper(mb_substr($k, 0, 1)); // G/S/C/T/O
                    $fmt[] = $abbr . ':' . $n;
                }
                $parkingDisplay = implode(' · ', $fmt);
            }
        ?>
            <tr>
                <td>
                    <a href="/dashboard/unit.php?id=<?= (int)$u['id'] ?>"><strong><?= e((string)$u['unit_number']) ?></strong></a>
                    <?php if ($isRented): ?>
                        <span class="badge badge--warning" style="font-size: var(--fs-xs); margin-left: 4px;" title="At least one occupant is a tenant">Rented</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($u['primary_owner_name'])): ?>
                        <strong><?= e((string)$u['primary_owner_name']) ?></strong>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$u['occupant_count'] === 0): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <strong><?= (int)$u['occupant_count'] ?></strong>
                        <span class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace(',', ' · ', str_replace('_',' ',(string)$u['roles']))) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($parkingDisplay !== ''): ?>
                        <span style="font-size: var(--fs-sm);"><?= e($parkingDisplay) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['ownership_percent'] !== null ? rtrim(rtrim(number_format((float)$u['ownership_percent'], 4, '.', ''), '0'), '.') . '%' : '—' ?></td>
                <td>
                    <?php if ($hasAssessment): ?>
                        <strong>$<?= number_format($monthlyTotal, 2) ?>/mo</strong>
                        <?php if ($annualHoa !== null && $annualGar !== null): ?>
                            <div class="muted" style="font-size: var(--fs-xs);">HOA $<?= number_format($annualHoa / 12, 2) ?> + Garage $<?= number_format($annualGar / 12, 2) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">—</span>
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

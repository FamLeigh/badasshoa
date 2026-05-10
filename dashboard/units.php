<?php
// Units list — every registered unit in this association. Manager-only entry
// point; click a row for the unit detail page (occupants, per-unit docs).
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
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
                'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage, ownership_percent, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$assocId, $num, $type, $beds, $baths, $sqft, $pct, $notes ?: null]);
            $newId = (int)db()->lastInsertId();
            audit('unit.added', ['unit_number' => $num], $newId, 'unit');
            flash('success', "Unit \"$num\" registered.");
            redirect('/dashboard/unit.php?id=' . $newId);
        } catch (PDOException $e) {
            $flashError = "Unit \"$num\" already exists.";
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

$showAdd = ($_GET['action'] ?? '') === 'new';

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
        <?php if (!$showAdd): ?>
            <a class="btn btn--primary" href="?action=new">+ Register a unit</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

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
                <th>Unit</th><th>Type</th><th>Bd / Ba / Sqft</th><th>Ownership %</th>
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

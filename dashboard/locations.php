<?php
// Locations CRUD — building / property locations curated by managers.
// Used today by /dashboard/events.php (location autocomplete on the form).
// Will also power the maintenance-request flow once we build that.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    if ($name === '') {
        $flashError = 'Name is required.';
    } else {
        try {
            db()->prepare(
                'INSERT INTO locations (association_id, name, description, sort_order)
                 VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM locations AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $name, $desc ?: null, $assocId]);
            audit('location.added', ['name' => $name], (int)db()->lastInsertId(), 'location');
            flash('success', "Location \"$name\" added.");
            redirect('/dashboard/locations.php');
        } catch (PDOException $e) {
            $flashError = "A location named \"$name\" already exists.";
        }
    }
}

// --- Edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    $lid    = (int)($_POST['id'] ?? 0);
    $name   = trim((string)($_POST['name'] ?? ''));
    $desc   = trim((string)($_POST['description'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;

    $check = db()->prepare('SELECT name FROM locations WHERE id = ? AND association_id = ?');
    $check->execute([$lid, $assocId]);
    $oldName = (string)($check->fetchColumn() ?: '');
    if ($oldName === '') {
        $flashError = 'Location not found.';
    } elseif ($name === '') {
        $flashError = 'Name is required.';
    } else {
        try {
            db()->prepare(
                'UPDATE locations SET name = ?, description = ?, is_active = ? WHERE id = ? AND association_id = ?'
            )->execute([$name, $desc ?: null, $active, $lid, $assocId]);
            audit('location.edited', ['from' => $oldName, 'to' => $name, 'active' => (bool)$active], $lid, 'location');
            flash('success', "Location updated.");
            redirect('/dashboard/locations.php');
        } catch (PDOException $e) {
            $flashError = "Another location is already named \"$name\".";
        }
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $lid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM locations WHERE id = ? AND association_id = ?')->execute([$lid, $assocId]);
    audit('location.deleted', [], $lid, 'location');
    flash('success', 'Location deleted. Existing event/maintenance entries keep the location label as plain text.');
    redirect('/dashboard/locations.php');
}

$rows = db()->prepare('SELECT * FROM locations WHERE association_id = ? ORDER BY is_active DESC, sort_order, name');
$rows->execute([$assocId]);
$locations = $rows->fetchAll();

$editLoc = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM locations WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editLoc = $stmt->fetch() ?: null;
}
$showAdd = ($_GET['action'] ?? '') === 'new';

$active = 'locations';
$page_title = 'Locations — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Locations</h1>
            <p class="muted">Building locations for events and (eventually) maintenance work orders.</p>
        </div>
        <?php if (!$showAdd && !$editLoc): ?>
            <a class="btn btn--primary" href="?action=new">+ New location</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showAdd || $editLoc):
        $isEdit = $editLoc !== null;
        $vals = $editLoc ?? ['name'=>'','description'=>'','is_active'=>1,'id'=>0];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit location' : 'New location' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/locations.php">← Back to list</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>
            <div class="field">
                <label class="field__label" for="loc-name">Name</label>
                <input class="input" id="loc-name" name="name" required maxlength="120" value="<?= e((string)$vals['name']) ?>" placeholder="Clubhouse · Lobby · Pool deck · Roof terrace">
            </div>
            <div class="field">
                <label class="field__label" for="loc-desc">Description (optional)</label>
                <textarea class="textarea" id="loc-desc" name="description" rows="3" placeholder="Any notes — capacity, access instructions, etc."><?= e((string)($vals['description'] ?? '')) ?></textarea>
            </div>
            <?php if ($isEdit): ?>
            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="is_active" <?= (int)$vals['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Active — shows in the location picker on event/maintenance forms</span>
                </label>
            </div>
            <?php endif; ?>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/locations.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create location' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$locations): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No locations yet. Add the first one — it'll show up in the location dropdown when you create events.</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">Add your first location</a></p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Name</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($locations as $loc): ?>
            <tr style="<?= (int)$loc['is_active'] === 0 ? 'opacity: 0.55;' : '' ?>">
                <td><strong><?= e((string)$loc['name']) ?></strong></td>
                <td><span class="muted" style="font-size: var(--fs-sm);"><?= e(mb_strimwidth((string)($loc['description'] ?? ''), 0, 80, '…')) ?: '—' ?></span></td>
                <td>
                    <?php if ((int)$loc['is_active'] === 1): ?>
                        <span class="badge badge--success">active</span>
                    <?php else: ?>
                        <span class="badge">inactive</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$loc['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete location &quot;<?= e((string)$loc['name']) ?>&quot;? Existing event/maintenance entries keep the label as text.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$loc['id'] ?>">
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

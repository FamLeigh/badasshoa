<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
$canManage = role_can_manage(viewing_role());
if (!can_do('manage_attractions')) { http_response_code(403); die('Access denied'); }

$assocId  = (int)$_SESSION['association_id'];
$canEdit  = can_do('manage_attractions');
$errors   = [];
$success  = '';

$CATEGORIES = [
    'dining'        => '🍽️  Dining',
    'shopping'      => '🛍️  Shopping',
    'entertainment' => '🎭  Entertainment',
    'outdoor'       => '🌿  Outdoor',
    'culture'       => '🎨  Culture',
    'services'      => '🔧  Services',
    'other'         => '📍  Other',
];

// ── POST handlers ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $editId  = (int)($_POST['id'] ?? 0);
        $name    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
        $desc    = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000) ?: null;
        $url     = mb_substr(trim((string)($_POST['website_url'] ?? '')), 0, 500) ?: null;
        $address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 500) ?: null;
        $cat     = array_key_exists((string)($_POST['category'] ?? ''), $CATEGORIES)
                     ? $_POST['category'] : 'other';
        $sort    = max(0, min(9999, (int)($_POST['sort_order'] ?? 0)));
        $active  = isset($_POST['active']) ? 1 : 0;

        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Website URL is not valid.';
        }
        if ($name === '') $errors[] = 'Name is required.';

        // Geocode address if provided
        $lat = null; $lon = null;
        if ($address !== null && empty($errors)) {
            $geo = geocode_address($address);
            if ($geo) { $lat = $geo['lat']; $lon = $geo['lon']; }
        }

        // Photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo((string)($_FILES['photo']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $errors[] = 'Photo must be JPG, PNG, GIF, or WebP.';
            } elseif ((int)($_FILES['photo']['size'] ?? 0) > 10 * 1024 * 1024) {
                $errors[] = 'Photo must be under 10 MB.';
            } elseif (empty($errors)) {
                $dir  = __DIR__ . '/../storage/uploads/' . $assocId . '/attractions';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = bin2hex(random_bytes(12)) . '.' . $ext;
                $dest  = $dir . '/' . $fname;
                if (move_uploaded_file((string)$_FILES['photo']['tmp_name'], $dest)) {
                    $photoPath = 'attractions/' . $fname;
                } else {
                    $errors[] = 'Photo upload failed — try again.';
                }
            }
        }

        if (empty($errors)) {
            if ($editId > 0) {
                $row = db()->prepare('SELECT * FROM association_attractions WHERE id=? AND association_id=?');
                $row->execute([$editId, $assocId]);
                $row = $row->fetch();
                if ($row) {
                    $oldPhoto = (string)($row['photo_path'] ?? '');
                    $sql  = 'UPDATE association_attractions SET name=?,description=?,website_url=?,address=?,latitude=?,longitude=?,category=?,sort_order=?,active=?';
                    $args = [$name,$desc,$url,$address,$lat,$lon,$cat,$sort,$active];
                    if ($photoPath !== null) {
                        $sql .= ',photo_path=?';
                        $args[] = $photoPath;
                        if ($oldPhoto) {
                            $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $oldPhoto;
                            if (is_file($f)) unlink($f);
                        }
                    }
                    $sql .= ' WHERE id=? AND association_id=?';
                    $args[] = $editId; $args[] = $assocId;
                    db()->prepare($sql)->execute($args);
                    flash('success', 'Attraction updated.');
                    redirect('/dashboard/attractions.php');
                }
            } else {
                db()->prepare(
                    'INSERT INTO association_attractions
                        (association_id,name,description,website_url,address,latitude,longitude,category,sort_order,active,photo_path)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$assocId,$name,$desc,$url,$address,$lat,$lon,$cat,$sort,$active,$photoPath]);
                flash('success', 'Attraction added.');
                redirect('/dashboard/attractions.php');
            }
        }
    }

    if ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $row   = db()->prepare('SELECT photo_path FROM association_attractions WHERE id=? AND association_id=?');
        $row->execute([$delId, $assocId]);
        $row = $row->fetch();
        if ($row) {
            if ($row['photo_path']) {
                $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $row['photo_path'];
                if (is_file($f)) unlink($f);
            }
            db()->prepare('DELETE FROM association_attractions WHERE id=? AND association_id=?')->execute([$delId, $assocId]);
            flash('success', 'Attraction removed.');
        }
        redirect('/dashboard/attractions.php');
    }

    if ($action === 'toggle') {
        $togId = (int)($_POST['id'] ?? 0);
        db()->prepare(
            'UPDATE association_attractions SET active = 1 - active WHERE id=? AND association_id=?'
        )->execute([$togId, $assocId]);
        redirect('/dashboard/attractions.php');
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────

$editRow = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $canEdit) {
    $stmt = db()->prepare('SELECT * FROM association_attractions WHERE id=? AND association_id=?');
    $stmt->execute([$editId, $assocId]);
    $editRow = $stmt->fetch() ?: null;
}

$stmt = db()->prepare(
    'SELECT * FROM association_attractions WHERE association_id=? ORDER BY sort_order, name'
);
$stmt->execute([$assocId]);
$attractions = $stmt->fetchAll();

$assocLat = isset($association['latitude'])  && $association['latitude']  !== null ? (float)$association['latitude']  : null;
$assocLon = isset($association['longitude']) && $association['longitude'] !== null ? (float)$association['longitude'] : null;

$active      = 'attractions';
$page_title  = 'Area Attractions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

<div class="row row--between" style="margin-bottom: var(--sp-6); gap: var(--sp-4); flex-wrap: wrap; align-items: flex-end;">
    <div>
        <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);">Area Attractions</h1>
        <p class="muted" style="margin: 0;">Local places worth knowing about — shown on your public community page.</p>
    </div>
    <div class="row" style="gap: var(--sp-3);">
        <?php if ($canEdit): ?>
            <a class="btn btn--primary" href="/dashboard/attractions.php?edit=new">+ Add attraction</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach (flash_take() as $f): ?>
    <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<?php if ($errors): ?>
    <div class="flash flash--error">
        <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canEdit && ($editRow || isset($_GET['edit']))): ?>
<!-- ── Add / Edit form ──────────────────────────────────────────────────── -->
<div class="card card--padded" style="margin-bottom: var(--sp-6);">
    <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-4);">
        <?= $editRow ? 'Edit Attraction' : 'New Attraction' ?>
    </h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
        <div class="form-grid form-grid--2" style="gap: var(--sp-4);">
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="a-name">Name *</label>
                <input class="input" type="text" id="a-name" name="name" required maxlength="200"
                       value="<?= e((string)($editRow['name'] ?? $_POST['name'] ?? '')) ?>"
                       placeholder="e.g. Ponce Inlet Lighthouse">
            </div>
            <div class="field">
                <label class="field__label" for="a-cat">Category</label>
                <select class="input" id="a-cat" name="category">
                    <?php foreach ($CATEGORIES as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= (($editRow['category'] ?? $_POST['category'] ?? 'other') === $val) ? 'selected' : '' ?>>
                            <?= e($lbl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="a-sort">Sort order</label>
                <input class="input" type="number" id="a-sort" name="sort_order" min="0" max="9999"
                       value="<?= (int)($editRow['sort_order'] ?? $_POST['sort_order'] ?? 0) ?>">
                <div class="field__hint">Lower = appears first. Same sort order = alphabetical.</div>
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="a-desc">Description</label>
                <textarea class="textarea" id="a-desc" name="description" rows="3" maxlength="2000"
                          placeholder="Brief description of this place…"><?= e((string)($editRow['description'] ?? $_POST['description'] ?? '')) ?></textarea>
            </div>
            <div class="field">
                <label class="field__label" for="a-url">Website URL</label>
                <input class="input" type="url" id="a-url" name="website_url" maxlength="500"
                       value="<?= e((string)($editRow['website_url'] ?? $_POST['website_url'] ?? '')) ?>"
                       placeholder="https://...">
            </div>
            <div class="field">
                <label class="field__label" for="a-address">Address</label>
                <input class="input" type="text" id="a-address" name="address" maxlength="500"
                       value="<?= e((string)($editRow['address'] ?? $_POST['address'] ?? '')) ?>"
                       placeholder="4931 S Peninsula Dr, Ponce Inlet, FL 32127">
                <div class="field__hint">Used to calculate distance from your building and link to maps. Geocoded automatically on save.</div>
                <?php if (!empty($editRow['latitude']) && !empty($editRow['longitude'])): ?>
                    <div style="margin-top: var(--sp-2); font-size: var(--fs-xs); color: var(--color-green);">
                        ✓ Geocoded — <?= round((float)$editRow['latitude'], 4) ?>, <?= round((float)$editRow['longitude'], 4) ?>
                    </div>
                <?php elseif (!empty($editRow['address'])): ?>
                    <div style="margin-top: var(--sp-2); font-size: var(--fs-xs); color: var(--color-orange);">
                        Address on file but not yet geocoded — save again to retry.
                    </div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label class="field__label" for="a-photo">Photo</label>
                <?php if (!empty($editRow['photo_path'])): ?>
                    <div style="margin-bottom: var(--sp-3); padding: var(--sp-3); background: var(--color-surface-2); border-radius: var(--r-md); display: flex; align-items: center; gap: var(--sp-3);">
                        <img src="/dashboard/file.php?type=attraction&id=<?= (int)$editRow['id'] ?>" alt=""
                             style="width:80px; height:80px; object-fit:cover; border-radius: var(--r-sm); flex-shrink:0; display:block;">
                        <div>
                            <div style="font-size: var(--fs-sm); font-weight: 600; margin-bottom: 2px;">Current photo</div>
                            <div class="muted" style="font-size: var(--fs-xs);">Choose a new file below to replace it.</div>
                        </div>
                    </div>
                <?php endif; ?>
                <input class="input" type="file" id="a-photo" name="photo" accept="image/*">
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer;">
                    <input type="checkbox" name="active" value="1" <?= ((int)($editRow['active'] ?? 1)) ? 'checked' : '' ?>>
                    <span>Show on public landing page</span>
                </label>
            </div>
        </div>
        <div class="row" style="margin-top: var(--sp-4); gap: var(--sp-3);">
            <button class="btn btn--primary" type="submit"><?= $editRow ? 'Save changes' : 'Add attraction' ?></button>
            <a class="btn btn--ghost" href="/dashboard/attractions.php">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($attractions): ?>

<?php
// Build list of categories that actually exist in this association's attractions
$_usedCats = [];
foreach ($attractions as $_a) { $_usedCats[$_a['category']] = true; }
?>
<div style="margin-bottom: var(--sp-3); display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap;">
    <input type="search" id="attr-search" class="input" placeholder="Search name or address…"
           style="max-width: 260px; font-size: var(--fs-sm);"
           oninput="filterAttrTable()">
    <div style="display: flex; gap: var(--sp-2); flex-wrap: wrap;" id="attr-cat-btns">
        <button class="btn btn--xs btn--primary" data-cat="all" onclick="setAttrCat(this,'all')">All <span id="attr-count">(<?= count($attractions) ?>)</span></button>
        <?php foreach ($CATEGORIES as $catKey => $catLabel):
            if (!isset($_usedCats[$catKey])) continue; ?>
            <button class="btn btn--xs btn--ghost" data-cat="<?= e($catKey) ?>" onclick="setAttrCat(this,'<?= e($catKey) ?>')"><?= e($catLabel) ?></button>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <table class="table" id="attr-table">
        <thead>
            <tr>
                <th style="width:64px;"></th>
                <th>Name</th>
                <th>Category</th>
                <th>Distance</th>
                <th>Status</th>
                <th style="width:130px;"></th>
            </tr>
        </thead>
        <tbody>
        <tr id="attr-no-results" style="display:none;">
            <td colspan="6" style="text-align:center; padding: var(--sp-8); color: var(--color-text-muted);">No attractions match this filter.</td>
        </tr>
        <?php foreach ($attractions as $a): ?>
            <?php
            $distLabel = '';
            if ($assocLat !== null && $assocLon !== null && !empty($a['latitude']) && !empty($a['longitude'])) {
                $mi = haversine_miles($assocLat, $assocLon, (float)$a['latitude'], (float)$a['longitude']);
                $distLabel = $mi < 0.1 ? '< 0.1 mi' : round($mi, 1) . ' mi';
            }
            $mapUrl = '';
            if (!empty($a['latitude']) && !empty($a['longitude'])) {
                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . $a['latitude'] . ',' . $a['longitude'];
            } elseif (!empty($a['address'])) {
                $mapUrl = 'https://maps.google.com/?q=' . rawurlencode((string)$a['address']);
            }
            ?>
            <tr data-cat="<?= e($a['category']) ?>" data-name="<?= e(strtolower((string)$a['name'] . ' ' . (string)$a['address'])) ?>">
                <td style="padding: var(--sp-2);">
                    <?php if (!empty($a['photo_path'])): ?>
                        <img src="/dashboard/file.php?type=attraction&id=<?= (int)$a['id'] ?>" alt=""
                             style="width:52px; height:52px; object-fit:cover; border-radius: var(--r-sm); display:block;">
                    <?php else: ?>
                        <div style="width:52px; height:52px; border-radius: var(--r-sm); background: var(--color-surface-2); display:flex; align-items:center; justify-content:center; font-size:1.4rem;">📍</div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e((string)$a['name']) ?></strong>
                    <?php if (!empty($a['address'])): ?>
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;"><?= e((string)$a['address']) ?></div>
                    <?php elseif (!empty($a['description'])): ?>
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;"><?= e(mb_strimwidth((string)$a['description'], 0, 80, '…')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['website_url'])): ?>
                        <div style="font-size: var(--fs-xs);"><a href="<?= e((string)$a['website_url']) ?>" target="_blank" rel="noopener"><?= e(parse_url((string)$a['website_url'], PHP_URL_HOST) ?: $a['website_url']) ?> ↗</a></div>
                    <?php endif; ?>
                </td>
                <td><?= e($CATEGORIES[$a['category']] ?? $a['category']) ?></td>
                <td style="white-space: nowrap;">
                    <?php if ($distLabel): ?>
                        <span style="font-size: var(--fs-sm);"><?= e($distLabel) ?></span>
                        <?php if ($mapUrl): ?>
                            <br><a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener" style="font-size: var(--fs-xs);">Map ↗</a>
                        <?php endif; ?>
                    <?php elseif ($mapUrl): ?>
                        <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener" class="muted" style="font-size: var(--fs-xs);">Map ↗</a>
                    <?php else: ?>
                        <span class="muted" style="font-size: var(--fs-xs);">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($a['active']): ?>
                        <span class="badge badge--green">Public</span>
                    <?php else: ?>
                        <span class="badge badge--muted">Hidden</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                    <div class="row" style="gap: var(--sp-2); justify-content: flex-end;">
                        <a class="btn btn--xs" href="/dashboard/attractions.php?edit=<?= (int)$a['id'] ?>">Edit</a>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn--xs btn--ghost" type="submit"><?= $a['active'] ? 'Hide' : 'Show' ?></button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove this attraction?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn--xs btn--error" type="submit">Delete</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="card card--padded" style="text-align: center; padding: var(--sp-12);">
    <div style="font-size: 2.5rem; margin-bottom: var(--sp-3);">🌍</div>
    <h3 style="margin: 0 0 var(--sp-2);">No attractions yet</h3>
    <p class="muted" style="margin: 0 0 var(--sp-4);">Add local restaurants, parks, shops, and landmarks your residents will appreciate.</p>
    <?php if ($canEdit): ?>
        <a class="btn btn--primary" href="/dashboard/attractions.php?edit=new">+ Add first attraction</a>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><?php /* /container */ ?>

<script>
var _attrCat = 'all';

function setAttrCat(btn, cat) {
    _attrCat = cat;
    document.querySelectorAll('#attr-cat-btns button').forEach(function(b) {
        b.classList.toggle('btn--primary', b.dataset.cat === cat);
        b.classList.toggle('btn--ghost',   b.dataset.cat !== cat);
    });
    filterAttrTable();
}

function filterAttrTable() {
    var q   = (document.getElementById('attr-search').value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#attr-table tbody tr[data-cat]');
    var vis  = 0;
    rows.forEach(function(row) {
        var catOk  = _attrCat === 'all' || row.dataset.cat === _attrCat;
        var nameOk = q === '' || row.dataset.name.indexOf(q) !== -1;
        var show   = catOk && nameOk;
        row.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('attr-no-results').style.display = vis === 0 ? '' : 'none';
    document.getElementById('attr-count').textContent = '(' + vis + ')';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

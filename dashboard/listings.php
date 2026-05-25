<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
$canManage = role_can_manage(viewing_role());
if (!can_do('submit_listing')) { http_response_code(403); die('Access denied'); }

$assocId  = (int)$_SESSION['association_id'];
$myUserId = (int)$_SESSION['user_id'];
$user     = current_user();

$STATUSES = ['active' => 'Active', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented'];
$TYPES    = ['sale' => 'For Sale', 'rent' => 'For Rent'];

// ── Units available to this user ───────────────────────────────────────────

$allUnitsStmt = db()->prepare(
    'SELECT id, unit_number, bedrooms, baths, square_footage, type
     FROM units WHERE association_id=? ORDER BY unit_number+0, unit_number'
);
$allUnitsStmt->execute([$assocId]);
$allUnits = $allUnitsStmt->fetchAll();

// For non-management: restrict to their own unit
$myUnitRow = null;
if (!$canManage && !empty($user['unit_number'])) {
    foreach ($allUnits as $u) {
        if ($u['unit_number'] === $user['unit_number']) { $myUnitRow = $u; break; }
    }
}
$selectableUnits = $canManage ? $allUnits : ($myUnitRow ? [$myUnitRow] : []);

// Unit data embedded for JS auto-fill
$unitJson = [];
foreach ($allUnits as $u) {
    $unitJson[(int)$u['id']] = [
        'beds' => $u['bedrooms'],
        'baths' => $u['baths'],
        'sqft'  => $u['square_footage'],
        'num'   => $u['unit_number'],
    ];
}

function can_edit_listing(array $listing, int $myUserId, bool $canManage): bool {
    return $canManage || (int)($listing['seller_user_id'] ?? 0) === $myUserId;
}

// ── POST handlers ──────────────────────────────────────────────────────────

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $editId     = (int)($_POST['id'] ?? 0);
        $type       = in_array((string)($_POST['listing_type'] ?? ''), ['sale','rent']) ? $_POST['listing_type'] : 'sale';
        $title      = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        $desc       = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000) ?: null;
        $priceStr   = preg_replace('/[^0-9.]/', '', (string)($_POST['price'] ?? ''));
        $priceCents = $priceStr !== '' ? (int)round((float)$priceStr * 100) : null;
        $beds       = ($_POST['beds'] ?? '') !== '' ? max(0, (int)$_POST['beds']) : null;
        $bathsStr   = (string)($_POST['baths'] ?? '');
        $baths      = $bathsStr !== '' ? round((float)$bathsStr, 1) : null;
        $sqft       = ($_POST['sq_ft'] ?? '') !== '' ? max(0, (int)$_POST['sq_ft']) : null;
        $cName      = mb_substr(trim((string)($_POST['contact_name']  ?? '')), 0, 200) ?: null;
        $cEmail     = mb_substr(trim((string)($_POST['contact_email'] ?? '')), 0, 255) ?: null;
        $cPhone     = mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 40)  ?: null;
        $status     = array_key_exists((string)($_POST['status'] ?? ''), $STATUSES) ? $_POST['status'] : 'active';

        // Unit ID — non-management locked to their own unit
        $unitId = (int)($_POST['unit_id'] ?? 0) ?: null;
        if (!$canManage && $unitId !== null) {
            $ok = false;
            foreach ($selectableUnits as $u) {
                if ((int)$u['id'] === $unitId) { $ok = true; break; }
            }
            if (!$ok) $unitId = $myUnitRow ? (int)$myUnitRow['id'] : null;
        }

        if ($title === '') $errors[] = 'Title is required.';
        if ($cEmail && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Contact email is not valid.';

        // Validate uploaded photos
        $uploads = [];
        if (!empty($_FILES['photos']['tmp_name'])) {
            $names = (array)$_FILES['photos']['name'];
            $tmps  = (array)$_FILES['photos']['tmp_name'];
            $sizes = (array)$_FILES['photos']['size'];
            foreach ($names as $i => $name) {
                if (empty($tmps[$i])) continue;
                $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $errors[] = "Photo " . ($i + 1) . ": must be JPG, PNG, GIF, or WebP.";
                } elseif ((int)($sizes[$i] ?? 0) > 15 * 1024 * 1024) {
                    $errors[] = "Photo " . ($i + 1) . ": must be under 15 MB.";
                } else {
                    $uploads[] = ['tmp' => $tmps[$i], 'ext' => $ext];
                }
            }
        }

        if (empty($errors)) {
            if ($editId > 0) {
                $row = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
                $row->execute([$editId, $assocId]);
                $row = $row->fetch();
                if ($row && can_edit_listing($row, $myUserId, $canManage)) {
                    db()->prepare(
                        'UPDATE property_listings
                         SET listing_type=?,title=?,description=?,price_cents=?,beds=?,baths=?,sq_ft=?,
                             unit_id=?,contact_name=?,contact_email=?,contact_phone=?,status=?
                         WHERE id=? AND association_id=?'
                    )->execute([$type,$title,$desc,$priceCents,$beds,$baths,$sqft,
                                $unitId,$cName,$cEmail,$cPhone,$status,$editId,$assocId]);
                    // Save new photos
                    save_listing_photos($editId, $assocId, $uploads);
                    flash('success', 'Listing updated.');
                    redirect('/dashboard/listings.php');
                }
            } else {
                db()->prepare(
                    'INSERT INTO property_listings
                        (association_id,seller_user_id,unit_id,listing_type,title,description,
                         price_cents,beds,baths,sq_ft,contact_name,contact_email,contact_phone,status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$assocId,$myUserId,$unitId,$type,$title,$desc,
                             $priceCents,$beds,$baths,$sqft,$cName,$cEmail,$cPhone,$status]);
                $newId = (int)db()->lastInsertId();
                save_listing_photos($newId, $assocId, $uploads);
                flash('success', 'Listing added.');
                redirect('/dashboard/listings.php');
            }
        }
    }

    if ($action === 'delete_photo') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT lp.id, lp.photo_path, pl.seller_user_id
             FROM listing_photos lp
             JOIN property_listings pl ON pl.id = lp.listing_id
             WHERE lp.id=? AND pl.association_id=?'
        );
        $stmt->execute([$photoId, $assocId]);
        $photoRow = $stmt->fetch();
        if ($photoRow && ($canManage || (int)$photoRow['seller_user_id'] === $myUserId)) {
            $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $photoRow['photo_path'];
            if (is_file($f)) unlink($f);
            db()->prepare('DELETE FROM listing_photos WHERE id=?')->execute([$photoId]);
        }
        redirect('/dashboard/listings.php?edit=' . (int)($_POST['listing_id'] ?? 0));
    }

    if ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $row   = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
        $row->execute([$delId, $assocId]);
        $row = $row->fetch();
        if ($row && can_edit_listing($row, $myUserId, $canManage)) {
            // Delete photos from disk (DB rows cascade on listing delete)
            $photos = db()->prepare('SELECT photo_path FROM listing_photos WHERE listing_id=?');
            $photos->execute([$delId]);
            foreach ($photos->fetchAll() as $p) {
                $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $p['photo_path'];
                if (is_file($f)) unlink($f);
            }
            // Legacy single photo
            if (!empty($row['photo_path'])) {
                $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $row['photo_path'];
                if (is_file($f)) unlink($f);
            }
            db()->prepare('DELETE FROM property_listings WHERE id=? AND association_id=?')->execute([$delId, $assocId]);
            flash('success', 'Listing removed.');
        }
        redirect('/dashboard/listings.php');
    }

    if ($action === 'status') {
        $updId    = (int)($_POST['id'] ?? 0);
        $newStatus = array_key_exists((string)($_POST['status'] ?? ''), $STATUSES) ? $_POST['status'] : null;
        if ($newStatus) {
            $row = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
            $row->execute([$updId, $assocId]);
            $row = $row->fetch();
            if ($row && can_edit_listing($row, $myUserId, $canManage)) {
                db()->prepare('UPDATE property_listings SET status=? WHERE id=? AND association_id=?')
                    ->execute([$newStatus, $updId, $assocId]);
            }
        }
        redirect('/dashboard/listings.php');
    }
}

function save_listing_photos(int $listingId, int $assocId, array $uploads): void {
    if (!$uploads) return;
    $dir = __DIR__ . '/../storage/uploads/' . $assocId . '/listings';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $sortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM listing_photos WHERE listing_id=?');
    $sortStmt->execute([$listingId]);
    $sort = (int)$sortStmt->fetchColumn();
    foreach ($uploads as $up) {
        $fname = bin2hex(random_bytes(12)) . '.' . $up['ext'];
        if (move_uploaded_file($up['tmp'], $dir . '/' . $fname)) {
            db()->prepare('INSERT INTO listing_photos (listing_id, photo_path, sort_order) VALUES (?,?,?)')
                ->execute([$listingId, 'listings/' . $fname, ++$sort]);
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────

$editRow    = null;
$editPhotos = [];
$editId     = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
    $stmt->execute([$editId, $assocId]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow && !can_edit_listing($editRow, $myUserId, $canManage)) {
        $editRow = null;
    }
    if ($editRow) {
        $pStmt = db()->prepare('SELECT * FROM listing_photos WHERE listing_id=? ORDER BY sort_order, id');
        $pStmt->execute([$editId]);
        $editPhotos = $pStmt->fetchAll();
    }
}

$filterType   = in_array((string)($_GET['type']   ?? ''), ['sale','rent','']) ? ($_GET['type'] ?? '') : '';
$filterStatus = array_key_exists((string)($_GET['status'] ?? ''), $STATUSES) ? ($_GET['status'] ?? '') : '';

$where  = 'WHERE pl.association_id=?';
$params = [$assocId];
if ($filterType   !== '') { $where .= ' AND pl.listing_type=?'; $params[] = $filterType; }
if ($filterStatus !== '') { $where .= ' AND pl.status=?';       $params[] = $filterStatus; }

// Load listings + first photo id per listing
$stmt = db()->prepare(
    "SELECT pl.*,
            (SELECT lp.id FROM listing_photos lp WHERE lp.listing_id=pl.id ORDER BY lp.sort_order, lp.id LIMIT 1) AS first_photo_id
     FROM property_listings pl
     $where
     ORDER BY FIELD(pl.status,'active','pending','sold','rented'), pl.created_at DESC"
);
$stmt->execute($params);
$allListings = $stmt->fetchAll();

$active     = 'listings';
$page_title = 'Property Listings';
require_once __DIR__ . '/../includes/header.php';

function listing_price_display(?int $cents, string $type): string {
    if ($cents === null || $cents === 0) return 'Price on request';
    $f = '$' . number_format($cents / 100, 0);
    return $type === 'rent' ? $f . '/mo' : $f;
}
$STATUS_BADGES = [
    'active'  => 'badge--green',
    'pending' => 'badge--orange',
    'sold'    => 'badge--muted',
    'rented'  => 'badge--muted',
];
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

<div class="row row--between" style="margin-bottom: var(--sp-6); gap: var(--sp-4); flex-wrap: wrap; align-items: flex-end;">
    <div>
        <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);">Property Listings</h1>
        <p class="muted" style="margin: 0;">Homes for sale or rent in <?= e((string)$association['name']) ?>.</p>
    </div>
    <div class="row" style="gap: var(--sp-3);">
        <?php if (!$editRow && !isset($_GET['edit'])): ?>
            <?php if ($canManage || !empty($selectableUnits) || empty($user['unit_number'])): ?>
                <a class="btn btn--primary" href="/dashboard/listings.php?edit=new">+ New listing</a>
            <?php endif; ?>
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

<?php if ($editRow || isset($_GET['edit'])): ?>
<!-- ── Add / Edit form ──────────────────────────────────────────────────── -->
<div class="card card--padded" style="margin-bottom: var(--sp-6);">
    <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-4);">
        <?= $editRow ? 'Edit Listing' : 'New Listing' ?>
    </h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

        <div class="form-grid form-grid--2" style="gap: var(--sp-4);">

            <!-- Unit selector -->
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="l-unit">Unit</label>
                <?php if ($canManage): ?>
                    <select class="input" id="l-unit" name="unit_id">
                        <option value="">— No specific unit —</option>
                        <?php foreach ($selectableUnits as $u):
                            $sel = (int)($editRow['unit_id'] ?? $_POST['unit_id'] ?? 0) === (int)$u['id'];
                        ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                                Unit <?= e($u['unit_number']) ?>
                                <?php
                                $spec = [];
                                if ($u['bedrooms'])       $spec[] = $u['bedrooms'] . ' BD';
                                if ($u['baths'])          $spec[] = $u['baths'] . ' BA';
                                if ($u['square_footage']) $spec[] = number_format((int)$u['square_footage']) . ' sq ft';
                                if ($spec) echo '— ' . implode(' · ', $spec);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Selecting a unit pre-fills bedrooms, baths, and sq ft.</div>
                <?php elseif ($myUnitRow): ?>
                    <input type="hidden" name="unit_id" value="<?= (int)$myUnitRow['id'] ?>">
                    <div class="input" style="background: var(--color-surface); color: var(--color-text-soft); cursor: default;">
                        Unit <?= e($myUnitRow['unit_number']) ?>
                    </div>
                    <div class="field__hint">Listings are tied to your unit.</div>
                <?php else: ?>
                    <input type="hidden" name="unit_id" value="">
                    <div class="input" style="background: var(--color-surface); color: var(--color-text-soft); cursor: default; font-style: italic;">
                        No unit on file — contact the board to assign one.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Type -->
            <div class="field">
                <label class="field__label" for="l-type">Listing type *</label>
                <select class="input" id="l-type" name="listing_type">
                    <?php foreach ($TYPES as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= (($editRow['listing_type'] ?? $_POST['listing_type'] ?? 'sale') === $val) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Status -->
            <div class="field">
                <label class="field__label" for="l-status">Status</label>
                <select class="input" id="l-status" name="status">
                    <?php foreach ($STATUSES as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= (($editRow['status'] ?? $_POST['status'] ?? 'active') === $val) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Title -->
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="l-title">Listing title *</label>
                <input class="input" type="text" id="l-title" name="title" required maxlength="200"
                       value="<?= e((string)($editRow['title'] ?? $_POST['title'] ?? '')) ?>"
                       placeholder="e.g. 2BD/2BA Corner Unit with Ocean View">
            </div>
            <!-- Price -->
            <div class="field">
                <label class="field__label" for="l-price">Price</label>
                <div class="input-prefix-wrap">
                    <span class="input-prefix">$</span>
                    <input class="input input--prefix" type="text" id="l-price" name="price" maxlength="15"
                           value="<?= $editRow && $editRow['price_cents'] ? number_format((int)$editRow['price_cents'] / 100, 0) : e((string)($_POST['price'] ?? '')) ?>"
                           placeholder="Leave blank for 'Price on request'">
                </div>
            </div>
            <!-- Sq ft -->
            <div class="field">
                <label class="field__label" for="l-sqft">Sq ft</label>
                <input class="input" type="number" id="l-sqft" name="sq_ft" min="0" max="99999"
                       value="<?= e((string)($editRow['sq_ft'] ?? $_POST['sq_ft'] ?? '')) ?>">
            </div>
            <!-- Beds -->
            <div class="field">
                <label class="field__label" for="l-beds">Bedrooms</label>
                <input class="input" type="number" id="l-beds" name="beds" min="0" max="99"
                       value="<?= e((string)($editRow['beds'] ?? $_POST['beds'] ?? '')) ?>"
                       placeholder="e.g. 2">
            </div>
            <!-- Baths -->
            <div class="field">
                <label class="field__label" for="l-baths">Bathrooms</label>
                <input class="input" type="number" id="l-baths" name="baths" min="0" max="99" step="0.5"
                       value="<?= e((string)($editRow['baths'] ?? $_POST['baths'] ?? '')) ?>"
                       placeholder="e.g. 2.5">
            </div>
            <!-- Description -->
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="l-desc">Description</label>
                <textarea class="textarea" id="l-desc" name="description" rows="5" maxlength="5000"
                          placeholder="Full listing details, features, HOA fees, showing contact…"><?= e((string)($editRow['description'] ?? $_POST['description'] ?? '')) ?></textarea>
            </div>
            <!-- Contact -->
            <div class="field">
                <label class="field__label" for="l-cname">Contact name</label>
                <input class="input" type="text" id="l-cname" name="contact_name" maxlength="200"
                       value="<?= e((string)($editRow['contact_name'] ?? $_POST['contact_name'] ?? '')) ?>"
                       placeholder="Agent or owner name">
            </div>
            <div class="field">
                <label class="field__label" for="l-cphone">Contact phone</label>
                <input class="input" type="tel" id="l-cphone" name="contact_phone" maxlength="40"
                       value="<?= e((string)($editRow['contact_phone'] ?? $_POST['contact_phone'] ?? '')) ?>">
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="l-cemail">Contact email</label>
                <input class="input" type="email" id="l-cemail" name="contact_email" maxlength="255"
                       value="<?= e((string)($editRow['contact_email'] ?? $_POST['contact_email'] ?? '')) ?>">
            </div>

            <!-- Photos -->
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label">Photos</label>
                <?php if ($editPhotos): ?>
                <div style="display: flex; flex-wrap: wrap; gap: var(--sp-3); margin-bottom: var(--sp-3);">
                    <?php foreach ($editPhotos as $ep): ?>
                    <div style="position: relative;">
                        <img src="/dashboard/file.php?type=listing_photo&id=<?= (int)$ep['id'] ?>"
                             style="width: 100px; height: 100px; object-fit: cover; border-radius: var(--r-md); display: block;" alt="">
                        <form method="post" style="margin:0;"
                              onsubmit="return confirm('Remove this photo?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="<?= (int)$ep['id'] ?>">
                            <input type="hidden" name="listing_id" value="<?= (int)$editRow['id'] ?>">
                            <button type="submit" title="Remove photo"
                                    style="position:absolute; top:4px; right:4px; width:22px; height:22px;
                                           border-radius:50%; border:none; background:rgba(0,0,0,.65);
                                           color:#fff; font-size:13px; line-height:1; cursor:pointer;
                                           display:flex; align-items:center; justify-content:center;">×</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($editRow && !empty($editRow['photo_path'])): ?>
                    <div style="margin-bottom: var(--sp-3);">
                        <img src="/dashboard/file.php?type=listing&id=<?= (int)$editRow['id'] ?>"
                             style="width:100px; height:100px; object-fit:cover; border-radius: var(--r-md);" alt="">
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 4px;">Legacy photo — upload a new one below to replace it.</div>
                    </div>
                <?php endif; ?>
                <input class="input" type="file" id="l-photos" name="photos[]" accept="image/*" multiple>
                <div class="field__hint">JPG, PNG, WebP, or GIF · max 15 MB each · multiple allowed</div>
            </div>
        </div>

        <div class="row" style="margin-top: var(--sp-5); gap: var(--sp-3);">
            <button class="btn btn--primary" type="submit"><?= $editRow ? 'Save changes' : 'Add listing' ?></button>
            <a class="btn btn--ghost" href="/dashboard/listings.php">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var unitSel = document.getElementById('l-unit');
    if (!unitSel) return;
    var unitData = <?= json_encode($unitJson, JSON_HEX_TAG) ?>;
    unitSel.addEventListener('change', function () {
        var u = unitData[this.value];
        if (!u) return;
        var beds  = document.getElementById('l-beds');
        var baths = document.getElementById('l-baths');
        var sqft  = document.getElementById('l-sqft');
        var title = document.getElementById('l-title');
        if (beds  && u.beds  != null) beds.value  = u.beds;
        if (baths && u.baths != null) baths.value = u.baths;
        if (sqft  && u.sqft  != null) sqft.value  = u.sqft;
        if (title && title.value === '' && u.num) {
            title.value = 'Unit ' + u.num + ' — ' + (document.getElementById('l-type').value === 'rent' ? 'For Rent' : 'For Sale');
        }
    });
})();
</script>

<?php endif; ?>

<!-- ── Filter bar ───────────────────────────────────────────────────────── -->
<div class="row" style="gap: var(--sp-3); margin-bottom: var(--sp-4); flex-wrap: wrap; align-items: center;">
    <form method="get" class="row" style="gap: var(--sp-2); align-items: center; flex-wrap: wrap;">
        <select class="input input--sm" name="type" onchange="this.form.submit()">
            <option value="">All types</option>
            <?php foreach ($TYPES as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $filterType === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input input--sm" name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($STATUSES as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $filterStatus === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <span class="muted" style="font-size: var(--fs-sm);"><?= count($allListings) ?> listing<?= count($allListings) !== 1 ? 's' : '' ?></span>
</div>

<?php if ($allListings): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--sp-4);">
<?php foreach ($allListings as $l):
    $priceCentsVal = $l['price_cents'] !== null ? (int)$l['price_cents'] : null;
    $canEditThis   = can_edit_listing($l, $myUserId, $canManage);
    $photoSrc = null;
    if ($l['first_photo_id']) {
        $photoSrc = '/dashboard/file.php?type=listing_photo&id=' . (int)$l['first_photo_id'];
    } elseif (!empty($l['photo_path'])) {
        $photoSrc = '/dashboard/file.php?type=listing&id=' . (int)$l['id'];
    }
?>
    <div class="card" style="display: flex; flex-direction: column;">
        <?php if ($photoSrc): ?>
            <div style="height: 180px; overflow: hidden; border-radius: var(--r-md) var(--r-md) 0 0; flex-shrink: 0;">
                <img src="<?= e($photoSrc) ?>" alt=""
                     style="width:100%; height:100%; object-fit:cover; display:block;">
            </div>
        <?php endif; ?>
        <div style="padding: var(--sp-4); flex: 1; display: flex; flex-direction: column;">
            <div class="row" style="align-items: flex-start; justify-content: space-between; gap: var(--sp-2); margin-bottom: var(--sp-2);">
                <span class="badge <?= $STATUS_BADGES[$l['status']] ?? 'badge--muted' ?>"><?= e($STATUSES[$l['status']] ?? $l['status']) ?></span>
                <span class="badge badge--navy"><?= e($TYPES[$l['listing_type']] ?? $l['listing_type']) ?></span>
            </div>
            <h3 style="font-size: var(--fs-md); margin: 0 0 var(--sp-2); line-height: 1.3;"><?= e((string)$l['title']) ?></h3>
            <div style="font-size: var(--fs-xl); font-weight: 900; color: var(--color-orange); margin-bottom: var(--sp-2);">
                <?= listing_price_display($priceCentsVal, (string)$l['listing_type']) ?>
            </div>
            <?php
            $specs = [];
            if ($l['beds']  !== null) $specs[] = $l['beds']  . ' BD';
            if ($l['baths'] !== null) $specs[] = $l['baths'] . ' BA';
            if ($l['sq_ft'] !== null) $specs[] = number_format((int)$l['sq_ft']) . ' sq ft';
            ?>
            <?php if ($specs): ?>
                <div class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-2);"><?= implode(' · ', array_map('e', $specs)) ?></div>
            <?php endif; ?>
            <?php if (!empty($l['description'])): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3); flex: 1;"><?= e(mb_strimwidth(strip_tags((string)$l['description']), 0, 120, '…')) ?></p>
            <?php endif; ?>
            <?php if (!empty($l['contact_name']) || !empty($l['contact_phone'])): ?>
                <div class="muted" style="font-size: var(--fs-xs); margin-bottom: var(--sp-3);">
                    <?= !empty($l['contact_name'])  ? e((string)$l['contact_name'])  . ' ' : '' ?>
                    <?= !empty($l['contact_phone']) ? '· ' . e((string)$l['contact_phone']) : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($canEditThis): ?>
            <div class="row" style="gap: var(--sp-2); margin-top: auto; flex-wrap: wrap;">
                <a class="btn btn--xs" href="/dashboard/listings.php?edit=<?= (int)$l['id'] ?>">Edit</a>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <select name="status" class="input input--xs" onchange="this.form.submit()">
                        <?php foreach ($STATUSES as $sv => $sl): ?>
                            <option value="<?= e($sv) ?>" <?= $l['status'] === $sv ? 'selected' : '' ?>><?= e($sl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this listing?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn--xs btn--error" type="submit">Delete</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card card--padded" style="text-align: center; padding: var(--sp-12);">
    <div style="font-size: 2.5rem; margin-bottom: var(--sp-3);">🏠</div>
    <h3 style="margin: 0 0 var(--sp-2);">No listings yet</h3>
    <p class="muted" style="margin: 0 0 var(--sp-4);">Post homes for sale or rent within the community.</p>
    <a class="btn btn--primary" href="/dashboard/listings.php?edit=new">+ Add first listing</a>
</div>
<?php endif; ?>

</div><?php /* /container */ ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

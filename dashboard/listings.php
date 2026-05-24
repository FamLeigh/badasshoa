<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
$canManage = role_can_manage(viewing_role());
if (!$canManage) { http_response_code(403); die('Access denied'); }

$assocId  = (int)$_SESSION['association_id'];
$myUserId = (int)$_SESSION['user_id'];
$canEdit  = can_do('edit_content');
$errors   = [];

$STATUSES = ['active' => 'Active', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented'];
$TYPES    = ['sale' => 'For Sale', 'rent' => 'For Rent'];

// ── POST handlers ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $editId       = (int)($_POST['id'] ?? 0);
        $type         = in_array((string)($_POST['listing_type'] ?? ''), ['sale','rent']) ? $_POST['listing_type'] : 'sale';
        $title        = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        $desc         = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000) ?: null;
        $priceStr     = preg_replace('/[^0-9.]/', '', (string)($_POST['price'] ?? ''));
        $priceCents   = $priceStr !== '' ? (int)round((float)$priceStr * 100) : null;
        $beds         = ($_POST['beds'] ?? '') !== '' ? max(0, (int)$_POST['beds']) : null;
        $bathsStr     = (string)($_POST['baths'] ?? '');
        $baths        = $bathsStr !== '' ? round((float)$bathsStr, 1) : null;
        $sqft         = ($_POST['sq_ft'] ?? '') !== '' ? max(0, (int)$_POST['sq_ft']) : null;
        $cName        = mb_substr(trim((string)($_POST['contact_name']  ?? '')), 0, 200) ?: null;
        $cEmail       = mb_substr(trim((string)($_POST['contact_email'] ?? '')), 0, 255) ?: null;
        $cPhone       = mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 40)  ?: null;
        $status       = array_key_exists((string)($_POST['status'] ?? ''), $STATUSES) ? $_POST['status'] : 'active';

        if ($title === '') $errors[] = 'Title is required.';
        if ($cEmail && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Contact email is not valid.';

        // Photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo((string)($_FILES['photo']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $errors[] = 'Photo must be JPG, PNG, GIF, or WebP.';
            } elseif ((int)($_FILES['photo']['size'] ?? 0) > 15 * 1024 * 1024) {
                $errors[] = 'Photo must be under 15 MB.';
            } elseif (empty($errors)) {
                $dir  = __DIR__ . '/../storage/uploads/' . $assocId . '/listings';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = bin2hex(random_bytes(12)) . '.' . $ext;
                $dest  = $dir . '/' . $fname;
                if (move_uploaded_file((string)$_FILES['photo']['tmp_name'], $dest)) {
                    $photoPath = 'listings/' . $fname;
                } else {
                    $errors[] = 'Photo upload failed — try again.';
                }
            }
        }

        if (empty($errors)) {
            if ($editId > 0) {
                $row = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
                $row->execute([$editId, $assocId]);
                $row = $row->fetch();
                if ($row) {
                    $oldPhoto = (string)($row['photo_path'] ?? '');
                    $sql = 'UPDATE property_listings SET listing_type=?,title=?,description=?,price_cents=?,beds=?,baths=?,sq_ft=?,contact_name=?,contact_email=?,contact_phone=?,status=?';
                    $args = [$type,$title,$desc,$priceCents,$beds,$baths,$sqft,$cName,$cEmail,$cPhone,$status];
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
                    flash('success', 'Listing updated.');
                    redirect('/dashboard/listings.php');
                }
            } else {
                db()->prepare(
                    'INSERT INTO property_listings
                        (association_id,listing_type,title,description,price_cents,beds,baths,sq_ft,
                         contact_name,contact_email,contact_phone,status,photo_path)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$assocId,$type,$title,$desc,$priceCents,$beds,$baths,$sqft,$cName,$cEmail,$cPhone,$status,$photoPath]);
                flash('success', 'Listing added.');
                redirect('/dashboard/listings.php');
            }
        }
    }

    if ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $row   = db()->prepare('SELECT photo_path FROM property_listings WHERE id=? AND association_id=?');
        $row->execute([$delId, $assocId]);
        $row = $row->fetch();
        if ($row) {
            if ($row['photo_path']) {
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
            db()->prepare('UPDATE property_listings SET status=? WHERE id=? AND association_id=?')
                ->execute([$newStatus, $updId, $assocId]);
        }
        redirect('/dashboard/listings.php');
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────

$editRow = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $canEdit) {
    $stmt = db()->prepare('SELECT * FROM property_listings WHERE id=? AND association_id=?');
    $stmt->execute([$editId, $assocId]);
    $editRow = $stmt->fetch() ?: null;
}

$filterType   = in_array((string)($_GET['type']   ?? ''), ['sale','rent','']) ? ($_GET['type'] ?? '') : '';
$filterStatus = array_key_exists((string)($_GET['status'] ?? ''), $STATUSES) ? ($_GET['status'] ?? '') : '';

$where  = 'WHERE association_id=?';
$params = [$assocId];
if ($filterType   !== '') { $where .= ' AND listing_type=?'; $params[] = $filterType; }
if ($filterStatus !== '') { $where .= ' AND status=?';       $params[] = $filterStatus; }

$stmt = db()->prepare("SELECT * FROM property_listings $where ORDER BY FIELD(status,'active','pending','sold','rented'), created_at DESC");
$stmt->execute($params);
$allListings = $stmt->fetchAll();

$active = 'listings';
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

<div class="page-header">
    <div>
        <h1 class="page-title">Property Listings</h1>
        <p class="muted">Homes for sale or rent in <?= e((string)$association['name']) ?>.</p>
    </div>
    <div class="row" style="gap: var(--sp-3);">
        <?php if ($canEdit && !$editRow && !isset($_GET['edit'])): ?>
            <a class="btn btn--primary" href="/dashboard/listings.php?edit=new">+ New listing</a>
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
        <?= $editRow ? 'Edit Listing' : 'New Listing' ?>
    </h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

        <div class="form-grid form-grid--2" style="gap: var(--sp-4);">
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
            <div class="field">
                <label class="field__label" for="l-cemail">Contact email</label>
                <input class="input" type="email" id="l-cemail" name="contact_email" maxlength="255"
                       value="<?= e((string)($editRow['contact_email'] ?? $_POST['contact_email'] ?? '')) ?>">
            </div>
            <!-- Photo -->
            <div class="field">
                <label class="field__label" for="l-photo">Photo</label>
                <input class="input" type="file" id="l-photo" name="photo" accept="image/*">
                <?php if (!empty($editRow['photo_path'])): ?>
                    <div style="margin-top: var(--sp-2);">
                        <img src="/file.php?type=listing&id=<?= (int)$editRow['id'] ?>" alt=""
                             style="max-height: 100px; border-radius: var(--r-sm); display: block;">
                        <span class="muted" style="font-size: var(--fs-xs);">Upload a new photo to replace</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="row" style="margin-top: var(--sp-5); gap: var(--sp-3);">
            <button class="btn btn--primary" type="submit"><?= $editRow ? 'Save changes' : 'Add listing' ?></button>
            <a class="btn btn--ghost" href="/dashboard/listings.php">Cancel</a>
        </div>
    </form>
</div>
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
?>
    <div class="card" style="display: flex; flex-direction: column;">
        <?php if (!empty($l['photo_path'])): ?>
            <div style="height: 180px; overflow: hidden; border-radius: var(--r-md) var(--r-md) 0 0; flex-shrink: 0;">
                <img src="/file.php?type=listing&id=<?= (int)$l['id'] ?>" alt=""
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
            <?php if ($canEdit): ?>
            <div class="row" style="gap: var(--sp-2); margin-top: auto; flex-wrap: wrap;">
                <a class="btn btn--xs" href="/dashboard/listings.php?edit=<?= (int)$l['id'] ?>">Edit</a>
                <!-- Quick status change -->
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
    <?php if ($canEdit): ?>
        <a class="btn btn--primary" href="/dashboard/listings.php?edit=new">+ Add first listing</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require __DIR__ . '/_bootstrap.php';

$user    = current_user();
$assocId = (int)($_SESSION['association_id'] ?? 0);

// ── constants ──────────────────────────────────────────────────────────────
$CATEGORIES = [
    'furniture'    => 'Furniture',
    'electronics'  => 'Electronics',
    'appliances'   => 'Appliances',
    'clothing'     => 'Clothing',
    'sports'       => 'Sports & fitness',
    'tools'        => 'Tools',
    'vehicles'     => 'Vehicles / bikes',
    'garden'       => 'Garden & outdoors',
    'baby'         => 'Baby & kids',
    'other'        => 'Other',
];
$CONDITIONS = [
    'new'       => 'New',
    'like_new'  => 'Like new',
    'good'      => 'Good',
    'fair'      => 'Fair',
    'for_parts' => 'For parts',
];
$COND_CLS = [
    'new'       => 'badge--success',
    'like_new'  => 'badge--info',
    'good'      => '',
    'fair'      => 'badge--warning',
    'for_parts' => 'badge--error',
];

$canBoard   = role_can_manage((string)(viewing_role()));
$myUserId   = (int)$user['id'];

// ── helpers ────────────────────────────────────────────────────────────────
function price_display(mixed $cents): string {
    if ($cents === null || $cents === '') return 'Free';
    $n = (int)$cents;
    if ($n === 0) return 'Free';
    return '$' . number_format($n / 100, 2);
}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    // ── post / edit listing ───────────────────────────────────────────────
    if (in_array($form, ['post_listing', 'edit_listing'], true)) {
        $isEdit  = $form === 'edit_listing';
        $lid     = $isEdit ? (int)($_POST['id'] ?? 0) : 0;
        $title   = trim((string)($_POST['title']       ?? ''));
        $desc    = trim((string)($_POST['description'] ?? ''));
        $priceRaw= trim((string)($_POST['price']       ?? ''));
        $cat     = (string)($_POST['category']  ?? 'other');
        $cond    = (string)($_POST['condition']  ?? 'good');

        if (!array_key_exists($cat,  $CATEGORIES)) $cat  = 'other';
        if (!array_key_exists($cond, $CONDITIONS))  $cond = 'good';

        $cents = null;
        if ($priceRaw !== '' && $priceRaw !== '0') {
            $parsed = (float)str_replace(['$',','], '', $priceRaw);
            $cents  = (int)round($parsed * 100);
        }

        $errors = [];
        if ($title === '')  $errors[] = 'Title is required.';
        if ($desc  === '')  $errors[] = 'Description is required.';

        // photo upload (optional, max 15 MB)
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext    = strtolower(pathinfo((string)($_FILES['photo']['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed= ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Photo must be JPG, PNG, GIF, or WebP.';
            } elseif ((int)($_FILES['photo']['size'] ?? 0) > 15 * 1024 * 1024) {
                $errors[] = 'Photo must be under 15 MB.';
            } else {
                $dir = __DIR__ . '/../storage/uploads/' . $assocId . '/marketplace';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname     = bin2hex(random_bytes(12)) . '.' . $ext;
                $dest      = $dir . '/' . $fname;
                if (move_uploaded_file((string)$_FILES['photo']['tmp_name'], $dest)) {
                    $photoPath = 'marketplace/' . $fname;
                } else {
                    $errors[] = 'Photo upload failed — try again.';
                }
            }
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
        } elseif ($isEdit) {
            $row = db()->prepare('SELECT * FROM marketplace_listings WHERE id=? AND association_id=?');
            $row->execute([$lid, $assocId]);
            $row = $row->fetch();
            if (!$row || ((int)$row['seller_user_id'] !== $myUserId && !$canBoard)) {
                flash('error', 'Not found.');
            } else {
                $oldPhoto = (string)($row['photo_path'] ?? '');
                $sql = 'UPDATE marketplace_listings SET title=?,description=?,price_cents=?,category=?,condition_label=?';
                $args = [$title, $desc, $cents, $cat, $cond];
                if ($photoPath !== null) {
                    $sql .= ',photo_path=?';
                    $args[] = $photoPath;
                    // delete old photo file
                    if ($oldPhoto !== '') {
                        $old = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $oldPhoto;
                        if (is_file($old)) @unlink($old);
                    }
                }
                $sql .= ' WHERE id=? AND association_id=?';
                $args[] = $lid; $args[] = $assocId;
                db()->prepare($sql)->execute($args);
                audit('marketplace.edited', ['title' => $title], $lid, 'marketplace_listing');
                flash('success', 'Listing updated.');
            }
        } else {
            $stmt = db()->prepare(
                'INSERT INTO marketplace_listings (association_id,seller_user_id,title,description,price_cents,category,condition_label,photo_path)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$assocId, $myUserId, $title, $desc, $cents, $cat, $cond, $photoPath]);
            audit('marketplace.posted', ['title' => $title], (int)db()->lastInsertId(), 'marketplace_listing');
            flash('success', 'Listing posted!');
        }
        redirect('/dashboard/marketplace.php');
    }

    // ── mark sold ─────────────────────────────────────────────────────────
    if ($form === 'mark_sold') {
        $lid = (int)($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT seller_user_id FROM marketplace_listings WHERE id=? AND association_id=?');
        $row->execute([$lid, $assocId]);
        $row = $row->fetch();
        if ($row && (int)$row['seller_user_id'] === $myUserId) {
            db()->prepare("UPDATE marketplace_listings SET status='sold' WHERE id=? AND association_id=?")->execute([$lid, $assocId]);
            audit('marketplace.sold', [], $lid, 'marketplace_listing');
            flash('success', 'Listing marked as sold.');
        }
        redirect('/dashboard/marketplace.php');
    }

    // ── reactivate ────────────────────────────────────────────────────────
    if ($form === 'reactivate_listing') {
        $lid = (int)($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT seller_user_id FROM marketplace_listings WHERE id=? AND association_id=?');
        $row->execute([$lid, $assocId]);
        $row = $row->fetch();
        if ($row && (int)$row['seller_user_id'] === $myUserId) {
            db()->prepare("UPDATE marketplace_listings SET status='active' WHERE id=? AND association_id=?")->execute([$lid, $assocId]);
            flash('success', 'Listing reactivated.');
        }
        redirect('/dashboard/marketplace.php');
    }

    // ── delete listing (seller or board) ─────────────────────────────────
    if ($form === 'delete_listing') {
        $lid = (int)($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT * FROM marketplace_listings WHERE id=? AND association_id=?');
        $row->execute([$lid, $assocId]);
        $row = $row->fetch();
        if ($row && ((int)$row['seller_user_id'] === $myUserId || $canBoard)) {
            if (!empty($row['photo_path'])) {
                $f = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $row['photo_path'];
                if (is_file($f)) @unlink($f);
            }
            db()->prepare('DELETE FROM marketplace_listings WHERE id=? AND association_id=?')->execute([$lid, $assocId]);
            audit('marketplace.deleted', ['title' => $row['title']], $lid, 'marketplace_listing');
            flash('success', 'Listing removed.');
        }
        redirect('/dashboard/marketplace.php');
    }

    // ── board remove ──────────────────────────────────────────────────────
    if ($form === 'board_remove' && $canBoard) {
        $lid    = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        db()->prepare(
            "UPDATE marketplace_listings SET status='removed', removed_by=?, removed_reason=? WHERE id=? AND association_id=?"
        )->execute([$myUserId, $reason, $lid, $assocId]);
        audit('marketplace.board_removed', ['reason' => $reason], $lid, 'marketplace_listing');
        flash('success', 'Listing removed.');
        redirect('/dashboard/marketplace.php');
    }
}

// ── GET / filters ──────────────────────────────────────────────────────────
$catFilter  = (string)($_GET['cat']   ?? '');
$showMine   = isset($_GET['mine']);
$showSold   = isset($_GET['sold']);
if ($catFilter !== '' && !array_key_exists($catFilter, $CATEGORIES)) $catFilter = '';

$sql    = 'SELECT l.*, u.first_name, u.last_name, u.email, u.phone, u.unit_number FROM marketplace_listings l
           JOIN users u ON u.id = l.seller_user_id
           WHERE l.association_id = ?';
$params = [$assocId];

if ($showMine) {
    $sql .= ' AND l.seller_user_id = ?'; $params[] = $myUserId;
    if ($showSold) {
        $sql .= " AND l.status IN ('active','sold')";
    } else {
        $sql .= " AND l.status = 'active'";
    }
} else {
    $sql .= " AND l.status = 'active'";
}
if ($catFilter !== '') { $sql .= ' AND l.category = ?'; $params[] = $catFilter; }
$sql .= ' ORDER BY l.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

// ── edit preload ───────────────────────────────────────────────────────────
$editRow = null;
if (isset($_GET['edit'])) {
    $er = db()->prepare('SELECT l.*, u.first_name, u.last_name, u.email, u.phone, u.unit_number FROM marketplace_listings l JOIN users u ON u.id=l.seller_user_id WHERE l.id=? AND l.association_id=?');
    $er->execute([(int)$_GET['edit'], $assocId]);
    $row = $er->fetch();
    if ($row && ((int)$row['seller_user_id'] === $myUserId || $canBoard)) {
        $editRow = $row;
    }
}

// ── view preload ───────────────────────────────────────────────────────────
$viewListing = null;
if (isset($_GET['view'])) {
    $vr = db()->prepare('SELECT l.*, u.first_name, u.last_name, u.email, u.phone, u.unit_number FROM marketplace_listings l JOIN users u ON u.id=l.seller_user_id WHERE l.id=? AND l.association_id=?');
    $vr->execute([(int)$_GET['view'], $assocId]);
    $viewListing = $vr->fetch() ?: null;
}

$page_title  = 'Community Marketplace';
$page_layout = 'app';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

<?php if ($editRow): ?>
<!-- ═══════════════════════════════════════════════ EDIT LISTING ═════════ -->
<div style="margin-bottom: var(--sp-4);">
    <a href="?view=<?= (int)$editRow['id'] ?>" style="font-size: var(--fs-sm); color: var(--color-muted); text-decoration: none;">← Back to listing</a>
</div>
<div class="card" style="margin-bottom: var(--sp-6);">
    <h2 style="margin:0 0 var(--sp-4); font-size: var(--fs-xl);">Edit listing</h2>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width: 640px;">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="edit_listing">
        <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
        <div class="field"><label class="field__label" for="et">Title</label><input class="input" id="et" name="title" required maxlength="120" value="<?= e((string)$editRow['title']) ?>"></div>
        <div class="field"><label class="field__label" for="ed">Description</label><textarea class="textarea" id="ed" name="description" rows="4" required><?= e((string)$editRow['description']) ?></textarea></div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="ecat">Category</label>
                <select class="input" id="ecat" name="category">
                    <?php foreach ($CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $editRow['category'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="ecnd">Condition</label>
                <select class="input" id="ecnd" name="condition">
                    <?php foreach ($CONDITIONS as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $editRow['condition_label'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="epr">Price (leave blank or 0 for free)</label>
            <input class="input" id="epr" name="price" placeholder="e.g. 25 or 125.00" value="<?= $editRow['price_cents'] ? e((string)(number_format((int)$editRow['price_cents'] / 100, 2))) : '' ?>">
        </div>
        <div class="field">
            <label class="field__label">Photo (optional — replaces current if uploaded)</label>
            <?php if (!empty($editRow['photo_path'])): ?>
                <img src="/marketplace-image.php?id=<?= (int)$editRow['id'] ?>" alt="" style="max-height: 120px; border-radius: var(--r-md); margin-bottom: var(--sp-2); display:block;">
            <?php endif; ?>
            <input type="file" name="photo" accept="image/*" class="input" style="padding: var(--sp-1);">
        </div>
        <div class="row">
            <button class="btn btn--primary" type="submit">Save changes</button>
            <a class="btn" href="?view=<?= (int)$editRow['id'] ?>">Cancel</a>
        </div>
    </form>
</div>

<?php elseif (isset($_GET['post'])): ?>
<!-- ═══════════════════════════════════════════════ POST LISTING ══════════ -->
<div style="margin-bottom: var(--sp-4);">
    <a href="/dashboard/marketplace.php" style="font-size: var(--fs-sm); color: var(--color-muted); text-decoration: none;">← Back to marketplace</a>
</div>
<div class="card" style="margin-bottom: var(--sp-6);">
    <h2 style="margin:0 0 var(--sp-4); font-size: var(--fs-xl);">Post a listing</h2>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width: 640px;">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="post_listing">
        <div class="field"><label class="field__label" for="nt">Title</label><input class="input" id="nt" name="title" required maxlength="120" placeholder="e.g. Queen bed frame, white"></div>
        <div class="field"><label class="field__label" for="nd">Description</label><textarea class="textarea" id="nd" name="description" rows="4" required placeholder="Size, color, any wear, pickup details…"></textarea></div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="ncat">Category</label>
                <select class="input" id="ncat" name="category">
                    <?php foreach ($CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="ncnd">Condition</label>
                <select class="input" id="ncnd" name="condition">
                    <?php foreach ($CONDITIONS as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $k === 'good' ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="npr">Price (leave blank or 0 for free)</label>
            <input class="input" id="npr" name="price" placeholder="e.g. 25 or 125.00">
        </div>
        <div class="field">
            <label class="field__label">Photo (optional, max 15 MB)</label>
            <input type="file" name="photo" accept="image/*" class="input" style="padding: var(--sp-1);">
        </div>
        <div class="row">
            <button class="btn btn--primary" type="submit">Post listing</button>
            <a class="btn" href="/dashboard/marketplace.php">Cancel</a>
        </div>
    </form>
</div>

<?php elseif ($viewListing): ?>
<!-- ═══════════════════════════════════════════════ DETAIL VIEW ═══════════ -->
<?php
    $vl         = $viewListing;
    $vIsMine    = (int)$vl['seller_user_id'] === $myUserId;
    $vCondCls   = $COND_CLS[$vl['condition_label']] ?? '';
    $vSeller    = trim((string)$vl['first_name'] . ' ' . (string)$vl['last_name']) ?: (string)$vl['email'];
    $vIsSold    = $vl['status'] === 'sold';
    $vCanDelete = $vIsMine || $canBoard;
?>
<div style="margin-bottom: var(--sp-5);">
    <a href="/dashboard/marketplace.php" style="font-size: var(--fs-sm); color: var(--color-muted); text-decoration: none;">← Back to marketplace</a>
</div>

<div class="mp-detail-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--sp-6); align-items: start;">
    <!-- Left: image -->
    <div>
        <?php if (!empty($vl['photo_path'])): ?>
            <img src="/marketplace-image.php?id=<?= (int)$vl['id'] ?>" alt="" style="width:100%; border-radius: var(--r-lg); object-fit:cover; max-height: 420px; display:block;">
        <?php else: ?>
            <div style="width:100%; height:300px; background:var(--color-navy-10,#f0f3f8); border-radius: var(--r-lg); display:flex; align-items:center; justify-content:center; font-size: 5rem;">
                <?= match($vl['category']) {
                    'electronics' => '🖥️', 'furniture' => '🪑', 'appliances' => '🍳',
                    'clothing' => '👕', 'sports' => '⚽', 'tools' => '🔧',
                    'vehicles' => '🚲', 'garden' => '🌿', 'baby' => '🍼',
                    default => '📦'
                } ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: details -->
    <div style="display:flex; flex-direction:column; gap: var(--sp-4);">
        <?php if ($vIsSold): ?><span class="badge badge--error" style="align-self:flex-start;">SOLD</span><?php endif; ?>
        <h1 style="font-size: var(--fs-2xl); margin: 0; line-height: 1.2;"><?= e($vl['title']) ?></h1>
        <div style="font-size: var(--fs-3xl); font-weight: 800; color: <?= $vl['price_cents'] ? 'var(--color-navy)' : 'var(--color-success)' ?>;">
            <?= price_display($vl['price_cents']) ?>
        </div>
        <div class="row" style="gap: var(--sp-2); flex-wrap:wrap;">
            <span class="badge <?= $vCondCls ?>"><?= e($CONDITIONS[$vl['condition_label']] ?? $vl['condition_label']) ?></span>
            <span class="badge"><?= e($CATEGORIES[$vl['category']] ?? $vl['category']) ?></span>
        </div>
        <div style="font-size: var(--fs-sm); line-height: 1.6; white-space: pre-wrap;"><?= e((string)$vl['description']) ?></div>
        <div class="muted" style="font-size: var(--fs-sm);">
            Posted by <?= e($vSeller) ?>
            <?php if (!empty($vl['unit_number'])): ?>&middot; Unit <?= e($vl['unit_number']) ?><?php endif; ?>
            &middot; <?= udate('M j, Y', strtotime((string)$vl['created_at'])) ?>
        </div>
        <?php if (!empty($vl['phone'])): ?>
        <div style="font-size: var(--fs-sm);">📞 <?= e($vl['phone']) ?></div>
        <?php endif; ?>

        <!-- Disclaimer -->
        <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); font-size: var(--fs-xs); line-height: 1.6; color: #78350f;">
            <strong>As-is listing.</strong> All items are sold as-is with no warranty or guarantee unless the seller explicitly states otherwise above. Verify condition before exchanging payment. The association is not a party to this transaction and assumes no liability for disputes, misrepresentation, item quality, or loss. Exchange items in a common area of the building.
        </div>

        <!-- Contact (non-owner, active listing) -->
        <?php if (!$vIsMine && !$vIsSold): ?>
            <a href="mailto:<?= e((string)$vl['email']) ?>?subject=<?= urlencode('Re: ' . $vl['title'] . ' — Community Marketplace') ?>" class="btn btn--primary">Contact seller</a>
        <?php endif; ?>

        <!-- Owner actions -->
        <?php if ($vIsMine): ?>
            <div class="row" style="gap: var(--sp-2); flex-wrap:wrap;">
                <?php if (!$vIsSold): ?>
                    <a class="btn" href="?edit=<?= (int)$vl['id'] ?>">Edit listing</a>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="mark_sold">
                        <input type="hidden" name="id" value="<?= (int)$vl['id'] ?>">
                        <button class="btn" type="submit">Mark as sold</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="reactivate_listing">
                        <input type="hidden" name="id" value="<?= (int)$vl['id'] ?>">
                        <button class="btn" type="submit">Relist</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Delete (seller or board) -->
        <?php if ($vCanDelete): ?>
            <form method="post" onsubmit="return confirm('Permanently delete this listing?')">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete_listing">
                <input type="hidden" name="id" value="<?= (int)$vl['id'] ?>">
                <button class="btn btn--error" type="submit" style="width:100%;">Delete listing</button>
            </form>
        <?php endif; ?>

        <!-- Board soft-remove (when not the poster) -->
        <?php if ($canBoard && !$vIsMine && !$vIsSold): ?>
            <details>
                <summary class="muted" style="font-size: var(--fs-xs); cursor:pointer; user-select:none;">Board: remove without deleting</summary>
                <form method="post" style="margin-top: var(--sp-2); display:flex; flex-direction:column; gap: var(--sp-2);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="board_remove">
                    <input type="hidden" name="id" value="<?= (int)$vl['id'] ?>">
                    <input class="input" name="reason" placeholder="Reason (optional)" style="font-size: var(--fs-sm);">
                    <button class="btn btn--error btn--sm" type="submit">Remove listing</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</div>

<style>
@media (max-width: 700px) {
    .mp-detail-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php else: ?>
<!-- ═══════════════════════════════════════════════ BROWSE ════════════════ -->

<div class="row" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--sp-3); margin-bottom: var(--sp-4);">
    <h1 style="font-size: var(--fs-3xl); margin:0;">Community Marketplace</h1>
    <a class="btn btn--primary" href="?post=1">+ Post a listing</a>
</div>

<!-- Disclaimer -->
<details style="margin-bottom: var(--sp-5);">
    <summary style="font-size: var(--fs-xs); color: var(--color-muted); cursor: pointer; user-select: none; list-style: none; display: flex; align-items: center; gap: var(--sp-1);">
        <span>⚠️</span> <span>Marketplace disclaimer</span>
    </summary>
    <div style="margin-top: var(--sp-2); background: #fefce8; border: 1px solid #fde68a; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); font-size: var(--fs-xs); line-height: 1.7; color: #78350f;">
        All items are listed by community members and sold as-is with no warranty or guarantee unless the seller explicitly states otherwise in their listing. Buyers are responsible for inspecting items and verifying condition before exchanging payment. The association is not a party to any transaction and assumes no liability for disputes, misrepresentation, item quality, safety issues, or financial loss. Exchange items in a common area of the building. To report a listing that violates community standards, contact the board directly.
    </div>
</details>

<!-- Filter bar -->
<form method="get" class="row" style="gap: var(--sp-2); flex-wrap: wrap; margin-bottom: var(--sp-5); align-items: center;">
    <a class="badge <?= $catFilter === '' && !$showMine ? 'badge--navy' : '' ?>" href="?" style="text-decoration:none; <?= ($catFilter !== '' || $showMine) ? 'opacity:.6;' : '' ?>">All</a>
    <?php foreach ($CATEGORIES as $k => $v): ?>
        <a class="badge <?= $catFilter === $k ? 'badge--navy' : '' ?>" href="?cat=<?= e($k) ?><?= $showMine ? '&mine=1' : '' ?>" style="text-decoration:none; <?= $catFilter !== $k ? 'opacity:.6;' : '' ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
    <span style="margin-left: auto; display:flex; gap: var(--sp-2); align-items:center;">
        <?php if ($showMine): ?>
            <a class="badge badge--info" href="?<?= $catFilter ? 'cat='.e($catFilter).'&' : '' ?>mine=1&sold=1" style="text-decoration:none;">My listings + sold</a>
            <a class="badge" href="?" style="text-decoration:none; opacity:.6;">Exit my view</a>
        <?php else: ?>
            <a class="badge" href="?mine=1<?= $catFilter ? '&cat='.e($catFilter) : '' ?>" style="text-decoration:none; opacity:.6;">My listings</a>
        <?php endif; ?>
    </span>
</form>

<?php if (empty($listings)): ?>
    <div class="card" style="text-align:center; padding: var(--sp-10);">
        <div style="font-size: 3rem; margin-bottom: var(--sp-3);">🛒</div>
        <p class="muted"><?= $showMine ? 'You have no active listings.' : 'No listings yet — be the first to post something!' ?></p>
        <a class="btn btn--primary" href="?post=1" style="margin-top: var(--sp-3);">Post a listing</a>
    </div>
<?php else: ?>
<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--sp-4);">
    <?php foreach ($listings as $l):
        $isMine     = (int)$l['seller_user_id'] === $myUserId;
        $condCls    = $COND_CLS[$l['condition_label']] ?? '';
        $sellerName = trim((string)$l['first_name'] . ' ' . (string)$l['last_name']) ?: (string)$l['email'];
        $isSold     = $l['status'] === 'sold';
    ?>
    <div class="card" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; position:relative; <?= $isSold ? 'opacity:.7;' : '' ?>">
        <?php if ($isSold): ?>
            <div style="position:absolute; top: var(--sp-2); right: var(--sp-2); z-index:1;">
                <span class="badge badge--error">SOLD</span>
            </div>
        <?php endif; ?>
        <!-- Clickable area → detail view -->
        <a href="?view=<?= (int)$l['id'] ?>" style="text-decoration:none; color:inherit; display:block;">
            <?php if (!empty($l['photo_path'])): ?>
                <img src="/marketplace-image.php?id=<?= (int)$l['id'] ?>" alt="" style="width:100%; height:180px; object-fit:cover; display:block;">
            <?php else: ?>
                <div style="width:100%; height:120px; background:var(--color-navy-10,#f0f3f8); display:flex; align-items:center; justify-content:center; font-size: 2.5rem;">
                    <?= match($l['category']) {
                        'electronics' => '🖥️', 'furniture' => '🪑', 'appliances' => '🍳',
                        'clothing' => '👕', 'sports' => '⚽', 'tools' => '🔧',
                        'vehicles' => '🚲', 'garden' => '🌿', 'baby' => '🍼',
                        default => '📦'
                    } ?>
                </div>
            <?php endif; ?>
            <div style="padding: var(--sp-3); display:flex; flex-direction:column; gap: var(--sp-2);">
                <div class="row" style="gap: var(--sp-2); flex-wrap:wrap; align-items:center;">
                    <span class="badge <?= $condCls ?>"><?= e($CONDITIONS[$l['condition_label']] ?? $l['condition_label']) ?></span>
                    <span class="badge"><?= e($CATEGORIES[$l['category']] ?? $l['category']) ?></span>
                </div>
                <div style="font-weight: 700; font-size: var(--fs-base); line-height: 1.3;"><?= e($l['title']) ?></div>
                <div class="muted" style="font-size: var(--fs-sm); line-height: 1.4;"><?= e(mb_strimwidth((string)$l['description'], 0, 120, '…')) ?></div>
                <div style="font-size: var(--fs-xl); font-weight: 800; color: <?= $l['price_cents'] ? 'var(--color-navy)' : 'var(--color-success)' ?>;">
                    <?= price_display($l['price_cents']) ?>
                </div>
                <div class="muted" style="font-size: var(--fs-xs);">
                    <?= e($sellerName) ?>
                    <?php if (!empty($l['unit_number'])): ?> &middot; Unit <?= e($l['unit_number']) ?><?php endif; ?>
                    &middot; <?= udate('M j', strtotime((string)$l['created_at'])) ?>
                </div>
                <?php if (!empty($l['phone'])): ?>
                <div class="muted" style="font-size: var(--fs-xs);"><?= e($l['phone']) ?></div>
                <?php endif; ?>
            </div>
        </a>
        <!-- Owner quick actions (no delete — that lives in the detail view) -->
        <?php if ($isMine && !$isSold): ?>
            <div class="row" style="gap: var(--sp-2); padding: 0 var(--sp-3) var(--sp-3); flex-wrap: wrap;">
                <a class="btn btn--ghost" href="?edit=<?= (int)$l['id'] ?>" style="flex:1; text-align:center; font-size: var(--fs-sm);">Edit</a>
                <form method="post" style="flex:1;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="mark_sold">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn--ghost" style="width:100%; font-size: var(--fs-sm);" type="submit">Mark sold</button>
                </form>
            </div>
        <?php elseif ($isMine && $isSold): ?>
            <div style="padding: 0 var(--sp-3) var(--sp-3);">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="reactivate_listing">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn--ghost" style="width:100%; font-size: var(--fs-sm);" type="submit">Relist</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

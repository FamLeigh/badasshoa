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

    // ── delete own listing ────────────────────────────────────────────────
    if ($form === 'delete_listing') {
        $lid = (int)($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT * FROM marketplace_listings WHERE id=? AND association_id=?');
        $row->execute([$lid, $assocId]);
        $row = $row->fetch();
        if ($row && (int)$row['seller_user_id'] === $myUserId) {
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

$sql    = 'SELECT l.*, u.first_name, u.last_name, u.email FROM marketplace_listings l
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
    $er = db()->prepare('SELECT * FROM marketplace_listings WHERE id=? AND association_id=?');
    $er->execute([(int)$_GET['edit'], $assocId]);
    $row = $er->fetch();
    if ($row && ((int)$row['seller_user_id'] === $myUserId || $canBoard)) {
        $editRow = $row;
    }
}

$page_title  = 'Community Marketplace';
$page_layout = 'app';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

<?php if ($editRow): ?>
<!-- ═══════════════════════════════════════════════ EDIT LISTING ═════════ -->
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
            <a class="btn" href="/dashboard/marketplace.php">Cancel</a>
        </div>
    </form>
</div>
<?php elseif (isset($_GET['post'])): ?>
<!-- ═══════════════════════════════════════════════ POST LISTING ══════════ -->
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
<?php else: ?>
<!-- ═══════════════════════════════════════════════ BROWSE ════════════════ -->

<div class="row" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--sp-3); margin-bottom: var(--sp-5);">
    <h1 style="font-size: var(--fs-3xl); margin:0;">Community Marketplace</h1>
    <a class="btn btn--primary" href="?post=1">+ Post a listing</a>
</div>

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
        $isMine  = (int)$l['seller_user_id'] === $myUserId;
        $condCls = $COND_CLS[$l['condition_label']] ?? '';
        $sellerName = trim((string)$l['first_name'] . ' ' . (string)$l['last_name']) ?: (string)$l['email'];
        $isSold  = $l['status'] === 'sold';
    ?>
    <div class="card" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; position:relative; <?= $isSold ? 'opacity:.7;' : '' ?>">
        <?php if ($isSold): ?>
            <div style="position:absolute; top: var(--sp-2); right: var(--sp-2); z-index:1;">
                <span class="badge badge--error">SOLD</span>
            </div>
        <?php endif; ?>
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
        <div style="padding: var(--sp-3); flex:1; display:flex; flex-direction:column; gap: var(--sp-2);">
            <div class="row" style="gap: var(--sp-2); flex-wrap:wrap; align-items:center;">
                <span class="badge <?= $condCls ?>"><?= e($CONDITIONS[$l['condition_label']] ?? $l['condition_label']) ?></span>
                <span class="badge"><?= e($CATEGORIES[$l['category']] ?? $l['category']) ?></span>
            </div>
            <div style="font-weight: 700; font-size: var(--fs-base); line-height: 1.3;"><?= e($l['title']) ?></div>
            <div class="muted" style="font-size: var(--fs-sm); flex:1; line-height: 1.4;"><?= e(mb_strimwidth((string)$l['description'], 0, 120, '…')) ?></div>
            <div style="font-size: var(--fs-xl); font-weight: 800; color: <?= $l['price_cents'] ? 'var(--color-navy)' : 'var(--color-success)' ?>;">
                <?= price_display($l['price_cents']) ?>
            </div>
            <div class="muted" style="font-size: var(--fs-xs);">
                <?= e($sellerName) ?> &middot; <?= udate('M j', strtotime((string)$l['created_at'])) ?>
            </div>
            <?php if (!$isSold && !$isMine): ?>
                <a href="mailto:<?= e((string)$l['email']) ?>?subject=<?= urlencode('Re: ' . $l['title'] . ' — Community Marketplace') ?>" class="btn btn--primary" style="width:100%; text-align:center; margin-top: auto;">Contact seller</a>
            <?php endif; ?>
            <?php if ($isMine): ?>
                <div class="row" style="gap: var(--sp-2); flex-wrap: wrap; margin-top: auto;">
                    <?php if (!$isSold): ?>
                        <a class="btn" href="?edit=<?= (int)$l['id'] ?>" style="flex:1; text-align:center;">Edit</a>
                        <form method="post" style="flex:1;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="mark_sold">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button class="btn" style="width:100%;" type="submit">Mark sold</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="flex:1;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="reactivate_listing">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button class="btn" style="width:100%;" type="submit">Relist</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Remove this listing?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete_listing">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <button class="btn btn--error" type="submit">Delete</button>
                    </form>
                </div>
            <?php elseif ($canBoard && !$isSold): ?>
                <details style="margin-top: auto;">
                    <summary class="muted" style="font-size: var(--fs-xs); cursor:pointer; user-select:none;">Board: remove listing</summary>
                    <form method="post" style="margin-top: var(--sp-2);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="board_remove">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <input class="input" name="reason" placeholder="Reason (optional)" style="margin-bottom: var(--sp-2); font-size: var(--fs-sm);">
                        <button class="btn btn--error btn--sm" type="submit">Remove</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

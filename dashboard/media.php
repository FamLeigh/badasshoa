<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;

// --- Upload handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $caption    = trim((string)($_POST['caption'] ?? ''));
    $category   = trim((string)($_POST['category'] ?? ''));
    $visibility = $_POST['visibility'] ?? 'private';
    $linkedType = $_POST['linked_type'] ?? 'general';
    if (!in_array($visibility, ['public','private'], true))                       $visibility = 'private';
    if (!in_array($linkedType, ['general','work_order','violation','announcement'], true)) $linkedType = 'general';

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'Upload failed.';
    } elseif ($_FILES['file']['size'] > 12 * 1024 * 1024) {
        $flashError = 'Max image size is 12 MB.';
    } else {
        $allowed = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            $flashError = 'Image must be PNG, JPG, GIF, or WEBP.';
        } else {
            $newName = uuid_filename($_FILES['file']['name']);
            $relDir  = "uploads/$assocId/media";
            $absDir  = storage_path($relDir);
            ensure_dir($absDir);
            $relPath = "$relDir/$newName";
            $absPath = "$absDir/$newName";
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $absPath)) {
                $flashError = 'Could not save image.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO media (association_id, uploaded_by, file_path, file_name, file_type, caption, category, visibility, linked_type)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $assocId, (int)$user['id'], $relPath, $_FILES['file']['name'],
                    $allowed[$ext], $caption ?: null, $category ?: null, $visibility, $linkedType,
                ]);
                $newId = (int)db()->lastInsertId();
                audit('media.uploaded', ['caption' => $caption, 'visibility' => $visibility], $newId, 'media');
                flash('success', 'Image uploaded.');
                redirect('/dashboard/media.php?tab=' . $visibility);
            }
        }
    }
}

// --- Edit metadata (caption, category, visibility, linked_type) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $id         = (int)($_POST['id'] ?? 0);
    $caption    = trim((string)($_POST['caption'] ?? ''));
    $category   = trim((string)($_POST['category'] ?? ''));
    $visibility = $_POST['visibility'] ?? 'private';
    $linkedType = $_POST['linked_type'] ?? 'general';
    if (!in_array($visibility, ['public','private'], true))                               $visibility = 'private';
    if (!in_array($linkedType, ['general','work_order','violation','announcement'], true)) $linkedType = 'general';

    $check = db()->prepare('SELECT 1 FROM media WHERE id = ? AND association_id = ?');
    $check->execute([$id, $assocId]);
    if (!$check->fetchColumn()) {
        $flashError = 'Image not found.';
    } else {
        db()->prepare(
            'UPDATE media SET caption = ?, category = ?, visibility = ?, linked_type = ?
              WHERE id = ? AND association_id = ?'
        )->execute([$caption ?: null, $category ?: null, $visibility, $linkedType, $id, $assocId]);
        audit('media.edited', ['caption' => mb_strimwidth($caption, 0, 60, '…'), 'visibility' => $visibility], $id, 'media');
        flash('success', 'Image info updated.');
        redirect('/dashboard/media.php?tab=' . $visibility);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT file_path FROM media WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        $abs = storage_path($row['file_path']);
        if (is_file($abs)) @unlink($abs);
        db()->prepare('DELETE FROM media WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
        audit('media.deleted', [], $id, 'media');
        flash('success', 'Image deleted.');
    }
    redirect('/dashboard/media.php');
}

$tab = $_GET['tab'] ?? 'public';
if (!in_array($tab, ['public','private'], true)) $tab = 'public';

$sql = 'SELECT * FROM media WHERE association_id = ? AND visibility = ? ORDER BY created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute([$assocId, $tab]);
$items = $stmt->fetchAll();

$showUpload = ($_GET['action'] ?? '') === 'new' && $canManage;

$editItem = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM media WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editItem = $stmt->fetch() ?: null;
}

$page_title = 'Media — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Media</h1>
            <p class="muted">Public gallery for amenity photos. Private album for maintenance evidence.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ Upload</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($editItem): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6); display:flex; gap: var(--sp-5); flex-wrap: wrap;">
        <div style="flex: 0 0 auto;">
            <img src="/dashboard/file.php?type=media&id=<?= (int)$editItem['id'] ?>" alt="" style="width: 200px; height: 200px; object-fit: cover; border-radius: var(--r-md);">
        </div>
        <div style="flex: 1; min-width: 280px;">
            <div class="card__head">
                <h3 class="card__title">Edit image info</h3>
                <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/media.php?tab=<?= e((string)$editItem['visibility']) ?>">← Back</a>
            </div>
            <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
                Editing metadata only. To replace the actual image, delete this one and upload again.
            </p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="edit">
                <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="emvis">Visibility</label>
                        <select class="select" id="emvis" name="visibility">
                            <option value="public"  <?= $editItem['visibility']==='public'?'selected':'' ?>>Public — gallery</option>
                            <option value="private" <?= $editItem['visibility']==='private'?'selected':'' ?>>Private — board only</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="emlinked">Linked to</label>
                        <select class="select" id="emlinked" name="linked_type">
                            <?php foreach (['general'=>'General','work_order'=>'Work order','violation'=>'Violation','announcement'=>'Announcement'] as $v=>$lbl): ?>
                                <option value="<?= e($v) ?>" <?= $editItem['linked_type']===$v?'selected':'' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="emcat">Category</label>
                    <input class="input" id="emcat" name="category" value="<?= e((string)($editItem['category'] ?? '')) ?>" placeholder="Pool / Lobby / Roof / Damage">
                </div>
                <div class="field">
                    <label class="field__label" for="emcap">Caption</label>
                    <input class="input" id="emcap" name="caption" value="<?= e((string)($editItem['caption'] ?? '')) ?>">
                </div>
                <div class="row" style="justify-content: flex-end;">
                    <a class="btn btn--ghost" href="/dashboard/media.php?tab=<?= e((string)$editItem['visibility']) ?>">Cancel</a>
                    <button class="btn btn--primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showUpload): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">New upload</h3>
        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="upload">
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="mfile">Image</label><input class="input" type="file" id="mfile" name="file" required accept="image/*"></div>
                <div class="field">
                    <label class="field__label" for="mvis">Visibility</label>
                    <select class="select" id="mvis" name="visibility">
                        <option value="public">Public — gallery</option>
                        <option value="private" selected>Private — board only</option>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="mcat">Category</label><input class="input" id="mcat" name="category" placeholder="Pool / Lobby / Roof / Damage"></div>
                <div class="field">
                    <label class="field__label" for="mlinked">Linked to</label>
                    <select class="select" id="mlinked" name="linked_type">
                        <option value="general">General</option>
                        <option value="work_order">Work order</option>
                        <option value="violation">Violation</option>
                        <option value="announcement">Announcement</option>
                    </select>
                </div>
            </div>
            <div class="field"><label class="field__label" for="mcap">Caption</label><input class="input" id="mcap" name="caption"></div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/media.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Upload</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <a class="tab <?= $tab==='public'  ? 'is-active' : '' ?>" href="?tab=public">Public gallery</a>
        <a class="tab <?= $tab==='private' ? 'is-active' : '' ?>" href="?tab=private">Private / Internal</a>
    </div>

    <?php if (!$items): ?>
        <div class="card card--padded center"><p class="muted">No images in this album yet.</p></div>
    <?php else: ?>
    <div class="gallery">
        <?php foreach ($items as $m): ?>
            <div class="gallery__item">
                <a href="/dashboard/file.php?type=media&id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                    <img src="/dashboard/file.php?type=media&id=<?= (int)$m['id'] ?>" alt="<?= e((string)$m['caption']) ?>" loading="lazy">
                </a>
                <?php if ($m['caption']): ?>
                    <div class="gallery__caption"><?= e($m['caption']) ?></div>
                <?php endif; ?>
                <?php if ($canManage): ?>
                <div style="position:absolute; top:6px; right:6px; display:flex; gap: 4px;">
                    <a href="?action=edit&id=<?= (int)$m['id'] ?>" class="btn btn--ghost" style="padding: 0.2rem 0.5rem; font-size: var(--fs-xs); background: rgba(255,255,255,0.92);" title="Edit info">Edit</a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete this image?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button class="btn btn--danger" style="padding: 0.2rem 0.5rem; font-size: var(--fs-xs);" type="submit" title="Delete">×</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

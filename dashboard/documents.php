<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = role_can_manage(viewing_role());

// Ensure the association has the default category set on first visit.
ensure_default_document_categories($assocId);

$flashError = null;

// --- Add category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') {
        try {
            db()->prepare(
                'INSERT INTO document_categories (association_id, name, sort_order)
                 VALUES (?, ?, COALESCE((SELECT MAX(sort_order) FROM document_categories AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $name, $assocId]);
            audit('document_category.added', ['name' => $name], (int)db()->lastInsertId(), 'document_category');
            flash('success', "Category \"$name\" added.");
        } catch (PDOException $e) {
            flash('error', "Category \"$name\" already exists.");
        }
    }
    redirect('/dashboard/documents.php?manage_cats=1');
}

// --- Rename category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_rename') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid     = (int)($_POST['id'] ?? 0);
    $newName = trim((string)($_POST['name'] ?? ''));
    if ($cid && $newName !== '') {
        // Look up the old name first so we can update existing documents.
        $oldStmt = db()->prepare('SELECT name FROM document_categories WHERE id = ? AND association_id = ?');
        $oldStmt->execute([$cid, $assocId]);
        $oldName = (string)($oldStmt->fetchColumn() ?: '');
        if ($oldName === '') {
            flash('error', 'Category not found.');
        } elseif ($oldName === $newName) {
            // no-op
        } else {
            db()->beginTransaction();
            try {
                db()->prepare('UPDATE document_categories SET name = ? WHERE id = ? AND association_id = ?')
                    ->execute([$newName, $cid, $assocId]);
                // Keep existing documents in sync — they point to the category by name.
                db()->prepare('UPDATE documents SET category = ? WHERE association_id = ? AND category = ?')
                    ->execute([$newName, $assocId, $oldName]);
                db()->commit();
                audit('document_category.renamed', ['from' => $oldName, 'to' => $newName], $cid, 'document_category');
                flash('success', "Renamed \"$oldName\" → \"$newName\".");
            } catch (PDOException $e) {
                db()->rollBack();
                flash('error', "Could not rename — \"$newName\" is already in use.");
            }
        }
    }
    redirect('/dashboard/documents.php?manage_cats=1');
}

// --- Delete category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM document_categories WHERE id = ? AND association_id = ?')
        ->execute([$cid, $assocId]);
    audit('document_category.deleted', [], $cid, 'document_category');
    flash('success', 'Category deleted. Existing documents keep the label.');
    redirect('/dashboard/documents.php?manage_cats=1');
}

// --- Upload handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category    = trim((string)($_POST['category'] ?? 'General'));
    $access      = $_POST['access_level'] ?? 'members_only';
    if (!in_array($access, ['public', 'members_only', 'board_only'], true)) $access = 'members_only';

    if ($title === '') {
        $flashError = 'Title is required.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'File upload failed.';
    } elseif ($_FILES['file']['size'] > 25 * 1024 * 1024) {
        $flashError = 'Max file size is 25 MB.';
    } else {
        $allowed = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx'=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg'=> 'image/jpeg',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
        ];
        $orig = $_FILES['file']['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            $flashError = 'File type ".' . e($ext) . '" not allowed.';
        } else {
            $newName = uuid_filename($orig);
            $relDir  = "uploads/$assocId/documents";
            $absDir  = storage_path($relDir);
            ensure_dir($absDir);
            $relPath = "$relDir/$newName";
            $absPath = "$absDir/$newName";
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $absPath)) {
                $flashError = 'Could not save file. Check storage permissions.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO documents (association_id, title, description, category, file_path, file_type, access_level, uploaded_by, version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $assocId, $title, $description, $category, $relPath,
                    $allowed[$ext], $access, (int)$user['id'], '1.0',
                ]);
                $newId = (int)db()->lastInsertId();
                audit('document.uploaded', ['title' => $title, 'access' => $access], $newId, 'document');
                flash('success', "Uploaded \"$title\".");
                redirect('/dashboard/documents.php');
            }
        }
    }
}

// --- Delete handler (board only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT file_path, title FROM documents WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        $abs = storage_path($row['file_path']);
        if (is_file($abs)) @unlink($abs);
        db()->prepare('DELETE FROM documents WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
        audit('document.deleted', ['title' => $row['title']], $id, 'document');
        flash('success', "Deleted \"{$row['title']}\".");
    }
    redirect('/dashboard/documents.php');
}

// --- Listing query (filters) ---
$qCategory = trim((string)($_GET['category'] ?? ''));
$qSearch   = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT d.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS uploader
        FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
        WHERE d.association_id = ?';
$params = [$assocId];
if ($qCategory !== '') { $sql .= ' AND d.category = ?'; $params[] = $qCategory; }
if ($qSearch !== '')   { $sql .= ' AND (d.title LIKE ? OR d.description LIKE ?)'; $params[] = "%$qSearch%"; $params[] = "%$qSearch%"; }
// Hide board-only docs from anyone who isn't allowed to manage. Uses viewing_role
// so view-as-homeowner correctly suppresses them in the listing too.
if (!role_can_manage(viewing_role())) {
    $sql .= ' AND d.access_level <> "board_only"';
}
$sql .= ' ORDER BY d.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Curated category list (drives both the upload SELECT and the filter SELECT).
$catStmt = db()->prepare('SELECT id, name FROM document_categories WHERE association_id = ? ORDER BY sort_order, name');
$catStmt->execute([$assocId]);
$categoryRows = $catStmt->fetchAll();
$categories   = array_column($categoryRows, 'name');

// Filter dropdown also includes any "ghost" categories actually used by documents
// but no longer in the curated list (kept so old uploads remain filterable).
$inUseStmt = db()->prepare(
    'SELECT DISTINCT category FROM documents WHERE association_id = ? AND category IS NOT NULL AND category <> ""'
);
$inUseStmt->execute([$assocId]);
$inUseCats = array_column($inUseStmt->fetchAll(), 'category');
$filterCategories = $categories;
foreach ($inUseCats as $c) {
    if (!in_array($c, $filterCategories, true)) $filterCategories[] = $c;
}
sort($filterCategories);

$showUpload    = ($_GET['action'] ?? '') === 'new' && $canManage;
$showManageCat = ($_GET['manage_cats'] ?? '') === '1' && $canManage;
$page_title = 'Documents — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Documents</h1>
            <p class="muted">Bylaws, forms, minutes, insurance certificates — versioned and access-controlled.</p>
        </div>
        <?php if ($canManage): ?>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="?manage_cats=1">Manage categories</a>
                <a class="btn btn--primary" href="?action=new">+ Upload</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showManageCat): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Manage categories</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/documents.php">← Back to documents</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
            Categories drive the dropdown on the upload form and the filter on the documents list. Renaming a category also updates every document already tagged with it. Deleting a category leaves existing documents tagged with the old label (so nothing disappears).
        </p>

        <form method="post" class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="cat_add">
            <input class="input" name="name" required placeholder="New category name" style="max-width: 320px;">
            <button class="btn btn--primary" type="submit">Add</button>
        </form>

        <?php if (!$categoryRows): ?>
            <p class="muted">No categories yet.</p>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Name</th><th style="text-align:right; width: 220px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($categoryRows as $cat): ?>
                <tr>
                    <td>
                        <form method="post" class="row" style="gap: var(--sp-2);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="cat_rename">
                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                            <input class="input" name="name" value="<?= e((string)$cat['name']) ?>" required style="max-width: 320px;">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Save</button>
                        </form>
                    </td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete category &quot;<?= e((string)$cat['name']) ?>&quot;? Existing documents will keep the label as a free-text value.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="cat_delete">
                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                            <button class="btn btn--danger" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showUpload): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">New document</h3>
        <form method="post" action="/dashboard/documents.php" enctype="multipart/form-data" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="upload">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="title">Title</label>
                    <input class="input" id="title" name="title" required placeholder="2026 Bylaws (revised)">
                </div>
                <div class="field">
                    <label class="field__label" for="category">Category</label>
                    <select class="select" id="category" name="category">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c) ?>" <?= $c === 'General' ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($canManage): ?>
                        <div class="field__hint">
                            <a href="?manage_cats=1">Manage categories →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="access_level">Access</label>
                    <select class="select" id="access_level" name="access_level">
                        <option value="public">Public — anyone with the link</option>
                        <option value="members_only" selected>Members only</option>
                        <option value="board_only">Board only</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="file">File (max 25 MB)</label>
                    <input class="input" type="file" id="file" name="file" required>
                    <div class="field__hint">PDF, Word, Excel, images, txt, csv.</div>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="description">Description (optional)</label>
                <textarea class="textarea" id="description" name="description" rows="3"></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/documents.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Upload</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <form method="get" class="row" style="margin-bottom: var(--sp-4); gap: var(--sp-3);">
        <input class="input" type="search" name="q" placeholder="Search title or description" value="<?= e($qSearch) ?>" style="max-width: 320px;">
        <select class="select" name="category" style="max-width: 220px;">
            <option value="">All categories</option>
            <?php foreach ($filterCategories as $c): ?>
                <option value="<?= e($c) ?>" <?= $c === $qCategory ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
    </form>

    <?php if (!$rows): ?>
        <div class="card card--padded center"><p class="muted">No documents yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Access</th>
                <th>Uploaded</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $accessClass = $r['access_level'] === 'board_only' ? 'badge--navy'
                         : ($r['access_level'] === 'public' ? 'badge--success' : 'badge--info');
        ?>
            <tr>
                <td>
                    <strong><?= e($r['title']) ?></strong>
                    <?php if ($r['description']): ?>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth($r['description'], 0, 90, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($r['category'] ?: '—') ?></td>
                <td><span class="badge <?= $accessClass ?>"><?= e(str_replace('_',' ',$r['access_level'])) ?></span></td>
                <td>
                    <?= e(date('M j, Y', strtotime($r['created_at']))) ?>
                    <div class="muted" style="font-size: var(--fs-xs);">v<?= e((string)$r['version']) ?> &middot; <?= e(trim((string)$r['uploader']) ?: 'unknown') ?></div>
                </td>
                <td style="text-align:right;">
                    <a class="btn btn--ghost" href="/dashboard/file.php?type=document&id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">View</a>
                    <?php if ($canManage): ?>
                        <form method="post" action="/dashboard/documents.php" style="display:inline;" onsubmit="return confirm('Delete this document?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn--danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

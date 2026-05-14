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

// --- Edit metadata (title, description, category, access, unit assignment) ---
// File replacement is intentionally not supported here — re-upload + delete
// is the path for that. Keeps this handler simple and avoids orphaning
// storage files on partial errors.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $did           = (int)($_POST['id'] ?? 0);
    $title         = trim((string)($_POST['title'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));
    $category      = trim((string)($_POST['category'] ?? 'General'));
    $access        = $_POST['access_level'] ?? 'members_only';
    $unitId        = ($_POST['unit_id'] ?? '') === '' ? null : (int)$_POST['unit_id'];
    $memberId      = ($_POST['user_id'] ?? '') === '' ? null : (int)$_POST['user_id'];
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    if ($effectiveDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) $effectiveDate = '';
    $version = trim((string)($_POST['version'] ?? ''));
    if (!in_array($access, ['public','members_only','board_only','unit_only'], true)) $access = 'members_only';
    if ($access === 'unit_only' && !$unitId) $access = 'members_only';
    if ($unitId) {
        $check = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $check->execute([$unitId, $assocId]);
        if (!$check->fetchColumn()) $unitId = null;
    }
    if ($memberId) {
        $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $check->execute([$memberId, $assocId]);
        if (!$check->fetchColumn()) $memberId = null;
    }

    $check = db()->prepare('SELECT 1 FROM documents WHERE id = ? AND association_id = ?');
    $check->execute([$did, $assocId]);
    if (!$check->fetchColumn()) {
        $flashError = 'Document not found.';
    } elseif ($title === '') {
        $flashError = 'Title is required.';
    } else {
        db()->prepare(
            'UPDATE documents
                SET title = ?, description = ?, category = ?, access_level = ?, unit_id = ?, user_id = ?,
                    effective_date = ?, version = ?
              WHERE id = ? AND association_id = ?'
        )->execute([$title, $description ?: null, $category ?: null, $access, $unitId, $memberId,
                    $effectiveDate ?: null, $version ?: null, $did, $assocId]);
        audit('document.edited', ['title' => $title, 'access' => $access, 'unit_id' => $unitId, 'user_id' => $memberId], $did, 'document');
        flash('success', "Updated \"$title\".");
        redirect('/dashboard/documents.php');
    }
}

// --- Compose / save composed document (no file upload; HTML body via Quill) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'compose') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $did           = (int)($_POST['id'] ?? 0);
    $title         = trim((string)($_POST['title'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));
    $category      = trim((string)($_POST['category'] ?? 'General'));
    $access        = $_POST['access_level'] ?? 'members_only';
    $unitId        = ($_POST['unit_id'] ?? '') === '' ? null : (int)$_POST['unit_id'];
    $memberId      = ($_POST['user_id'] ?? '') === '' ? null : (int)$_POST['user_id'];
    $body          = (string)($_POST['body_html'] ?? '');
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    if ($effectiveDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) $effectiveDate = '';
    if (!in_array($access, ['public','members_only','board_only','unit_only'], true)) $access = 'members_only';
    if ($access === 'unit_only' && !$unitId) $access = 'members_only';
    if ($unitId) {
        $check = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $check->execute([$unitId, $assocId]);
        if (!$check->fetchColumn()) $unitId = null;
    }
    if ($memberId) {
        $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $check->execute([$memberId, $assocId]);
        if (!$check->fetchColumn()) $memberId = null;
    }

    $bodyText = trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', $body)));
    if ($title === '')       $flashError = 'Title is required.';
    elseif ($bodyText === '') $flashError = 'Body is required — write something in the editor.';
    elseif ($did === 0) {
        db()->prepare(
            'INSERT INTO documents (association_id, unit_id, user_id, title, description, effective_date, body_html, category,
                                    file_path, file_type, access_level, uploaded_by, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, "text/html", ?, ?, "1.0")'
        )->execute([
            $assocId, $unitId, $memberId, $title, $description ?: null, $effectiveDate ?: null,
            $body, $category ?: null, $access, (int)$user['id'],
        ]);
        $newId = (int)db()->lastInsertId();
        audit('document.composed', ['title' => $title, 'access' => $access, 'unit_id' => $unitId, 'user_id' => $memberId], $newId, 'document');
        flash('success', "Created \"$title\".");
        redirect('/dashboard/document.php?id=' . $newId);
    } else {
        // Edit existing composed document
        $check = db()->prepare('SELECT file_path FROM documents WHERE id = ? AND association_id = ?');
        $check->execute([$did, $assocId]);
        $row = $check->fetch();
        if (!$row || !empty($row['file_path'])) {
            $flashError = 'That document is an uploaded file, not a composed one — use Edit metadata instead.';
        } else {
            db()->prepare(
                'UPDATE documents
                    SET title = ?, description = ?, body_html = ?, category = ?, access_level = ?, unit_id = ?, user_id = ?,
                        effective_date = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([$title, $description ?: null, $body, $category ?: null, $access, $unitId, $memberId,
                        $effectiveDate ?: null, $did, $assocId]);
            audit('document.edited', ['title' => $title, 'composed' => true], $did, 'document');
            flash('success', "Updated \"$title\".");
            redirect('/dashboard/document.php?id=' . $did);
        }
    }
}

// --- Upload handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $title         = trim((string)($_POST['title'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));
    $category      = trim((string)($_POST['category'] ?? 'General'));
    $access        = $_POST['access_level'] ?? 'members_only';
    $unitId        = ($_POST['unit_id'] ?? '') !== '' ? (int)$_POST['unit_id'] : null;
    $memberId      = ($_POST['user_id'] ?? '') === '' ? null : (int)$_POST['user_id'];
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    if ($effectiveDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) $effectiveDate = '';
    if (!in_array($access, ['public', 'members_only', 'board_only', 'unit_only'], true)) $access = 'members_only';
    // unit_only without a unit makes no sense — fall back to members_only.
    if ($access === 'unit_only' && !$unitId) $access = 'members_only';
    // Validate unit belongs to this association.
    if ($unitId) {
        $check = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $check->execute([$unitId, $assocId]);
        if (!$check->fetchColumn()) $unitId = null;
    }
    if ($memberId) {
        $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $check->execute([$memberId, $assocId]);
        if (!$check->fetchColumn()) $memberId = null;
    }

    if ($title === '') {
        $flashError = 'Title is required.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'File upload failed.';
    } elseif ($_FILES['file']['size'] > 25 * 1024 * 1024) {
        $flashError = 'Max file size is 25 MB.';
    } elseif (storage_over_quota_by($association, (int)$_FILES['file']['size'])) {
        $used  = association_storage_used_bytes((int)$association['id']);
        $quota = association_storage_quota_bytes($association);
        $flashError = 'This upload would put you over your storage quota ('
            . format_bytes($used) . ' of ' . format_bytes($quota)
            . ' used). Delete something or contact us to add more space ($5/mo per extra GB).';
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
                    'INSERT INTO documents (association_id, unit_id, user_id, title, description, effective_date, category, file_path, file_type, access_level, uploaded_by, version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $assocId, $unitId, $memberId, $title, $description, $effectiveDate ?: null,
                    $category, $relPath, $allowed[$ext], $access, (int)$user['id'], '1.0',
                ]);
                $newId = (int)db()->lastInsertId();
                audit('document.uploaded', ['title' => $title, 'access' => $access, 'unit_id' => $unitId, 'user_id' => $memberId], $newId, 'document');
                flash('success', "Uploaded \"$title\".");
                redirect($unitId ? '/dashboard/unit.php?id=' . $unitId : '/dashboard/documents.php');
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
$qUnitId   = (int)($_GET['filter_unit_id'] ?? 0);
$qUserId   = (int)($_GET['filter_user_id'] ?? 0);

$sql = 'SELECT d.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS uploader,
               un.unit_number AS unit_label,
               TRIM(CONCAT(IFNULL(mu.first_name,""), " ", IFNULL(mu.last_name,""))) AS member_label
        FROM documents d
        LEFT JOIN users u  ON u.id  = d.uploaded_by
        LEFT JOIN units un ON un.id = d.unit_id
        LEFT JOIN users mu ON mu.id = d.user_id
        WHERE d.association_id = ?';
$params = [$assocId];
if ($qCategory !== '') { $sql .= ' AND d.category = ?'; $params[] = $qCategory; }
if ($qSearch !== '')   { $sql .= ' AND (d.title LIKE ? OR d.description LIKE ?)'; $params[] = "%$qSearch%"; $params[] = "%$qSearch%"; }
if ($qUnitId)          { $sql .= ' AND d.unit_id = ?'; $params[] = $qUnitId; }
if ($qUserId)          { $sql .= ' AND d.user_id = ?'; $params[] = $qUserId; }

// Visibility (uses viewing_role for view-as fidelity):
//   - Managers see everything.
//   - Non-managers: board_only docs are hidden; unit_only docs are visible
//     only to occupants of that unit.
if (!role_can_manage(viewing_role())) {
    $sql .= " AND d.access_level <> 'board_only'
              AND (d.access_level <> 'unit_only'
                   OR d.unit_id IN (SELECT unit_id FROM unit_occupants WHERE user_id = ?))";
    $params[] = (int)$user['id'];
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

// Edit target
$editDoc = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editDoc = $stmt->fetch() ?: null;
}

// Compose target (new or edit body)
$composeDoc = null;
$showCompose = ($_GET['action'] ?? '') === 'compose' && $canManage;
if ($showCompose && !empty($_GET['id'])) {
    $cid = (int)$_GET['id'];
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ? AND association_id = ?');
    $stmt->execute([$cid, $assocId]);
    $composeDoc = $stmt->fetch() ?: null;
    // Only allow composing on documents that are already composed (or new).
    if ($composeDoc && !empty($composeDoc['file_path'])) {
        $composeDoc = null;
        $flashError = 'That document is an uploaded file — composing edits the HTML body, which it doesn\'t have.';
    }
}
if ($showCompose) {
    $page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
}

// Pre-selected unit (from /dashboard/unit.php "+ Upload to unit" link, or from filter)
$preselectUnitId = (int)($_GET['unit_id'] ?? 0);

// Units list for the upload dropdown + listing filter (manager-relevant only).
$unitsList = [];
$membersList = [];
if ($canManage) {
    $u = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
    $u->execute([$assocId]);
    $unitsList = $u->fetchAll();

    // All non-inactive members — for the "attach to member" picker on document forms.
    $m = db()->prepare(
        "SELECT id, first_name, last_name, unit_number
           FROM users
          WHERE association_id = ? AND status <> 'inactive'
          ORDER BY last_name, first_name"
    );
    $m->execute([$assocId]);
    $membersList = $m->fetchAll();
}

$preselectUserId = (int)($_GET['user_id'] ?? 0);
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
                <a class="btn btn--ghost" href="?action=compose">✏️ Compose</a>
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

    <?php if ($editDoc): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Edit document</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/documents.php">← Back</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
            Editing metadata only. To replace the file itself, delete this entry and upload again.
        </p>
        <form method="post" action="/dashboard/documents.php" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit">
            <input type="hidden" name="id" value="<?= (int)$editDoc['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ed-title">Title</label>
                    <input class="input" id="ed-title" name="title" required value="<?= e((string)$editDoc['title']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ed-cat">Category</label>
                    <select class="select" id="ed-cat" name="category">
                        <?php
                        $haveMatch = false;
                        foreach ($categories as $c):
                            $sel = ($editDoc['category'] === $c);
                            if ($sel) $haveMatch = true;
                        ?>
                            <option value="<?= e($c) ?>" <?= $sel ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                        <?php if ($editDoc['category'] && !$haveMatch): ?>
                            <option value="<?= e((string)$editDoc['category']) ?>" selected><?= e((string)$editDoc['category']) ?> (legacy)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ed-unit">Attach to unit</label>
                    <select class="select" id="ed-unit" name="unit_id">
                        <option value="">— Not unit-specific —</option>
                        <?php foreach ($unitsList as $u_): ?>
                            <option value="<?= (int)$u_['id'] ?>" <?= (int)($editDoc['unit_id'] ?? 0) === (int)$u_['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u_['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="ed-mem">Attach to member</label>
                    <select class="select" id="ed-mem" name="user_id">
                        <option value="">— Not member-specific —</option>
                        <?php foreach ($membersList as $m_):
                            $nm = trim($m_['first_name'] . ' ' . $m_['last_name']);
                            if ($nm === '') continue;
                        ?>
                            <option value="<?= (int)$m_['id'] ?>" <?= (int)($editDoc['user_id'] ?? 0) === (int)$m_['id'] ? 'selected' : '' ?>><?= e($nm) ?><?= !empty($m_['unit_number']) ? ' · ' . e((string)$m_['unit_number']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Surfaces on that person's profile page.</div>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ed-access">Access</label>
                    <select class="select" id="ed-access" name="access_level">
                        <option value="public"        <?= $editDoc['access_level']==='public'?'selected':'' ?>>Public — anyone with the link</option>
                        <option value="members_only"  <?= $editDoc['access_level']==='members_only'?'selected':'' ?>>Members only</option>
                        <option value="board_only"    <?= $editDoc['access_level']==='board_only'?'selected':'' ?>>Board only</option>
                        <option value="unit_only"     <?= $editDoc['access_level']==='unit_only'?'selected':'' ?>>Unit only — its occupants + board</option>
                    </select>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ed-effdate">Effective date</label>
                    <input class="input" type="date" id="ed-effdate" name="effective_date"
                           value="<?= e((string)($editDoc['effective_date'] ?? '')) ?>">
                    <div class="field__hint">When this version takes effect — shown on the document listing.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="ed-ver">Version</label>
                    <input class="input" id="ed-ver" name="version" maxlength="20"
                           value="<?= e((string)($editDoc['version'] ?? '')) ?>" placeholder="e.g. 2.1 or 2026-A">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="ed-desc">Description</label>
                <textarea class="textarea" id="ed-desc" name="description" rows="4"><?= e((string)($editDoc['description'] ?? '')) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/documents.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showCompose):
        $cv = $composeDoc ?? ['title'=>'','description'=>'','body_html'=>'','category'=>'General','access_level'=>'members_only','unit_id'=>null,'user_id'=>null,'id'=>0];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $composeDoc ? 'Edit document body' : 'Compose a new document' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/documents.php">← Back</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
            Write the document directly here — no PDF or Word file needed. You'll be able to print or save-as-PDF from the document view afterwards.
        </p>
        <form method="post" action="/dashboard/documents.php" class="form" data-doc-compose-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="compose">
            <?php if ($composeDoc): ?><input type="hidden" name="id" value="<?= (int)$cv['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="dc-title">Title</label>
                    <input class="input" id="dc-title" name="title" required maxlength="255" value="<?= e((string)$cv['title']) ?>" placeholder="2026 Pool House Procedures">
                </div>
                <div class="field">
                    <label class="field__label" for="dc-cat">Category</label>
                    <select class="select" id="dc-cat" name="category">
                        <?php $haveCatMatch = false;
                        foreach ($categories as $c):
                            $sel = $cv['category'] === $c;
                            if ($sel) $haveCatMatch = true;
                        ?>
                            <option value="<?= e($c) ?>" <?= $sel ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                        <?php if (!empty($cv['category']) && !$haveCatMatch): ?>
                            <option value="<?= e((string)$cv['category']) ?>" selected><?= e((string)$cv['category']) ?> (legacy)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="dc-unit">Attach to unit (optional)</label>
                    <select class="select" id="dc-unit" name="unit_id">
                        <option value="">— Not unit-specific —</option>
                        <?php foreach ($unitsList as $u_): ?>
                            <option value="<?= (int)$u_['id'] ?>" <?= (int)($cv['unit_id'] ?? 0) === (int)$u_['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u_['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="dc-mem">Attach to member (optional)</label>
                    <select class="select" id="dc-mem" name="user_id">
                        <option value="">— Not member-specific —</option>
                        <?php foreach ($membersList as $m_):
                            $nm = trim($m_['first_name'] . ' ' . $m_['last_name']);
                            if ($nm === '') continue;
                        ?>
                            <option value="<?= (int)$m_['id'] ?>" <?= (int)($cv['user_id'] ?? 0) === (int)$m_['id'] ? 'selected' : '' ?>><?= e($nm) ?><?= !empty($m_['unit_number']) ? ' · ' . e((string)$m_['unit_number']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Surfaces on that person's profile page.</div>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="dc-access">Access</label>
                    <select class="select" id="dc-access" name="access_level">
                        <option value="public"        <?= $cv['access_level']==='public'?'selected':'' ?>>Public — anyone with the link</option>
                        <option value="members_only"  <?= $cv['access_level']==='members_only'?'selected':'' ?>>Members only</option>
                        <option value="board_only"    <?= $cv['access_level']==='board_only'?'selected':'' ?>>Board only</option>
                        <option value="unit_only"     <?= $cv['access_level']==='unit_only'?'selected':'' ?>>Unit only</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="dc-effdate">Effective date (optional)</label>
                    <input class="input" type="date" id="dc-effdate" name="effective_date"
                           value="<?= e((string)($cv['effective_date'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="dc-desc">Description (optional, shown in the listing)</label>
                <input class="input" id="dc-desc" name="description" maxlength="500" value="<?= e((string)($cv['description'] ?? '')) ?>">
            </div>
            <div class="field">
                <label class="field__label">Body</label>
                <div id="doc-editor" data-initial-html="<?= e((string)$cv['body_html']) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                <textarea name="body_html" id="dc-body" hidden></textarea>
                <div class="field__hint">Use the toolbar to format. Click the image button to drop a photo into the document.</div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/documents.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $composeDoc ? 'Save changes' : 'Create document' ?></button>
            </div>
        </form>
    </div>

    <input type="file" id="doc-img-input" accept="image/*" style="display:none;">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
    (function () {
        if (typeof Quill === 'undefined') return;
        var editorEl = document.getElementById('doc-editor');
        if (!editorEl) return;
        var hidden   = document.getElementById('dc-body');
        var csrfTok  = document.querySelector('input[name="_csrf"]').value;
        var initial  = editorEl.getAttribute('data-initial-html') || '';

        var quill = new Quill('#doc-editor', {
            theme: 'snow',
            placeholder: 'Write the document. Toolbar handles formatting; image button drops photos inline.',
            modules: {
                toolbar: {
                    container: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['blockquote'],
                        ['link', 'image'],
                        ['clean']
                    ],
                    handlers: { image: function () { document.getElementById('doc-img-input').click(); } }
                }
            }
        });
        editorEl.querySelector('.ql-editor').style.minHeight = '320px';
        if (initial) quill.clipboard.dangerouslyPasteHTML(0, initial);

        var imgInput = document.getElementById('doc-img-input');
        imgInput.addEventListener('change', async function (ev) {
            var file = ev.target.files[0]; if (!file) return;
            var fd = new FormData(); fd.append('file', file); fd.append('_csrf', csrfTok);
            try {
                var res = await fetch('/dashboard/upload-image.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': csrfTok }});
                var data = await res.json();
                if (data.ok && data.url) {
                    var range = quill.getSelection(true);
                    quill.insertEmbed(range.index, 'image', data.url, 'user');
                    quill.setSelection(range.index + 1);
                } else { alert('Upload failed: ' + (data.error || 'unknown error')); }
            } catch (err) { alert('Upload failed: ' + err.message); }
            imgInput.value = '';
        });

        var form = document.querySelector('form[data-doc-compose-form]');
        if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
    })();
    </script>
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
                    <label class="field__label" for="unit_id">Attach to unit (optional)</label>
                    <select class="select" id="unit_id" name="unit_id">
                        <option value="">— Not unit-specific —</option>
                        <?php foreach ($unitsList as $u_): ?>
                            <option value="<?= (int)$u_['id'] ?>" <?= $preselectUnitId === (int)$u_['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u_['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Pick a unit for rental agreements, deeds, and anything specific to one home.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="user_id">Attach to member (optional)</label>
                    <select class="select" id="user_id" name="user_id">
                        <option value="">— Not member-specific —</option>
                        <?php foreach ($membersList as $m_):
                            $nm = trim($m_['first_name'] . ' ' . $m_['last_name']);
                            if ($nm === '') continue;
                        ?>
                            <option value="<?= (int)$m_['id'] ?>" <?= $preselectUserId === (int)$m_['id'] ? 'selected' : '' ?>><?= e($nm) ?><?= !empty($m_['unit_number']) ? ' · ' . e((string)$m_['unit_number']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Surfaces on that person's profile page.</div>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="access_level">Access</label>
                    <select class="select" id="access_level" name="access_level">
                        <option value="public">Public — anyone with the link</option>
                        <option value="members_only" <?= !$preselectUnitId ? 'selected' : '' ?>>Members only</option>
                        <option value="board_only">Board only</option>
                        <option value="unit_only" <?= $preselectUnitId ? 'selected' : '' ?>>Unit only — its occupants + board</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="upload-effdate">Effective date (optional)</label>
                    <input class="input" type="date" id="upload-effdate" name="effective_date">
                    <div class="field__hint">When this version takes effect.</div>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="file">File (max 25 MB)</label>
                <input class="input" type="file" id="file" name="file" required>
                <div class="field__hint">PDF, Word, Excel, images, txt, csv.</div>
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

    <form method="get" class="row" style="margin-bottom: var(--sp-4); gap: var(--sp-3); flex-wrap: wrap;">
        <input class="input" type="search" name="q" placeholder="Search title or description" value="<?= e($qSearch) ?>" style="max-width: 280px;">
        <select class="select" name="category" style="max-width: 200px;">
            <option value="">All categories</option>
            <?php foreach ($filterCategories as $c): ?>
                <option value="<?= e($c) ?>" <?= $c === $qCategory ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($canManage && $unitsList): ?>
            <select class="select" name="filter_unit_id" style="max-width: 200px;">
                <option value="0">All units</option>
                <?php foreach ($unitsList as $u_): ?>
                    <option value="<?= (int)$u_['id'] ?>" <?= $qUnitId === (int)$u_['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u_['unit_number']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn--ghost" type="submit">Filter</button>
        <?php if ($qSearch !== '' || $qCategory !== '' || $qUnitId): ?>
            <a class="btn btn--ghost" href="/dashboard/documents.php">Clear</a>
        <?php endif; ?>
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
                <th>Unit</th>
                <th>Access</th>
                <th>Uploaded</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $accessClass = match ($r['access_level']) {
                'board_only' => 'badge--navy',
                'public'     => 'badge--success',
                'unit_only'  => 'badge--orange',
                default      => 'badge--info',
            };
        ?>
            <tr>
                <td>
                    <strong><?= e($r['title']) ?></strong>
                    <?php if ($r['description']): ?>
                        <div class="muted rule-body-clamp" style="font-size: var(--fs-xs); white-space: pre-wrap; -webkit-line-clamp: 2;"><?= e((string)$r['description']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($r['category'] ?: '—') ?></td>
                <td>
                    <?php if (!empty($r['unit_label'])): ?>
                        <a href="/dashboard/unit.php?id=<?= (int)$r['unit_id'] ?>"><?= e((string)$r['unit_label']) ?></a>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $accessClass ?>"><?= e(str_replace('_',' ',$r['access_level'])) ?></span></td>
                <td>
                    <?php if (!empty($r['effective_date'])): ?>
                        <strong style="font-size: var(--fs-sm);">Eff. <?= e(udate('M j, Y', strtotime((string)$r['effective_date']))) ?></strong>
                    <?php else: ?>
                        <?= e(udate('M j, Y', strtotime($r['created_at']))) ?>
                    <?php endif; ?>
                    <div class="muted" style="font-size: var(--fs-xs);">
                        <?php if (!empty($r['version'])): ?>v<?= e((string)$r['version']) ?> &middot; <?php endif; ?>
                        <?= e(trim((string)$r['uploader']) ?: 'unknown') ?>
                    </div>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <?php $viewUrl = !empty($r['file_path']) ? '/dashboard/file.php?type=document&id=' . (int)$r['id'] : '/dashboard/document.php?id=' . (int)$r['id']; ?>
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="<?= e($viewUrl) ?>" <?= !empty($r['file_path']) ? 'target="_blank" rel="noopener"' : '' ?>>View</a>
                    <?php if ($canManage): ?>
                        <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                        <form method="post" action="/dashboard/documents.php" style="display:inline;" onsubmit="return confirm('Delete this document?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
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

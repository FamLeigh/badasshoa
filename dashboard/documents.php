<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_member'];

$flashError = null;

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
// Hide board-only docs from non-board roles.
if ((ROLE_RANK[$user['role']] ?? 0) < ROLE_RANK['board_member']) {
    $sql .= ' AND d.access_level <> "board_only"';
}
$sql .= ' ORDER BY d.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Distinct categories for filter dropdown.
$cats = db()->prepare('SELECT DISTINCT category FROM documents WHERE association_id = ? AND category IS NOT NULL ORDER BY category');
$cats->execute([$assocId]);
$categories = array_column($cats->fetchAll(), 'category');

$showUpload = ($_GET['action'] ?? '') === 'new' && $canManage;
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
            <a class="btn btn--primary" href="?action=new">+ Upload</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

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
                    <input class="input" id="category" name="category" placeholder="Bylaws / Forms / Minutes / Insurance" list="cats-list">
                    <datalist id="cats-list">
                        <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                    </datalist>
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
            <?php foreach ($categories as $c): ?>
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

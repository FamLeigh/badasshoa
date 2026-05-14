<?php
// View a single document. For composed documents (body_html set, file_path
// null) it renders the body inline; for uploaded files it redirects to the
// file gatekeeper. ?print=1 strips the chrome and auto-opens the print dialog.
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = role_can_manage(viewing_role());

$did = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT d.*, un.unit_number AS unit_label,
            TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS uploader_name
       FROM documents d
       LEFT JOIN units un ON un.id = d.unit_id
       LEFT JOIN users u  ON u.id  = d.uploaded_by
      WHERE d.id = ? AND d.association_id = ?'
);
$stmt->execute([$did, $assocId]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); die('Document not found'); }

// Access-level enforcement — mirrors /dashboard/file.php.
if ($doc['access_level'] === 'board_only' && !role_can_manage(viewing_role())) {
    http_response_code(403); die('Forbidden');
}
if ($doc['access_level'] === 'unit_only' && !role_can_manage(viewing_role())) {
    $allowed = false;
    if (!empty($doc['unit_id'])) {
        $chk = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
        $chk->execute([(int)$doc['unit_id'], (int)$user['id']]);
        $allowed = (bool)$chk->fetchColumn();
    }
    if (!$allowed) { http_response_code(403); die('Forbidden'); }
}

// Uploaded files (file_path set) are served through the existing gatekeeper.
// This page is the right home for composed documents (body_html set).
if (!empty($doc['file_path'])) {
    header('Location: /dashboard/file.php?type=document&id=' . (int)$did);
    exit;
}

$printMode = isset($_GET['print']);

if ($printMode) {
    audit('document.printed', ['title' => $doc['title']], (int)$did, 'document');
    ?><!doctype html>
    <html lang="en"><head>
    <meta charset="utf-8">
    <title><?= e((string)$doc['title']) ?> — <?= e((string)$association['name']) ?></title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 1in; line-height: 1.55; }
        h1 { font-size: 22pt; margin: 0 0 0.25em; }
        .meta { color: #555; font-size: 10pt; margin-bottom: 1.5em; padding-bottom: 0.5em; border-bottom: 1px solid #ccc; }
        img { max-width: 100%; }
        a { color: #0a4a8f; }
        .footer { margin-top: 2em; padding-top: 1em; border-top: 1px solid #ccc; color: #888; font-size: 9pt; }
        @media print { @page { margin: 0.7in; } a { color: inherit; text-decoration: none; } }
    </style>
    </head><body>
    <?= print_header_html($association) ?>
    <h1><?= e((string)$doc['title']) ?></h1>
    <div class="meta">
        <?php if (!empty($doc['category'])): ?><?= e((string)$doc['category']) ?> · <?php endif; ?>
        <?php if (!empty($doc['unit_label'])): ?>Unit <?= e((string)$doc['unit_label']) ?> · <?php endif; ?>
        Created <?= e(udate('M j, Y', strtotime((string)$doc['created_at']))) ?>
        <?php if ($doc['uploaded_by']): ?> · by <?= e(trim((string)$doc['uploader_name']) ?: 'unknown') ?><?php endif; ?>
    </div>
    <div class="body">
        <?php if (!empty($doc['description'])): ?>
            <p style="color:#555; font-style: italic;"><?= e((string)$doc['description']) ?></p>
        <?php endif; ?>
        <?= (string)$doc['body_html'] /* board-trusted HTML from Quill */ ?>
    </div>
    <?= print_footer_html('Printed ' . udate('M j, Y')) ?>
    <script>window.addEventListener('load', function(){ window.print(); });</script>
    </body></html>
    <?php
    exit;
}

$page_title = $doc['title'] . ' — Documents';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 880px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4); align-items:flex-start; flex-wrap: wrap; gap: var(--sp-2);">
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/documents.php">← All documents</a>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= (int)$doc['id'] ?>&print=1" target="_blank" rel="noopener">🖨 Print</a>
            <?php if ($canManage): ?>
                <a class="btn btn--ghost" href="/dashboard/documents.php?action=edit&id=<?= (int)$doc['id'] ?>">Edit metadata</a>
                <a class="btn btn--ghost" href="/dashboard/documents.php?action=compose&id=<?= (int)$doc['id'] ?>">Edit body</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
        <?php
        $accessClass = match ($doc['access_level']) {
            'board_only' => 'badge--navy',
            'public'     => 'badge--success',
            'unit_only'  => 'badge--orange',
            default      => 'badge--info',
        };
        ?>
        <span class="badge <?= $accessClass ?>"><?= e(str_replace('_',' ',(string)$doc['access_level'])) ?></span>
        <?php if (!empty($doc['category'])): ?>
            <span class="badge" style="background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid rgba(182,130,42,0.25);"><?= e((string)$doc['category']) ?></span>
        <?php endif; ?>
        <?php if (!empty($doc['unit_label'])): ?>
            <a class="badge badge--info" href="/dashboard/unit.php?id=<?= (int)$doc['unit_id'] ?>" style="text-decoration:none;">Unit <?= e((string)$doc['unit_label']) ?></a>
        <?php endif; ?>
        <span class="muted" style="font-size: var(--fs-sm);">Created <?= e(udate('M j, Y', strtotime((string)$doc['created_at']))) ?></span>
    </div>

    <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-4); letter-spacing: -0.01em;"><?= e((string)$doc['title']) ?></h1>

    <?php if (!empty($doc['description'])): ?>
        <p class="muted" style="font-size: var(--fs-md); margin-bottom: var(--sp-4);"><?= e((string)$doc['description']) ?></p>
    <?php endif; ?>

    <article class="card card--padded" style="line-height: var(--lh-loose);">
        <?= (string)$doc['body_html'] /* board-trusted HTML from Quill */ ?>
    </article>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

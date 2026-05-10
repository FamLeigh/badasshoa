<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$ajax      = isset($_GET['ajax']);
$flashError    = null;
$importSummary = null;

// ============================================================================
// Downloadable CSV template
// ============================================================================
if (($_GET['download'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="badasshoa-rules-template.csv"');
    echo "title,rule_number,category,source,effective_date,body\n";
    echo '"No grilling on balconies","4.2.1","Common areas","board_rule","2025-01-15","Per fire-code regulations, gas and charcoal grills are prohibited on all unit balconies and patios."' . "\n";
    echo '"Pet weight limit","2.1","Pets","bylaw","2020-06-01","Pets must not exceed 50 lbs at maturity. Documentation required at move-in."' . "\n";
    echo '"Quiet hours","5.3","Noise","policy","2024-03-01","Noise from any source must not be audible outside the unit between 10 PM and 7 AM."' . "\n";
    exit;
}

// ============================================================================
// POST handlers
// ============================================================================

// --- Add rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    [$title,$body,$cat,$source,$num,$date,$err] = rule_form_validate($_POST);
    if ($err) {
        $flashError = $err;
    } else {
        db()->prepare(
            'INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, $title, $body, $cat ?: null, $source, $num ?: null, $date ?: null]);
        $newId = (int)db()->lastInsertId();
        audit('rule.added', ['title' => $title, 'source' => $source], $newId, 'rule');
        flash('success', "Rule \"$title\" added.");
        redirect('/dashboard/search.php');
    }
}

// --- Edit rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid = (int)($_POST['id'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM rules WHERE id = ? AND association_id = ?');
    $check->execute([$rid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Rule not found'); }

    [$title,$body,$cat,$source,$num,$date,$err] = rule_form_validate($_POST);
    if ($err) {
        $flashError = $err;
    } else {
        db()->prepare(
            'UPDATE rules SET title = ?, body = ?, category = ?, source = ?, rule_number = ?, effective_date = ?
             WHERE id = ? AND association_id = ?'
        )->execute([$title, $body, $cat ?: null, $source, $num ?: null, $date ?: null, $rid, $assocId]);
        audit('rule.edited', ['title' => $title], $rid, 'rule');
        flash('success', "Rule \"$title\" updated.");
        redirect('/dashboard/search.php');
    }
}

// --- Delete rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT title FROM rules WHERE id = ? AND association_id = ?');
    $stmt->execute([$rid, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('DELETE FROM rules WHERE id = ? AND association_id = ?')->execute([$rid, $assocId]);
        audit('rule.deleted', ['title' => $row['title']], $rid, 'rule');
        flash('success', "Rule \"{$row['title']}\" deleted.");
    }
    redirect('/dashboard/search.php');
}

// --- Add category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') {
        try {
            db()->prepare(
                'INSERT INTO rule_categories (association_id, name, sort_order)
                 VALUES (?, ?, COALESCE((SELECT MAX(sort_order) FROM rule_categories AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $name, $assocId]);
            audit('rule_category.added', ['name' => $name], (int)db()->lastInsertId(), 'rule_category');
            flash('success', "Category \"$name\" added.");
        } catch (PDOException $e) {
            flash('error', "Category \"$name\" already exists.");
        }
    }
    redirect('/dashboard/search.php?action=categories');
}

// --- Delete category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM rule_categories WHERE id = ? AND association_id = ?')->execute([$cid, $assocId]);
    audit('rule_category.deleted', [], $cid, 'rule_category');
    flash('success', 'Category deleted. Existing rules keep the category label.');
    redirect('/dashboard/search.php?action=categories');
}

// --- CSV import ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'CSV upload failed.';
    } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        $flashError = 'Max CSV size is 2 MB.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $flashError = 'Could not read CSV.';
        } else {
            $existingCats = [];
            $catStmt = db()->prepare('SELECT name FROM rule_categories WHERE association_id = ?');
            $catStmt->execute([$assocId]);
            foreach ($catStmt->fetchAll() as $c) $existingCats[mb_strtolower($c['name'])] = $c['name'];

            $added = 0; $errors = []; $row = 0; $headerMap = null;

            while (($cols = fgetcsv($fh)) !== false) {
                $row++;
                if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

                if ($headerMap === null) {
                    $headerMap = [];
                    foreach ($cols as $i => $name) {
                        $key = strtolower(trim(str_replace(' ', '_', (string)$name)));
                        $headerMap[$key] = $i;
                    }
                    foreach (['title','body'] as $req) {
                        if (!isset($headerMap[$req])) {
                            $flashError = "Missing required column: $req. Required: title, body. Optional: rule_number, category, source, effective_date.";
                            break 2;
                        }
                    }
                    continue;
                }

                $get   = fn($k) => isset($headerMap[$k], $cols[$headerMap[$k]]) ? trim((string)$cols[$headerMap[$k]]) : '';
                $title = $get('title');
                $body  = $get('body');
                $num   = $get('rule_number');
                $cat   = $get('category');
                $src   = strtolower($get('source')) ?: 'board_rule';
                $date  = $get('effective_date');
                if (!in_array($src, ['bylaw','board_rule','policy'], true)) $src = 'board_rule';
                if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

                if ($title === '') { $errors[] = "Row $row: missing title"; continue; }
                if ($body === '')  { $errors[] = "Row $row: missing body";  continue; }

                if ($cat !== '' && !isset($existingCats[mb_strtolower($cat)])) {
                    db()->prepare('INSERT IGNORE INTO rule_categories (association_id, name) VALUES (?, ?)')
                        ->execute([$assocId, $cat]);
                    $existingCats[mb_strtolower($cat)] = $cat;
                }

                db()->prepare(
                    'INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$assocId, $title, $body, $cat ?: null, $src, $num ?: null, $date ?: null]);
                $added++;
            }
            fclose($fh);
            $importSummary = ['added' => $added, 'errors' => $errors];
            audit('rules.imported', $importSummary);
        }
    }
}

// ============================================================================
// Helpers
// ============================================================================
function rule_form_validate(array $post): array
{
    $title  = trim((string)($post['title'] ?? ''));
    $body   = (string)($post['body'] ?? '');
    $cat    = trim((string)($post['category'] ?? ''));
    $source = $post['source'] ?? 'board_rule';
    $num    = trim((string)($post['rule_number'] ?? ''));
    $date   = trim((string)($post['effective_date'] ?? ''));
    if (!in_array($source, ['bylaw','board_rule','policy'], true)) $source = 'board_rule';
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

    $bodyText = trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', $body)));
    $err = '';
    if ($title === '')      $err = 'Title is required.';
    elseif ($bodyText === '') $err = 'Body is required.';

    return [$title, $body, $cat, $source, $num, $date, $err];
}

// ============================================================================
// Data load
// ============================================================================

// Categories (used by add/edit form selects)
$catStmt = db()->prepare('SELECT id, name FROM rule_categories WHERE association_id = ? ORDER BY sort_order, name');
$catStmt->execute([$assocId]);
$categories = $catStmt->fetchAll();

// Edit target
$editRule = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM rules WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editRule = $stmt->fetch() ?: null;
}

// Search / list
$q       = trim((string)($_GET['q'] ?? ''));
$source  = $_GET['source'] ?? '';
$results = [];

if ($q !== '') {
    $sql = "SELECT id, title, body, category, source, rule_number, effective_date,
                   MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
            FROM rules
            WHERE association_id = ?
              AND MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)";
    $params = [$q, $assocId, $q];
    if (in_array($source, ['bylaw','board_rule','policy'], true)) {
        $sql .= ' AND source = ?'; $params[] = $source;
    }
    $sql .= ' ORDER BY score DESC LIMIT 30';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        $sql = "SELECT id, title, body, category, source, rule_number, effective_date
                FROM rules WHERE association_id = ? AND (title LIKE ? OR body LIKE ?)";
        $params = [$assocId, "%$q%", "%$q%"];
        if (in_array($source, ['bylaw','board_rule','policy'], true)) {
            $sql .= ' AND source = ?'; $params[] = $source;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 30';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
}

// AJAX response (live search)
if ($ajax) {
    if (empty($results)) {
        echo '<div class="card card--padded muted center">No matches for &ldquo;' . e($q) . '&rdquo;.</div>';
        exit;
    }
    foreach ($results as $r) {
        $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
        $highlighted = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', e($excerpt));
        echo '<div class="search-result">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2);">
                    <span class="badge badge--' . ($r['source'] === 'bylaw' ? 'navy' : ($r['source'] === 'policy' ? 'info' : 'orange')) . '">' . e(str_replace('_',' ',$r['source'])) . '</span>
                    ' . ($r['rule_number'] ? '<span class="muted" style="font-size: var(--fs-xs);">#' . e($r['rule_number']) . '</span>' : '') . '
                    ' . ($r['category'] ? '<span class="muted" style="font-size: var(--fs-xs);">&middot; ' . e($r['category']) . '</span>' : '') . '
                </div>
                <strong>' . e($r['title']) . '</strong>
                <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">' . $highlighted . '</p>
              </div>';
    }
    exit;
}

// View flags
$showAdd  = ($_GET['action'] ?? '') === 'new'        && $canManage;
$showEdit = $editRule !== null;
$showCats = ($_GET['action'] ?? '') === 'categories' && $canManage;
$showImp  = ($_GET['action'] ?? '') === 'import'     && $canManage;
$showForm = $showAdd || $showEdit;

$page_title = 'Rules &amp; Bylaws — ' . $association['name'];
if ($showForm) {
    $page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
}
require __DIR__ . '/../includes/header.php';

// Helper for the rule form (used by both add and edit)
function rule_form_card(?array $editing, array $categories): void {
    $isEdit = $editing !== null;
    $vals = $editing ?? [];
    $action = $isEdit ? 'edit' : 'add';
    $title  = $vals['title']          ?? '';
    $cat    = $vals['category']       ?? '';
    $src    = $vals['source']         ?? 'board_rule';
    $num    = $vals['rule_number']    ?? '';
    $date   = $vals['effective_date'] ?? '';
    $body   = $vals['body']           ?? '';
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit rule' : 'New rule' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?action=categories">Manage categories →</a>
        </div>
        <form method="post" class="form" data-rule-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= e($action) ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rtitle">Title</label>
                    <input class="input" id="rtitle" name="title" required value="<?= e($title) ?>" placeholder="No barbecues on balconies">
                </div>
                <div class="field">
                    <label class="field__label" for="rsource">Source</label>
                    <select class="select" id="rsource" name="source">
                        <option value="bylaw"      <?= $src==='bylaw'?'selected':'' ?>>Bylaw</option>
                        <option value="board_rule" <?= $src==='board_rule'?'selected':'' ?>>Board rule</option>
                        <option value="policy"     <?= $src==='policy'?'selected':'' ?>>Policy</option>
                    </select>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rcat">Category</label>
                    <select class="select" id="rcat" name="category">
                        <option value="">— None —</option>
                        <?php
                        $haveMatch = false;
                        foreach ($categories as $c):
                            $sel = ($cat === $c['name']);
                            if ($sel) $haveMatch = true;
                        ?>
                            <option value="<?= e($c['name']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($cat !== '' && !$haveMatch): ?>
                            <option value="<?= e($cat) ?>" selected><?= e($cat) ?> (legacy)</option>
                        <?php endif; ?>
                    </select>
                    <div class="field__hint"><a href="?action=categories">Add or manage categories →</a></div>
                </div>
                <div class="field">
                    <label class="field__label" for="rnum">Rule number</label>
                    <input class="input" id="rnum" name="rule_number" value="<?= e($num) ?>" placeholder="3.4.1">
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rdate">Effective date</label>
                    <input class="input" type="date" id="rdate" name="effective_date" value="<?= e((string)$date) ?>">
                    <div class="field__hint">When this rule went into effect.</div>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <div class="field">
                <label class="field__label">Body</label>
                <div id="rule-editor" data-initial-html="<?= e($body) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                <textarea name="body" id="rbody" hidden><?= e($body) ?></textarea>
                <div class="field__hint">Use the toolbar to format. Click the image button to upload photos directly into the rule.</div>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Save rule' ?></button>
            </div>
        </form>
    </div>
    <?php
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Rules &amp; bylaws</h1>
            <p class="muted">Search, manage, and import your association's rules.</p>
        </div>
        <?php if ($canManage): ?>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="?action=categories">Categories</a>
            <a class="btn btn--ghost" href="?action=import">⬆ Import CSV</a>
            <a class="btn btn--primary" href="?action=new">+ Add rule</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($importSummary): ?>
        <div class="flash flash--success">
            Imported <strong><?= (int)$importSummary['added'] ?></strong> rule<?= $importSummary['added']===1?'':'s' ?>.
            <?php if (!empty($importSummary['errors'])): ?>
                <details style="margin-top: var(--sp-2);">
                    <summary><?= count($importSummary['errors']) ?> row<?= count($importSummary['errors'])===1?'':'s' ?> errored</summary>
                    <ul style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">
                        <?php foreach ($importSummary['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($showForm): rule_form_card($editRule, $categories); ?>

    <input type="file" id="rule-img-input" accept="image/*" style="display:none;">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
        (function () {
            if (typeof Quill === 'undefined') return;
            var editorEl = document.getElementById('rule-editor');
            if (!editorEl) return;

            var hidden  = document.getElementById('rbody');
            var csrfTok = document.querySelector('input[name="_csrf"]').value;
            var initial = editorEl.getAttribute('data-initial-html') || '';

            var quill = new Quill('#rule-editor', {
                theme: 'snow',
                placeholder: 'Write the rule. Use the toolbar to format and the image button to add photos.',
                modules: {
                    toolbar: {
                        container: [
                            [{ 'header': [2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            ['blockquote'],
                            ['link', 'image'],
                            ['clean']
                        ],
                        handlers: {
                            image: function () { document.getElementById('rule-img-input').click(); }
                        }
                    }
                }
            });
            editorEl.querySelector('.ql-editor').style.minHeight = '240px';

            // Pre-populate on edit
            if (initial) {
                quill.clipboard.dangerouslyPasteHTML(0, initial);
            }

            // Image upload
            var imgInput = document.getElementById('rule-img-input');
            imgInput.addEventListener('change', async function (ev) {
                var file = ev.target.files[0]; if (!file) return;
                var fd = new FormData();
                fd.append('file', file); fd.append('_csrf', csrfTok);
                try {
                    var res = await fetch('/dashboard/upload-image.php', {
                        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': csrfTok }
                    });
                    var data = await res.json();
                    if (data.ok && data.url) {
                        var range = quill.getSelection(true);
                        quill.insertEmbed(range.index, 'image', data.url, 'user');
                        quill.setSelection(range.index + 1);
                    } else { alert('Upload failed: ' + (data.error || 'unknown error')); }
                } catch (err) { alert('Upload failed: ' + err.message); }
                imgInput.value = '';
            });

            // Sync HTML to hidden textarea on submit
            var form = document.querySelector('form[data-rule-form]');
            if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
        })();
    </script>
    <?php endif; ?>

    <?php if ($showCats): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Manage categories</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>
        <p class="muted">These categories appear in the dropdown when adding or editing rules. Deleting a category here doesn't remove the label from existing rules.</p>

        <form method="post" class="row" style="gap: var(--sp-2); margin: var(--sp-4) 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="cat_add">
            <input class="input" name="name" required placeholder="New category name (e.g. Trash &amp; recycling)" style="flex: 1; max-width: 360px;">
            <button class="btn btn--primary" type="submit">Add category</button>
        </form>

        <?php if (!$categories): ?>
            <p class="muted">No categories yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Name</th><th>Sort order</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td>—</td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category? Existing rules keep their label.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="cat_delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn--ghost" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showImp): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Import rules from CSV</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>
        <p class="muted">
            Required columns: <code>title</code>, <code>body</code>.
            Optional: <code>rule_number</code>, <code>category</code>, <code>source</code>, <code>effective_date</code>.<br>
            <strong>source</strong> must be one of <code>bylaw</code>, <code>board_rule</code>, or <code>policy</code> (defaults to <code>board_rule</code> if blank).<br>
            <strong>effective_date</strong> must be in <code>YYYY-MM-DD</code> format.<br>
            Categories that don't exist yet are auto-created.
        </p>
        <p>
            <a class="btn btn--ghost" href="?download=template" download>⬇ Download template CSV</a>
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">title,rule_number,category,source,effective_date,body
"No grilling on balconies","4.2.1","Common areas","board_rule","2025-01-15","Per fire-code regulations, gas and charcoal grills are prohibited on all unit balconies and patios."
"Pet weight limit","2.1","Pets","bylaw","2020-06-01","Pets must not exceed 50 lbs at maturity. Documentation required at move-in."</pre>

        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import">
            <div class="field">
                <label class="field__label" for="csv">CSV file (max 2 MB)</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Import</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$showCats && !$showImp): /* show search bar + results unless on a sub-view */ ?>
    <div data-live-search data-endpoint="/dashboard/search.php">
        <form class="search-bar" method="get">
            <span aria-hidden="true">🔎</span>
            <input type="search" name="q" placeholder="Search by keyword: pets, balconies, renovations, …" value="<?= e($q) ?>">
            <select class="select" name="source" style="max-width: 180px;">
                <option value="">Any source</option>
                <option value="bylaw"      <?= $source==='bylaw'?'selected':'' ?>>Bylaws</option>
                <option value="board_rule" <?= $source==='board_rule'?'selected':'' ?>>Board rules</option>
                <option value="policy"     <?= $source==='policy'?'selected':'' ?>>Policies</option>
            </select>
            <button class="btn btn--primary" type="submit">Search</button>
        </form>

        <div class="search-results" data-results>
            <?php if ($q === '' && !$results): ?>
                <div class="card card--padded muted center">No rules on file yet.<?= $canManage ? ' <a href="?action=new">Add the first one</a>.' : '' ?></div>
            <?php elseif ($q === ''): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-4);">Showing the most recent <?= count($results) ?> rule<?= count($results)===1?'':'s' ?>.</p>
                <?php foreach ($results as $r): ?>
                <div class="search-result">
                    <div class="row row--between" style="margin-bottom: var(--sp-2);">
                        <div class="row" style="gap: var(--sp-2);">
                            <span class="badge badge--<?= $r['source']==='bylaw'?'navy':($r['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ',$r['source'])) ?></span>
                            <?php if ($r['rule_number']): ?><span class="muted" style="font-size: var(--fs-xs);">#<?= e($r['rule_number']) ?></span><?php endif; ?>
                            <?php if ($r['category']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; <?= e($r['category']) ?></span><?php endif; ?>
                            <?php if ($r['effective_date']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; in effect <?= e(date('M j, Y', strtotime((string)$r['effective_date']))) ?></span><?php endif; ?>
                        </div>
                        <?php if ($canManage): ?>
                        <div class="row" style="gap: var(--sp-2);">
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this rule?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" type="submit">Delete</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <strong><?= e($r['title']) ?></strong>
                    <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);"><?= e(mb_strimwidth(strip_tags($r['body']), 0, 240, '…')) ?></p>
                </div>
                <?php endforeach; ?>
            <?php elseif (!$results): ?>
                <div class="card card--padded muted center">No matches for &ldquo;<?= e($q) ?>&rdquo;.</div>
            <?php else: ?>
                <?php foreach ($results as $r):
                    $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
                    $highlighted = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', e($excerpt));
                ?>
                <div class="search-result">
                    <div class="row row--between" style="margin-bottom: var(--sp-2);">
                        <div class="row" style="gap: var(--sp-2);">
                            <span class="badge badge--<?= $r['source']==='bylaw'?'navy':($r['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ',$r['source'])) ?></span>
                            <?php if ($r['rule_number']): ?><span class="muted" style="font-size: var(--fs-xs);">#<?= e($r['rule_number']) ?></span><?php endif; ?>
                            <?php if ($r['category']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; <?= e($r['category']) ?></span><?php endif; ?>
                            <?php if ($r['effective_date']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; in effect <?= e(date('M j, Y', strtotime((string)$r['effective_date']))) ?></span><?php endif; ?>
                        </div>
                        <?php if ($canManage): ?>
                        <div class="row" style="gap: var(--sp-2);">
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this rule?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" type="submit">Delete</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <strong><?= e($r['title']) ?></strong>
                    <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);"><?= $highlighted ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

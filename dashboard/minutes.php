<?php
// Board meeting minutes — all members can read; board + property manager can create/edit.
// List (chronological) → detail → new/edit form with Quill WYSIWYG body.
require __DIR__ . '/_bootstrap.php';
require_login();

// Board admins can restrict minutes reading via the Permissions page.
if (!can_do('read_minutes') && !role_can_manage(viewing_role())) {
    $page_title = 'Meeting minutes';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="padding: var(--sp-8) var(--sp-6);"><div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);"><p class="muted">Meeting minutes are not available for your account type. Contact the board for access.</p></div></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;

$MEETING_TYPES = [
    'regular'   => 'Regular Meeting',
    'special'   => 'Special Meeting',
    'annual'    => 'Annual Meeting',
    'executive' => 'Executive Session',
];

// --- Save new / edit --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['form'] ?? ''), ['add','edit'], true)) {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $isEdit      = $_POST['form'] === 'edit';
    $mid         = (int)($_POST['id'] ?? 0);
    $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
    $mtype       = $_POST['meeting_type'] ?? 'regular';
    $title       = trim((string)($_POST['title'] ?? ''));
    $bodyHtml    = trim((string)($_POST['body_html'] ?? ''));
    $attendees   = trim((string)($_POST['attendees'] ?? ''));

    if (!array_key_exists($mtype, $MEETING_TYPES)) $mtype = 'regular';
    if ($meetingDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        $flashError = 'Meeting date is required.';
    } elseif ($title === '') {
        $flashError = 'Title is required.';
    } else {
        if ($isEdit) {
            $chk = db()->prepare('SELECT 1 FROM meeting_minutes WHERE id = ? AND association_id = ?');
            $chk->execute([$mid, $assocId]);
            if (!$chk->fetchColumn()) { http_response_code(404); die('Not found'); }
            db()->prepare(
                'UPDATE meeting_minutes
                    SET meeting_date = ?, meeting_type = ?, title = ?, body_html = ?, attendees = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([$meetingDate, $mtype, $title, $bodyHtml, $attendees ?: null, $mid, $assocId]);
            audit('minutes.edited', ['title' => $title], $mid, 'meeting_minutes');
            flash('success', 'Minutes updated.');
            redirect('/dashboard/minutes.php?id=' . $mid);
        } else {
            db()->prepare(
                'INSERT INTO meeting_minutes (association_id, meeting_date, meeting_type, title, body_html, attendees, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$assocId, $meetingDate, $mtype, $title, $bodyHtml, $attendees ?: null, (int)$user['id']]);
            $newId = (int)db()->lastInsertId();
            audit('minutes.created', ['title' => $title, 'date' => $meetingDate], $newId, 'meeting_minutes');
            flash('success', 'Minutes saved.');
            redirect('/dashboard/minutes.php?id=' . $newId);
        }
    }
}

// --- Delete -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $mid = (int)($_POST['id'] ?? 0);
    $row = db()->prepare('SELECT title FROM meeting_minutes WHERE id = ? AND association_id = ?');
    $row->execute([$mid, $assocId]);
    if ($r = $row->fetch()) {
        db()->prepare('DELETE FROM meeting_minutes WHERE id = ? AND association_id = ?')->execute([$mid, $assocId]);
        audit('minutes.deleted', ['title' => $r['title']], $mid, 'meeting_minutes');
        flash('success', "Minutes \"{$r['title']}\" deleted.");
    }
    redirect('/dashboard/minutes.php');
}

// --- View routing -----------------------------------------------------------
$detailId = (int)($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';

$detail = null;
if ($detailId > 0) {
    $stmt = db()->prepare(
        "SELECT m.*,
                TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS author_name
           FROM meeting_minutes m
           LEFT JOIN users u ON u.id = m.created_by
          WHERE m.id = ? AND m.association_id = ?"
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;
}

$listing = [];
if (!$detail && $action === '') {
    $typeFilter = $_GET['type'] ?? '';
    $sql = 'SELECT * FROM meeting_minutes WHERE association_id = ?';
    $params = [$assocId];
    if (array_key_exists($typeFilter, $MEETING_TYPES)) { $sql .= ' AND meeting_type = ?'; $params[] = $typeFilter; }
    $sql .= ' ORDER BY meeting_date DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listing = $stmt->fetchAll();
}

$editRow = null;
if ($action === 'edit' && $detailId > 0 && $detail && $canManage) {
    $editRow = $detail;
}

$active     = 'minutes';
$page_title = 'Meeting minutes — ' . $association['name'];

// Quill CSS only needed on the new/edit form.
if ($action === 'new' || $editRow) {
    $page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
}

require __DIR__ . '/../includes/header.php';

function mtype_badge(string $t): string {
    return match ($t) {
        'annual'    => 'badge--success',
        'special'   => 'badge--warning',
        'executive' => 'badge--navy',
        default     => 'badge--info',
    };
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1000px;">

<?php if ($detail && !$editRow): ?>
    <!-- ===== DETAIL VIEW ===== -->
    <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/minutes.php">← Back to minutes</a>
            <h1 style="font-size: var(--fs-2xl); margin: var(--sp-2) 0 var(--sp-1);"><?= e((string)$detail['title']) ?></h1>
            <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                <span class="badge <?= mtype_badge((string)$detail['meeting_type']) ?>"><?= e($MEETING_TYPES[$detail['meeting_type']] ?? (string)$detail['meeting_type']) ?></span>
                <span class="muted" style="font-size: var(--fs-sm);"><?= e(udate('l, F j, Y', strtotime((string)$detail['meeting_date']))) ?></span>
                <?php if (!empty($detail['author_name'])): ?>
                    <span class="muted" style="font-size: var(--fs-sm);">· recorded by <?= e(trim((string)$detail['author_name'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canManage): ?>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= (int)$detail['id'] ?>&action=edit">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete these minutes? This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete">
                <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Delete</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($detail['attendees'])): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid var(--color-info);">
        <strong>In attendance:</strong>
        <p style="margin: var(--sp-1) 0 0; white-space: pre-wrap;"><?= e((string)$detail['attendees']) ?></p>
    </div>
    <?php endif; ?>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <?php if (!empty($detail['body_html'])): ?>
            <div class="prose"><?= (string)$detail['body_html'] /* board-trusted HTML from Quill */ ?></div>
        <?php else: ?>
            <p class="muted">No body recorded.</p>
        <?php endif; ?>
    </div>

<?php elseif ($action === 'new' || $editRow): ?>
    <!-- ===== NEW / EDIT FORM ===== -->
    <?php if (!$canManage) { http_response_code(403); die('Forbidden'); } ?>
    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <h1 style="font-size: var(--fs-2xl); margin: 0;"><?= $editRow ? 'Edit minutes' : 'Record meeting minutes' ?></h1>
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/minutes.php<?= $editRow ? '?id=' . (int)$editRow['id'] : '' ?>">← Back</a>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <form method="post" class="form card card--padded" id="minutes-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="<?= $editRow ? 'edit' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

        <div class="form-row form-row--3">
            <div class="field">
                <label class="field__label" for="md">Meeting date</label>
                <input class="input" type="date" id="md" name="meeting_date" required
                       value="<?= e((string)($editRow['meeting_date'] ?? date('Y-m-d'))) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="mt">Meeting type</label>
                <select class="select" id="mt" name="meeting_type">
                    <?php foreach ($MEETING_TYPES as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= ($editRow['meeting_type'] ?? 'regular') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><!-- spacer --></div>
        </div>

        <div class="field">
            <label class="field__label" for="mtitle">Title</label>
            <input class="input" id="mtitle" name="title" required maxlength="255"
                   placeholder="e.g. May Board Meeting — Quorum Reached"
                   value="<?= e((string)($editRow['title'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="field__label" for="matt">In attendance <span class="muted" style="font-weight:400;">(optional — one name per line or comma-separated)</span></label>
            <textarea class="textarea" id="matt" name="attendees" rows="3"
                      placeholder="Kevin Leigh (President), Wayne Dictor (Treasurer)&#10;Mark Applegate (Property Manager)…"><?= e((string)($editRow['attendees'] ?? '')) ?></textarea>
        </div>

        <div class="field">
            <label class="field__label">Minutes body</label>
            <div id="minutes-editor"
                 data-initial-html="<?= e((string)($editRow['body_html'] ?? '')) ?>"
                 style="min-height: 320px; border: 1px solid var(--color-border); border-radius: var(--r-md); background: #fff;"></div>
            <input type="hidden" name="body_html" id="minutes-body-source">
            <div class="field__hint">Use headings for sections like "Called to order", "Old business", "Motions", "Adjournment".</div>
        </div>

        <div class="row" style="justify-content: flex-end; gap: var(--sp-2);">
            <a class="btn btn--ghost" href="/dashboard/minutes.php<?= $editRow ? '?id=' . (int)$editRow['id'] : '' ?>">Cancel</a>
            <button class="btn btn--primary" type="submit"><?= $editRow ? 'Save changes' : 'Save minutes' ?></button>
        </div>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
    (function () {
        if (typeof Quill === 'undefined') return;
        var el     = document.getElementById('minutes-editor');
        var hidden = document.getElementById('minutes-body-source');
        var form   = document.getElementById('minutes-form');
        if (!el || !hidden || !form) return;
        var initial = el.getAttribute('data-initial-html') || '';
        var quill = new Quill('#minutes-editor', {
            theme: 'snow',
            placeholder: 'Start typing the minutes…',
            modules: {
                toolbar: [
                    [{ 'header': [2, 3, false] }],
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['link'],
                    ['clean']
                ]
            }
        });
        if (initial) quill.clipboard.dangerouslyPasteHTML(0, initial);
        form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
    })();
    </script>

<?php else: ?>
    <!-- ===== LIST VIEW ===== -->
    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Meeting minutes</h1>
            <p class="muted">Official record of board meetings.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ Record minutes</a>
        <?php endif; ?>
    </div>

    <?php
    $typeFilter = $_GET['type'] ?? '';
    ?>
    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
        <span class="muted" style="font-size: var(--fs-xs); align-self:center;">Filter:</span>
        <?php foreach ($MEETING_TYPES as $val => $lbl): ?>
            <a class="badge <?= $typeFilter===$val ? mtype_badge($val) : '' ?>"
               href="?type=<?= e($val) ?>" style="text-decoration:none; <?= $typeFilter!==$val?'opacity:0.6;':'' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
        <?php if ($typeFilter): ?>
            <a class="muted" href="/dashboard/minutes.php" style="font-size: var(--fs-xs); align-self:center;">clear</a>
        <?php endif; ?>
    </div>

    <?php if (!$listing): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No meeting minutes recorded yet.</p>
            <?php if ($canManage): ?>
                <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Record the first meeting</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="stack-md">
        <?php foreach ($listing as $row): ?>
            <a class="card card--padded" href="?id=<?= (int)$row['id'] ?>" style="display:block; text-decoration:none; color:inherit;">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap; align-items: center;">
                    <span class="badge <?= mtype_badge((string)$row['meeting_type']) ?>"><?= e($MEETING_TYPES[$row['meeting_type']] ?? (string)$row['meeting_type']) ?></span>
                    <strong style="font-size: var(--fs-lg);"><?= e((string)$row['title']) ?></strong>
                </div>
                <div class="muted" style="font-size: var(--fs-sm);">
                    <?= e(udate('l, F j, Y', strtotime((string)$row['meeting_date']))) ?>
                    <?php if (!empty($row['attendees'])): ?>
                        · <?= e(mb_strimwidth((string)$row['attendees'], 0, 80, '…')) ?>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

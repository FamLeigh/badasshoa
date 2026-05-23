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

    // Validate and store checked board member IDs — verify they belong to this association.
    $rawIds = array_filter(array_map('intval', (array)($_POST['attendee_ids'] ?? [])));
    $attendeeUserIds = '';
    if ($rawIds) {
        $ph    = implode(',', array_fill(0, count($rawIds), '?'));
        $vStmt = db()->prepare("SELECT id FROM users WHERE id IN ($ph) AND association_id = ?");
        $vStmt->execute(array_merge(array_values($rawIds), [$assocId]));
        $validIds = $vStmt->fetchAll(PDO::FETCH_COLUMN);
        // Preserve the submitted order so display matches the checkbox order.
        $ordered = array_filter($rawIds, fn($id) => in_array($id, $validIds, false));
        $attendeeUserIds = implode(',', $ordered);
    }

    // Sign-in sheet upload (optional; images + PDF only).
    $signinPath = null;
    $signinType = null;
    $signinError = null;
    try {
        $uploaded = save_attachment($assocId, 'minutes/signin', 'signin_sheet');
        if ($uploaded) {
            $signinPath = $uploaded['file_path'];
            $signinType = $uploaded['file_type'];
        }
    } catch (RuntimeException $e) {
        $signinError = $e->getMessage();
    }

    if (!array_key_exists($mtype, $MEETING_TYPES)) $mtype = 'regular';
    if ($meetingDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        $flashError = 'Meeting date is required.';
    } elseif ($title === '') {
        $flashError = 'Title is required.';
    } elseif ($signinError) {
        $flashError = 'Sign-in sheet: ' . $signinError;
    } else {
        if ($isEdit) {
            $chk = db()->prepare('SELECT signin_sheet_path FROM meeting_minutes WHERE id = ? AND association_id = ?');
            $chk->execute([$mid, $assocId]);
            $existing = $chk->fetch();
            if (!$existing) { http_response_code(404); die('Not found'); }

            // Determine the final sheet path: new upload > explicit removal > keep existing.
            $removeSheet = !empty($_POST['remove_signin_sheet']);
            if ($signinPath) {
                // New file uploaded — delete the old one if present.
                if (!empty($existing['signin_sheet_path'])) {
                    @unlink(storage_path($existing['signin_sheet_path']));
                }
                $finalPath = $signinPath;
                $finalType = $signinType;
            } elseif ($removeSheet) {
                if (!empty($existing['signin_sheet_path'])) {
                    @unlink(storage_path($existing['signin_sheet_path']));
                }
                $finalPath = null;
                $finalType = null;
            } else {
                $finalPath = $existing['signin_sheet_path'] ?: null;
                $finalType = null; // unchanged — leave DB value alone via COALESCE in query
            }

            db()->prepare(
                'UPDATE meeting_minutes
                    SET meeting_date = ?, meeting_type = ?, title = ?, body_html = ?,
                        attendees = ?, attendee_user_ids = ?,
                        signin_sheet_path = ?, signin_sheet_type = COALESCE(?, signin_sheet_type)
                  WHERE id = ? AND association_id = ?'
            )->execute([
                $meetingDate, $mtype, $title, $bodyHtml,
                $attendees ?: null, $attendeeUserIds ?: null,
                $finalPath, $signinType,
                $mid, $assocId,
            ]);
            audit('minutes.edited', ['title' => $title], $mid, 'meeting_minutes');
            flash('success', 'Minutes updated.');
            redirect('/dashboard/minutes.php?id=' . $mid);
        } else {
            db()->prepare(
                'INSERT INTO meeting_minutes
                    (association_id, meeting_date, meeting_type, title, body_html,
                     attendees, attendee_user_ids, signin_sheet_path, signin_sheet_type, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $assocId, $meetingDate, $mtype, $title, $bodyHtml,
                $attendees ?: null, $attendeeUserIds ?: null,
                $signinPath, $signinType, (int)$user['id'],
            ]);
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

// Board members for the attendance picker (always load; used on form + detail view).
$boardMembersStmt = db()->prepare(
    "SELECT id, first_name, last_name, board_office, role FROM users
      WHERE association_id = ? AND role IN ('board_admin','board_member','property_manager') AND status <> 'inactive'
      ORDER BY FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director') = 0,
               FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director'),
               last_name, first_name"
);
$boardMembersStmt->execute([$assocId]);
$boardMembersForPicker = $boardMembersStmt->fetchAll();

// Resolve stored attendee user IDs → user rows for the detail view.
$resolvedAttendees = [];
if ($detail && !empty($detail['attendee_user_ids'])) {
    $storedIds = array_filter(array_map('intval', explode(',', (string)$detail['attendee_user_ids'])));
    if ($storedIds) {
        $ph    = implode(',', array_fill(0, count($storedIds), '?'));
        $uStmt = db()->prepare("SELECT id, first_name, last_name, board_office FROM users WHERE id IN ($ph) AND association_id = ?");
        $uStmt->execute(array_merge(array_values($storedIds), [$assocId]));
        $uMap  = [];
        foreach ($uStmt->fetchAll() as $u) { $uMap[(int)$u['id']] = $u; }
        foreach ($storedIds as $uid) {
            if (isset($uMap[$uid])) $resolvedAttendees[] = $uMap[$uid];
        }
    }
}

// Pre-selected IDs when editing.
$checkedAttendeeIds = [];
if ($editRow && !empty($editRow['attendee_user_ids'])) {
    $checkedAttendeeIds = array_filter(array_map('intval', explode(',', (string)$editRow['attendee_user_ids'])));
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

    <?php if ($resolvedAttendees || !empty($detail['attendees'])): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid var(--color-info);">
        <strong>In attendance</strong>
        <?php if ($resolvedAttendees): ?>
        <div style="display: flex; flex-wrap: wrap; gap: var(--sp-2); margin-top: var(--sp-2);">
            <?php foreach ($resolvedAttendees as $ra): ?>
            <?php
                $raName   = e(trim($ra['first_name'] . ' ' . $ra['last_name']));
                $raOffice = board_office_label($ra['board_office'] ?? null);
            ?>
            <span style="display: inline-flex; align-items: center; gap: var(--sp-1); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--r-sm); padding: 0.2rem 0.6rem; font-size: var(--fs-sm);">
                <?= $raName ?>
                <?php if ($raOffice): ?>
                    <span class="muted" style="font-size: var(--fs-xs);"><?= e($raOffice) ?></span>
                <?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($detail['attendees'])): ?>
        <p style="margin: var(--sp-2) 0 0; white-space: pre-wrap; font-size: var(--fs-sm); color: var(--color-text-muted);"><?= e((string)$detail['attendees']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canManage && !empty($detail['signin_sheet_path'])): ?>
    <div style="margin-bottom: var(--sp-4);">
        <a href="/dashboard/file.php?type=minutes_signin&id=<?= (int)$detail['id'] ?>"
           target="_blank" rel="noopener"
           class="btn btn--ghost" style="font-size: var(--fs-sm);">
            📄 View sign-in sheet
        </a>
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

    <form method="post" enctype="multipart/form-data" class="form card card--padded" id="minutes-form">
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
            <label class="field__label">Board &amp; management in attendance</label>
            <?php if ($boardMembersForPicker): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--sp-2); border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3); background: var(--color-bg);">
                <?php foreach ($boardMembersForPicker as $bm): ?>
                <?php
                    $bmId      = (int)$bm['id'];
                    $bmName    = trim($bm['first_name'] . ' ' . $bm['last_name']);
                    $bmOffice  = board_office_label($bm['board_office'] ?? null) ?: role_label((string)$bm['role']);
                    $bmChecked = in_array($bmId, $checkedAttendeeIds, true);
                ?>
                <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer; padding: var(--sp-1) 0;">
                    <input type="checkbox" name="attendee_ids[]" value="<?= $bmId ?>"
                           <?= $bmChecked ? 'checked' : '' ?>
                           style="width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--color-primary);">
                    <span style="line-height: 1.3;">
                        <?= e($bmName) ?>
                        <?php if ($bmOffice): ?>
                            <span class="muted" style="font-size: var(--fs-xs); display: block;"><?= e($bmOffice) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="muted" style="font-size: var(--fs-sm);">No board members found for this association.</p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="matt">Additional attendees <span class="muted" style="font-weight:400;">(guests, residents, attorneys, etc. — optional)</span></label>
            <textarea class="textarea" id="matt" name="attendees" rows="2"
                      placeholder="Mark Applegate (Property Manager), Jane Smith (HOA attorney)…"><?= e((string)($editRow['attendees'] ?? '')) ?></textarea>
        </div>

        <div class="field">
            <label class="field__label" for="signin-sheet">Sign-in sheet <span class="muted" style="font-weight:400;">(PDF or image — board access only)</span></label>
            <?php if ($editRow && !empty($editRow['signin_sheet_path'])): ?>
            <div style="display: flex; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-2); padding: var(--sp-2) var(--sp-3); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--r-sm); font-size: var(--fs-sm);">
                <span>📄</span>
                <a href="/dashboard/file.php?type=minutes_signin&id=<?= (int)$editRow['id'] ?>" target="_blank" rel="noopener" style="color: var(--color-primary);">Current sign-in sheet</a>
                <label style="margin-left: auto; display: flex; align-items: center; gap: var(--sp-1); cursor: pointer; color: var(--color-error); font-size: var(--fs-xs);">
                    <input type="checkbox" name="remove_signin_sheet" value="1" style="accent-color: var(--color-error);"> Remove
                </label>
            </div>
            <div class="field__hint" style="margin-bottom: var(--sp-1);">Upload a new file to replace it, or check "Remove" to delete it.</div>
            <?php endif; ?>
            <input class="input" type="file" id="signin-sheet" name="signin_sheet"
                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,image/*,application/pdf"
                   style="padding: var(--sp-1) var(--sp-2);">
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
                    <?php
                    $listBoardCount = !empty($row['attendee_user_ids'])
                        ? count(array_filter(explode(',', (string)$row['attendee_user_ids'])))
                        : 0;
                    ?>
                    <?php if ($listBoardCount): ?>
                        · <?= $listBoardCount ?> board member<?= $listBoardCount !== 1 ? 's' : '' ?> in attendance
                        <?php if (!empty($row['attendees'])): ?>
                            + guests
                        <?php endif; ?>
                    <?php elseif (!empty($row['attendees'])): ?>
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

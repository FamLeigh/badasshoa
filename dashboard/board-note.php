<?php
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();

$validTypes = ['general','call','email','text','in_person'];
$typeLabels = [
    'general'   => ['label' => 'General',   'emoji' => '📋', 'color' => '#6b7280'],
    'call'      => ['label' => 'Call',       'emoji' => '📞', 'color' => '#2563eb'],
    'email'     => ['label' => 'Email',      'emoji' => '📧', 'color' => '#7c3aed'],
    'text'      => ['label' => 'Text',       'emoji' => '💬', 'color' => '#059669'],
    'in_person' => ['label' => 'In person',  'emoji' => '🤝', 'color' => '#d97706'],
];

// unit_id optional
$unitId = (int)($_GET['unit_id'] ?? 0);
$unit   = null;
if ($unitId) {
    $stmt = db()->prepare('SELECT id, unit_number FROM units WHERE id = ? AND association_id = ?');
    $stmt->execute([$unitId, $assocId]);
    $unit = $stmt->fetch() ?: null;
    if (!$unit) $unitId = 0;
}

// subject user optional
$subjectUserId = (int)($_GET['user_id'] ?? 0);
$subjectUser   = null;
if ($subjectUserId) {
    $su = db()->prepare('SELECT id, first_name, last_name FROM users WHERE id = ? AND association_id = ?');
    $su->execute([$subjectUserId, $assocId]);
    $subjectUser = $su->fetch() ?: null;
    if (!$subjectUser) $subjectUserId = 0;
}

if (!$unitId && !$subjectUserId) redirect('/dashboard/directory.php');

$selfUrl  = '/dashboard/board-note.php?' . http_build_query(array_filter([
    'unit_id' => $unitId ?: null,
    'user_id' => $subjectUserId ?: null,
]));
$backUrl   = $unit ? '/dashboard/unit.php?id=' . $unitId : '/dashboard/directory.php';
$backLabel = $unit ? '← Unit ' . $unit['unit_number'] : '← Directory';

// --- POST: delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_board_note') {
    csrf_check();
    $nid = (int)($_POST['note_id'] ?? 0);
    $row = db()->prepare('SELECT id FROM board_notes WHERE id = ? AND association_id = ?');
    $row->execute([$nid, $assocId]);
    if ($row->fetchColumn()) {
        db()->prepare('DELETE FROM board_notes WHERE id = ?')->execute([$nid]);
        audit('board_note.deleted', ['unit_id' => $unitId, 'subject_user_id' => $subjectUserId ?: null], $nid, 'board_note');
        flash('success', 'Note deleted.');
    }
    redirect($selfUrl);
}

// --- POST: edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_board_note') {
    csrf_check();
    $nid      = (int)($_POST['note_id'] ?? 0);
    $noteText = trim((string)($_POST['note_text'] ?? ''));
    $noteType = $_POST['note_type'] ?? 'general';
    $noteDate = trim((string)($_POST['note_date'] ?? ''));
    if (!in_array($noteType, $validTypes, true)) $noteType = 'general';
    if ($noteDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $noteDate)) $noteDate = '';

    if ($noteText === '') {
        flash('error', 'Note text is required.');
        redirect($selfUrl . '&edit_note=' . $nid);
    }
    $row = db()->prepare('SELECT id FROM board_notes WHERE id = ? AND association_id = ?');
    $row->execute([$nid, $assocId]);
    if ($row->fetchColumn()) {
        db()->prepare(
            'UPDATE board_notes SET note_text = ?, note_type = ?, note_date = ? WHERE id = ? AND association_id = ?'
        )->execute([$noteText, $noteType, $noteDate ?: null, $nid, $assocId]);
        audit('board_note.edited', ['note_id' => $nid], $nid, 'board_note');
        flash('success', 'Note updated.');
    }
    redirect($selfUrl);
}

// --- POST: add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_board_note') {
    csrf_check();
    $noteText = trim((string)($_POST['note_text'] ?? ''));
    $noteType = $_POST['note_type'] ?? 'general';
    $noteDate = trim((string)($_POST['note_date'] ?? ''));
    if (!in_array($noteType, $validTypes, true)) $noteType = 'general';
    if ($noteDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $noteDate)) $noteDate = '';

    if ($noteText === '') {
        flash('error', 'Note text is required.');
        redirect($selfUrl);
    }
    db()->prepare(
        'INSERT INTO board_notes (association_id, author_user_id, unit_id, subject_user_id, note_text, note_type, note_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$assocId, (int)$user['id'], $unitId ?: null, $subjectUserId ?: null, $noteText, $noteType, $noteDate ?: null]);
    audit('board_note.added', ['unit_id' => $unitId, 'subject_user_id' => $subjectUserId ?: null], (int)db()->lastInsertId(), 'board_note');
    flash('success', 'Note saved.');
    redirect($selfUrl);
}

// --- Load notes ---
if ($subjectUserId) {
    $nWhere  = 'n.association_id = ? AND n.subject_user_id = ?';
    $nParams = [$assocId, $subjectUserId];
} else {
    $nWhere  = 'n.association_id = ? AND n.unit_id = ? AND n.subject_user_id IS NULL';
    $nParams = [$assocId, $unitId];
}
$notesStmt = db()->prepare(
    "SELECT n.*, u.first_name AS author_first, u.last_name AS author_last
       FROM board_notes n
       JOIN users u ON u.id = n.author_user_id
      WHERE $nWhere
      ORDER BY COALESCE(n.note_date, DATE(n.created_at)) DESC, n.created_at DESC"
);
$notesStmt->execute($nParams);
$notes = $notesStmt->fetchAll();

$editNoteId = (int)($_GET['edit_note'] ?? 0);

// Heading
if ($subjectUser) {
    $subjectName = trim((string)$subjectUser['first_name'] . ' ' . (string)$subjectUser['last_name']);
    $heading     = $subjectName . ($unit ? ' · Unit ' . $unit['unit_number'] : '');
} else {
    $heading = 'Unit ' . ($unit['unit_number'] ?? '');
}

$page_title = 'Notes: ' . strip_tags($heading) . ' — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 760px; padding: var(--sp-6) var(--sp-4);">

    <a href="<?= e($backUrl) ?>"
       style="display:inline-flex; align-items:center; gap: var(--sp-1); font-size: var(--fs-sm); color: var(--color-text-soft); text-decoration:none; margin-bottom: var(--sp-5);">
        <?= e($backLabel) ?>
    </a>

    <div class="row row--between" style="align-items:baseline; margin-bottom: var(--sp-6); flex-wrap:wrap; gap: var(--sp-2);">
        <h1 style="font-size: var(--fs-2xl); margin:0;">📋 <?= e($heading) ?></h1>
        <span class="muted" style="font-size: var(--fs-sm);">Board only</span>
    </div>

    <!-- Add form -->
    <form method="post" class="card card--padded" style="margin-bottom: var(--sp-6);">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add_board_note">
        <div class="row" style="gap: var(--sp-3); margin-bottom: var(--sp-3); flex-wrap:wrap;">
            <div style="flex:0 0 160px;">
                <label class="form-label" for="note_type_add" style="font-size:var(--fs-xs); font-weight:600; display:block; margin-bottom:4px;">Type</label>
                <select id="note_type_add" name="note_type" class="form-input" style="font-size:var(--fs-sm);">
                    <?php foreach ($typeLabels as $val => $info): ?>
                        <option value="<?= $val ?>"><?= $info['emoji'] ?> <?= $info['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:0 0 180px;">
                <label class="form-label" for="note_date_add" style="font-size:var(--fs-xs); font-weight:600; display:block; margin-bottom:4px;">Date</label>
                <input type="date" id="note_date_add" name="note_date" class="form-input"
                       value="<?= date('Y-m-d') ?>"
                       style="font-size:var(--fs-sm);">
            </div>
        </div>
        <label class="form-label" for="note_text_add" style="font-size:var(--fs-xs); font-weight:600; display:block; margin-bottom:4px;">Note</label>
        <textarea
            id="note_text_add"
            name="note_text"
            class="form-input"
            rows="5"
            autofocus
            placeholder="Enter your note…"
            style="width:100%; resize:vertical; font-size:var(--fs-base); line-height:1.6; box-sizing:border-box;"
        ></textarea>
        <div class="row" style="gap: var(--sp-2); margin-top: var(--sp-3);">
            <button type="submit" class="btn btn--primary">Save note</button>
            <a class="btn btn--ghost" href="<?= e($backUrl) ?>">Cancel</a>
        </div>
    </form>

    <!-- Existing notes -->
    <?php if ($notes): ?>
    <div style="display:flex; flex-direction:column; gap: var(--sp-3);">
        <?php foreach ($notes as $n):
            $nid    = (int)$n['id'];
            $author = trim((string)$n['author_first'] . ' ' . (string)$n['author_last']) ?: 'Staff';
            $tinfo  = $typeLabels[$n['note_type']] ?? $typeLabels['general'];
            $dispDate = $n['note_date']
                ? udate('M j, Y', strtotime((string)$n['note_date']))
                : udate('M j, Y', strtotime((string)$n['created_at']));
        ?>
        <div style="background:var(--color-surface-2); border-radius:var(--r-md); padding:var(--sp-3) var(--sp-4);">
            <?php if ($editNoteId === $nid): ?>
            <!-- Edit form -->
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="edit_board_note">
                <input type="hidden" name="note_id" value="<?= $nid ?>">
                <div class="row" style="gap:var(--sp-3); margin-bottom:var(--sp-3); flex-wrap:wrap;">
                    <div style="flex:0 0 160px;">
                        <label class="form-label" style="font-size:var(--fs-xs); font-weight:600; display:block; margin-bottom:4px;">Type</label>
                        <select name="note_type" class="form-input" style="font-size:var(--fs-sm);">
                            <?php foreach ($typeLabels as $val => $info): ?>
                                <option value="<?= $val ?>" <?= $n['note_type'] === $val ? 'selected' : '' ?>><?= $info['emoji'] ?> <?= $info['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:0 0 180px;">
                        <label class="form-label" style="font-size:var(--fs-xs); font-weight:600; display:block; margin-bottom:4px;">Date</label>
                        <input type="date" name="note_date" class="form-input"
                               value="<?= e($n['note_date'] ?? date('Y-m-d', strtotime((string)$n['created_at']))) ?>"
                               style="font-size:var(--fs-sm);">
                    </div>
                </div>
                <textarea name="note_text" class="form-input" rows="4"
                    style="width:100%; resize:vertical; font-size:var(--fs-sm); line-height:1.6; box-sizing:border-box; margin-bottom:var(--sp-3);"
                    ><?= e((string)$n['note_text']) ?></textarea>
                <div class="row" style="gap:var(--sp-2);">
                    <button type="submit" class="btn btn--primary" style="font-size:var(--fs-sm); padding:0.35rem 0.9rem;">Save</button>
                    <a class="btn btn--ghost" href="<?= e($selfUrl) ?>" style="font-size:var(--fs-sm); padding:0.35rem 0.9rem;">Cancel</a>
                </div>
            </form>
            <?php else: ?>
            <!-- Display -->
            <div class="row row--between" style="align-items:flex-start; gap:var(--sp-2);">
                <div style="display:flex; align-items:center; gap:var(--sp-2); flex-wrap:wrap;">
                    <span style="font-size:var(--fs-xs); font-weight:600; color:<?= $tinfo['color'] ?>; background:<?= $tinfo['color'] ?>18; border-radius:4px; padding:1px 7px; border:1px solid <?= $tinfo['color'] ?>44;">
                        <?= $tinfo['emoji'] ?> <?= $tinfo['label'] ?>
                    </span>
                    <span style="font-size:var(--fs-xs); color:var(--color-text-soft);"><?= e($dispDate) ?> &middot; <?= e($author) ?></span>
                </div>
                <div style="display:flex; gap:var(--sp-1); flex-shrink:0;">
                    <a href="<?= e($selfUrl) ?>&edit_note=<?= $nid ?>"
                       style="font-size:var(--fs-xs); color:var(--color-text-soft); text-decoration:none; padding:2px 7px; border-radius:4px; border:1px solid var(--color-border);">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this note?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete_board_note">
                        <input type="hidden" name="note_id" value="<?= $nid ?>">
                        <button type="submit" style="background:none; border:1px solid var(--color-border); border-radius:4px; cursor:pointer; color:var(--color-text-soft); font-size:var(--fs-xs); padding:2px 7px; line-height:1.4;">✕</button>
                    </form>
                </div>
            </div>
            <div style="white-space:pre-wrap; font-size:var(--fs-sm); line-height:1.55; margin-top:var(--sp-2);"><?= e((string)$n['note_text']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="muted" style="font-size:var(--fs-sm);">No notes yet.</p>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

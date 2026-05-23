<?php
// Board meeting planner — list and create meetings.
// board_member can view and propose agenda items; board_admin / property_manager can fully manage.
require __DIR__ . '/_bootstrap.php';
require_login();

$role = viewing_role();
$canView   = in_array($role, ['board_admin','board_member','property_manager','super_admin'], true);
$canManage = in_array($role, ['board_admin','property_manager','super_admin'], true);

if (!$canView) {
    $page_title = 'Board Meetings';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="padding:var(--sp-8) var(--sp-6);"><div class="card card--padded center" style="padding:var(--sp-12) var(--sp-6);"><p class="muted">Board meeting planning is only available to board members and managers.</p></div></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$user = current_user();

$MEETING_TYPES = [
    'regular'   => 'Regular',
    'special'   => 'Special',
    'annual'    => 'Annual',
    'executive' => 'Executive Session',
];

$STATUS_LABELS = [
    'draft'          => 'Draft',
    'notice_posted'  => 'Notice Posted',
    'completed'      => 'Completed',
    'cancelled'      => 'Cancelled',
];

$STATUS_BADGE = [
    'draft'         => '',
    'notice_posted' => 'badge--info',
    'completed'     => 'badge--success',
    'cancelled'     => 'badge--warning',
];

// --- Create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $mtype = array_key_exists($_POST['meeting_type'] ?? '', $MEETING_TYPES) ? $_POST['meeting_type'] : 'regular';
    $mdate = trim((string)($_POST['meeting_date'] ?? ''));
    $mtime = trim((string)($_POST['meeting_time'] ?? '')) ?: null;
    $title = trim((string)($_POST['title'] ?? ''));
    $loc   = trim((string)($_POST['location'] ?? '')) ?: null;

    if ($mdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mdate)) {
        flash('error', 'Meeting date is required.');
        redirect('/dashboard/meetings.php?action=new');
    }
    if ($title === '') {
        $monthYear = date('F Y', strtotime($mdate));
        $title = $MEETING_TYPES[$mtype] . ' Board Meeting — ' . $monthYear;
    }

    db()->beginTransaction();
    db()->prepare(
        'INSERT INTO board_meetings
            (association_id, title, meeting_type, meeting_date, meeting_time, location, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$assocId, $title, $mtype, $mdate, $mtime, $loc, (int)$user['id']]);
    $mid = (int)db()->lastInsertId();

    // Auto-insert standard agenda items.
    $std = [
        [10,  'call_to_order',    'Call to order'],
        [20,  'proof_of_notice',  'Proof of Notice'],
        [30,  'certify_quorum',   'Certify a Quorum of Officers'],
        [40,  'approve_minutes',  'Approve Minutes from Last Meeting'],
        [990, 'motion_to_adjourn','Motion to Adjourn'],
        [999, 'public_comments',  'Public Comments / Open Forum'],
    ];
    $iStmt = db()->prepare(
        'INSERT INTO agenda_items
            (meeting_id, association_id, sort_order, category, title, status, entered_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($std as [$so, $cat, $ttl]) {
        $iStmt->execute([$mid, $assocId, $so, $cat, $ttl, 'approved', (int)$user['id']]);
    }
    db()->commit();

    audit('meeting.created', ['title' => $title, 'date' => $mdate], $mid, 'board_meetings');
    flash('success', 'Meeting created.');
    redirect('/dashboard/meeting-detail.php?id=' . $mid);
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $mid = (int)($_POST['id'] ?? 0);
    $row = db()->prepare('SELECT title, status FROM board_meetings WHERE id = ? AND association_id = ?');
    $row->execute([$mid, $assocId]);
    if ($r = $row->fetch()) {
        if ($r['status'] !== 'draft') {
            flash('error', 'Only draft meetings can be deleted.');
        } else {
            db()->prepare('DELETE FROM resolution_votes WHERE resolution_id IN (SELECT id FROM resolutions WHERE meeting_id = ?)')->execute([$mid]);
            db()->prepare('DELETE FROM resolutions WHERE meeting_id = ?')->execute([$mid]);
            db()->prepare('DELETE FROM agenda_items WHERE meeting_id = ?')->execute([$mid]);
            db()->prepare('DELETE FROM board_meetings WHERE id = ? AND association_id = ?')->execute([$mid, $assocId]);
            audit('meeting.deleted', ['title' => $r['title']], $mid, 'board_meetings');
            flash('success', "Meeting \"{$r['title']}\" deleted.");
        }
    }
    redirect('/dashboard/meetings.php');
}

// --- Listing ---
$tab = ($_GET['tab'] ?? '') === 'past' ? 'past' : 'upcoming';
$action = $_GET['action'] ?? '';

$sql = 'SELECT m.*,
               (SELECT COUNT(*) FROM agenda_items WHERE meeting_id = m.id AND status NOT IN (\'removed\')) AS item_count,
               (SELECT COUNT(*) FROM resolutions WHERE meeting_id = m.id) AS resolution_count
          FROM board_meetings m
         WHERE m.association_id = ?
           AND ';
$params = [$assocId];
if ($tab === 'past') {
    $sql .= 'm.meeting_date < CURDATE() ORDER BY m.meeting_date DESC LIMIT 100';
} else {
    $sql .= 'm.meeting_date >= CURDATE() ORDER BY m.meeting_date ASC LIMIT 100';
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$meetings = $stmt->fetchAll();

$active     = 'meetings';
$page_title = 'Board Meetings — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1000px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Board Meetings</h1>
            <p class="muted">Plan agendas, track resolutions, and generate official notice documents.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ New meeting</a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'new' && $canManage): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6); border-top: 3px solid var(--color-primary);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-4);">Schedule a meeting</h2>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="create">

            <div class="form-row form-row--3">
                <div class="field">
                    <label class="field__label" for="mdate">Meeting date <span style="color:var(--color-error)">*</span></label>
                    <input class="input" type="date" id="mdate" name="meeting_date" required value="<?= e(date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="mtime">Time</label>
                    <input class="input" type="time" id="mtime" name="meeting_time" value="18:00">
                </div>
                <div class="field">
                    <label class="field__label" for="mtype">Meeting type</label>
                    <select class="select" id="mtype" name="meeting_type">
                        <?php foreach ($MEETING_TYPES as $v => $l): ?>
                            <option value="<?= e($v) ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="mtitle">Title <span class="muted" style="font-weight:400;">(leave blank to auto-generate)</span></label>
                    <input class="input" id="mtitle" name="title" maxlength="255"
                           placeholder="e.g. May Regular Board Meeting">
                </div>
                <div class="field">
                    <label class="field__label" for="mloc">Location</label>
                    <input class="input" id="mloc" name="location" maxlength="500"
                           placeholder="e.g. West Lobby, 2727 N. Atlantic Ave">
                </div>
            </div>

            <div class="row" style="justify-content:flex-end; gap:var(--sp-2); margin-top:var(--sp-2);">
                <a class="btn btn--ghost" href="/dashboard/meetings.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Create meeting</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="row" style="gap:var(--sp-1); margin-bottom:var(--sp-4); border-bottom:2px solid var(--color-border); padding-bottom:0;">
        <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past'] as $tv => $tl): ?>
            <a href="?tab=<?= $tv ?>"
               style="padding: var(--sp-2) var(--sp-3); text-decoration:none; font-weight:600; font-size:var(--fs-sm);
                      border-bottom: 3px solid <?= $tab === $tv ? 'var(--color-primary)' : 'transparent' ?>;
                      color: <?= $tab === $tv ? 'var(--color-primary)' : 'var(--color-text-muted)' ?>;
                      margin-bottom: -2px;"><?= $tl ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$meetings): ?>
        <div class="card card--padded center" style="padding:var(--sp-12) var(--sp-6);">
            <p class="muted"><?= $tab === 'upcoming' ? 'No upcoming meetings scheduled.' : 'No past meetings on record.' ?></p>
            <?php if ($canManage && $tab === 'upcoming'): ?>
                <p style="margin-top:var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Schedule first meeting</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="stack-sm">
        <?php foreach ($meetings as $m): ?>
            <?php
            $dateStr = udate('l, F j, Y', strtotime((string)$m['meeting_date']));
            $timeStr = $m['meeting_time'] ? date('g:i A', strtotime((string)$m['meeting_time'])) : '';
            $status  = (string)($m['status'] ?? 'draft');
            ?>
            <div class="card card--padded" style="display:flex; align-items:center; gap:var(--sp-4); flex-wrap:wrap;">
                <div style="flex:1; min-width:0;">
                    <div class="row" style="gap:var(--sp-2); margin-bottom:var(--sp-1); flex-wrap:wrap; align-items:center;">
                        <span class="badge <?= e($STATUS_BADGE[$status] ?? '') ?>"><?= e($STATUS_LABELS[$status] ?? $status) ?></span>
                        <span class="badge" style="background:var(--color-bg); color:var(--color-text-muted); border:1px solid var(--color-border);"><?= e($MEETING_TYPES[$m['meeting_type']] ?? (string)$m['meeting_type']) ?></span>
                        <strong style="font-size:var(--fs-lg);"><?= e((string)$m['title']) ?></strong>
                    </div>
                    <div class="muted" style="font-size:var(--fs-sm);">
                        <?= e($dateStr) ?>
                        <?php if ($timeStr): ?> at <?= e($timeStr) ?><?php endif; ?>
                        <?php if (!empty($m['location'])): ?> · <?= e((string)$m['location']) ?><?php endif; ?>
                    </div>
                    <div class="muted" style="font-size:var(--fs-xs); margin-top:var(--sp-1);">
                        <?= (int)$m['item_count'] ?> agenda item<?= $m['item_count'] != 1 ? 's' : '' ?>
                        <?php if ($m['resolution_count']): ?>
                            · <?= (int)$m['resolution_count'] ?> resolution<?= $m['resolution_count'] != 1 ? 's' : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row" style="gap:var(--sp-2); flex-shrink:0;">
                    <a class="btn btn--ghost" href="/dashboard/meeting-detail.php?id=<?= (int)$m['id'] ?>">Open</a>
                    <?php if ($canManage && $status === 'draft'): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('Delete this draft meeting? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="color:var(--color-error);">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

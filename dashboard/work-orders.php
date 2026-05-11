<?php
// Work Orders — admin-only operational tickets. Board + property manager
// create + track work being done. Members never see this page (require_management).
//
// Single file handles list (?status=...) + detail (?id=N) + create (?action=new)
// + edit (?action=edit&id=N), following the same pattern as concerns.php.
//
// Status workflow: open → in_progress → completed → closed (with `blocked` as
// an off-ramp). Each status change drops a row in work_order_notes so the
// detail page renders a full timeline (status changes + free-text notes
// interleaved by created_at).
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

$STATUSES = [
    'open'        => ['label' => 'Open',        'cls' => 'badge--warning'],
    'in_progress' => ['label' => 'In progress', 'cls' => 'badge--info'],
    'blocked'     => ['label' => 'Blocked',     'cls' => 'badge--error'],
    'completed'   => ['label' => 'Completed',   'cls' => 'badge--success'],
    'closed'      => ['label' => 'Closed',      'cls' => ''],
];
$PRIORITIES = [
    'low'    => ['label' => 'Low',    'cls' => ''],
    'normal' => ['label' => 'Normal', 'cls' => 'badge--info'],
    'high'   => ['label' => 'High',   'cls' => 'badge--warning'],
    'urgent' => ['label' => 'Urgent', 'cls' => 'badge--error'],
];

// --- Add / edit ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['form'] ?? ''), ['add','edit'], true)) {
    csrf_check();
    $isEdit       = $_POST['form'] === 'edit';
    $woId         = (int)($_POST['id'] ?? 0);
    $title        = trim((string)($_POST['title'] ?? ''));
    $body         = trim((string)($_POST['body'] ?? ''));
    $priority     = $_POST['priority'] ?? 'normal';
    $locationId   = ($_POST['location_id'] ?? '') !== '' ? (int)$_POST['location_id'] : null;
    $unitId       = ($_POST['unit_id']     ?? '') !== '' ? (int)$_POST['unit_id']     : null;
    $assignedId   = ($_POST['assigned_user_id']      ?? '') !== '' ? (int)$_POST['assigned_user_id']      : null;
    $contractorId = ($_POST['contractor_contact_id'] ?? '') !== '' ? (int)$_POST['contractor_contact_id'] : null;
    $estimate     = ($_POST['cost_estimate'] ?? '') !== '' ? (float)$_POST['cost_estimate'] : null;
    $actual       = ($_POST['cost_actual']   ?? '') !== '' ? (float)$_POST['cost_actual']   : null;
    $dueDate      = trim((string)($_POST['due_date'] ?? ''));
    $sourceConcernId = ($_POST['source_concern_id'] ?? '') !== '' ? (int)$_POST['source_concern_id'] : null;
    if (!array_key_exists($priority, $PRIORITIES)) $priority = 'normal';
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $dueDate = '';

    if ($title === '') {
        $flashError = 'Title is required.';
    } else {
        if ($isEdit) {
            $check = db()->prepare('SELECT 1 FROM work_orders WHERE id = ? AND association_id = ?');
            $check->execute([$woId, $assocId]);
            if (!$check->fetchColumn()) { http_response_code(404); die('Work order not found'); }
            db()->prepare(
                'UPDATE work_orders
                    SET title = ?, body = ?, priority = ?, location_id = ?, unit_id = ?,
                        assigned_user_id = ?, contractor_contact_id = ?, cost_estimate = ?,
                        cost_actual = ?, due_date = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([
                $title, $body ?: null, $priority, $locationId, $unitId, $assignedId, $contractorId,
                $estimate, $actual, $dueDate ?: null, $woId, $assocId,
            ]);
            audit('work_order.edited', ['title' => $title], $woId, 'work_order');
            flash('success', 'Work order updated.');
            redirect('/dashboard/work-orders.php?id=' . $woId);
        } else {
            db()->prepare(
                'INSERT INTO work_orders
                    (association_id, title, body, priority, location_id, unit_id, assigned_user_id,
                     contractor_contact_id, cost_estimate, cost_actual, due_date, source_concern_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $assocId, $title, $body ?: null, $priority, $locationId, $unitId, $assignedId,
                $contractorId, $estimate, $actual, $dueDate ?: null, $sourceConcernId, (int)$user['id'],
            ]);
            $newId = (int)db()->lastInsertId();
            audit('work_order.created', ['title' => $title, 'source_concern_id' => $sourceConcernId], $newId, 'work_order');
            // Audit the concern → WO conversion on the concern side too, so it shows in either timeline.
            if ($sourceConcernId !== null) {
                audit('concern.converted_to_work_order', ['work_order_id' => $newId, 'title' => $title], $sourceConcernId, 'concern');
            }
            flash('success', "Work order \"$title\" created.");
            redirect('/dashboard/work-orders.php?id=' . $newId);
        }
    }
}

// --- Status change ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    $woId   = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'open';
    $note   = trim((string)($_POST['note'] ?? ''));
    if (!array_key_exists($status, $STATUSES)) $status = 'open';

    $stmt = db()->prepare('SELECT * FROM work_orders WHERE id = ? AND association_id = ?');
    $stmt->execute([$woId, $assocId]);
    $wo = $stmt->fetch();
    if (!$wo) { http_response_code(404); die('Work order not found'); }

    $closedAtSql = in_array($status, ['completed','closed'], true)
        ? ($wo['closed_at'] ?: date('Y-m-d H:i:s'))
        : null;

    db()->prepare(
        'UPDATE work_orders SET status = ?, closed_at = ? WHERE id = ? AND association_id = ?'
    )->execute([$status, $closedAtSql, $woId, $assocId]);

    // Drop a timeline entry. is_status_change=1 with optional note body.
    db()->prepare(
        'INSERT INTO work_order_notes (work_order_id, author_id, body, is_status_change, new_status)
         VALUES (?, ?, ?, 1, ?)'
    )->execute([$woId, (int)$user['id'], $note ?: null, $status]);

    audit('work_order.status_changed', ['status' => $status], $woId, 'work_order');
    flash('success', 'Status updated to ' . $STATUSES[$status]['label'] . '.');
    redirect('/dashboard/work-orders.php?id=' . $woId);
}

// --- Add a free-text note ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'note') {
    csrf_check();
    $woId = (int)($_POST['id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));

    $check = db()->prepare('SELECT 1 FROM work_orders WHERE id = ? AND association_id = ?');
    $check->execute([$woId, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Work order not found'); }

    if ($body !== '') {
        db()->prepare(
            'INSERT INTO work_order_notes (work_order_id, author_id, body) VALUES (?, ?, ?)'
        )->execute([$woId, (int)$user['id'], $body]);
        audit('work_order.note_added', [], $woId, 'work_order');
        flash('success', 'Note added.');
    }
    redirect('/dashboard/work-orders.php?id=' . $woId);
}

// --- Delete -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $woId = (int)($_POST['id'] ?? 0);
    $row  = db()->prepare('SELECT title FROM work_orders WHERE id = ? AND association_id = ?');
    $row->execute([$woId, $assocId]);
    $r = $row->fetch();
    if ($r) {
        db()->prepare('DELETE FROM work_orders WHERE id = ? AND association_id = ?')->execute([$woId, $assocId]);
        audit('work_order.deleted', ['title' => $r['title']], $woId, 'work_order');
        flash('success', "Work order \"{$r['title']}\" deleted.");
    }
    redirect('/dashboard/work-orders.php');
}

// --- View routing -------------------------------------------------------
$detailId = (int)($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';

// Pull dropdown sources (units, locations, contractors, board users)
$units = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
$units->execute([$assocId]);
$units = $units->fetchAll();

$locations = db()->prepare('SELECT id, name FROM locations WHERE association_id = ? AND is_active = 1 ORDER BY sort_order, name');
$locations->execute([$assocId]);
$locations = $locations->fetchAll();

$contractors = db()->prepare(
    "SELECT id, label, trade FROM association_contacts
      WHERE association_id = ? AND kind = 'contractor'
      ORDER BY label"
);
$contractors->execute([$assocId]);
$contractors = $contractors->fetchAll();

$staff = db()->prepare(
    "SELECT id, first_name, last_name, role, board_office
       FROM users
      WHERE association_id = ?
        AND status = 'active'
        AND role IN ('board_admin','board_member','property_manager')
      ORDER BY FIELD(role,'property_manager','board_admin','board_member'), last_name, first_name"
);
$staff->execute([$assocId]);
$staff = $staff->fetchAll();

// Detail row + timeline
$detail   = null;
$timeline = [];
if ($detailId > 0) {
    $stmt = db()->prepare(
        "SELECT w.*,
                u.unit_number,
                l.name AS location_name,
                a.first_name AS assignee_first, a.last_name AS assignee_last,
                c.label AS contractor_label,
                cr.first_name AS creator_first, cr.last_name AS creator_last,
                con.subject AS source_concern_subject, con.type AS source_concern_type
           FROM work_orders w
           LEFT JOIN units u                 ON u.id  = w.unit_id
           LEFT JOIN locations l             ON l.id  = w.location_id
           LEFT JOIN users a                 ON a.id  = w.assigned_user_id
           LEFT JOIN association_contacts c  ON c.id  = w.contractor_contact_id
           LEFT JOIN users cr                ON cr.id = w.created_by
           LEFT JOIN concerns con            ON con.id = w.source_concern_id
          WHERE w.id = ? AND w.association_id = ?"
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;

    if ($detail) {
        $stmt = db()->prepare(
            "SELECT n.*,
                    TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS author_name
               FROM work_order_notes n LEFT JOIN users u ON u.id = n.author_id
              WHERE n.work_order_id = ?
              ORDER BY n.created_at"
        );
        $stmt->execute([$detailId]);
        $timeline = $stmt->fetchAll();
    }
}

// Edit form preload
$editWo = null;
if ($action === 'edit' && $detailId > 0 && $detail) {
    $editWo = $detail;
}

// New form: support ?from_concern=N — preload title/body from the concern
$prefillFromConcern = null;
if ($action === 'new' && ($cid = (int)($_GET['from_concern'] ?? 0)) > 0) {
    $stmt = db()->prepare('SELECT id, subject, body, type FROM concerns WHERE id = ? AND association_id = ?');
    $stmt->execute([$cid, $assocId]);
    $prefillFromConcern = $stmt->fetch() ?: null;
}

// List filters
$statusFilter = $_GET['status'] ?? 'open';
if (!array_key_exists($statusFilter, $STATUSES) && $statusFilter !== 'all') $statusFilter = 'open';

$listRows = [];
if (!$detail && $action === '') {
    $sql = "SELECT w.*, u.unit_number, l.name AS location_name,
                   a.first_name AS assignee_first, a.last_name AS assignee_last,
                   c.label AS contractor_label
              FROM work_orders w
              LEFT JOIN units u                ON u.id = w.unit_id
              LEFT JOIN locations l            ON l.id = w.location_id
              LEFT JOIN users a                ON a.id = w.assigned_user_id
              LEFT JOIN association_contacts c ON c.id = w.contractor_contact_id
             WHERE w.association_id = ?";
    $params = [$assocId];
    if ($statusFilter !== 'all') {
        $sql .= ' AND w.status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY FIELD(w.priority,"urgent","high","normal","low"), w.created_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listRows = $stmt->fetchAll();
}

// Counts by status for the filter tabs
$countByStatus = [];
$stmt = db()->prepare('SELECT status, COUNT(*) AS n FROM work_orders WHERE association_id = ? GROUP BY status');
$stmt->execute([$assocId]);
foreach ($stmt->fetchAll() as $r) $countByStatus[$r['status']] = (int)$r['n'];

$active = 'work-orders';
$page_title = 'Work orders — ' . $association['name'];
require __DIR__ . '/../includes/header.php';

function wo_form_card(?array $editing, ?array $prefill, array $units, array $locations, array $contractors, array $staff, array $PRIORITIES): void {
    $isEdit = $editing !== null;
    $vals = $editing ?? [
        'id' => 0, 'title' => '', 'body' => '', 'priority' => 'normal',
        'location_id' => null, 'unit_id' => null, 'assigned_user_id' => null,
        'contractor_contact_id' => null, 'cost_estimate' => null, 'cost_actual' => null,
        'due_date' => null,
    ];
    if (!$isEdit && $prefill) {
        $vals['title'] = (string)$prefill['subject'];
        $vals['body']  = "From a {$prefill['type']} (concern #{$prefill['id']}):\n\n" . (string)$prefill['body'];
    }
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title"><?= $isEdit ? 'Edit work order' : 'New work order' ?></h3>
        <?php if (!$isEdit && $prefill): ?>
            <p class="muted" style="font-size: var(--fs-sm);">Pre-filled from <a href="/dashboard/concerns.php?id=<?= (int)$prefill['id'] ?>">concern #<?= (int)$prefill['id'] ?> · <?= e((string)$prefill['subject']) ?></a>.</p>
        <?php endif; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>
            <?php if (!$isEdit && $prefill): ?><input type="hidden" name="source_concern_id" value="<?= (int)$prefill['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="w-t">Title</label>
                    <input class="input" id="w-t" name="title" required maxlength="255" value="<?= e((string)$vals['title']) ?>" placeholder="Replace lobby door closer">
                </div>
                <div class="field">
                    <label class="field__label" for="w-p">Priority</label>
                    <select class="select" id="w-p" name="priority">
                        <?php foreach ($PRIORITIES as $val => $meta): ?>
                            <option value="<?= e($val) ?>" <?= ($vals['priority'] ?? 'normal') === $val ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="w-l">Location</label>
                    <select class="select" id="w-l" name="location_id">
                        <option value="">— not set —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['id'] ?>" <?= ((int)($vals['location_id'] ?? 0) === (int)$loc['id']) ? 'selected' : '' ?>><?= e((string)$loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint"><a href="/dashboard/locations.php">Manage locations →</a></div>
                </div>
                <div class="field">
                    <label class="field__label" for="w-u">Unit (if applicable)</label>
                    <select class="select" id="w-u" name="unit_id">
                        <option value="">— not unit-specific —</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= ((int)($vals['unit_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>><?= e((string)$u['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="w-a">Assigned to (internal)</label>
                    <select class="select" id="w-a" name="assigned_user_id">
                        <option value="">— unassigned —</option>
                        <?php foreach ($staff as $s):
                            $nm = trim($s['first_name'] . ' ' . $s['last_name']);
                            $off = board_office_label((string)($s['board_office'] ?? ''));
                            $tag = $off !== '' ? " · $off" : (' · ' . str_replace('_',' ',$s['role']));
                        ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)($vals['assigned_user_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>><?= e($nm . $tag) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="w-c">Contractor</label>
                    <select class="select" id="w-c" name="contractor_contact_id">
                        <option value="">— none —</option>
                        <?php foreach ($contractors as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((int)($vals['contractor_contact_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e((string)$c['label']) ?><?= !empty($c['trade']) ? ' · ' . e((string)$c['trade']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint"><a href="/dashboard/contacts.php">Manage contractors →</a></div>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="w-d">Due date</label>
                    <input class="input" type="date" id="w-d" name="due_date" value="<?= e((string)($vals['due_date'] ?? '')) ?>">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="w-e">Cost estimate ($)</label>
                    <input class="input" type="number" id="w-e" name="cost_estimate" step="0.01" min="0" value="<?= e((string)($vals['cost_estimate'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="w-x">Cost actual ($)</label>
                    <input class="input" type="number" id="w-x" name="cost_actual" step="0.01" min="0" value="<?= e((string)($vals['cost_actual'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="w-b">Description</label>
                <textarea class="textarea" id="w-b" name="body" rows="5" placeholder="What needs doing, what's been observed, any access notes…"><?= e((string)($vals['body'] ?? '')) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/work-orders.php<?= $isEdit ? '?id=' . (int)$vals['id'] : '' ?>">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create work order' ?></button>
            </div>
        </form>
    </div>
    <?php
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <?php if ($detail): ?>
        <!-- DETAIL VIEW -->
        <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <div>
                <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/work-orders.php">← Back to list</a>
                <h1 style="font-size: var(--fs-2xl); margin: var(--sp-2) 0 var(--sp-2);">#<?= (int)$detail['id'] ?> · <?= e((string)$detail['title']) ?></h1>
                <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                    <span class="badge <?= $STATUSES[$detail['status']]['cls'] ?>"><?= e($STATUSES[$detail['status']]['label']) ?></span>
                    <span class="badge <?= $PRIORITIES[$detail['priority']]['cls'] ?>"><?= e($PRIORITIES[$detail['priority']]['label']) ?> priority</span>
                    <?php if (!empty($detail['source_concern_id'])): ?>
                        <a class="badge badge--info" href="/dashboard/concerns.php?id=<?= (int)$detail['source_concern_id'] ?>" style="text-decoration:none;">
                            from <?= e((string)$detail['source_concern_type']) ?> #<?= (int)$detail['source_concern_id'] ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="?id=<?= (int)$detail['id'] ?>&action=edit">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this work order? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Delete</button>
                </form>
            </div>
        </div>

        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

        <?php if ($editWo): wo_form_card($editWo, null, $units, $locations, $contractors, $staff, $PRIORITIES); else: ?>

        <!-- Meta grid -->
        <div class="card card--padded" style="margin-bottom: var(--sp-4);">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-3);">
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Location</div>
                    <div><?= !empty($detail['location_name']) ? e((string)$detail['location_name']) : '<span class="muted">— not set —</span>' ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Unit</div>
                    <div><?= !empty($detail['unit_number']) ? '<a href="/dashboard/unit.php?n=' . urlencode((string)$detail['unit_number']) . '">' . e((string)$detail['unit_number']) . '</a>' : '<span class="muted">— not unit-specific —</span>' ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Assigned to</div>
                    <div><?= !empty($detail['assignee_first']) ? e(trim($detail['assignee_first'] . ' ' . $detail['assignee_last'])) : '<span class="muted">— unassigned —</span>' ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Contractor</div>
                    <div><?= !empty($detail['contractor_label']) ? e((string)$detail['contractor_label']) : '<span class="muted">—</span>' ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Due date</div>
                    <div><?= !empty($detail['due_date']) ? e(date('M j, Y', strtotime((string)$detail['due_date']))) : '<span class="muted">—</span>' ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Cost</div>
                    <div>
                        <?php if ($detail['cost_estimate'] !== null || $detail['cost_actual'] !== null): ?>
                            <?= $detail['cost_actual'] !== null ? '$' . number_format((float)$detail['cost_actual'], 2) : '—' ?>
                            <span class="muted" style="font-size: var(--fs-xs);"> actual</span>
                            <?php if ($detail['cost_estimate'] !== null): ?>
                                <br><span class="muted" style="font-size: var(--fs-xs);">est. $<?= number_format((float)$detail['cost_estimate'], 2) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Created</div>
                    <div>
                        <?= e(date('M j, Y', strtotime((string)$detail['created_at']))) ?>
                        <?php if (!empty($detail['creator_first'])): ?>
                            <span class="muted" style="font-size: var(--fs-xs);"> by <?= e(trim($detail['creator_first'] . ' ' . $detail['creator_last'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($detail['closed_at'])): ?>
                <div>
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Closed</div>
                    <div><?= e(date('M j, Y', strtotime((string)$detail['closed_at']))) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($detail['body'])): ?>
            <article class="card card--padded" style="margin-bottom: var(--sp-4); white-space: pre-wrap;"><?= e((string)$detail['body']) ?></article>
        <?php endif; ?>

        <h3 style="font-size: var(--fs-lg); margin: var(--sp-6) 0 var(--sp-3);">Timeline</h3>
        <?php if (!$timeline): ?>
            <p class="muted">No notes or status changes yet.</p>
        <?php else: ?>
            <div class="stack-md" style="margin-bottom: var(--sp-4);">
            <?php foreach ($timeline as $t):
                $isStatus = (int)$t['is_status_change'] === 1;
                $bg = $isStatus ? 'border-left: 3px solid var(--color-info); background: var(--color-info-bg);' : '';
            ?>
                <div class="card card--padded" style="<?= $bg ?>">
                    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                        <strong><?= e(trim((string)$t['author_name']) ?: '— system —') ?></strong>
                        <?php if ($isStatus): ?>
                            <span class="badge badge--info" style="font-size: var(--fs-xs);">→ <?= e($STATUSES[$t['new_status']]['label'] ?? $t['new_status']) ?></span>
                        <?php endif; ?>
                        <span class="muted" style="font-size: var(--fs-xs);">· <?= e(date('M j, Y g:i A', strtotime((string)$t['created_at']))) ?></span>
                    </div>
                    <?php if (!empty($t['body'])): ?>
                        <p style="white-space: pre-wrap; margin: 0;"><?= e((string)$t['body']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add a note -->
        <form method="post" class="form card card--padded" style="margin-bottom: var(--sp-4);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="note">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <div class="field">
                <label class="field__label" for="n-body">Add a note</label>
                <textarea class="textarea" id="n-body" name="body" rows="3" required placeholder="Talked to Alice from ABC Plumbing — she can come Tuesday."></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Post note</button>
            </div>
        </form>

        <!-- Status controls -->
        <form method="post" class="form card card--padded">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="set_status">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Change status</h3>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ss">Status</label>
                    <select class="select" id="ss" name="status">
                        <?php foreach ($STATUSES as $val => $meta): ?>
                            <option value="<?= e($val) ?>" <?= $detail['status']===$val?'selected':'' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"></div>
            </div>
            <div class="field">
                <label class="field__label" for="sn">Note (optional)</label>
                <textarea class="textarea" id="sn" name="note" rows="2" placeholder="Closing — contractor confirmed done, signed off."></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Update status</button>
            </div>
        </form>

        <?php endif; /* editWo */ ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <!-- CREATE / EDIT VIEW (no detail row in context) -->
        <div class="row row--between" style="margin-bottom: var(--sp-3);">
            <h1 style="font-size: var(--fs-2xl); margin: 0;"><?= $action === 'edit' ? 'Edit work order' : 'New work order' ?></h1>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/work-orders.php">← Back to list</a>
        </div>
        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
        <?php wo_form_card($editWo, $prefillFromConcern, $units, $locations, $contractors, $staff, $PRIORITIES); ?>

    <?php else: ?>
        <!-- LIST VIEW -->
        <div class="row row--between" style="margin-bottom: var(--sp-3);">
            <div>
                <h1 style="font-size: var(--fs-3xl); margin: 0;">Work orders</h1>
                <p class="muted">Operational tickets the board + management track to completion.</p>
            </div>
            <a class="btn btn--primary" href="?action=new">+ New work order</a>
        </div>

        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
            <?php foreach ($STATUSES as $val => $meta):
                $count = $countByStatus[$val] ?? 0;
                $active = $statusFilter === $val;
            ?>
                <a class="badge <?= $active ? $meta['cls'] : '' ?>" href="?status=<?= e($val) ?>" style="text-decoration:none; <?= !$active ? 'opacity: 0.6;' : '' ?>">
                    <?= e($meta['label']) ?>
                    <?php if ($count): ?> · <?= $count ?><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <a class="badge <?= $statusFilter === 'all' ? 'badge--navy' : '' ?>" href="?status=all" style="text-decoration:none; <?= $statusFilter !== 'all' ? 'opacity: 0.6;' : '' ?>">All</a>
        </div>

        <?php if (!$listRows): ?>
            <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
                <p class="muted">No <?= e($STATUSES[$statusFilter]['label'] ?? $statusFilter) ?> work orders.</p>
                <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Create the first one</a></p>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Title</th><th>Status</th><th>Priority</th><th>Location / Unit</th><th>Assigned</th><th>Due</th></tr>
            </thead>
            <tbody>
            <?php foreach ($listRows as $r): ?>
                <tr style="cursor: pointer;" onclick="window.location='?id=<?= (int)$r['id'] ?>'">
                    <td>#<?= (int)$r['id'] ?></td>
                    <td><a href="?id=<?= (int)$r['id'] ?>"><strong><?= e((string)$r['title']) ?></strong></a></td>
                    <td><span class="badge <?= $STATUSES[$r['status']]['cls'] ?>"><?= e($STATUSES[$r['status']]['label']) ?></span></td>
                    <td><span class="badge <?= $PRIORITIES[$r['priority']]['cls'] ?>"><?= e($PRIORITIES[$r['priority']]['label']) ?></span></td>
                    <td>
                        <?php
                        $where = [];
                        if (!empty($r['location_name'])) $where[] = e((string)$r['location_name']);
                        if (!empty($r['unit_number']))   $where[] = 'Unit ' . e((string)$r['unit_number']);
                        echo $where ? implode(' · ', $where) : '<span class="muted">—</span>';
                        ?>
                    </td>
                    <td><?= !empty($r['assignee_first']) ? e(trim($r['assignee_first'] . ' ' . $r['assignee_last'])) : (!empty($r['contractor_label']) ? '<em>' . e((string)$r['contractor_label']) . '</em>' : '<span class="muted">—</span>') ?></td>
                    <td><?= !empty($r['due_date']) ? e(date('M j', strtotime((string)$r['due_date']))) : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

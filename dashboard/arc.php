<?php
// Architectural Review Committee (ARC) requests.
//
// Members (any signed-in user) can submit a request to change something
// exterior — paint color, dish, deck, windows, landscaping — and attach
// photos. The board reviews, comments, and decides: approved / denied /
// approved-with-conditions. Submitters see only their own; managers see
// everything.
//
// Single file handles list (?status=...), submit (?action=new), detail
// (?id=N), and decision (?id=N + status_change form).
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;

$CATEGORIES = [
    'paint'             => 'Paint / exterior color',
    'structural'        => 'Structural / addition',
    'roof'              => 'Roof',
    'windows'           => 'Windows',
    'doors'             => 'Doors',
    'landscaping'       => 'Landscaping',
    'fencing'           => 'Fencing',
    'signage'           => 'Signage',
    'satellite_antenna' => 'Satellite / antenna',
    'solar'             => 'Solar',
    'other'             => 'Other',
];
$STATUS = [
    'draft'        => ['label' => 'Draft',        'cls' => ''],
    'submitted'    => ['label' => 'Submitted',    'cls' => 'badge--info'],
    'under_review' => ['label' => 'Under review', 'cls' => 'badge--warning'],
    'approved'     => ['label' => 'Approved',     'cls' => 'badge--success'],
    'denied'       => ['label' => 'Denied',       'cls' => 'badge--error'],
    'withdrawn'    => ['label' => 'Withdrawn',    'cls' => ''],
    'completed'    => ['label' => 'Completed',    'cls' => 'badge--success'],
];

// ---------- Submit a new request ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'submit') {
    csrf_check();
    $title    = trim((string)($_POST['title'] ?? ''));
    $cat      = $_POST['category'] ?? 'other';
    $body     = trim((string)($_POST['description'] ?? ''));
    $loc      = trim((string)($_POST['location_details'] ?? ''));
    $reqStart = trim((string)($_POST['requested_start'] ?? ''));
    $reqEnd   = trim((string)($_POST['requested_end'] ?? ''));
    $contN    = trim((string)($_POST['contractor_name'] ?? ''));
    $contL    = trim((string)($_POST['contractor_license'] ?? ''));
    $cost     = ($_POST['estimated_cost'] ?? '') !== '' ? (float)$_POST['estimated_cost'] : null;
    if (!array_key_exists($cat, $CATEGORIES)) $cat = 'other';
    if ($reqStart !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqStart)) $reqStart = '';
    if ($reqEnd   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqEnd))   $reqEnd   = '';

    // Auto-attach submitter's unit if they have one on file.
    $unitId = null;
    if (!empty($user['unit_number'])) {
        $u = db()->prepare('SELECT id FROM units WHERE association_id = ? AND unit_number = ? LIMIT 1');
        $u->execute([$assocId, $user['unit_number']]);
        $unitId = $u->fetchColumn() ?: null;
    }

    if ($title === '')      $flashError = 'Title is required.';
    elseif ($body === '')   $flashError = 'Tell us what you want to do — description is required.';
    else {
        db()->prepare(
            'INSERT INTO arc_requests
                (association_id, submitter_user_id, unit_id, title, category, description,
                 location_details, requested_start, requested_end, contractor_name,
                 contractor_license, estimated_cost, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "submitted")'
        )->execute([
            $assocId, (int)$user['id'], $unitId,
            $title, $cat, $body,
            $loc ?: null,
            $reqStart ?: null, $reqEnd ?: null,
            $contN ?: null, $contL ?: null,
            $cost,
        ]);
        $newId = (int)db()->lastInsertId();
        audit('arc_request.submitted', ['title' => $title, 'category' => $cat], $newId, 'arc_request');

        $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) ?: (string)$user['email'];
        notify_association_managers(
            $assocId,
            "[{$association['name']}] New ARC request: {$title}",
            "$name submitted an Architectural Review request for {$association['name']}.\n\n"
            . "Title: {$title}\nCategory: {$CATEGORIES[$cat]}\n"
            . ($loc !== '' ? "Location: {$loc}\n" : '')
            . "\n{$body}\n\n"
            . "Review and respond:\n"
            . "https://badasshoa.com/dashboard/arc.php?id={$newId}\n"
        );

        flash('success', 'Submitted. The board will review and respond — you&rsquo;ll get an email when they do.');
        redirect('/dashboard/arc.php?id=' . $newId);
    }
}

// ---------- Comment on a request ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'comment') {
    csrf_check();
    $rid      = (int)($_POST['id'] ?? 0);
    $body     = trim((string)($_POST['body'] ?? ''));
    $internal = $canManage && isset($_POST['is_internal']) ? 1 : 0;
    $stmt = db()->prepare('SELECT * FROM arc_requests WHERE id = ? AND association_id = ?');
    $stmt->execute([$rid, $assocId]);
    $r = $stmt->fetch();
    if (!$r) { http_response_code(404); die('Not found'); }
    if (!$canManage && (int)$r['submitter_user_id'] !== (int)$user['id']) { http_response_code(403); die('Forbidden'); }
    if ($body === '') {
        $flashError = 'Comment body is required.';
    } else {
        db()->prepare(
            'INSERT INTO arc_request_comments (request_id, author_user_id, body, is_internal)
             VALUES (?, ?, ?, ?)'
        )->execute([$rid, (int)$user['id'], $body, $internal]);
        audit('arc_request.commented', ['internal' => (bool)$internal], $rid, 'arc_request');
        $authorName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) ?: (string)$user['email'];
        if (!$canManage) {
            notify_association_managers(
                $assocId,
                "[{$association['name']}] Reply on ARC request: {$r['title']}",
                "$authorName replied on \"{$r['title']}\":\n\n{$body}\n\n"
                . "https://badasshoa.com/dashboard/arc.php?id={$rid}\n"
            );
        } elseif (!$internal && !empty($r['submitter_user_id'])) {
            $sStmt = db()->prepare('SELECT first_name, email FROM users WHERE id = ?');
            $sStmt->execute([(int)$r['submitter_user_id']]);
            if ($s = $sStmt->fetch()) {
                send_mail((string)$s['email'],
                    "[{$association['name']}] Reply on your ARC request: {$r['title']}",
                    "Hi " . ($s['first_name'] ?: 'there') . ",\n\n"
                    . "The board replied on your Architectural Review request:\n\n"
                    . "  {$r['title']}\n\n"
                    . "$authorName wrote:\n\n{$body}\n\n"
                    . "View + reply: https://badasshoa.com/dashboard/arc.php?id={$rid}\n");
            }
        }
        redirect('/dashboard/arc.php?id=' . $rid);
    }
}

// ---------- Set status / decide ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid    = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'under_review';
    $note   = trim((string)($_POST['decision_note'] ?? ''));
    $cond   = trim((string)($_POST['decision_conditions'] ?? ''));
    if (!array_key_exists($status, $STATUS)) $status = 'under_review';
    $stmt = db()->prepare('SELECT * FROM arc_requests WHERE id = ? AND association_id = ?');
    $stmt->execute([$rid, $assocId]);
    $r = $stmt->fetch();
    if (!$r) { http_response_code(404); die('Not found'); }

    $decidedAt = in_array($status, ['approved','denied','completed','withdrawn'], true)
        ? ($r['decided_at'] ?: date('Y-m-d H:i:s'))
        : null;

    db()->prepare(
        'UPDATE arc_requests
            SET status = ?, decision_note = ?, decision_conditions = ?,
                decided_at = ?, decided_by_user_id = ?
          WHERE id = ? AND association_id = ?'
    )->execute([
        $status, $note ?: null, $cond ?: null,
        $decidedAt, $decidedAt ? (int)$user['id'] : null,
        $rid, $assocId,
    ]);
    audit('arc_request.status_changed', ['status' => $status], $rid, 'arc_request');

    // Email the submitter on decisions (not on every status nudge).
    if (in_array($status, ['approved','denied'], true) && !empty($r['submitter_user_id'])) {
        $sStmt = db()->prepare('SELECT first_name, email FROM users WHERE id = ?');
        $sStmt->execute([(int)$r['submitter_user_id']]);
        if ($s = $sStmt->fetch()) {
            $verb = $status === 'approved' ? 'approved' : 'denied';
            send_mail((string)$s['email'],
                "[{$association['name']}] Your ARC request was {$verb}",
                "Hi " . ($s['first_name'] ?: 'there') . ",\n\n"
                . "The board has " . $verb . " your Architectural Review request:\n\n"
                . "  {$r['title']}\n\n"
                . ($note !== '' ? "Board's note:\n{$note}\n\n" : '')
                . ($status === 'approved' && $cond !== '' ? "Conditions:\n{$cond}\n\n" : '')
                . "View: https://badasshoa.com/dashboard/arc.php?id={$rid}\n");
        }
    }

    flash('success', 'Status updated to ' . $STATUS[$status]['label'] . '.');
    redirect('/dashboard/arc.php?id=' . $rid);
}

// ---------- Withdraw (submitter-only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'withdraw') {
    csrf_check();
    $rid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM arc_requests WHERE id = ? AND association_id = ?');
    $stmt->execute([$rid, $assocId]);
    $r = $stmt->fetch();
    if (!$r) { http_response_code(404); die('Not found'); }
    if ((int)$r['submitter_user_id'] !== (int)$user['id'] && !$canManage) { http_response_code(403); die('Forbidden'); }
    db()->prepare('UPDATE arc_requests SET status = "withdrawn" WHERE id = ? AND association_id = ?')
        ->execute([$rid, $assocId]);
    audit('arc_request.withdrawn', [], $rid, 'arc_request');
    flash('success', 'Request withdrawn.');
    redirect('/dashboard/arc.php?id=' . $rid);
}

// ---------- Detail + list ----------
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
$comments = [];
if ($detailId > 0) {
    $stmt = db()->prepare(
        'SELECT r.*,
                TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name,
                s.email AS submitter_email,
                u.unit_number,
                TRIM(CONCAT(IFNULL(d.first_name,""), " ", IFNULL(d.last_name,""))) AS decider_name
           FROM arc_requests r
           LEFT JOIN users s ON s.id = r.submitter_user_id
           LEFT JOIN units u ON u.id = r.unit_id
           LEFT JOIN users d ON d.id = r.decided_by_user_id
          WHERE r.id = ? AND r.association_id = ?'
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;
    if ($detail && !$canManage && (int)$detail['submitter_user_id'] !== (int)$user['id']) {
        $detail = null;
    }
    if ($detail) {
        $cSql = 'SELECT c.*,
                        TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS author_name,
                        u.role AS author_role
                   FROM arc_request_comments c
                   LEFT JOIN users u ON u.id = c.author_user_id
                  WHERE c.request_id = ?';
        if (!$canManage) $cSql .= ' AND c.is_internal = 0';
        $cSql .= ' ORDER BY c.created_at ASC';
        $cStmt = db()->prepare($cSql);
        $cStmt->execute([$detailId]);
        $comments = $cStmt->fetchAll();
    }
}

$statusFilter = $_GET['status'] ?? 'open';
$listing = [];
$pendingCount = 0;
if (!$detail && ($_GET['action'] ?? '') !== 'new') {
    $sql = 'SELECT r.*,
                   TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name,
                   u.unit_number
              FROM arc_requests r
              LEFT JOIN users s ON s.id = r.submitter_user_id
              LEFT JOIN units u ON u.id = r.unit_id
             WHERE r.association_id = ?';
    $params = [$assocId];
    if (!$canManage) {
        $sql .= ' AND r.submitter_user_id = ?';
        $params[] = (int)$user['id'];
    }
    if ($statusFilter === 'open') {
        $sql .= " AND r.status IN ('submitted','under_review')";
    } elseif (array_key_exists($statusFilter, $STATUS)) {
        $sql .= ' AND r.status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY (r.status IN ("submitted","under_review")) DESC, r.created_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listing = $stmt->fetchAll();

    if ($canManage) {
        $pc = db()->prepare("SELECT COUNT(*) FROM arc_requests WHERE association_id = ? AND status IN ('submitted','under_review')");
        $pc->execute([$assocId]);
        $pendingCount = (int)$pc->fetchColumn();
    }
}

$showSubmit = ($_GET['action'] ?? '') === 'new';

$page_title = 'Architectural Review — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <?php if ($detail): /* ---------- DETAIL ---------- */ ?>
        <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/arc.php">← Back</a>
        </div>

        <div class="card card--padded" style="margin-bottom: var(--sp-4);">
            <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                <span class="badge <?= $STATUS[$detail['status']]['cls'] ?>"><?= e($STATUS[$detail['status']]['label']) ?></span>
                <span class="badge"><?= e($CATEGORIES[$detail['category']] ?? $detail['category']) ?></span>
                <span class="muted" style="font-size: var(--fs-sm);">
                    by <?= e(trim((string)$detail['submitter_name']) ?: (string)($detail['submitter_email'] ?? '— removed —')) ?>
                    <?php if (!empty($detail['unit_number'])): ?> · Unit <?= e((string)$detail['unit_number']) ?><?php endif; ?>
                    · <?= e(date('M j, Y', strtotime((string)$detail['created_at']))) ?>
                </span>
            </div>
            <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-3);"><?= e((string)$detail['title']) ?></h1>
            <div style="white-space: pre-wrap; line-height: 1.55; font-size: var(--fs-md);"><?= e((string)$detail['description']) ?></div>

            <div style="margin-top: var(--sp-4); display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-3);">
                <?php if (!empty($detail['location_details'])): ?>
                    <div><div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Location</div><?= e((string)$detail['location_details']) ?></div>
                <?php endif; ?>
                <?php if (!empty($detail['requested_start']) || !empty($detail['requested_end'])): ?>
                    <div>
                        <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Requested dates</div>
                        <?= !empty($detail['requested_start']) ? e(date('M j, Y', strtotime((string)$detail['requested_start']))) : '?' ?>
                        <?= !empty($detail['requested_end']) ? ' – ' . e(date('M j, Y', strtotime((string)$detail['requested_end']))) : '' ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($detail['contractor_name'])): ?>
                    <div><div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Contractor</div><?= e((string)$detail['contractor_name']) ?><?php if (!empty($detail['contractor_license'])): ?><div class="muted" style="font-size: var(--fs-xs);">Lic. <?= e((string)$detail['contractor_license']) ?></div><?php endif; ?></div>
                <?php endif; ?>
                <?php if ($detail['estimated_cost'] !== null): ?>
                    <div><div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Estimated cost</div>$<?= number_format((float)$detail['estimated_cost'], 2) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (in_array($detail['status'], ['approved','denied','completed'], true) && (!empty($detail['decision_note']) || !empty($detail['decision_conditions']))): ?>
            <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid <?= $detail['status']==='approved' ? 'var(--color-success)' : ($detail['status']==='denied' ? 'var(--color-error)' : 'var(--color-info)') ?>;">
                <strong>Board decision — <?= e($STATUS[$detail['status']]['label']) ?></strong>
                <?php if (!empty($detail['decision_note'])): ?>
                    <p style="white-space: pre-wrap; margin: var(--sp-2) 0 0;"><?= e((string)$detail['decision_note']) ?></p>
                <?php endif; ?>
                <?php if (!empty($detail['decision_conditions'])): ?>
                    <p style="margin: var(--sp-2) 0 0;"><strong>Conditions:</strong></p>
                    <p style="white-space: pre-wrap; margin: 0;"><?= e((string)$detail['decision_conditions']) ?></p>
                <?php endif; ?>
                <small class="muted">
                    By <?= e(trim((string)$detail['decider_name']) ?: 'board') ?>
                    <?php if (!empty($detail['decided_at'])): ?> on <?= e(date('M j, Y', strtotime((string)$detail['decided_at']))) ?><?php endif; ?>
                </small>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

        <h3 style="font-size: var(--fs-lg); margin: var(--sp-6) 0 var(--sp-3);">Discussion</h3>
        <?php if (!$comments): ?>
            <p class="muted">No replies yet.</p>
        <?php else: ?>
            <div class="stack-md" style="margin-bottom: var(--sp-4);">
            <?php foreach ($comments as $c): $isInt = (int)$c['is_internal'] === 1; ?>
                <div class="card card--padded" style="<?= $isInt ? 'border-left: 3px solid var(--color-warning); background: var(--color-warning-bg);' : '' ?>">
                    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                        <strong><?= e(trim((string)$c['author_name']) ?: '—') ?></strong>
                        <?php if ($c['author_role']): ?>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace('_',' ',(string)$c['author_role'])) ?></span>
                        <?php endif; ?>
                        <?php if ($isInt): ?>
                            <span class="badge badge--warning" style="font-size: var(--fs-xs);">internal · board only</span>
                        <?php endif; ?>
                        <span class="muted" style="font-size: var(--fs-xs);">· <?= e(date('M j, Y g:i A', strtotime((string)$c['created_at']))) ?></span>
                    </div>
                    <p style="white-space: pre-wrap; margin: 0;"><?= e((string)$c['body']) ?></p>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="form card card--padded" style="margin-bottom: var(--sp-6);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="comment">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <div class="field">
                <label class="field__label" for="cmt-body"><?= $canManage ? 'Reply / add a note' : 'Reply' ?></label>
                <textarea class="textarea" id="cmt-body" name="body" rows="3" required></textarea>
            </div>
            <?php if ($canManage): ?>
            <label style="display:flex; align-items:center; gap: var(--sp-2); margin-bottom: var(--sp-2);">
                <input type="checkbox" name="is_internal">
                <span>Internal note — only board sees it</span>
            </label>
            <?php endif; ?>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Post</button>
            </div>
        </form>

        <?php if ($canManage): ?>
        <form method="post" class="card card--padded" style="margin-bottom: var(--sp-4);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="set_status">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Board decision</h3>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ss">Status</label>
                    <select class="select" id="ss" name="status">
                        <?php foreach ($STATUS as $val => $meta): if ($val === 'draft') continue; ?>
                            <option value="<?= e($val) ?>" <?= $detail['status']===$val?'selected':'' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"></div>
            </div>
            <div class="field">
                <label class="field__label" for="dnote">Note to submitter (optional)</label>
                <textarea class="textarea" id="dnote" name="decision_note" rows="3"><?= e((string)($detail['decision_note'] ?? '')) ?></textarea>
            </div>
            <div class="field">
                <label class="field__label" for="dcond">Conditions of approval (optional)</label>
                <textarea class="textarea" id="dcond" name="decision_conditions" rows="3" placeholder="Use trim color #SW7008 · complete by Sept 30 · contractor must be licensed"><?= e((string)($detail['decision_conditions'] ?? '')) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Save decision</button>
            </div>
        </form>
        <?php elseif ((int)$detail['submitter_user_id'] === (int)$user['id'] && in_array($detail['status'], ['submitted','under_review'], true)): ?>
            <form method="post" class="card card--padded" style="margin-bottom: var(--sp-4);" onsubmit="return confirm('Withdraw this request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="withdraw">
                <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Withdraw request</button>
            </form>
        <?php endif; ?>

    <?php elseif ($showSubmit): /* ---------- NEW ---------- */ ?>

        <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <h1 style="font-size: var(--fs-2xl); margin: 0;">Submit an architectural request</h1>
            <a class="muted" style="font-size: var(--fs-sm); align-self: center;" href="/dashboard/arc.php">← Back</a>
        </div>
        <p class="muted" style="margin-bottom: var(--sp-4);">
            Use this form for anything visible from outside the unit — paint color, satellite dish, deck, windows, landscaping changes, fencing, signs. The board reviews + responds; you'll get an email when they decide.
        </p>

        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

        <form method="post" class="form card card--padded">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="submit">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="atitle">Title</label>
                    <input class="input" id="atitle" name="title" required placeholder="Paint exterior trim · Install satellite dish · Replace front door">
                </div>
                <div class="field">
                    <label class="field__label" for="acat">Category</label>
                    <select class="select" id="acat" name="category">
                        <?php foreach ($CATEGORIES as $val => $lbl): ?>
                            <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="abody">Description</label>
                <textarea class="textarea" id="abody" name="description" rows="5" required placeholder="What are you proposing? Materials, colors, dimensions, anything specific."></textarea>
            </div>
            <div class="field">
                <label class="field__label" for="aloc">Location details (optional)</label>
                <input class="input" id="aloc" name="location_details" placeholder="Front-facing side · Rear patio · West-side bedroom window">
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="astart">Requested start date</label>
                    <input class="input" type="date" id="astart" name="requested_start">
                </div>
                <div class="field">
                    <label class="field__label" for="aend">Requested completion</label>
                    <input class="input" type="date" id="aend" name="requested_end">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="acname">Contractor (optional)</label>
                    <input class="input" id="acname" name="contractor_name">
                </div>
                <div class="field">
                    <label class="field__label" for="aclic">Contractor license #</label>
                    <input class="input" id="aclic" name="contractor_license">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="acost">Estimated cost ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="acost" name="estimated_cost">
                </div>
                <div class="field"></div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/arc.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Submit request</button>
            </div>
        </form>

    <?php else: /* ---------- LIST ---------- */ ?>

        <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <div>
                <h1 style="font-size: var(--fs-3xl); margin: 0;">Architectural Review</h1>
                <p class="muted">Owner requests for exterior changes — paint, dishes, decks, windows, landscaping.</p>
            </div>
            <a class="btn btn--primary" href="?action=new">+ New request</a>
        </div>

        <?php if ($canManage && $pendingCount > 0 && $statusFilter !== 'open'): ?>
            <div class="flash flash--warning" style="margin-bottom: var(--sp-4);">
                <strong><?= (int)$pendingCount ?> request<?= $pendingCount === 1 ? '' : 's' ?> awaiting review.</strong>
                <a href="?status=open" style="margin-left: var(--sp-2);">Review now →</a>
            </div>
        <?php endif; ?>

        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
            <?php
            $tabs = ['open' => 'Open', 'approved' => 'Approved', 'denied' => 'Denied', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn', 'all' => 'All'];
            foreach ($tabs as $val => $lbl):
                $isActive = $statusFilter === $val;
            ?>
                <a class="badge <?= $isActive ? 'badge--navy' : '' ?>" href="?status=<?= e($val) ?>" style="text-decoration:none; <?= !$isActive ? 'opacity: 0.6;' : '' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$listing): ?>
            <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
                <p class="muted">No <?= e($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>requests.</p>
                <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Submit one</a></p>
            </div>
        <?php else: ?>
        <div class="stack-md">
        <?php foreach ($listing as $r): ?>
            <a class="card card--padded" href="?id=<?= (int)$r['id'] ?>" style="display: block; text-decoration: none; color: inherit; transition: transform 120ms ease, box-shadow 120ms ease;"
               onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 14px rgba(15,31,61,0.08)';"
               onmouseout="this.style.transform=''; this.style.boxShadow='';">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <span class="badge <?= $STATUS[$r['status']]['cls'] ?>"><?= e($STATUS[$r['status']]['label']) ?></span>
                    <span class="badge"><?= e($CATEGORIES[$r['category']] ?? $r['category']) ?></span>
                    <span class="muted" style="font-size: var(--fs-xs);">
                        <?= e(trim((string)$r['submitter_name']) ?: '—') ?>
                        <?php if (!empty($r['unit_number'])): ?> · Unit <?= e((string)$r['unit_number']) ?><?php endif; ?>
                        · <?= e(date('M j, Y', strtotime((string)$r['created_at']))) ?>
                    </span>
                </div>
                <strong style="font-size: var(--fs-lg);"><?= e((string)$r['title']) ?></strong>
                <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);"><?= e(mb_strimwidth((string)$r['description'], 0, 220, '…')) ?></p>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

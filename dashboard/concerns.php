<?php
// Concerns — members file complaints / compliments / suggestions; managers
// review, comment, and resolve. Comment threads support an "internal" flag
// so the board can coordinate privately before posting a public response.
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());

$flashError = null;

// --- Submit a new concern ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'submit') {
    csrf_check();
    $type     = $_POST['type'] ?? 'complaint';
    $cat      = trim((string)($_POST['category'] ?? ''));
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $body     = trim((string)($_POST['body'] ?? ''));
    $anon     = isset($_POST['is_anonymous']) ? 1 : 0;
    if (!in_array($type, ['complaint','compliment','suggestion'], true)) $type = 'complaint';

    // Optional structured targets — "who/what is this about?"
    $targetUserId = ($_POST['target_user_id'] ?? '') !== '' ? (int)$_POST['target_user_id'] : null;
    $targetUnitId = ($_POST['target_unit_id'] ?? '') !== '' ? (int)$_POST['target_unit_id'] : null;
    // Validate that both belong to this association.
    if ($targetUserId) {
        $chk = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $chk->execute([$targetUserId, $assocId]);
        if (!$chk->fetchColumn()) $targetUserId = null;
    }
    if ($targetUnitId) {
        $chk = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $chk->execute([$targetUnitId, $assocId]);
        if (!$chk->fetchColumn()) $targetUnitId = null;
    }
    // Cited rules — array of rule IDs from the multi-pick chip widget.
    $ruleIds = [];
    foreach ((array)($_POST['rule_ids'] ?? []) as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) $ruleIds[] = $rid;
    }
    $ruleIds = array_values(array_unique($ruleIds));
    if ($ruleIds) {
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $chk = db()->prepare("SELECT id FROM rules WHERE association_id = ? AND id IN ($placeholders)");
        $chk->execute(array_merge([$assocId], $ruleIds));
        $ruleIds = array_map('intval', array_column($chk->fetchAll(), 'id'));
    }

    if ($subject === '')  $flashError = 'Subject is required.';
    elseif ($body === '') $flashError = 'Tell us a bit more — body is required.';
    else {
        db()->beginTransaction();
        try {
            db()->prepare(
                'INSERT INTO concerns (association_id, submitter_user_id, target_user_id, target_unit_id, type, category, subject, body, is_anonymous)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$assocId, (int)$user['id'], $targetUserId, $targetUnitId, $type, $cat ?: null, $subject, $body, $anon]);
            $newId = (int)db()->lastInsertId();

            if ($ruleIds) {
                $ins = db()->prepare('INSERT IGNORE INTO concern_rule_citations (concern_id, rule_id) VALUES (?, ?)');
                foreach ($ruleIds as $rid) { $ins->execute([$newId, $rid]); }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            $flashError = 'Submit failed: ' . $e->getMessage();
        }

        if (!$flashError) {
            audit('concern.submitted', [
                'type' => $type, 'subject' => $subject, 'is_anonymous' => $anon,
                'target_user_id' => $targetUserId, 'target_unit_id' => $targetUnitId,
                'rules_cited' => count($ruleIds),
            ], $newId, 'concern');

            // Notify the board.
            $name = $anon ? 'A member (anonymous)' : (trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) ?: (string)$user['email']);
            notify_association_managers(
                $assocId,
                "[{$association['name']}] New {$type}: {$subject}",
                "$name has filed a new {$type} for {$association['name']}.\n\n"
                . "Subject: {$subject}\n"
                . ($cat !== '' ? "Category: {$cat}\n" : '')
                . "\n{$body}\n\n"
                . "Review and respond:\n"
                . "https://badasshoa.com/dashboard/concerns.php?id={$newId}\n"
            );

            flash('success', 'Submitted. The board will review and respond — you\'ll get an email when they do.');
            redirect('/dashboard/concerns.php?id=' . $newId);
        }
    }
}

// --- Add a comment to an existing concern -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'comment') {
    csrf_check();
    $cid     = (int)($_POST['id'] ?? 0);
    $body    = trim((string)($_POST['body'] ?? ''));
    $internal = $canManage && isset($_POST['is_internal']) ? 1 : 0;

    $stmt = db()->prepare('SELECT * FROM concerns WHERE id = ? AND association_id = ?');
    $stmt->execute([$cid, $assocId]);
    $concern = $stmt->fetch();
    if (!$concern) { http_response_code(404); die('Concern not found'); }
    // Submitters can only comment on their own concern. Managers can comment on any.
    if (!$canManage && (int)$concern['submitter_user_id'] !== (int)$user['id']) {
        http_response_code(403); die('Forbidden');
    }
    if ($body === '') {
        $flashError = 'Comment body is required.';
    } else {
        db()->prepare(
            'INSERT INTO concern_comments (concern_id, author_user_id, body, is_internal)
             VALUES (?, ?, ?, ?)'
        )->execute([$cid, (int)$user['id'], $body, $internal]);
        audit('concern.commented', ['internal' => (bool)$internal], $cid, 'concern');

        // Email notifications:
        //   - if member commented on their own concern → notify managers
        //   - if manager commented (and not internal) → notify submitter
        $authorName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) ?: (string)$user['email'];
        if (!$canManage) {
            notify_association_managers(
                $assocId,
                "[{$association['name']}] New comment on concern: {$concern['subject']}",
                "$authorName replied to a concern for {$association['name']}:\n\n{$body}\n\n"
                . "https://badasshoa.com/dashboard/concerns.php?id={$cid}\n"
            );
        } elseif (!$internal && (int)$concern['is_anonymous'] === 0) {
            // Email the original submitter (skipped for anonymous — no inbox to write to)
            $sStmt = db()->prepare("SELECT first_name, email FROM users WHERE id = ? AND status = 'active'");
            $sStmt->execute([(int)$concern['submitter_user_id']]);
            if ($s = $sStmt->fetch()) {
                send_mail((string)$s['email'],
                    "[{$association['name']}] Board response to your concern",
                    "Hi " . ((string)$s['first_name'] ?: 'there') . ",\n\n"
                    . "The board responded to \"{$concern['subject']}\":\n\n{$body}\n\n"
                    . "View the full thread:\nhttps://badasshoa.com/dashboard/concerns.php?id={$cid}\n\n"
                    . "— {$association['name']}");
            }
        }

        flash('success', 'Comment added.');
        redirect('/dashboard/concerns.php?id=' . $cid);
    }
}

// --- Set status / resolve ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid    = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'in_progress';
    $note   = trim((string)($_POST['resolution_summary'] ?? ''));
    if (!in_array($status, ['new','in_progress','resolved','closed'], true)) $status = 'in_progress';

    $stmt = db()->prepare('SELECT * FROM concerns WHERE id = ? AND association_id = ?');
    $stmt->execute([$cid, $assocId]);
    $concern = $stmt->fetch();
    if (!$concern) { http_response_code(404); die('Concern not found'); }

    if ($status === 'resolved') {
        db()->prepare(
            "UPDATE concerns
                SET status = 'resolved',
                    resolved_at = NOW(),
                    resolved_by_user_id = ?,
                    resolution_summary = ?
              WHERE id = ?"
        )->execute([(int)$user['id'], $note ?: null, $cid]);
    } else {
        db()->prepare('UPDATE concerns SET status = ? WHERE id = ?')->execute([$status, $cid]);
    }
    audit('concern.status_changed', ['status' => $status], $cid, 'concern');

    // Notify the submitter (skip if anonymous).
    if ((int)$concern['is_anonymous'] === 0 && $concern['submitter_user_id']) {
        $sStmt = db()->prepare("SELECT first_name, email FROM users WHERE id = ? AND status = 'active'");
        $sStmt->execute([(int)$concern['submitter_user_id']]);
        if ($s = $sStmt->fetch()) {
            send_mail((string)$s['email'],
                "[{$association['name']}] Concern status: " . str_replace('_',' ',$status),
                "Hi " . ((string)$s['first_name'] ?: 'there') . ",\n\n"
                . "Your concern \"{$concern['subject']}\" is now: " . str_replace('_',' ',$status) . ".\n"
                . ($status === 'resolved' && $note !== '' ? "\nResolution: {$note}\n" : '')
                . "\nhttps://badasshoa.com/dashboard/concerns.php?id={$cid}\n\n"
                . "— {$association['name']}");
        }
    }

    flash('success', 'Status updated.');
    redirect('/dashboard/concerns.php?id=' . $cid);
}

// --- Detail view ------------------------------------------------------
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($detailId) {
    $stmt = db()->prepare(
        'SELECT c.*,
                TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name,
                s.email AS submitter_email,
                TRIM(CONCAT(IFNULL(t.first_name,""), " ", IFNULL(t.last_name,""))) AS target_name,
                t.unit_number AS target_user_unit,
                u.unit_number AS target_unit_number
           FROM concerns c
           LEFT JOIN users s ON s.id = c.submitter_user_id
           LEFT JOIN users t ON t.id = c.target_user_id
           LEFT JOIN units u ON u.id = c.target_unit_id
          WHERE c.id = ? AND c.association_id = ?'
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;

    // Submitters can only see their own concerns. Managers see all. Target
    // persons are NOT given visibility — they never see they were named.
    if ($detail && !$canManage && (int)$detail['submitter_user_id'] !== (int)$user['id']) {
        $detail = null; // pretend it doesn't exist
    }

    // Cited rules (visible to board + submitter only — same gate as the row itself).
    $citedRules = [];
    if ($detail) {
        $rStmt = db()->prepare(
            'SELECT r.id, r.rule_number, r.title, r.source
               FROM concern_rule_citations crc
               JOIN rules r ON r.id = crc.rule_id
              WHERE crc.concern_id = ?
              ORDER BY CAST(r.rule_number AS UNSIGNED), r.rule_number'
        );
        $rStmt->execute([$detailId]);
        $citedRules = $rStmt->fetchAll();
    }

    if ($detail) {
        $cmtSql = 'SELECT cc.*,
                          TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS author_name,
                          u.email AS author_email,
                          u.role AS author_role
                     FROM concern_comments cc
                     LEFT JOIN users u ON u.id = cc.author_user_id
                    WHERE cc.concern_id = ?';
        if (!$canManage) $cmtSql .= ' AND cc.is_internal = 0';
        $cmtSql .= ' ORDER BY cc.created_at ASC, cc.id ASC';
        $cmtStmt = db()->prepare($cmtSql);
        $cmtStmt->execute([$detailId]);
        $comments = $cmtStmt->fetchAll();
    }

    // Any work orders that were spawned from this concern (only meaningful for managers).
    $relatedWorkOrders = [];
    if ($detail && $canManage) {
        $stmt = db()->prepare(
            'SELECT id, title, status FROM work_orders
              WHERE association_id = ? AND source_concern_id = ?
              ORDER BY created_at DESC'
        );
        $stmt->execute([$assocId, (int)$detail['id']]);
        $relatedWorkOrders = $stmt->fetchAll();
    }
}

// --- List view --------------------------------------------------------
$listing = [];
$pendingCount = 0;
if (!$detail && ($_GET['action'] ?? '') !== 'submit') {
    if ($canManage) {
        $statusFilter = $_GET['status'] ?? 'open';
        $typeFilter   = $_GET['type'] ?? '';
        $where  = ['c.association_id = ?'];
        $params = [$assocId];
        if ($statusFilter === 'open')      { $where[] = "c.status IN ('new','in_progress')"; }
        elseif (in_array($statusFilter, ['new','in_progress','resolved','closed'], true)) { $where[] = 'c.status = ?'; $params[] = $statusFilter; }
        if (in_array($typeFilter, ['complaint','compliment','suggestion'], true)) { $where[] = 'c.type = ?'; $params[] = $typeFilter; }
        $sql = 'SELECT c.*, TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name
                  FROM concerns c LEFT JOIN users s ON s.id = c.submitter_user_id
                 WHERE ' . implode(' AND ', $where) . "
                 ORDER BY (c.status='new') DESC, c.updated_at DESC LIMIT 200";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $listing = $stmt->fetchAll();

        $pCount = db()->prepare("SELECT COUNT(*) FROM concerns WHERE association_id = ? AND status IN ('new','in_progress')");
        $pCount->execute([$assocId]);
        $pendingCount = (int)$pCount->fetchColumn();
    } else {
        // Members only see their own concerns.
        $stmt = db()->prepare(
            'SELECT * FROM concerns WHERE association_id = ? AND submitter_user_id = ?
              ORDER BY (status="new") DESC, updated_at DESC LIMIT 200'
        );
        $stmt->execute([$assocId, (int)$user['id']]);
        $listing = $stmt->fetchAll();
    }
}

$showSubmit = ($_GET['action'] ?? '') === 'submit';

// Picker sources for the submit form: members for "about a person",
// units for "about a unit", and rules for citations. Loaded once.
$membersForPicker = [];
$unitsForPicker   = [];
$rulesForPicker   = [];
if ($showSubmit) {
    $m = db()->prepare("SELECT id, first_name, last_name, unit_number
                          FROM users
                         WHERE association_id = ? AND status <> 'inactive'
                         ORDER BY last_name, first_name");
    $m->execute([$assocId]);
    $membersForPicker = $m->fetchAll();

    $u = db()->prepare('SELECT id, unit_number FROM units
                         WHERE association_id = ?
                         ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
    $u->execute([$assocId]);
    $unitsForPicker = $u->fetchAll();

    $r = db()->prepare('SELECT id, rule_number, title, source
                         FROM rules
                        WHERE association_id = ?
                        ORDER BY CAST(rule_number AS UNSIGNED), rule_number');
    $r->execute([$assocId]);
    $rulesForPicker = $r->fetchAll();
}

$active = 'concerns';
$page_title = 'Concerns — ' . $association['name'];
require __DIR__ . '/../includes/header.php';

function concern_type_badge(string $t): string {
    return match ($t) {
        'compliment' => 'badge--success',
        'suggestion' => 'badge--info',
        default      => 'badge--warning',
    };
}
function concern_status_badge(string $s): string {
    return match ($s) {
        'new'         => 'badge--orange',
        'in_progress' => 'badge--warning',
        'resolved'    => 'badge--success',
        'closed'      => 'badge--navy',
        default       => '',
    };
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <?php if ($detail): ?>
    <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-3); gap: var(--sp-3); flex-wrap: wrap;">
        <div>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/concerns.php">← Back</a>
            <h1 style="font-size: var(--fs-2xl); margin: var(--sp-2) 0 0;"><?= e((string)$detail['subject']) ?></h1>
            <div class="row" style="gap: var(--sp-2); margin-top: var(--sp-2); flex-wrap: wrap;">
                <span class="badge <?= concern_type_badge((string)$detail['type']) ?>"><?= e((string)$detail['type']) ?></span>
                <span class="badge <?= concern_status_badge((string)$detail['status']) ?>"><?= e(str_replace('_',' ',(string)$detail['status'])) ?></span>
                <?php if (!empty($detail['category'])): ?><span class="muted" style="font-size: var(--fs-sm);">· <?= e((string)$detail['category']) ?></span><?php endif; ?>
                <span class="muted" style="font-size: var(--fs-sm);">
                    · <?= (int)$detail['is_anonymous'] === 1 ? 'Anonymous' : 'by ' . e(trim((string)$detail['submitter_name']) ?: (string)$detail['submitter_email']) ?>
                    on <?= e(date('M j, Y', strtotime((string)$detail['created_at']))) ?>
                </span>
            </div>
        </div>
        <?php if ($canManage): ?>
        <div>
            <a class="btn btn--primary" href="/dashboard/work-orders.php?action=new&from_concern=<?= (int)$detail['id'] ?>"
               title="Open a new Work Order pre-filled with this concern's subject + body">
                🛠 Convert to Work Order
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php $hasTargets = !empty($detail['target_user_id']) || !empty($detail['target_unit_id']) || !empty($citedRules); ?>
    <?php if ($hasTargets): ?>
        <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid var(--color-warning); background: var(--color-warning-bg);">
            <strong>About:</strong>
            <ul style="margin: var(--sp-2) 0 0; padding-left: 1.2em; font-size: var(--fs-sm);">
                <?php if (!empty($detail['target_user_id']) && !empty($detail['target_name'])): ?>
                    <li>
                        <strong><?= e((string)$detail['target_name']) ?></strong>
                        <?php if (!empty($detail['target_user_unit'])): ?>
                            <span class="muted">· Unit <?= e((string)$detail['target_user_unit']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endif; ?>
                <?php if (!empty($detail['target_unit_id']) && !empty($detail['target_unit_number'])): ?>
                    <li><strong>Unit <?= e((string)$detail['target_unit_number']) ?></strong></li>
                <?php endif; ?>
                <?php if (!empty($citedRules)): ?>
                    <li>
                        <strong>Cited rule<?= count($citedRules) === 1 ? '' : 's' ?>:</strong>
                        <?php foreach ($citedRules as $i => $cr): ?>
                            <a href="/dashboard/rule.php?id=<?= (int)$cr['id'] ?>" target="_blank" rel="noopener">
                                <?php if (!empty($cr['rule_number'])): ?>#<?= e((string)$cr['rule_number']) ?> ·<?php endif; ?>
                                <?= e((string)$cr['title']) ?>
                            </a><?= $i < count($citedRules) - 1 ? '; ' : '' ?>
                        <?php endforeach; ?>
                    </li>
                <?php endif; ?>
            </ul>
            <?php if (!empty($detail['target_user_id'])): ?>
                <p class="muted" style="font-size: var(--fs-xs); margin: var(--sp-2) 0 0;">⚠ Privacy: the named person doesn't see they were named — board + submitter only.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canManage && !empty($relatedWorkOrders)): ?>
        <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid var(--color-info); background: var(--color-info-bg);">
            <strong>Linked work order<?= count($relatedWorkOrders) === 1 ? '' : 's' ?>:</strong>
            <ul style="margin: var(--sp-2) 0 0; padding-left: 1.2em; font-size: var(--fs-sm);">
            <?php foreach ($relatedWorkOrders as $wo): ?>
                <li>
                    <a href="/dashboard/work-orders.php?id=<?= (int)$wo['id'] ?>">#<?= (int)$wo['id'] ?> · <?= e((string)$wo['title']) ?></a>
                    <span class="badge" style="font-size: var(--fs-xs); margin-left: 6px;"><?= e(str_replace('_',' ',(string)$wo['status'])) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <article class="card card--padded" style="margin-bottom: var(--sp-4); white-space: pre-wrap;"><?= e((string)$detail['body']) ?></article>

    <?php if (!empty($detail['resolution_summary'])): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4); border-left: 3px solid var(--color-success);">
        <strong style="color: var(--color-success);">Resolution</strong>
        <p style="white-space: pre-wrap; margin: var(--sp-2) 0 0;"><?= e((string)$detail['resolution_summary']) ?></p>
        <small class="muted">Marked resolved <?= e(date('M j, Y', strtotime((string)$detail['resolved_at']))) ?></small>
    </div>
    <?php endif; ?>

    <h3 style="font-size: var(--fs-lg); margin: var(--sp-6) 0 var(--sp-3);">Discussion</h3>
    <?php if (empty($comments)): ?>
        <p class="muted">No replies yet.</p>
    <?php else: ?>
        <div class="stack-md" style="margin-bottom: var(--sp-4);">
        <?php foreach ($comments as $c):
            $isInternal = (int)$c['is_internal'] === 1;
        ?>
            <div class="card card--padded" style="<?= $isInternal ? 'border-left: 3px solid var(--color-warning); background: var(--color-warning-bg);' : '' ?>">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                    <strong><?= e(trim((string)$c['author_name']) ?: (string)$c['author_email']) ?></strong>
                    <?php if ($c['author_role']): ?>
                        <span class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace('_',' ',(string)$c['author_role'])) ?></span>
                    <?php endif; ?>
                    <?php if ($isInternal): ?>
                        <span class="badge badge--warning" style="font-size: var(--fs-xs);">internal · board only</span>
                    <?php endif; ?>
                    <span class="muted" style="font-size: var(--fs-xs);">· <?= e(date('M j, Y g:i A', strtotime((string)$c['created_at']))) ?></span>
                </div>
                <p style="white-space: pre-wrap; margin: 0;"><?= e((string)$c['body']) ?></p>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- New comment -->
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
            <span>Internal note — only board sees it (submitter never gets emailed about internal notes)</span>
        </label>
        <?php endif; ?>
        <div class="row" style="justify-content: flex-end;">
            <button class="btn btn--primary" type="submit">Post</button>
        </div>
    </form>

    <?php if ($canManage): ?>
    <!-- Status controls -->
    <form method="post" class="card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="set_status">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Set status</h3>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="ss">Status</label>
                <select class="select" id="ss" name="status">
                    <option value="new"         <?= $detail['status']==='new'?'selected':'' ?>>New</option>
                    <option value="in_progress" <?= $detail['status']==='in_progress'?'selected':'' ?>>In progress</option>
                    <option value="resolved"    <?= $detail['status']==='resolved'?'selected':'' ?>>Resolved</option>
                    <option value="closed"      <?= $detail['status']==='closed'?'selected':'' ?>>Closed</option>
                </select>
            </div>
            <div class="field"></div>
        </div>
        <div class="field">
            <label class="field__label" for="rs">Resolution summary <span class="muted" style="font-weight: 400;">(emailed to submitter when status = Resolved)</span></label>
            <textarea class="textarea" id="rs" name="resolution_summary" rows="3"><?= e((string)($detail['resolution_summary'] ?? '')) ?></textarea>
        </div>
        <div class="row" style="justify-content: flex-end;">
            <button class="btn btn--primary" type="submit">Update status</button>
        </div>
    </form>
    <?php endif; ?>

    <?php elseif ($showSubmit): ?>

    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <h1 style="font-size: var(--fs-2xl); margin: 0;">Submit a concern</h1>
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/concerns.php">← Back</a>
    </div>
    <p class="muted" style="margin-bottom: var(--sp-4);">
        Got a complaint, a compliment, or an idea? Tell the board. They'll reply, and you'll get an email when they do.
    </p>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <form method="post" class="form card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="submit">
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="ct">Type</label>
                <select class="select" id="ct" name="type">
                    <option value="complaint">Complaint</option>
                    <option value="compliment">Compliment</option>
                    <option value="suggestion">Suggestion</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="cc">Category (optional)</label>
                <input class="input" id="cc" name="category" placeholder="Noise · Common areas · Pets · …">
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="cs">Subject</label>
            <input class="input" id="cs" name="subject" required maxlength="255" placeholder="Brief one-liner">
        </div>
        <div class="field">
            <label class="field__label" for="cb">Details</label>
            <textarea class="textarea" id="cb" name="body" rows="6" required placeholder="What happened, when, where, and what would resolve it?"></textarea>
        </div>

        <!-- Optional structured targets — who/what is this about? -->
        <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
            <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Who or what is this about? <span class="muted">(optional)</span></legend>
            <p class="muted" style="font-size: var(--fs-xs); margin: 0 0 var(--sp-3);">⚠ The person named below <strong>does not see</strong> their name — only the board + you do.</p>

            <div class="form-row form-row--2">
                <div class="field" style="position: relative;">
                    <label class="field__label" for="c-person">About a person</label>
                    <?php
                    $personTypeahead = [];
                    foreach ($membersForPicker as $m) {
                        $nm = trim((string)$m['first_name'] . ' ' . (string)$m['last_name']);
                        if ($nm === '') continue;
                        $personTypeahead[] = ['id' => (int)$m['id'], 'name' => $nm, 'unit' => (string)($m['unit_number'] ?? '')];
                    }
                    ?>
                    <input class="input" type="text" id="c-person-search" autocomplete="off"
                           placeholder="Type a name…"
                           data-typeahead='<?= e(json_encode($personTypeahead, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                           aria-autocomplete="list" aria-controls="c-person-results">
                    <input type="hidden" id="c-person" name="target_user_id" value="">
                    <div id="c-person-results" class="typeahead-list" role="listbox" hidden></div>
                </div>
                <div class="field">
                    <label class="field__label" for="c-unit">About a unit</label>
                    <select class="select" id="c-unit" name="target_unit_id">
                        <option value="">— not unit-specific —</option>
                        <?php foreach ($unitsForPicker as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">Unit <?= e((string)$u['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field" style="position: relative;">
                <label class="field__label" for="c-rule-search">Cite rule(s)</label>
                <?php
                $ruleTypeahead = [];
                foreach ($rulesForPicker as $r) {
                    $ruleTypeahead[] = [
                        'id'    => (int)$r['id'],
                        'num'   => (string)($r['rule_number'] ?? ''),
                        'title' => (string)$r['title'],
                        'src'   => (string)($r['source'] ?? ''),
                    ];
                }
                ?>
                <input class="input" type="text" id="c-rule-search" autocomplete="off"
                       placeholder="Type a rule number or keyword to add (e.g. '3.4' or 'noise')…"
                       data-rule-typeahead='<?= e(json_encode($ruleTypeahead, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <div id="c-rule-results" class="typeahead-list" role="listbox" hidden></div>
                <div id="c-rule-chips" style="display:flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;"></div>
                <div class="field__hint">Pick the rule(s) this concern relates to. Searchable by number or title.</div>
            </div>
        </fieldset>

        <label style="display:flex; align-items:center; gap: var(--sp-2); margin-bottom: var(--sp-2);">
            <input type="checkbox" name="is_anonymous">
            <span>Submit anonymously — board won't see your name. (You still get email updates.)</span>
        </label>
        <div class="row" style="justify-content: flex-end;">
            <a class="btn btn--ghost" href="/dashboard/concerns.php">Cancel</a>
            <button class="btn btn--primary" type="submit">Submit</button>
        </div>
    </form>

    <?php else: ?>

    <div class="row row--between" style="margin-bottom: var(--sp-4);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Concerns</h1>
            <p class="muted">
                <?php if ($canManage): ?>
                    Complaints, compliments, and suggestions from members. Click any row to read and respond.
                <?php else: ?>
                    Your submissions to the board. The board sees everything except items you mark anonymous.
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primary" href="?action=submit">+ Submit a concern</a>
    </div>

    <?php if ($canManage && $pendingCount > 0): ?>
    <div class="flash flash--warning" style="margin-bottom: var(--sp-4);">
        <strong><?= (int)$pendingCount ?> open concern<?= $pendingCount===1?'':'s' ?></strong> awaiting board attention.
    </div>
    <?php endif; ?>

    <?php if ($canManage):
        $statusFilter = $_GET['status'] ?? 'open';
        $typeFilter   = $_GET['type'] ?? '';
    ?>
    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
        <span class="muted" style="font-size: var(--fs-xs); align-self:center;">Status:</span>
        <?php foreach (['open'=>'Open','new'=>'New','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed','all'=>'All'] as $val=>$lbl): ?>
            <a class="badge <?= $statusFilter===$val?'badge--navy':'' ?>"
               href="?<?= http_build_query(array_filter(['status'=>$val,'type'=>$typeFilter])) ?>"
               style="text-decoration:none; <?= $statusFilter!==$val?'opacity:0.6;':'' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
        <span class="muted" style="font-size: var(--fs-xs); align-self:center; margin-left: var(--sp-3);">Type:</span>
        <?php foreach (['complaint'=>'Complaint','compliment'=>'Compliment','suggestion'=>'Suggestion'] as $val=>$lbl): ?>
            <a class="badge <?= $typeFilter===$val?'badge--info':'' ?>"
               href="?<?= http_build_query(array_filter(['status'=>$statusFilter,'type'=>$val])) ?>"
               style="text-decoration:none; <?= $typeFilter!==$val?'opacity:0.6;':'' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
        <?php if ($typeFilter): ?>
            <a class="muted" href="?status=<?= e($statusFilter) ?>" style="font-size: var(--fs-xs); align-self:center;">clear type</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$listing): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">
                <?php if ($canManage): ?>No concerns yet matching that filter.<?php else: ?>You haven't filed any concerns yet.<?php endif; ?>
            </p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Subject</th><th>Type</th><th>Status</th>
                <?php if ($canManage): ?><th>Submitter</th><?php endif; ?>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($listing as $row): ?>
            <tr>
                <td>
                    <a href="?id=<?= (int)$row['id'] ?>"><strong><?= e((string)$row['subject']) ?></strong></a>
                    <?php if (!empty($row['category'])): ?>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$row['category']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= concern_type_badge((string)$row['type']) ?>"><?= e((string)$row['type']) ?></span></td>
                <td><span class="badge <?= concern_status_badge((string)$row['status']) ?>"><?= e(str_replace('_',' ',(string)$row['status'])) ?></span></td>
                <?php if ($canManage): ?>
                <td>
                    <?php if ((int)$row['is_anonymous'] === 1): ?>
                        <em class="muted">Anonymous</em>
                    <?php else: ?>
                        <?= e(trim((string)($row['submitter_name'] ?? '')) ?: '—') ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?= e(date('M j, Y', strtotime((string)$row['updated_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php if ($showSubmit): ?>
<style>
    .typeahead-list {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
        max-height: 280px; overflow-y: auto;
        background: #fff; border: 1px solid var(--color-border); border-radius: var(--r-md);
        box-shadow: 0 8px 24px rgba(15,31,61,0.12);
        margin-top: 2px;
    }
    .typeahead-list[hidden] { display: none; }
    .typeahead-item {
        padding: 8px 12px; cursor: pointer; font-size: var(--fs-sm);
        display: flex; justify-content: space-between; align-items: baseline; gap: var(--sp-3);
    }
    .typeahead-item:hover, .typeahead-item.is-active { background: var(--color-surface); }
    .typeahead-item .meta { color: var(--color-text-soft); font-size: var(--fs-xs); }
    .typeahead-empty { padding: 8px 12px; color: var(--color-text-soft); font-size: var(--fs-sm); font-style: italic; }
    .rule-chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 999px;
        background: var(--color-warning-bg); color: var(--color-warning);
        border: 1px solid rgba(182,130,42,0.25);
        font-size: var(--fs-xs);
    }
    .rule-chip button {
        border: 0; background: transparent; cursor: pointer; padding: 0; color: inherit;
        font-size: 14px; line-height: 1;
    }
</style>
<script>
(function () {
    // ----- Person typeahead (single-select) -----
    var pInput  = document.getElementById('c-person-search');
    var pHidden = document.getElementById('c-person');
    var pList   = document.getElementById('c-person-results');
    if (pInput && pHidden && pList) {
        var pData = JSON.parse(pInput.getAttribute('data-typeahead') || '[]');
        var pActive = -1; var pMatches = [];
        function norm(s) { return (s || '').toLowerCase(); }
        function pRender(q) {
            q = norm(q.trim());
            pMatches = !q ? pData.slice(0, 10) :
                pData.filter(function (m) {
                    return norm(m.name).indexOf(q) !== -1 || norm(m.unit).indexOf(q) !== -1;
                }).slice(0, 10);
            pList.innerHTML = '';
            if (!pMatches.length) { pList.innerHTML = '<div class="typeahead-empty">No members match.</div>'; pList.hidden = false; return; }
            pMatches.forEach(function (m, i) {
                var row = document.createElement('div');
                row.className = 'typeahead-item' + (i === pActive ? ' is-active' : '');
                row.innerHTML = '<span>' + esc(m.name) + '</span>' + (m.unit ? '<span class="meta">Unit ' + esc(m.unit) + '</span>' : '');
                row.addEventListener('mousedown', function (e) { e.preventDefault(); pPick(m); });
                pList.appendChild(row);
            });
            pList.hidden = false;
        }
        function pPick(m) { pInput.value = m.name + (m.unit ? ' · Unit ' + m.unit : ''); pHidden.value = m.id; pList.hidden = true; pActive = -1; }
        pInput.addEventListener('focus', function () { pRender(pInput.value); });
        pInput.addEventListener('input', function () { pHidden.value = ''; pActive = -1; pRender(pInput.value); });
        pInput.addEventListener('keydown', function (e) {
            if (pList.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); pActive = Math.min(pMatches.length - 1, pActive + 1); pRender(pInput.value); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); pActive = Math.max(0, pActive - 1); pRender(pInput.value); }
            else if (e.key === 'Enter' && pActive >= 0) { e.preventDefault(); pPick(pMatches[pActive]); }
            else if (e.key === 'Escape') { pList.hidden = true; }
        });
        document.addEventListener('click', function (e) {
            if (e.target !== pInput && !pList.contains(e.target)) pList.hidden = true;
        });
    }

    // ----- Rule typeahead with chips (multi-select) -----
    var rInput = document.getElementById('c-rule-search');
    var rList  = document.getElementById('c-rule-results');
    var rChips = document.getElementById('c-rule-chips');
    if (rInput && rList && rChips) {
        var rData = JSON.parse(rInput.getAttribute('data-rule-typeahead') || '[]');
        var selected = {}; // id → rule
        var rActive = -1; var rMatches = [];

        function rRender(q) {
            q = (q || '').toLowerCase().trim();
            rMatches = rData.filter(function (r) {
                if (selected[r.id]) return false;
                if (!q) return true;
                return ('#' + r.num).toLowerCase().indexOf(q) !== -1
                    || r.num.toLowerCase().indexOf(q) !== -1
                    || r.title.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 10);
            rList.innerHTML = '';
            if (!rMatches.length) { rList.innerHTML = '<div class="typeahead-empty">No rules match (or all matches already added).</div>'; rList.hidden = false; return; }
            rMatches.forEach(function (m, i) {
                var row = document.createElement('div');
                row.className = 'typeahead-item' + (i === rActive ? ' is-active' : '');
                row.innerHTML = '<span><strong>' + (m.num ? '#' + esc(m.num) + ' · ' : '') + '</strong>' + esc(m.title) + '</span>'
                              + '<span class="meta">' + esc(m.src) + '</span>';
                row.addEventListener('mousedown', function (e) { e.preventDefault(); rAdd(m); });
                rList.appendChild(row);
            });
            rList.hidden = false;
        }
        function rAdd(m) {
            selected[m.id] = m;
            rDrawChips();
            rInput.value = '';
            rRender('');
            rInput.focus();
        }
        function rRemove(id) { delete selected[id]; rDrawChips(); rRender(rInput.value); }
        function rDrawChips() {
            rChips.innerHTML = '';
            Object.keys(selected).forEach(function (id) {
                var m = selected[id];
                var c = document.createElement('span');
                c.className = 'rule-chip';
                c.innerHTML = (m.num ? '#' + esc(m.num) + ' · ' : '') + esc(m.title) + '<button type="button" aria-label="Remove">×</button>'
                            + '<input type="hidden" name="rule_ids[]" value="' + m.id + '">';
                c.querySelector('button').addEventListener('click', function () { rRemove(m.id); });
                rChips.appendChild(c);
            });
        }
        rInput.addEventListener('focus', function () { rRender(rInput.value); });
        rInput.addEventListener('input', function () { rActive = -1; rRender(rInput.value); });
        rInput.addEventListener('keydown', function (e) {
            if (rList.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); rActive = Math.min(rMatches.length - 1, rActive + 1); rRender(rInput.value); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); rActive = Math.max(0, rActive - 1); rRender(rInput.value); }
            else if (e.key === 'Enter' && rActive >= 0) { e.preventDefault(); rAdd(rMatches[rActive]); }
            else if (e.key === 'Escape') { rList.hidden = true; }
        });
        document.addEventListener('click', function (e) {
            if (e.target !== rInput && !rList.contains(e.target) && !rChips.contains(e.target)) rList.hidden = true;
        });
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>

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

    if ($subject === '')  $flashError = 'Subject is required.';
    elseif ($body === '') $flashError = 'Tell us a bit more — body is required.';
    else {
        db()->prepare(
            'INSERT INTO concerns (association_id, submitter_user_id, type, category, subject, body, is_anonymous)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, (int)$user['id'], $type, $cat ?: null, $subject, $body, $anon]);
        $newId = (int)db()->lastInsertId();
        audit('concern.submitted', ['type' => $type, 'subject' => $subject, 'is_anonymous' => $anon], $newId, 'concern');

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
                s.email AS submitter_email
           FROM concerns c
           LEFT JOIN users s ON s.id = c.submitter_user_id
          WHERE c.id = ? AND c.association_id = ?'
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;

    // Submitters can only see their own concerns. Managers see all.
    if ($detail && !$canManage && (int)$detail['submitter_user_id'] !== (int)$user['id']) {
        $detail = null; // pretend it doesn't exist
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
    <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-3);">
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
    </div>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
// Board email broadcasts — compose, send, and track per-recipient delivery.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
if (!$canManage) { http_response_code(403); die('Access denied'); }

// ── helpers ────────────────────────────────────────────────────────────────────
function audience_label(string $a): string {
    return match($a) { 'all' => 'All members', 'owners' => 'Owners', 'renters' => 'Renters', 'board' => 'Board only', default => $a };
}
function tier_label(string $t): string {
    return $t === 'required' ? 'Required notice' : 'Optional';
}
function status_badge(string $s): string {
    return match($s) {
        'sent'    => '<span class="badge badge--success">Sent</span>',
        'sending' => '<span class="badge badge--warning">Sending…</span>',
        'failed'  => '<span class="badge badge--error">Failed</span>',
        'draft'   => '<span class="badge">Draft</span>',
        default   => '<span class="badge">' . e($s) . '</span>',
    };
}
function recip_badge(string $s): string {
    return match($s) {
        'sent'          => '<span class="badge badge--success">Sent</span>',
        'bounced'       => '<span class="badge badge--error">Bounced</span>',
        'complained'    => '<span class="badge badge--error">Complained</span>',
        'unsubscribed'  => '<span class="badge">Unsubscribed</span>',
        'failed'        => '<span class="badge badge--error">Failed</span>',
        default         => '<span class="badge">Pending</span>',
    };
}

// ── GET audience recipient count (AJAX) ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'count') {
    header('Content-Type: application/json');
    $aud = $_GET['audience'] ?? 'all';
    $tier = ($_GET['tier'] ?? 'optional') === 'required' ? 'required' : 'optional';
    echo json_encode(['count' => (int)audience_count($aud, $tier, $assocId)]);
    exit;
}

function audience_count(string $aud, string $tier, int $assocId): int {
    $q = 'SELECT COUNT(*) FROM users WHERE association_id=? AND email NOT LIKE \'no-email-%\' AND email_bounce_count < 3';
    $p = [$assocId];
    if ($aud === 'owners')  { $q .= " AND role IN ('owner','board_admin','board_member','property_manager')"; }
    if ($aud === 'renters') { $q .= " AND role = 'renter'"; }
    if ($aud === 'board')   { $q .= " AND role IN ('board_admin','board_member','property_manager')"; }
    if ($tier === 'optional') { $q .= ' AND email_broadcast_opt_out = 0'; }
    $s = db()->prepare($q); $s->execute($p);
    return (int)$s->fetchColumn();
}

// ── POST: send or save draft ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'broadcast') {
    csrf_check();
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $bodyHtml = trim((string)($_POST['body_html'] ?? ''));
    $bodyText = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $bodyHtml)));
    $audience = in_array($_POST['audience'] ?? '', ['all','owners','renters','board'], true)
                ? (string)$_POST['audience'] : 'all';
    $tier     = (($_POST['tier'] ?? '') === 'required') ? 'required' : 'optional';
    $isDraft  = (($_POST['submit_action'] ?? '') === 'draft');

    if (!$subject || !$bodyHtml) {
        flash('error', 'Subject and body are required.');
        redirect('/dashboard/broadcasts.php?action=compose');
    }

    // Build recipient list
    $q = 'SELECT id, first_name, last_name, email FROM users WHERE association_id=? AND email NOT LIKE \'no-email-%\' AND email_bounce_count < 3';
    $p = [$assocId];
    if ($audience === 'owners')  { $q .= " AND role IN ('owner','board_admin','board_member','property_manager')"; }
    if ($audience === 'renters') { $q .= " AND role = 'renter'"; }
    if ($audience === 'board')   { $q .= " AND role IN ('board_admin','board_member','property_manager')"; }
    if ($tier === 'optional')    { $q .= ' AND email_broadcast_opt_out = 0'; }
    $rStmt = db()->prepare($q); $rStmt->execute($p);
    $recipients = $rStmt->fetchAll();

    if (empty($recipients) && !$isDraft) {
        flash('error', 'No eligible recipients for the selected audience.');
        redirect('/dashboard/broadcasts.php?action=compose');
    }

    // Create broadcast record
    $ins = db()->prepare('INSERT INTO broadcasts (association_id, created_by_user_id, subject, body_html, body_text, audience, tier, status, recipient_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $ins->execute([$assocId, (int)current_user()['id'], $subject, $bodyHtml, $bodyText, $audience, $tier, $isDraft ? 'draft' : 'sending', count($recipients)]);
    $broadcastId = (int)db()->lastInsertId();

    if ($isDraft) {
        flash('success', 'Draft saved.');
        redirect('/dashboard/broadcasts.php');
    }

    // Insert recipient rows
    $rIns = db()->prepare('INSERT INTO broadcast_recipients (broadcast_id, association_id, user_id, email, name, status) VALUES (?, ?, ?, ?, ?, \'pending\')');
    foreach ($recipients as $r) {
        $rIns->execute([$broadcastId, $assocId, (int)$r['id'], $r['email'], trim($r['first_name'] . ' ' . $r['last_name'])]);
    }

    // Send
    set_time_limit(0);
    $sentCount   = 0;
    $assocName   = (string)($association['name'] ?? 'Your HOA');
    $assocAddr   = implode(', ', array_filter([(string)($association['address'] ?? ''), (string)($association['city'] ?? ''), (string)($association['state_region'] ?? ''), (string)($association['postal_code'] ?? '')]));
    $replyTo     = (string)(current_user()['email'] ?? config()['mail']['from'] ?? '');
    $postmarkKey = config()['mail']['postmark_token'] ?? '';

    if ($postmarkKey) {
        // Postmark batch send — up to 500 per request
        $messages = [];
        foreach ($recipients as $r) {
            $uid      = (int)$r['id'];
            $token    = unsub_token($uid, $r['email']);
            $unsubUrl = base_url('/unsubscribe.php?uid=' . $uid . '&token=' . $token . '&bid=' . $broadcastId);
            $toStr    = $r['first_name'] ? (trim($r['first_name'] . ' ' . $r['last_name']) . ' <' . $r['email'] . '>') : $r['email'];
            $messages[] = [
                'From'          => $assocName . ' <' . config()['mail']['from'] . '>',
                'To'            => $toStr,
                'ReplyTo'       => $replyTo,
                'Subject'       => $subject,
                'HtmlBody'      => broadcast_email_html($bodyHtml, $assocName, $assocAddr, $tier === 'optional' ? $unsubUrl : null),
                'TextBody'      => $bodyText . ($tier === 'optional' ? "\n\nUnsubscribe: $unsubUrl" : ''),
                'MessageStream' => 'broadcast',
                'Headers'       => $tier === 'optional' ? [
                    ['Name' => 'List-Unsubscribe',      'Value' => '<' . $unsubUrl . '>'],
                    ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'],
                ] : [],
            ];
        }

        foreach (array_chunk($messages, 500) as $batch) {
            $ch = curl_init('https://api.postmarkapp.com/email/batch');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-Postmark-Server-Token: ' . $postmarkKey,
                ],
                CURLOPT_POSTFIELDS     => json_encode($batch),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $resp     = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $results = ($httpCode === 200) ? (json_decode($resp, true) ?? []) : [];
            foreach ($batch as $i => $msg) {
                $email = preg_match('/<([^>]+)>/', (string)$msg['To'], $mx) ? $mx[1] : (string)$msg['To'];
                if (isset($results[$i])) {
                    $res    = $results[$i];
                    $msgId  = $res['MessageID'] ?? null;
                    $errMsg = ($res['ErrorCode'] ?? 0) !== 0 ? (string)($res['Message'] ?? 'error') : null;
                    $st     = $errMsg ? 'failed' : 'sent';
                    if (!$errMsg) $sentCount++;
                } else {
                    $msgId = null; $errMsg = 'HTTP ' . $httpCode; $st = 'failed';
                }
                db()->prepare('UPDATE broadcast_recipients SET status=?, provider_msg_id=?, error=?, sent_at=NOW() WHERE broadcast_id=? AND email=? LIMIT 1')
                    ->execute([$st, $msgId, $errMsg, $broadcastId, $email]);
            }
        }
    } else {
        // Fallback: msmtp one-by-one (small lists only; Postmark strongly recommended for production)
        foreach ($recipients as $r) {
            $uid      = (int)$r['id'];
            $token    = unsub_token($uid, $r['email']);
            $unsubUrl = base_url('/unsubscribe.php?uid=' . $uid . '&token=' . $token . '&bid=' . $broadcastId);
            $html     = broadcast_email_html($bodyHtml, $assocName, $assocAddr, $tier === 'optional' ? $unsubUrl : null);
            $text     = $bodyText . ($tier === 'optional' ? "\n\nUnsubscribe: $unsubUrl" : '');
            send_mail($r['email'], $subject, $text, $html);
            db()->prepare('UPDATE broadcast_recipients SET status=\'sent\', sent_at=NOW() WHERE broadcast_id=? AND email=? LIMIT 1')
                ->execute([$broadcastId, $r['email']]);
            $sentCount++;
            usleep(80000);
        }
    }

    db()->prepare('UPDATE broadcasts SET status=\'sent\', sent_at=NOW(), sent_count=? WHERE id=?')
        ->execute([$sentCount, $broadcastId]);
    audit('broadcast.sent', ['subject' => $subject, 'recipients' => $sentCount], $broadcastId, 'broadcast');
    flash('success', 'Sent to ' . $sentCount . ' recipient' . ($sentCount !== 1 ? 's' : '') . '.');
    redirect('/dashboard/broadcasts.php?action=view&id=' . $broadcastId);
}

// ── POST: delete draft ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_broadcast') {
    csrf_check();
    $bid = (int)($_POST['id'] ?? 0);
    $chk = db()->prepare('SELECT status FROM broadcasts WHERE id=? AND association_id=?');
    $chk->execute([$bid, $assocId]);
    $bc = $chk->fetch();
    if ($bc && $bc['status'] === 'draft') {
        db()->prepare('DELETE FROM broadcast_recipients WHERE broadcast_id=?')->execute([$bid]);
        db()->prepare('DELETE FROM broadcasts WHERE id=? AND association_id=?')->execute([$bid, $assocId]);
        flash('success', 'Draft deleted.');
    }
    redirect('/dashboard/broadcasts.php');
}

// ── GET: view detail ───────────────────────────────────────────────────────────
$action        = (string)($_GET['action'] ?? 'list');
$viewId        = (int)($_GET['id'] ?? 0);
$viewBroadcast = null;
$viewRecipients = [];

if ($action === 'view' && $viewId) {
    $vStmt = db()->prepare('SELECT b.*, CONCAT(u.first_name," ",u.last_name) AS sender_name FROM broadcasts b LEFT JOIN users u ON u.id=b.created_by_user_id WHERE b.id=? AND b.association_id=?');
    $vStmt->execute([$viewId, $assocId]);
    $viewBroadcast = $vStmt->fetch();
    if (!$viewBroadcast) { http_response_code(404); die('Not found'); }
    $rStmt = db()->prepare('SELECT * FROM broadcast_recipients WHERE broadcast_id=? ORDER BY status, name');
    $rStmt->execute([$viewId]);
    $viewRecipients = $rStmt->fetchAll();
}

// ── GET: compose ───────────────────────────────────────────────────────────────
$audienceCounts = [];
if ($action === 'compose') {
    foreach (['all','owners','renters','board'] as $aud) {
        $audienceCounts[$aud] = audience_count($aud, 'optional', $assocId);
    }
}

// ── GET: list ──────────────────────────────────────────────────────────────────
$broadcasts = [];
if ($action === 'list') {
    $lStmt = db()->prepare('SELECT b.*, CONCAT(u.first_name," ",u.last_name) AS sender_name FROM broadcasts b LEFT JOIN users u ON u.id=b.created_by_user_id WHERE b.association_id=? ORDER BY b.created_at DESC LIMIT 100');
    $lStmt->execute([$assocId]);
    $broadcasts = $lStmt->fetchAll();
}

// Check if Postmark is configured (for UI warning)
$hasPostmark = !empty(config()['mail']['postmark_token'] ?? '');

$active         = 'broadcasts';
$page_title     = match($action) { 'compose' => 'New Broadcast', 'view' => 'Broadcast Detail', default => 'Email Broadcasts' };
$page_extra_head = $action === 'compose' ? '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">' : '';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12);">

<?php if ($action === 'list'): ?>

    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:var(--sp-3); margin-bottom:var(--sp-6);">
        <div>
            <h1 style="font-size:var(--fs-2xl); margin:0 0 var(--sp-1);">Email Broadcasts</h1>
            <p class="muted" style="margin:0; font-size:var(--fs-sm);">Send emails to your members — newsletters, important notices, meeting reminders.</p>
        </div>
        <a href="/dashboard/broadcasts.php?action=compose" class="btn btn--primary">+ New broadcast</a>
    </div>

    <?php if (!$hasPostmark): ?>
    <div class="card card--padded" style="margin-bottom:var(--sp-5); background:var(--color-warning-bg,#fffbeb); border:1px solid var(--color-warning-border,#f5d67a);">
        <p style="margin:0; font-size:var(--fs-sm);">
            <strong>Postmark not configured.</strong>
            Broadcasts will send via your mail server one at a time — fine for small lists but not ideal for 50+ recipients.
            Add <code>postmark_token</code> to your <code>config.php</code> mail section to enable Postmark's batch API.
            <a href="https://postmarkapp.com" target="_blank" rel="noopener">Sign up at postmarkapp.com</a> — free tier covers 100 emails/month.
        </p>
    </div>
    <?php endif; ?>

    <?php if (!$broadcasts): ?>
        <div class="card card--padded">
            <p class="muted" style="text-align:center; padding:var(--sp-8) 0;">No broadcasts yet. Send your first one.</p>
        </div>
    <?php else: ?>
    <div class="card" style="overflow:hidden;">
        <table class="table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Audience</th>
                    <th>Tier</th>
                    <th>Status</th>
                    <th style="text-align:right;">Recipients</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($broadcasts as $bc): ?>
                <tr style="cursor:pointer;" onclick="location.href='/dashboard/broadcasts.php?action=view&id=<?= (int)$bc['id'] ?>'">
                    <td><strong><?= e((string)$bc['subject']) ?></strong>
                        <?php if ($bc['sender_name']): ?><br><span class="muted" style="font-size:var(--fs-xs);"><?= e((string)$bc['sender_name']) ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:var(--fs-sm);"><?= e(audience_label((string)$bc['audience'])) ?></td>
                    <td style="font-size:var(--fs-sm);"><?= e(tier_label((string)$bc['tier'])) ?></td>
                    <td><?= status_badge((string)$bc['status']) ?></td>
                    <td style="text-align:right; font-size:var(--fs-sm);">
                        <?php if ($bc['status'] === 'sent'): ?>
                            <?= (int)$bc['sent_count'] ?> / <?= (int)$bc['recipient_count'] ?>
                        <?php else: ?>
                            <?= (int)$bc['recipient_count'] ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--fs-xs); white-space:nowrap;"><?= e(udate('M j, Y', strtotime((string)$bc['created_at']))) ?></td>
                    <td onclick="event.stopPropagation();">
                        <?php if ($bc['status'] === 'draft'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this draft?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete_broadcast">
                            <input type="hidden" name="id"   value="<?= (int)$bc['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding:0.25rem 0.6rem; font-size:var(--fs-xs); color:var(--color-error);">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($action === 'compose'): ?>

    <div style="margin-bottom:var(--sp-5); display:flex; align-items:center; gap:var(--sp-3);">
        <a href="/dashboard/broadcasts.php" class="btn btn--ghost" style="font-size:var(--fs-sm);">← Back</a>
        <h1 style="font-size:var(--fs-2xl); margin:0;">New Broadcast</h1>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 300px; gap:var(--sp-5); align-items:start;">

        <form id="broadcast-form" method="post" action="/dashboard/broadcasts.php">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="broadcast">
            <input type="hidden" name="body_html" id="body-html-input">
            <input type="hidden" name="submit_action" id="submit-action-input" value="send">

            <div class="card card--padded" style="margin-bottom:var(--sp-4);">
                <div class="field">
                    <label class="field__label" for="bc-subject">Subject line</label>
                    <input class="input" id="bc-subject" name="subject" maxlength="255" required placeholder="e.g. Important: Annual Meeting Notice">
                </div>

                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="bc-audience">Send to</label>
                        <select class="select" id="bc-audience" name="audience">
                            <option value="all">All members (<?= $audienceCounts['all'] ?>)</option>
                            <option value="owners">Owners (<?= $audienceCounts['owners'] ?>)</option>
                            <option value="renters">Renters (<?= $audienceCounts['renters'] ?>)</option>
                            <option value="board">Board only (<?= $audienceCounts['board'] ?>)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bc-tier">Type</label>
                        <select class="select" id="bc-tier" name="tier">
                            <option value="optional">Optional — members can unsubscribe</option>
                            <option value="required">Required notice — always delivered</option>
                        </select>
                        <p class="field__hint" style="font-size:var(--fs-xs); margin-top:var(--sp-1);">
                            Use "Required" for legal notices, dues statements, and emergency alerts.
                        </p>
                    </div>
                </div>
            </div>

            <div class="card card--padded" style="margin-bottom:var(--sp-4);">
                <label class="field__label" style="display:block; margin-bottom:var(--sp-2);">Message body</label>
                <div id="quill-editor" style="min-height:280px; font-size:var(--fs-base);"></div>
            </div>

        </form>

        <!-- Side panel -->
        <div>
            <div class="card card--padded" style="margin-bottom:var(--sp-4);">
                <p style="font-size:var(--fs-sm); font-weight:600; margin:0 0 var(--sp-1);">Recipients</p>
                <p id="recip-count" style="font-size:var(--fs-2xl); font-weight:700; margin:0; color:var(--color-primary);">
                    <?= $audienceCounts['all'] ?>
                </p>
                <p class="muted" style="font-size:var(--fs-xs); margin:var(--sp-1) 0 0;">eligible members</p>
            </div>

            <?php if (!$hasPostmark): ?>
            <div class="card card--padded" style="margin-bottom:var(--sp-4); font-size:var(--fs-xs); background:#fffbeb;">
                <strong>No Postmark key.</strong> Emails will go out via your mail server — slower and not tracked.
            </div>
            <?php endif; ?>

            <div class="card card--padded">
                <button type="submit" form="broadcast-form" class="btn btn--primary" style="width:100%; margin-bottom:var(--sp-2);"
                        onclick="prepareSend('send')">
                    Send now
                </button>
                <button type="submit" form="broadcast-form" class="btn btn--ghost" style="width:100%;"
                        onclick="prepareSend('draft')">
                    Save draft
                </button>
                <p class="muted" style="font-size:var(--fs-xs); margin:var(--sp-3) 0 0;">
                    Sending cannot be undone. Preview your message in the body editor above before sending.
                </p>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
    var quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: { toolbar: [
            ['bold','italic','underline'],
            [{ header: [2,3,false] }],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link'],
            ['clean']
        ]}
    });

    function prepareSend(action) {
        document.getElementById('body-html-input').value = quill.root.innerHTML;
        document.getElementById('submit-action-input').value = action;
    }

    // Live recipient count update when audience/tier changes
    var audSel  = document.getElementById('bc-audience');
    var tierSel = document.getElementById('bc-tier');
    var countEl = document.getElementById('recip-count');
    function updateCount() {
        var aud  = audSel.value;
        var tier = tierSel.value;
        fetch('/dashboard/broadcasts.php?action=count&audience=' + aud + '&tier=' + tier)
            .then(function(r) { return r.json(); })
            .then(function(d) { countEl.textContent = d.count; });
    }
    audSel.addEventListener('change', updateCount);
    tierSel.addEventListener('change', updateCount);
    </script>

<?php elseif ($action === 'view' && $viewBroadcast): ?>

    <div style="margin-bottom:var(--sp-5); display:flex; align-items:center; gap:var(--sp-3); flex-wrap:wrap;">
        <a href="/dashboard/broadcasts.php" class="btn btn--ghost" style="font-size:var(--fs-sm);">← Broadcasts</a>
        <h1 style="font-size:var(--fs-2xl); margin:0; flex:1;"><?= e((string)$viewBroadcast['subject']) ?></h1>
        <?= status_badge((string)$viewBroadcast['status']) ?>
    </div>

    <!-- Summary -->
    <div class="card card--padded" style="margin-bottom:var(--sp-4);">
        <dl style="display:grid; grid-template-columns: 160px 1fr; gap:var(--sp-2) var(--sp-4); font-size:var(--fs-sm);">
            <dt class="muted">Sent by</dt>
            <dd style="margin:0;"><?= e((string)($viewBroadcast['sender_name'] ?: '—')) ?></dd>

            <dt class="muted">Audience</dt>
            <dd style="margin:0;"><?= e(audience_label((string)$viewBroadcast['audience'])) ?></dd>

            <dt class="muted">Type</dt>
            <dd style="margin:0;"><?= e(tier_label((string)$viewBroadcast['tier'])) ?></dd>

            <?php if ($viewBroadcast['sent_at']): ?>
            <dt class="muted">Sent at</dt>
            <dd style="margin:0;"><?= e(udate('F j, Y \a\t g:i A', strtotime((string)$viewBroadcast['sent_at']))) ?> UTC</dd>
            <?php endif; ?>

            <dt class="muted">Delivered</dt>
            <dd style="margin:0;">
                <?php
                $sent  = array_filter($viewRecipients, fn($r) => $r['status'] === 'sent');
                $fail  = array_filter($viewRecipients, fn($r) => in_array($r['status'], ['bounced','failed','complained'], true));
                $unsub = array_filter($viewRecipients, fn($r) => $r['status'] === 'unsubscribed');
                ?>
                <strong><?= count($sent) ?></strong> sent
                <?php if (count($fail)):  ?> &middot; <span style="color:var(--color-error);"><?= count($fail) ?> failed</span><?php endif; ?>
                <?php if (count($unsub)): ?> &middot; <?= count($unsub) ?> unsubscribed<?php endif; ?>
                &nbsp;/ <?= (int)$viewBroadcast['recipient_count'] ?> total
            </dd>
        </dl>
    </div>

    <!-- Message preview -->
    <div class="card card--padded" style="margin-bottom:var(--sp-4);">
        <h2 style="font-size:var(--fs-base); margin:0 0 var(--sp-3);">Message</h2>
        <div style="border:1px solid var(--color-border); border-radius:var(--r-sm); padding:var(--sp-4); font-size:var(--fs-sm); line-height:1.7; background:var(--color-bg);">
            <?= $viewBroadcast['body_html'] ?>
        </div>
    </div>

    <!-- Recipient list -->
    <div class="card" style="overflow:hidden;">
        <div style="padding:var(--sp-4) var(--sp-4) var(--sp-3); border-bottom:1px solid var(--color-border);">
            <h2 style="font-size:var(--fs-base); margin:0;">Recipients (<?= count($viewRecipients) ?>)</h2>
        </div>
        <?php if ($viewRecipients): ?>
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Sent at</th></tr></thead>
            <tbody>
            <?php foreach ($viewRecipients as $rv): ?>
            <tr>
                <td><?= e((string)($rv['name'] ?: '—')) ?></td>
                <td style="font-size:var(--fs-xs);"><?= e((string)$rv['email']) ?></td>
                <td>
                    <?= recip_badge((string)$rv['status']) ?>
                    <?php if ($rv['error']): ?>
                        <span class="muted" style="font-size:var(--fs-xs); display:block;"><?= e((string)$rv['error']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:var(--fs-xs);"><?= $rv['sent_at'] ? e(udate('M j, g:i A', strtotime((string)$rv['sent_at']))) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="muted" style="padding:var(--sp-4);">No recipients.</p>
        <?php endif; ?>
    </div>

<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

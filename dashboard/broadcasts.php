<?php
// Board email broadcasts — compose, send, and track per-recipient delivery.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
$canManage = role_can_manage(viewing_role());
if (!$canManage) { http_response_code(403); die('Access denied'); }

// ── helpers ────────────────────────────────────────────────────────────────────
function audience_label(string $a): string {
    return match($a) {
        'all'    => 'All members',
        'owners' => 'Owners',
        'renters'=> 'Renters',
        'board'  => 'Board only',
        'custom' => 'Custom selection',
        default  => $a,
    };
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
        'sent'         => '<span class="badge badge--success">Sent</span>',
        'bounced'      => '<span class="badge badge--error">Bounced</span>',
        'complained'   => '<span class="badge badge--error">Complained</span>',
        'unsubscribed' => '<span class="badge">Unsubscribed</span>',
        'failed'       => '<span class="badge badge--error">Failed</span>',
        default        => '<span class="badge">Pending</span>',
    };
}

function audience_count(string $aud, string $tier, int $assocId): int {
    if ($aud === 'custom') return 0;
    $q = "SELECT COUNT(*) FROM users WHERE association_id=? AND email IS NOT NULL AND email != '' AND email NOT LIKE 'no-email-%' AND email_bounce_count < 3";
    $p = [$assocId];
    if ($aud === 'owners')  { $q .= " AND role IN ('owner','board_admin','board_member','property_manager')"; }
    if ($aud === 'renters') { $q .= " AND role = 'renter'"; }
    if ($aud === 'board')   { $q .= " AND role IN ('board_admin','board_member','property_manager')"; }
    if ($tier === 'optional') { $q .= ' AND email_broadcast_opt_out = 0'; }
    $s = db()->prepare($q); $s->execute($p);
    return (int)$s->fetchColumn();
}

// Build a multipart/mixed MIME message with optional PDF attachment (msmtp path).
function broadcast_mime(string $to, string $from, string $replyTo, string $subject, string $text, string $html, ?string $attachPath, ?string $attachName): string {
    $outerB = 'bhoa_mix_' . bin2hex(random_bytes(6));
    $altB   = 'bhoa_alt_' . bin2hex(random_bytes(6));

    $altPart = "--{$altB}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n"
             . "--{$altB}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n"
             . "--{$altB}--";

    if ($attachPath && is_file($attachPath)) {
        $headers = "From: {$from}\r\nTo: {$to}\r\nReply-To: {$replyTo}\r\n"
                 . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/mixed; boundary=\"{$outerB}\"\r\n";
        $b64 = chunk_split(base64_encode((string)file_get_contents($attachPath)));
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$attachName) ?: 'attachment.pdf';
        $body = "--{$outerB}\r\nContent-Type: multipart/alternative; boundary=\"{$altB}\"\r\n\r\n{$altPart}\r\n"
              . "--{$outerB}\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\n"
              . "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n{$b64}\r\n"
              . "--{$outerB}--";
    } else {
        $headers = "From: {$from}\r\nTo: {$to}\r\nReply-To: {$replyTo}\r\n"
                 . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"{$altB}\"\r\n";
        $body = $altPart;
    }
    return $headers . "\r\n" . $body;
}

// ── GET audience recipient count (AJAX) ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'count') {
    header('Content-Type: application/json');
    $aud  = $_GET['audience'] ?? 'all';
    $tier = ($_GET['tier'] ?? 'optional') === 'required' ? 'required' : 'optional';
    echo json_encode(['count' => audience_count($aud, $tier, $assocId)]);
    exit;
}

// ── POST: send or save draft ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'broadcast') {
    csrf_check();
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $bodyHtml = trim((string)($_POST['body_html'] ?? ''));
    $bodyText = trim(strip_tags(str_replace(['<br>','<br/>','<br />','</p>','</li>'], "\n", $bodyHtml)));
    $audience = in_array($_POST['audience'] ?? '', ['all','owners','renters','board','custom'], true)
                ? (string)$_POST['audience'] : 'all';
    $tier     = (($_POST['tier'] ?? '') === 'required') ? 'required' : 'optional';
    $isDraft  = (($_POST['submit_action'] ?? '') === 'draft');

    if (!$subject || !$bodyHtml) {
        flash('error', 'Subject and body are required.');
        redirect('/dashboard/broadcasts.php?action=compose');
    }

    // ── Handle file upload ────────────────────────────────────────────────────
    $attachPath = null;
    $attachName = null;
    if (!empty($_FILES['attachment']['tmp_name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        if ($file['size'] > 5 * 1024 * 1024) {
            flash('error', 'Attachment must be under 5 MB.');
            redirect('/dashboard/broadcasts.php?action=compose');
        }
        $mime = mime_content_type($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            flash('error', 'Only PDF attachments are supported.');
            redirect('/dashboard/broadcasts.php?action=compose');
        }
        $dir  = storage_path("uploads/{$assocId}/broadcasts");
        ensure_dir($dir);
        $fname = 'attach_' . bin2hex(random_bytes(8)) . '.pdf';
        move_uploaded_file($file['tmp_name'], $dir . '/' . $fname);
        $attachPath = "uploads/{$assocId}/broadcasts/{$fname}";
        $attachName = pathinfo((string)$file['name'], PATHINFO_BASENAME);
    }

    // ── Build recipient list ──────────────────────────────────────────────────
    $customIds = [];
    if ($audience === 'custom') {
        $rawIds = array_map('intval', (array)($_POST['custom_ids'] ?? []));
        $customIds = array_filter($rawIds);
        if (empty($customIds) && !$isDraft) {
            flash('error', 'Select at least one recipient for a custom broadcast.');
            redirect('/dashboard/broadcasts.php?action=compose');
        }
    }

    if ($audience === 'custom' && $customIds) {
        $ph = implode(',', array_fill(0, count($customIds), '?'));
        $rStmt = db()->prepare("SELECT id, first_name, last_name, email FROM users WHERE association_id=? AND id IN ($ph) AND email IS NOT NULL AND email != '' AND email NOT LIKE 'no-email-%' AND email_bounce_count < 3" . ($tier === 'optional' ? ' AND email_broadcast_opt_out = 0' : ''));
        $rStmt->execute(array_merge([$assocId], $customIds));
    } else {
        $q = "SELECT id, first_name, last_name, email FROM users WHERE association_id=? AND email IS NOT NULL AND email != '' AND email NOT LIKE 'no-email-%' AND email_bounce_count < 3";
        $p = [$assocId];
        if ($audience === 'owners')  { $q .= " AND role IN ('owner','board_admin','board_member','property_manager')"; }
        if ($audience === 'renters') { $q .= " AND role = 'renter'"; }
        if ($audience === 'board')   { $q .= " AND role IN ('board_admin','board_member','property_manager')"; }
        if ($tier === 'optional')    { $q .= ' AND email_broadcast_opt_out = 0'; }
        $rStmt = db()->prepare($q); $rStmt->execute($p);
    }
    $recipients = $rStmt->fetchAll();

    if (empty($recipients) && !$isDraft) {
        flash('error', 'No eligible recipients for the selected audience.');
        redirect('/dashboard/broadcasts.php?action=compose');
    }

    // ── Create broadcast record ───────────────────────────────────────────────
    $ins = db()->prepare('INSERT INTO broadcasts (association_id, created_by_user_id, subject, body_html, body_text, audience, tier, status, recipient_count, custom_user_ids, attachment_path, attachment_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $ins->execute([
        $assocId, (int)current_user()['id'], $subject, $bodyHtml, $bodyText,
        $audience, $tier, $isDraft ? 'draft' : 'sending', count($recipients),
        $customIds ? json_encode($customIds) : null,
        $attachPath, $attachName,
    ]);
    $broadcastId = (int)db()->lastInsertId();

    if ($isDraft) {
        flash('success', 'Draft saved.');
        redirect('/dashboard/broadcasts.php');
    }

    // ── Insert recipient rows ─────────────────────────────────────────────────
    $rIns = db()->prepare("INSERT INTO broadcast_recipients (broadcast_id, association_id, user_id, email, name, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    foreach ($recipients as $r) {
        $rIns->execute([$broadcastId, $assocId, (int)$r['id'], $r['email'], trim($r['first_name'] . ' ' . $r['last_name'])]);
    }

    // ── Send ──────────────────────────────────────────────────────────────────
    set_time_limit(0);
    $sentCount   = 0;
    $assocName   = (string)($association['name'] ?? 'Your HOA');
    $assocAddr   = implode(', ', array_filter([(string)($association['address'] ?? ''), (string)($association['city'] ?? ''), (string)($association['state_region'] ?? ''), (string)($association['postal_code'] ?? '')]));
    $fromEmail   = (string)(config()['mail']['from'] ?? 'noreply@badasshoa.com');
    $replyTo     = (string)(current_user()['email'] ?? $fromEmail);
    $postmarkKey = (string)(config()['mail']['postmark_token'] ?? '');
    $absAttach   = $attachPath ? storage_path($attachPath) : null;

    if ($postmarkKey) {
        // Postmark batch: base64-encode attachment once, reuse per message
        $attachPayload = ($absAttach && is_file($absAttach))
            ? [['Name' => $attachName, 'Content' => base64_encode((string)file_get_contents($absAttach)), 'ContentType' => 'application/pdf']]
            : [];

        $messages = [];
        foreach ($recipients as $r) {
            $uid      = (int)$r['id'];
            $token    = unsub_token($uid, $r['email']);
            $unsubUrl = 'https://badasshoa.com/unsubscribe.php?uid=' . $uid . '&token=' . $token . '&bid=' . $broadcastId;
            $toStr    = $r['first_name'] ? (trim($r['first_name'] . ' ' . $r['last_name']) . ' <' . $r['email'] . '>') : $r['email'];
            $msg = [
                'From'          => $assocName . ' <' . $fromEmail . '>',
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
            if ($attachPayload) $msg['Attachments'] = $attachPayload;
            $messages[] = $msg;
        }

        foreach (array_chunk($messages, 500) as $batch) {
            $ch = curl_init('https://api.postmarkapp.com/email/batch');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json', 'X-Postmark-Server-Token: ' . $postmarkKey],
                CURLOPT_POSTFIELDS     => json_encode($batch),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $resp     = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $results = ($httpCode === 200) ? (json_decode($resp, true) ?? []) : [];
            foreach ($batch as $i => $msg) {
                $email  = preg_match('/<([^>]+)>/', (string)$msg['To'], $mx) ? $mx[1] : (string)$msg['To'];
                $res    = $results[$i] ?? null;
                $msgId  = $res['MessageID'] ?? null;
                $errMsg = ($res && ($res['ErrorCode'] ?? 0) !== 0) ? (string)($res['Message'] ?? 'error') : ($res ? null : 'HTTP ' . $httpCode);
                $st     = $errMsg ? 'failed' : 'sent';
                if (!$errMsg) $sentCount++;
                db()->prepare('UPDATE broadcast_recipients SET status=?, provider_msg_id=?, error=?, sent_at=NOW() WHERE broadcast_id=? AND email=? LIMIT 1')
                    ->execute([$st, $msgId, $errMsg, $broadcastId, $email]);
            }
        }
    } else {
        // msmtp fallback — builds full MIME including attachment when present
        $cfg = config()['mail'] ?? [];
        $bin = $cfg['msmtp_path'] ?? '/usr/bin/msmtp';
        foreach ($recipients as $r) {
            $uid      = (int)$r['id'];
            $token    = unsub_token($uid, $r['email']);
            $unsubUrl = 'https://badasshoa.com/unsubscribe.php?uid=' . $uid . '&token=' . $token . '&bid=' . $broadcastId;
            $html     = broadcast_email_html($bodyHtml, $assocName, $assocAddr, $tier === 'optional' ? $unsubUrl : null);
            $text     = $bodyText . ($tier === 'optional' ? "\n\nUnsubscribe: $unsubUrl" : '');
            $toStr    = $r['first_name'] ? (trim($r['first_name'] . ' ' . $r['last_name']) . ' <' . $r['email'] . '>') : $r['email'];
            $message  = broadcast_mime($toStr, $assocName . ' <' . $fromEmail . '>', $replyTo, $subject, $text, $html, $absAttach, $attachName);

            $proc = proc_open($bin . ' -t -f ' . escapeshellarg($fromEmail), [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $message);
                fclose($pipes[0]);
                stream_get_contents($pipes[1]); fclose($pipes[1]);
                stream_get_contents($pipes[2]); fclose($pipes[2]);
                $exit = proc_close($proc);
            } else { $exit = -1; }

            $st = $exit === 0 ? 'sent' : 'failed';
            db()->prepare("UPDATE broadcast_recipients SET status=?, sent_at=NOW() WHERE broadcast_id=? AND email=? LIMIT 1")
                ->execute([$st, $broadcastId, $r['email']]);
            if ($st === 'sent') $sentCount++;
            usleep(80000);
        }
    }

    db()->prepare('UPDATE broadcasts SET status=\'sent\', sent_at=NOW(), sent_count=? WHERE id=?')->execute([$sentCount, $broadcastId]);
    audit('broadcast.sent', ['subject' => $subject, 'recipients' => $sentCount], $broadcastId, 'broadcast');
    flash('success', 'Sent to ' . $sentCount . ' recipient' . ($sentCount !== 1 ? 's' : '') . '.');
    redirect('/dashboard/broadcasts.php?action=view&id=' . $broadcastId);
}

// ── POST: delete draft ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_broadcast') {
    csrf_check();
    $bid = (int)($_POST['id'] ?? 0);
    $chk = db()->prepare('SELECT status, attachment_path FROM broadcasts WHERE id=? AND association_id=?');
    $chk->execute([$bid, $assocId]);
    $bc = $chk->fetch();
    if ($bc && $bc['status'] === 'draft') {
        if ($bc['attachment_path']) @unlink(storage_path((string)$bc['attachment_path']));
        db()->prepare('DELETE FROM broadcast_recipients WHERE broadcast_id=?')->execute([$bid]);
        db()->prepare('DELETE FROM broadcasts WHERE id=? AND association_id=?')->execute([$bid, $assocId]);
        flash('success', 'Draft deleted.');
    }
    redirect('/dashboard/broadcasts.php');
}

// ── GET: view detail ───────────────────────────────────────────────────────────
$action         = (string)($_GET['action'] ?? 'list');
$viewId         = (int)($_GET['id'] ?? 0);
$viewBroadcast  = null;
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
$membersList    = [];
if ($action === 'compose') {
    foreach (['all','owners','renters','board'] as $aud) {
        $audienceCounts[$aud] = audience_count($aud, 'optional', $assocId);
    }
    $mStmt = db()->prepare("SELECT id, first_name, last_name, email FROM users WHERE association_id=? AND email IS NOT NULL AND email != '' AND email NOT LIKE 'no-email-%' AND email_bounce_count < 3 AND status != 'inactive' ORDER BY first_name, last_name");
    $mStmt->execute([$assocId]);
    $membersList = $mStmt->fetchAll();
}

// ── GET: list ──────────────────────────────────────────────────────────────────
$broadcasts = [];
if ($action === 'list') {
    $lStmt = db()->prepare('SELECT b.*, CONCAT(u.first_name," ",u.last_name) AS sender_name FROM broadcasts b LEFT JOIN users u ON u.id=b.created_by_user_id WHERE b.association_id=? ORDER BY b.created_at DESC LIMIT 100');
    $lStmt->execute([$assocId]);
    $broadcasts = $lStmt->fetchAll();
}

$hasPostmark    = !empty(config()['mail']['postmark_token'] ?? '');
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
    <div class="card card--padded" style="margin-bottom:var(--sp-5); border-left:3px solid #f5c842;">
        <p style="margin:0; font-size:var(--fs-sm);">
            <strong>Postmark not configured.</strong>
            Broadcasts will send via your mail server one at a time — fine for small lists.
            Add <code>'postmark_token' =&gt; 'your-key'</code> to the mail section of <code>config.php</code> to enable Postmark's batch API.
        </p>
    </div>
    <?php endif; ?>

    <?php if (!$broadcasts): ?>
        <div class="card card--padded">
            <p class="muted" style="text-align:center; padding:var(--sp-8) 0;">No broadcasts yet.</p>
        </div>
    <?php else: ?>
    <div class="card" style="overflow:hidden;">
        <table class="table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Audience</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th style="text-align:right;">Sent</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($broadcasts as $bc): ?>
                <tr style="cursor:pointer;" onclick="location.href='/dashboard/broadcasts.php?action=view&id=<?= (int)$bc['id'] ?>'">
                    <td>
                        <strong><?= e((string)$bc['subject']) ?></strong>
                        <?php if ($bc['attachment_name']): ?>
                            <span class="muted" style="font-size:var(--fs-xs); margin-left:var(--sp-1);">📎 <?= e((string)$bc['attachment_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($bc['sender_name']): ?><br><span class="muted" style="font-size:var(--fs-xs);"><?= e((string)$bc['sender_name']) ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:var(--fs-sm);"><?= e(audience_label((string)$bc['audience'])) ?></td>
                    <td style="font-size:var(--fs-sm);"><?= e(tier_label((string)$bc['tier'])) ?></td>
                    <td><?= status_badge((string)$bc['status']) ?></td>
                    <td style="text-align:right; font-size:var(--fs-sm);">
                        <?= (int)$bc['sent_count'] ?><?= $bc['recipient_count'] ? ' / ' . (int)$bc['recipient_count'] : '' ?>
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

        <div>
        <form id="broadcast-form" method="post" action="/dashboard/broadcasts.php" enctype="multipart/form-data">
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
                            <option value="custom">Custom selection…</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bc-tier">Type</label>
                        <select class="select" id="bc-tier" name="tier">
                            <option value="optional">Optional — members can unsubscribe</option>
                            <option value="required">Required notice — always delivered</option>
                        </select>
                    </div>
                </div>

                <!-- Custom member picker — hidden unless audience=custom -->
                <div id="custom-picker-wrap" style="display:none; margin-top:var(--sp-3);">
                    <label class="field__label">Select recipients</label>
                    <select id="custom-ids-select" name="custom_ids[]" multiple style="display:none;"></select>
                    <div id="custom-picker-widget" style="border:1px solid var(--color-border); border-radius:var(--r-sm); padding:var(--sp-2); min-height:40px; cursor:text;">
                        <div id="custom-chips" style="display:flex; flex-wrap:wrap; gap:var(--sp-1); align-items:center;">
                            <input id="custom-picker-input" type="text" placeholder="Type a name…"
                                   style="border:none; outline:none; font-size:var(--fs-sm); min-width:150px; flex:1; padding:var(--sp-1); background:transparent;">
                        </div>
                    </div>
                    <ul id="custom-picker-list" style="display:none; position:absolute; z-index:200; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--r-sm); max-height:200px; overflow-y:auto; list-style:none; margin:2px 0 0; padding:0; min-width:280px; box-shadow:0 4px 16px rgba(0,0,0,.12);"></ul>
                    <p class="muted" style="font-size:var(--fs-xs); margin-top:var(--sp-1);" id="custom-count-hint">0 selected</p>
                </div>
            </div>

            <div class="card card--padded" style="margin-bottom:var(--sp-4);">
                <label class="field__label" style="display:block; margin-bottom:var(--sp-2);">Message body</label>
                <div id="quill-editor" style="min-height:260px; font-size:var(--fs-base);"></div>
            </div>

            <div class="card card--padded">
                <label class="field__label">Attach a PDF <span class="muted" style="font-weight:400;">(optional, max 5 MB)</span></label>
                <input type="file" name="attachment" accept=".pdf,application/pdf" class="input" id="bc-attachment"
                       style="padding:var(--sp-2);">
                <p class="field__hint muted" style="font-size:var(--fs-xs); margin-top:var(--sp-1);" id="attach-hint"></p>
            </div>

        </form>
        </div>

        <!-- Side panel -->
        <div>
            <div class="card card--padded" style="margin-bottom:var(--sp-4);">
                <p style="font-size:var(--fs-sm); font-weight:600; margin:0 0 var(--sp-1);">Recipients</p>
                <p id="recip-count" style="font-size:var(--fs-2xl); font-weight:700; margin:0; color:var(--color-primary);"><?= $audienceCounts['all'] ?></p>
                <p class="muted" style="font-size:var(--fs-xs); margin:var(--sp-1) 0 0;" id="recip-label">eligible members</p>
            </div>

            <?php if (!$hasPostmark): ?>
            <div class="card card--padded" style="margin-bottom:var(--sp-4); font-size:var(--fs-xs);">
                <strong>No Postmark key.</strong> Emails go out via your mail server.
            </div>
            <?php endif; ?>

            <div class="card card--padded">
                <button type="submit" form="broadcast-form" class="btn btn--primary" style="width:100%; margin-bottom:var(--sp-2);"
                        onclick="return prepareSend('send')">Send now</button>
                <button type="submit" form="broadcast-form" class="btn btn--ghost" style="width:100%;"
                        onclick="return prepareSend('draft')">Save draft</button>
                <p class="muted" style="font-size:var(--fs-xs); margin:var(--sp-3) 0 0;">
                    Sending cannot be undone. Confirm recipients above before sending.
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
        return true;
    }

    // ── Live recipient count ──────────────────────────────────────────────────
    var audSel  = document.getElementById('bc-audience');
    var tierSel = document.getElementById('bc-tier');
    var countEl = document.getElementById('recip-count');
    var labelEl = document.getElementById('recip-label');
    function updateCount() {
        var aud = audSel.value;
        if (aud === 'custom') { updateCustomCount(); return; }
        fetch('/dashboard/broadcasts.php?action=count&audience=' + aud + '&tier=' + tierSel.value)
            .then(function(r){ return r.json(); })
            .then(function(d){ countEl.textContent = d.count; labelEl.textContent = 'eligible members'; });
    }
    audSel.addEventListener('change', function() {
        var isCustom = audSel.value === 'custom';
        document.getElementById('custom-picker-wrap').style.display = isCustom ? '' : 'none';
        updateCount();
    });
    tierSel.addEventListener('change', updateCount);

    // ── Member chip picker ────────────────────────────────────────────────────
    var allMembers = <?= json_encode(array_map(fn($m) => [
        'id'    => (int)$m['id'],
        'label' => trim($m['first_name'] . ' ' . $m['last_name']),
        'email' => $m['email'],
    ], $membersList), JSON_HEX_TAG) ?>;

    var sel      = document.getElementById('custom-ids-select');
    var inp      = document.getElementById('custom-picker-input');
    var list     = document.getElementById('custom-picker-list');
    var chips    = document.getElementById('custom-chips');
    var hint     = document.getElementById('custom-count-hint');
    var selected = [];  // array of member objects

    function syncSelect() {
        while (sel.options.length) sel.remove(0);
        selected.forEach(function(m) {
            var o = document.createElement('option');
            o.value = m.id; o.selected = true; o.textContent = m.label;
            sel.appendChild(o);
        });
    }
    function renderChips() {
        // Remove existing chips (keep the input)
        Array.from(chips.querySelectorAll('.bc-chip')).forEach(function(c){ c.remove(); });
        selected.forEach(function(m) {
            var chip = document.createElement('span');
            chip.className = 'bc-chip';
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;background:var(--color-primary);color:#fff;border-radius:4px;padding:2px 8px;font-size:var(--fs-xs);white-space:nowrap;';
            chip.innerHTML = e(m.label) + ' <button type="button" style="background:none;border:none;color:#fff;cursor:pointer;padding:0;font-size:14px;line-height:1;" data-id="' + m.id + '">&times;</button>';
            chip.querySelector('button').addEventListener('click', function() {
                selected = selected.filter(function(x){ return x.id !== m.id; });
                renderChips(); syncSelect(); updateCustomCount();
            });
            chips.insertBefore(chip, inp);
        });
        hint.textContent = selected.length + ' selected';
    }
    function e(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function updateCustomCount() {
        countEl.textContent = selected.length;
        labelEl.textContent = 'recipient' + (selected.length !== 1 ? 's' : '') + ' selected';
    }
    function renderList(q) {
        list.innerHTML = '';
        var selectedIds = selected.map(function(m){ return m.id; });
        var matches = allMembers.filter(function(m){
            return selectedIds.indexOf(m.id) === -1 &&
                   (m.label.toLowerCase().indexOf(q.toLowerCase()) !== -1 || m.email.toLowerCase().indexOf(q.toLowerCase()) !== -1);
        }).slice(0, 8);
        if (!matches.length) { list.style.display = 'none'; return; }
        matches.forEach(function(m) {
            var li = document.createElement('li');
            li.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:var(--fs-sm);border-bottom:1px solid var(--color-border);';
            li.innerHTML = '<strong>' + e(m.label) + '</strong> <span style="color:var(--color-text-muted);font-size:var(--fs-xs);">' + e(m.email) + '</span>';
            li.addEventListener('mousedown', function(ev) {
                ev.preventDefault();
                selected.push(m);
                renderChips(); syncSelect(); updateCustomCount();
                inp.value = ''; renderList(''); inp.focus();
            });
            list.appendChild(li);
        });
        list.style.display = '';
        // Position below widget
        var widget = document.getElementById('custom-picker-widget');
        var rect = widget.getBoundingClientRect();
        list.style.width = rect.width + 'px';
    }
    inp.addEventListener('input', function(){ renderList(inp.value); });
    inp.addEventListener('focus', function(){ renderList(inp.value); });
    inp.addEventListener('blur',  function(){ setTimeout(function(){ list.style.display='none'; }, 150); });
    document.getElementById('custom-picker-widget').addEventListener('click', function(){ inp.focus(); });

    // ── File size hint ────────────────────────────────────────────────────────
    document.getElementById('bc-attachment').addEventListener('change', function() {
        var f = this.files[0];
        if (!f) { document.getElementById('attach-hint').textContent = ''; return; }
        var mb = (f.size / 1048576).toFixed(1);
        document.getElementById('attach-hint').textContent = f.name + ' — ' + mb + ' MB' + (f.size > 5242880 ? ' (too large — max 5 MB)' : '');
    });
    </script>
    <style>
    .bc-chip button:hover { opacity: .8; }
    </style>

<?php elseif ($action === 'view' && $viewBroadcast): ?>

    <div style="margin-bottom:var(--sp-5); display:flex; align-items:center; gap:var(--sp-3); flex-wrap:wrap;">
        <a href="/dashboard/broadcasts.php" class="btn btn--ghost" style="font-size:var(--fs-sm);">← Broadcasts</a>
        <h1 style="font-size:var(--fs-2xl); margin:0; flex:1;"><?= e((string)$viewBroadcast['subject']) ?></h1>
        <?= status_badge((string)$viewBroadcast['status']) ?>
    </div>

    <div class="card card--padded" style="margin-bottom:var(--sp-4);">
        <dl style="display:grid; grid-template-columns:160px 1fr; gap:var(--sp-2) var(--sp-4); font-size:var(--fs-sm);">
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
            <?php if ($viewBroadcast['attachment_name']): ?>
            <dt class="muted">Attachment</dt>
            <dd style="margin:0;">📎 <?= e((string)$viewBroadcast['attachment_name']) ?></dd>
            <?php endif; ?>
            <dt class="muted">Delivered</dt>
            <dd style="margin:0;">
                <?php
                $sent  = count(array_filter($viewRecipients, fn($r) => $r['status'] === 'sent'));
                $fail  = count(array_filter($viewRecipients, fn($r) => in_array($r['status'], ['bounced','failed','complained'], true)));
                $unsub = count(array_filter($viewRecipients, fn($r) => $r['status'] === 'unsubscribed'));
                ?>
                <strong><?= $sent ?></strong> sent
                <?php if ($fail):  ?> &middot; <span style="color:var(--color-error);"><?= $fail ?> failed</span><?php endif; ?>
                <?php if ($unsub): ?> &middot; <?= $unsub ?> unsubscribed<?php endif; ?>
                &nbsp;/ <?= (int)$viewBroadcast['recipient_count'] ?> total
            </dd>
        </dl>
    </div>

    <div class="card card--padded" style="margin-bottom:var(--sp-4);">
        <h2 style="font-size:var(--fs-base); margin:0 0 var(--sp-3);">Message</h2>
        <div style="border:1px solid var(--color-border); border-radius:var(--r-sm); padding:var(--sp-4); font-size:var(--fs-sm); line-height:1.7; background:var(--color-bg);">
            <?= $viewBroadcast['body_html'] ?>
        </div>
    </div>

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

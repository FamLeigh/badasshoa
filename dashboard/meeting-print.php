<?php
// Print-only views: proof of notice, notice of meeting, agenda, resolutions, full package.
// No nav shell — outputs raw HTML with print CSS.
require __DIR__ . '/_bootstrap.php';
require_login();

$role = viewing_role();
if (!in_array($role, ['board_admin','board_member','property_manager','super_admin'], true)) {
    http_response_code(403); die('Forbidden');
}

$mid  = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'agenda';
if (!in_array($type, ['proof','notice','agenda','resolutions','package'], true)) $type = 'agenda';

$meeting = null;
if ($mid) {
    $s = db()->prepare('SELECT * FROM board_meetings WHERE id = ? AND association_id = ?');
    $s->execute([$mid, $assocId]);
    $meeting = $s->fetch() ?: null;
}
if (!$meeting) { http_response_code(404); die('Meeting not found.'); }

// Load agenda items.
$agStmt = db()->prepare(
    'SELECT * FROM agenda_items WHERE meeting_id = ? AND association_id = ? AND status <> \'removed\' ORDER BY sort_order, id'
);
$agStmt->execute([$mid, $assocId]);
$agendaItems = $agStmt->fetchAll();

// Load resolutions + votes.
$resStmt = db()->prepare(
    "SELECT r.*,
            TRIM(CONCAT(IFNULL(mv.first_name,''),' ',IFNULL(mv.last_name,''))) AS mover_name,
            mv.board_office AS mover_office,
            TRIM(CONCAT(IFNULL(sv.first_name,''),' ',IFNULL(sv.last_name,''))) AS seconder_name,
            sv.board_office AS seconder_office
       FROM resolutions r
       LEFT JOIN users mv ON mv.id = r.moved_by_user_id
       LEFT JOIN users sv ON sv.id = r.seconded_by_user_id
      WHERE r.meeting_id = ? AND r.association_id = ?
      ORDER BY r.sort_order, r.id"
);
$resStmt->execute([$mid, $assocId]);
$resolutions = $resStmt->fetchAll();

// Load votes keyed by resolution_id → user_id → vote.
$allVotes = [];
if ($resolutions) {
    $rids  = array_column($resolutions, 'id');
    $phstr = implode(',', array_fill(0, count($rids), '?'));
    $vStmt = db()->prepare("SELECT rv.*, TRIM(CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,''))) AS voter_name, u.board_office FROM resolution_votes rv JOIN users u ON u.id = rv.voter_user_id WHERE rv.resolution_id IN ($phstr)");
    $vStmt->execute($rids);
    foreach ($vStmt->fetchAll() as $v) {
        $allVotes[(int)$v['resolution_id']][] = $v;
    }
}

// Board members in office order.
$boardStmt = db()->prepare(
    "SELECT id, first_name, last_name, board_office, role FROM users
      WHERE association_id = ? AND role IN ('board_admin','board_member','property_manager') AND status <> 'inactive'
      ORDER BY FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director') = 0,
               FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director'),
               last_name, first_name"
);
$boardStmt->execute([$assocId]);
$boardMembers = $boardStmt->fetchAll();

// Proof of notice signer.
$proofSigner = null;
if (!empty($meeting['proof_signed_by_user_id'])) {
    foreach ($boardMembers as $bm) {
        if ((int)$bm['id'] === (int)$meeting['proof_signed_by_user_id']) { $proofSigner = $bm; break; }
    }
}
// Find the association president for the agenda sign-off.
$president = null;
foreach ($boardMembers as $bm) {
    if ((string)($bm['board_office'] ?? '') === 'president') { $president = $bm; break; }
}

// Helper: ordinal suffix.
function ordinal(int $n): string {
    if ($n >= 11 && $n <= 13) return $n . 'th';
    return $n . match($n % 10) { 1=>'st',2=>'nd',3=>'rd', default=>'th'};
}

// Helper: format date as "NTH day of MONTH YEAR".
function long_date(string $d): string {
    $ts = strtotime($d);
    return ordinal((int)date('j', $ts)) . ' day of ' . date('F Y', $ts);
}

// Formatted meeting date/time.
$mDateLong = !empty($meeting['meeting_date']) ? udate('l, F j, Y', strtotime((string)$meeting['meeting_date'])) : '';
$mTime     = !empty($meeting['meeting_time'])  ? date('g:i A', strtotime((string)$meeting['meeting_time'])) : '';
$mTimeLong = $mTime ? $mDateLong . ', ' . $mTime : $mDateLong;

// Association display fields.
$assocName    = (string)($association['name'] ?? '');
$assocAddr    = trim((string)($association['address'] ?? ''));
$assocCity    = trim((string)($association['city'] ?? ''));
$assocState   = trim((string)($association['state_region'] ?? ''));
$assocZip     = trim((string)($association['postal_code'] ?? ''));
$assocPhone   = trim((string)($association['phone'] ?? ''));
$assocEmail   = trim((string)($association['contact_email'] ?? ''));
$assocAddrFull = implode(', ', array_filter([$assocAddr, $assocCity]));
if ($assocState || $assocZip) $assocAddrFull .= ', ' . trim("$assocState $assocZip");

$MEETING_TYPES = ['regular'=>'Regular Meeting','special'=>'Special Meeting','annual'=>'Annual Meeting','executive'=>'Executive Session'];
$CATEGORIES    = ['call_to_order'=>'Call to Order','proof_of_notice'=>'Proof of Notice','certify_quorum'=>'Certify a Quorum of Officers','approve_minutes'=>'Approve Minutes from Last Meeting','officers_report'=>"Officers' Report",'old_business'=>'Old Business','new_business'=>'New Business','motion_to_adjourn'=>'Motion to Adjourn','public_comments'=>'Public Comments / Open Forum','custom'=>null];
$STANDARD_CATS = ['call_to_order','proof_of_notice','certify_quorum','approve_minutes','motion_to_adjourn','public_comments'];

// Group agenda items: standard order, old_business group, new_business group.
$groupedAgenda = [];
foreach ($agendaItems as $ai) {
    if ($ai['status'] !== 'approved') continue;
    $groupedAgenda[] = $ai;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($assocName) ?> — <?= e($MEETING_TYPES[$meeting['meeting_type']] ?? 'Board Meeting') ?> — <?= e($mDateLong) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; background: #fff; margin: 0; padding: 0; }
.page { width: 7.5in; margin: 0 auto; padding: 0.75in 1in; }
.page + .page { page-break-before: always; }
h1, h2, h3, h4 { font-family: 'Times New Roman', Times, serif; }
.center { text-align: center; }
.bold { font-weight: bold; }
.underline { text-decoration: underline; }
.italic { font-style: italic; }
.small { font-size: 10pt; }
.page-title { font-size: 18pt; font-weight: bold; font-style: italic; margin: 18pt 0 14pt; }
.section-title { font-size: 11pt; font-weight: bold; margin: 10pt 0 4pt; }
p { margin: 0 0 8pt; line-height: 1.5; }
.sig-line { display: inline-block; border-bottom: 1px solid #000; width: 3in; vertical-align: bottom; }
.sig-block { margin-top: 36pt; }
.sig-row { margin-top: 10pt; }
.indent { text-indent: 0.5in; }
.notary-box { border: 2px solid #000; padding: 8pt 12pt; margin-top: 14pt; display: inline-block; min-width: 3in; font-size: 10pt; text-align: center; }
ol.agenda-list { padding-left: 1.5em; }
ol.agenda-list li { margin-bottom: 4pt; line-height: 1.4; }
ol.agenda-list li .sub { margin-left: 1.5em; list-style-type: disc; margin-top: 3pt; }
.divider { border: none; border-top: 1px solid #aaa; margin: 20pt 0; }
.resolution-block { margin-bottom: 24pt; page-break-inside: avoid; }
.resolution-block h3 { font-size: 12pt; font-weight: bold; text-transform: uppercase; margin-bottom: 6pt; }
.vote-row { display: flex; gap: 12pt; flex-wrap: wrap; margin-top: 4pt; font-size: 11pt; }
.vote-item { display: flex; gap: 4pt; align-items: baseline; }
.zoom-block { background: #f9f9f9; border: 1px solid #ccc; padding: 8pt 12pt; margin: 10pt 0; font-size: 11pt; }
.zoom-block a { color: #1a1aff; }
.participation-notice { font-size: 9pt; border-top: 1px solid #ccc; padding-top: 8pt; margin-top: 24pt; line-height: 1.4; }
@media print {
    @page { size: letter; margin: 0.75in 1in; }
    body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .page { width: 100%; padding: 0; margin: 0; }
    .no-print { display: none !important; }
}
.no-print { background: #f0f0f0; padding: 12px 20px; margin-bottom: 20px; border-bottom: 1px solid #ccc; font-family: sans-serif; font-size: 11pt; }
.no-print button { margin-right: 8px; padding: 6px 14px; cursor: pointer; }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">Print</button>
    <button onclick="window.close()">Close</button>
    &nbsp; Showing:
    <?php
    $typeLabels = ['proof'=>'Proof of Notice','notice'=>'Notice of Board Meeting','agenda'=>'Agenda','resolutions'=>'Resolutions','package'=>'Full Package'];
    echo e($typeLabels[$type] ?? $type);
    ?>
</div>

<?php

// ============================================================
// PROOF OF NOTICE AFFIDAVIT
// ============================================================
function render_proof(array $meeting, array $association, array $boardMembers, ?array $proofSigner,
                      string $assocName, string $assocAddrFull, string $assocPhone,
                      string $mTime, string $mTimeLong): void {
    global $MEETING_TYPES;
    $signerName   = $proofSigner ? trim($proofSigner['first_name'] . ' ' . $proofSigner['last_name']) : '___________________________';
    $signerTitle  = $proofSigner ? board_office_label((string)($proofSigner['board_office'] ?? '')) : 'President';
    if (!$signerTitle) $signerTitle = 'President';
    $proofState   = !empty($meeting['proof_state'])  ? (string)$meeting['proof_state']  : 'Florida';
    $proofCounty  = !empty($meeting['proof_county']) ? (string)$meeting['proof_county'] : '_______________';
    $signedDate   = !empty($meeting['proof_signed_date']) ? long_date((string)$meeting['proof_signed_date']) : '________ day of __________ ________';
    $notaryName   = !empty($meeting['proof_notary_name'])       ? (string)$meeting['proof_notary_name']       : '___________________________';
    $notaryComm   = !empty($meeting['proof_notary_commission']) ? (string)$meeting['proof_notary_commission'] : '____________';
    $notaryExp    = !empty($meeting['proof_notary_expires'])    ? date('n/j/Y', strtotime((string)$meeting['proof_notary_expires'])) : '__________';

    // Meeting date phrasing.
    $mDateLong = !empty($meeting['meeting_date']) ? udate('l, F j, Y', strtotime((string)$meeting['meeting_date'])) : '_______________';
    $location  = !empty($meeting['location']) ? (string)$meeting['location'] : '_______________';
    ?>
<div class="page">
    <div class="center">
        <div class="bold"><?= e(strtoupper($assocName)) ?></div>
        <?php if ($assocAddrFull): ?><div><?= e($assocAddrFull) ?></div><?php endif; ?>
        <?php if ($assocPhone): ?><div style="margin-top:6pt;">PHONE <?= e($assocPhone) ?></div><?php endif; ?>
    </div>

    <div class="center page-title">Proof of Notice Affidavit</div>

    <div class="section-title">STATE OF <?= e(strtoupper($proofState)) ?></div>
    <div class="section-title" style="margin-top:0;">COUNTY OF <?= e(strtoupper($proofCounty)) ?></div>

    <p style="margin-top:14pt;" class="indent">
        The undersigned <?= e($signerTitle) ?> of <?= e($assocName) ?>, being first duly sworn, deposes
        and says that the notice of the Board Meeting was posted in accordance with the requirements of
        Section 718.112(2)(d) Paragraph 2, F.S., at least 48 hours prior to the Board Meeting Date of
        <?= e($mDateLong) ?><?= $mTime ? ', at ' . e($mTime) : '' ?> at the <?= e($location) ?> of the
        <?= e($assocName) ?>.
    </p>

    <p>Please Note this meeting will be on the regular day of the week and usual time.</p>

    <p>Dated <?= e($signedDate) ?></p>

    <div class="sig-block">
        <div>By: <span class="sig-line">&nbsp;</span></div>
        <div class="sig-row" style="margin-left:2.4em;"><?= e($signerName) ?>, <?= e($signerTitle) ?></div>
    </div>

    <p style="margin-top:28pt;" class="indent">
        The foregoing Affidavit was acknowledged before me this <?= e($signedDate) ?>
        by <?= e($signerName) ?>, who is personally known to me and is <?= e($signerTitle) ?> of
        <?= e($assocName) ?> and who did not take an oath.
    </p>

    <div class="sig-block">
        <div><span class="sig-line">&nbsp;</span></div>
        <div class="sig-row"><?= e($notaryName) ?></div>
        <div>Notary Public</div>
        <div class="small" style="margin-top:4pt;">My Commission <?= e($notaryComm) ?> Expires <?= e($notaryExp) ?></div>
    </div>

    <div style="margin-top:20pt;">
        <div class="notary-box">
            Notary Public State of <?= e($proofState) ?><br>
            <?= e($notaryName) ?><br>
            <?php if (!empty($meeting['proof_notary_commission'])): ?>My Commission <?= e($notaryComm) ?><br><?php endif; ?>
            <?php if (!empty($meeting['proof_notary_expires'])): ?>Expires <?= e($notaryExp) ?><?php endif; ?>
            <br><small style="font-size:8pt;">[Affix notary seal here]</small>
        </div>
    </div>
</div>
<?php
}

// ============================================================
// NOTICE OF BOARD MEETING
// ============================================================
function render_notice(array $meeting, array $association, ?array $president,
                       string $assocName, string $assocAddrFull, string $assocPhone,
                       string $assocEmail, string $mTimeLong): void {
    global $MEETING_TYPES;
    $proofState  = !empty($meeting['proof_state'])  ? (string)$meeting['proof_state']  : 'Florida';
    $proofCounty = !empty($meeting['proof_county']) ? (string)$meeting['proof_county'] : '';
    $location    = !empty($meeting['location']) ? (string)$meeting['location'] : '';
    $vplat       = (string)($meeting['virtual_platform'] ?? 'none');
    $platNames   = ['zoom'=>'Zoom','google_meet'=>'Google Meet','teams'=>'Microsoft Teams','webex'=>'Webex','other'=>'Online'];
    $platLabel   = $platNames[$vplat] ?? 'Online';
    $presName    = $president ? trim($president['first_name'] . ' ' . $president['last_name']) : '';
    $presTitle   = $president ? (board_office_label((string)($president['board_office'] ?? '')) ?: 'President') : 'President';

    $signedDate = !empty($meeting['proof_signed_date'])
        ? long_date((string)$meeting['proof_signed_date'])
        : (!empty($meeting['meeting_date']) ? long_date((string)$meeting['meeting_date']) : '________ day of __________ ________');
    ?>
<div class="page">
    <div class="center">
        <div class="bold" style="font-size:13pt;"><?= e($assocName) ?></div>
        <div style="margin-top:6pt;"><?= e($assocAddrFull) ?></div>
        <div style="margin-top:4pt;">
            <?php if ($assocPhone): ?>Phone <?= e($assocPhone) ?><?php endif; ?>
            <?php if ($assocPhone && $assocEmail): ?>    <?php endif; ?>
            <?php if ($assocEmail): ?>Email <?= e($assocEmail) ?><?php endif; ?>
        </div>
    </div>

    <div class="center" style="margin-top:18pt;">
        <div class="bold underline" style="font-size:13pt;">NOTICE OF BOARD MEETING</div>
        <div style="margin-top:8pt;" class="bold">Date: <?= e($mTimeLong) ?></div>
        <?php if ($location): ?><div class="bold">Location: <?= e($location) ?></div><?php endif; ?>
    </div>

    <div style="margin-top:14pt;">
        <div class="section-title">State of <?= e($proofState) ?></div>
        <?php if ($proofCounty): ?><div class="section-title" style="margin-top:0;">County of <?= e($proofCounty) ?></div><?php endif; ?>
    </div>

    <?php if ($vplat !== 'none' && !empty($meeting['virtual_url'])): ?>
    <div class="zoom-block" style="margin-top:14pt;">
        <div class="bold"><?= e($platLabel) ?> info</div>
        <?php if ($vplat === 'zoom'): ?>
            <div><?= e($assocName) ?> is inviting you to a scheduled <?= e($platLabel) ?> meeting.</div>
        <?php endif; ?>
        <div style="margin-top:6pt;"><span class="bold">Topic:</span> <?= e((string)$meeting['title']) ?></div>
        <?php if (!empty($meeting['meeting_date']) && !empty($meeting['meeting_time'])): ?>
            <div><span class="bold">Time:</span> <?= e($mTimeLong) ?> Eastern Time (US and Canada)</div>
        <?php endif; ?>
        <div style="margin-top:6pt;">
            Join <?= e($platLabel) ?> Meeting<br>
            <a href="<?= e((string)$meeting['virtual_url']) ?>"><?= e((string)$meeting['virtual_url']) ?></a>
        </div>
        <?php if (!empty($meeting['virtual_meeting_id'])): ?>
            <div style="margin-top:4pt;"><span class="bold">Meeting ID:</span> <?= e((string)$meeting['virtual_meeting_id']) ?></div>
        <?php endif; ?>
        <?php if (!empty($meeting['virtual_passcode'])): ?>
            <div><span class="bold">Passcode:</span> <?= e((string)$meeting['virtual_passcode']) ?></div>
        <?php endif; ?>
        <?php if (!empty($meeting['virtual_phone_numbers'])): ?>
            <div style="margin-top:6pt;"><span class="bold">One tap mobile:</span><br>
            <?php foreach (explode("\n", trim((string)$meeting['virtual_phone_numbers'])) as $ph): ?>
                <?php if (trim($ph)): ?><?= e(trim($ph)) ?><br><?php endif; ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($meeting['virtual_sip'])): ?>
            <div style="margin-top:4pt;"><span class="bold">Join by SIP:</span> <?= e((string)$meeting['virtual_sip']) ?></div>
        <?php endif; ?>
        <?php if (!empty($meeting['virtual_notes'])): ?>
            <div style="margin-top:6pt;"><?= e((string)$meeting['virtual_notes']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sig-block" style="margin-top:32pt;">
        <div><span class="sig-line">&nbsp;</span></div>
        <?php if ($presName): ?>
            <div class="sig-row"><?= e($presName) ?>, <?= e($presTitle) ?></div>
        <?php else: ?>
            <div class="sig-row">_____________________________, <?= e($presTitle) ?></div>
        <?php endif; ?>
        <div style="margin-top:8pt;">Dated this <?= e($signedDate) ?></div>
    </div>

    <div class="participation-notice">
        Please allow the Board to conduct HOA business. The Board President will preside over the meeting and
        recognize Board members and those attending when they can address the meeting. President may move agenda
        items to fit the Schedule and or Guests. Comment on Agenda items may have three minutes per speaker at
        Board's direction. Open Forum will have a Three Minute Presentation time after adjournment of meeting.
        Thank you for your cooperation.
    </div>
</div>
<?php
}

// ============================================================
// AGENDA
// ============================================================
function render_agenda(array $meeting, array $groupedAgenda, ?array $president,
                       string $assocName, string $assocAddrFull, string $assocPhone,
                       string $assocEmail, string $mTimeLong): void {
    global $CATEGORIES, $STANDARD_CATS;
    $presName  = $president ? trim($president['first_name'] . ' ' . $president['last_name']) : '';
    $presTitle = $president ? (board_office_label((string)($president['board_office'] ?? '')) ?: 'President') : 'President';
    $location  = !empty($meeting['location']) ? (string)$meeting['location'] : '';

    $signedDate = !empty($meeting['proof_signed_date'])
        ? long_date((string)$meeting['proof_signed_date'])
        : (!empty($meeting['meeting_date']) ? long_date((string)$meeting['meeting_date']) : '________ day of __________ ________');

    // Build numbered list: flat items; old_business and new_business items get a section header
    // inserted before the first item of that category.
    $seenOldBiz = false;
    $seenNewBiz = false;
    $numbered   = []; // ['type'=>'section'|'item', 'label'=>..., 'title'=>..., 'desc'=>...]
    foreach ($groupedAgenda as $ai) {
        $cat = (string)$ai['category'];
        if ($cat === 'old_business' && !$seenOldBiz) {
            $numbered[] = ['type' => 'section', 'label' => 'Old Business'];
            $seenOldBiz = true;
        }
        if ($cat === 'new_business' && !$seenNewBiz) {
            $numbered[] = ['type' => 'section', 'label' => 'New Business'];
            $seenNewBiz = true;
        }
        $catLabel = $CATEGORIES[$cat] ?? null;
        $title    = in_array($cat, $STANDARD_CATS, true) ? ($catLabel ?? (string)$ai['title']) : (string)$ai['title'];
        $numbered[] = ['type' => 'item', 'title' => $title, 'desc' => (string)($ai['description'] ?? '')];
    }
    ?>
<div class="page">
    <div class="center">
        <div class="bold" style="font-size:13pt;"><?= e($assocName) ?></div>
        <?php if ($assocAddrFull): ?><div><?= e($assocAddrFull) ?></div><?php endif; ?>
        <?php if ($assocPhone || $assocEmail): ?>
        <div style="margin-top:4pt;">
            <?php if ($assocPhone): ?>Phone <?= e($assocPhone) ?><?php endif; ?>
            <?php if ($assocPhone && $assocEmail): ?>    <?php endif; ?>
            <?php if ($assocEmail): ?>Email <?= e($assocEmail) ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:10pt;" class="bold underline">NOTICE OF BOARD MEETING</div>
        <div style="margin-top:6pt;" class="bold">Date: <?= e($mTimeLong) ?></div>
        <?php if ($location): ?><div class="bold">Location: <?= e($location) ?></div><?php endif; ?>
    </div>

    <div style="margin-top:18pt;">
        <ol class="agenda-list">
        <?php $num = 0; foreach ($numbered as $entry): ?>
            <?php if ($entry['type'] === 'section'): ?>
                </ol><div style="margin:10pt 0 4pt;font-weight:bold;"><?= e($entry['label']) ?></div><ol class="agenda-list" start="<?= $num + 1 ?>">
            <?php else: $num++; ?>
                <li><?= e($entry['title']) ?>
                    <?php if ($entry['desc']): ?>
                        <div style="font-size:10pt;color:#444;margin-top:2pt;"><?= e($entry['desc']) ?></div>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        </ol>
    </div>

    <div class="sig-block" style="margin-top:32pt;">
        <span class="sig-line">&nbsp;</span>
        <span style="margin-left:16pt;">President signoff by <?= e($presName ?: '_____________________') ?></span>
        <div style="margin-top:8pt;">Dated this <?= e($signedDate) ?></div>
    </div>

    <div class="participation-notice">
        Please allow the Board to conduct HOA business. The Board President will preside over the meeting and
        recognize Board members and those attending when they can address the meeting. President may move agenda
        items to fit the Schedule and or Guests. Comment on Agenda items may have three minutes per speaker at
        Board's direction. Open Forum will have a Three Minute Presentation time after adjournment of meeting.
        Thank you for your cooperation.
    </div>
</div>
<?php
}

// ============================================================
// RESOLUTIONS
// ============================================================
function render_resolutions(array $meeting, array $resolutions, array $allVotes, array $boardMembers,
                             string $assocName, string $mDateLong): void {
    if (!$resolutions) {
        echo '<div class="page"><p style="color:#999;">No resolutions recorded for this meeting.</p></div>';
        return;
    }
    $RESULT_LABELS = ['pending'=>'Pending','passed'=>'PASSED','failed'=>'FAILED','tabled'=>'TABLED','withdrawn'=>'WITHDRAWN'];
    ?>
<div class="page">
    <div class="center">
        <div class="bold" style="font-size:14pt;">BOARD MEETING RESOLUTIONS</div>
        <div style="margin-top:4pt;"><?= e($assocName) ?></div>
        <div><?= e($mDateLong) ?></div>
    </div>
    <hr class="divider">
    <?php foreach ($resolutions as $ri => $res): ?>
    <div class="resolution-block">
        <h3>Resolution <?= $ri + 1 ?>: <?= e((string)$res['title']) ?></h3>
        <?php
        $clauses = array_values(array_filter(array_map('trim', explode("\n", (string)($res['body_text'] ?? '')))));
        if ($clauses):
            foreach ($clauses as $ci => $clause): ?>
            <p class="indent">
                <?php if (count($clauses) > 1): ?><strong><?= $ci + 1 ?>.</strong> <?php endif; ?>
                <strong>BE IT RESOLVED THAT</strong> <?= e($clause) ?>
            </p>
        <?php endforeach; endif; ?>
        <p style="margin-top:8pt;">
            <strong>Moved by:</strong> <?= $res['mover_name'] ? e(trim((string)$res['mover_name']) . ($res['mover_office'] ? ', ' . e(board_office_label((string)$res['mover_office'])) : '')) : '___________________________' ?>
        </p>
        <p>
            <strong>Seconded by:</strong> <?= $res['seconder_name'] ? e(trim((string)$res['seconder_name']) . ($res['seconder_office'] ? ', ' . e(board_office_label((string)$res['seconder_office'])) : '')) : '___________________________' ?>
        </p>
        <?php
        $votes = $allVotes[(int)$res['id']] ?? [];
        if ($votes):
            $yes = 0; $no = 0; $abs = 0; $notPres = 0; $na = 0;
            foreach ($votes as $v) {
                if ($v['vote']==='yes') $yes++;
                elseif ($v['vote']==='no') $no++;
                elseif ($v['vote']==='abstain') $abs++;
                elseif ($v['vote']==='not_present') $notPres++;
                else $na++;
            }
        ?>
        <div style="margin-top:6pt;"><strong>Vote:</strong></div>
        <div class="vote-row">
            <?php foreach ($votes as $v): ?>
            <div class="vote-item">
                <?= e(trim((string)$v['voter_name'])) ?>
                <?php if (!empty($v['board_office'])): ?> (<?= e(board_office_label((string)$v['board_office'])) ?>)<?php endif; ?>
                — <strong><?= e(match($v['vote']) { 'yes'=>'YES','no'=>'NO','abstain'=>'ABSTAIN','not_present'=>'NOT PRESENT','na'=>'N/A',default=>strtoupper($v['vote']) }) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top:6pt;">
            <strong>Tally:</strong> <?= $yes ?> yes — <?= $no ?> no — <?= $abs ?> abstain<?= $notPres ? ' — '.$notPres.' not present' : '' ?><?= $na ? ' — '.$na.' N/A' : '' ?>
        </p>
        <?php endif; ?>
        <?php
        $rcolor = match((string)$res['result']) {
            'passed' => 'color:#155724', 'failed' => 'color:#721c24', default => 'color:#856404'
        };
        ?>
        <p style="margin-top:6pt;">
            <strong>Result:</strong>
            <span style="<?= $rcolor ?>">
                <?= e($RESULT_LABELS[(string)$res['result']] ?? strtoupper((string)$res['result'])) ?>
            </span>
        </p>
        <?php if (!empty($res['result_notes'])): ?>
            <p><em><?= e((string)$res['result_notes']) ?></em></p>
        <?php endif; ?>
        <?php if ($ri + 1 < count($resolutions)): ?><hr class="divider"><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php
}

// ============================================================
// RENDER BASED ON TYPE
// ============================================================
if ($type === 'proof' || $type === 'package') {
    render_proof($meeting, $association, $boardMembers, $proofSigner,
                 $assocName, $assocAddrFull, $assocPhone, $mTime, $mTimeLong);
}
if ($type === 'notice' || $type === 'package') {
    render_notice($meeting, $association, $president,
                  $assocName, $assocAddrFull, $assocPhone, $assocEmail, $mTimeLong);
}
if ($type === 'agenda' || $type === 'package') {
    render_agenda($meeting, $groupedAgenda, $president,
                  $assocName, $assocAddrFull, $assocPhone, $assocEmail, $mTimeLong);
}
if ($type === 'resolutions' || $type === 'package') {
    render_resolutions($meeting, $resolutions, $allVotes, $boardMembers, $assocName, $mDateLong);
}
?>

</body>
</html>

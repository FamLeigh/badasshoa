<?php
// Print-ready violation notice. Opens as a standalone page (no nav) and
// auto-fires window.print(). Pass ?notice_id=N.
require __DIR__ . '/_bootstrap.php';

$noticeId = (int)($_GET['notice_id'] ?? 0);
if (!$noticeId) { http_response_code(400); echo 'Missing notice_id.'; exit; }

$stmt = db()->prepare(
    "SELECT n.*,
            v.association_id, v.violation_type, v.description, v.status AS v_status,
            v.unit_id, v.user_id, v.rule_id,
            un.unit_number,
            TRIM(CONCAT(IFNULL(res.first_name,''), ' ', IFNULL(res.last_name,''))) AS resident_name,
            res.email AS resident_email,
            r.rule_number, r.title AS rule_title, r.source AS rule_source,
            TRIM(CONCAT(IFNULL(iss.first_name,''), ' ', IFNULL(iss.last_name,''))) AS issuer_name,
            iss.board_office AS issuer_office, iss.role AS issuer_role
       FROM violation_notices n
       JOIN violations v  ON v.id  = n.violation_id
       LEFT JOIN units un ON un.id = v.unit_id
       LEFT JOIN users res ON res.id = v.user_id
       LEFT JOIN rules r   ON r.id  = v.rule_id
       LEFT JOIN users iss ON iss.id = n.issued_by
      WHERE n.id = ? AND v.association_id = ?"
);
$stmt->execute([$noticeId, $assocId]);
$notice = $stmt->fetch();
if (!$notice) { http_response_code(404); echo 'Notice not found.'; exit; }

// Board officer for the signature block: prefer the issuer if they are board;
// otherwise fall back to the board_admin of this association.
$signerName   = trim((string)$notice['issuer_name']);
$signerOffice = '';
if (!empty($notice['issuer_office'])) {
    $officeMap = [
        'president'         => 'President',
        'vice_president'    => 'Vice President',
        'secretary'         => 'Secretary',
        'treasurer'         => 'Treasurer',
        'director'          => 'Director',
    ];
    $signerOffice = $officeMap[$notice['issuer_office']] ?? ucwords(str_replace('_',' ',(string)$notice['issuer_office']));
} elseif (!empty($notice['issuer_role'])) {
    $signerOffice = ucwords(str_replace('_',' ',(string)$notice['issuer_role']));
}

// If issuer is not a board member, also pull the board_admin as the authorising officer.
$boardAdmin = null;
if (!in_array($notice['issuer_role'], ['board_admin','board_member','property_manager'], true)) {
    $ba = db()->prepare(
        "SELECT first_name, last_name, board_office FROM users
          WHERE association_id = ? AND role = 'board_admin' AND status = 'active'
          ORDER BY id LIMIT 1"
    );
    $ba->execute([$assocId]);
    $boardAdmin = $ba->fetch() ?: null;
}

$VTYPES = [
    'noise_disturbance'        => 'Noise / Disturbance',
    'parking_violation'        => 'Parking Violation',
    'pet_violation'            => 'Pet Violation',
    'unauthorized_modification'=> 'Unauthorized Modification',
    'lease_violation'          => 'Lease Violation',
    'common_area_misuse'       => 'Common Area Misuse',
    'maintenance_cleanliness'  => 'Maintenance / Cleanliness',
    'rule_violation'           => 'Rule Violation',
    'other'                    => 'Other',
];

$NOTICE_LABELS = [
    'warning' => 'Warning Notice',
    'cure'    => 'Notice to Cure',
    'fine'    => 'Notice of Fine',
    'hearing' => 'Notice of Hearing',
];

$noticeLabel = $NOTICE_LABELS[$notice['notice_type']] ?? ucwords(str_replace('_',' ',(string)$notice['notice_type']));
$vtypeLabel  = $VTYPES[$notice['violation_type']] ?? ucwords(str_replace('_',' ',(string)$notice['violation_type']));
$unitLabel   = !empty($notice['unit_number']) ? 'Unit ' . $notice['unit_number'] : '';
$issueDate   = udate('F j, Y', strtotime((string)$notice['issued_at']));

$recipientName = trim((string)$notice['resident_name']);
$recipientLine = implode(' — ', array_filter([$recipientName ?: null, $unitLabel ?: null]));
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title><?= e($noticeLabel) ?> — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.75in; }
    * { box-sizing: border-box; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; padding: 0; line-height: 1.55; font-size: 11pt; }
    .page { max-width: 7in; margin: 0 auto; padding: 0.5in; }

    /* Letterhead */
    .lh { display: flex; align-items: center; gap: 18pt; padding-bottom: 14pt; border-bottom: 2.5pt solid #0f1f3d; margin-bottom: 20pt; }
    .lh img { height: 60pt; max-width: 130pt; object-fit: contain; flex: 0 0 auto; }
    .lh-text { flex: 1; line-height: 1.3; }
    .lh-name { font-size: 15pt; font-weight: 800; color: #0f1f3d; }
    .lh-addr { font-size: 9pt; color: #4a5060; margin-top: 3pt; }
    .lh-contact { font-size: 9pt; color: #4a5060; }

    /* Notice heading */
    .notice-type { display: inline-block; background: #0f1f3d; color: #fff; font-size: 9pt; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 3pt 10pt; border-radius: 4pt; margin-bottom: 10pt; }
    .notice-type.warning { background: #a8782a; }
    .notice-type.cure    { background: #1f4f9c; }
    .notice-type.fine    { background: #a8322a; }
    .notice-type.hearing { background: #5a3a8a; }

    h1 { font-size: 17pt; font-weight: 800; color: #0f1f3d; margin: 0 0 6pt; }

    /* Meta box */
    .meta-box { border: 1pt solid #ccc; border-radius: 4pt; padding: 10pt 14pt; margin: 16pt 0; font-size: 10pt; line-height: 1.6; }
    .meta-box table { width: 100%; border-collapse: collapse; }
    .meta-box td { vertical-align: top; }
    .meta-box td:first-child { color: #4a5060; width: 120pt; font-weight: 600; }

    /* Fine callout */
    .fine-box { border: 2pt solid #a8322a; border-radius: 4pt; padding: 10pt 16pt; margin: 16pt 0; text-align: center; }
    .fine-box .fine-label { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.08em; color: #a8322a; font-weight: 700; }
    .fine-box .fine-amount { font-size: 22pt; font-weight: 800; color: #a8322a; }
    .fine-box .fine-due { font-size: 9pt; color: #4a5060; margin-top: 2pt; }

    /* Cure deadline callout */
    .cure-box { border: 2pt solid #1f4f9c; border-radius: 4pt; padding: 8pt 14pt; margin: 16pt 0; display: flex; align-items: center; gap: 14pt; }
    .cure-box .cure-label { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #1f4f9c; font-weight: 700; white-space: nowrap; }
    .cure-box .cure-date { font-size: 14pt; font-weight: 800; color: #1f4f9c; }

    /* Body */
    .body-text { white-space: pre-wrap; font-size: 11pt; line-height: 1.6; margin: 0; }

    /* Signature */
    .sig { margin-top: 32pt; }
    .sig-line { border-bottom: 1pt solid #333; width: 240pt; margin-bottom: 4pt; height: 28pt; }
    .sig-name { font-size: 10pt; color: #333; }
    .sig-office { font-size: 9pt; color: #4a5060; }
    .sig-assoc { font-size: 9pt; color: #4a5060; margin-top: 2pt; }

    /* Footer */
    .print-foot { margin-top: 28pt; padding-top: 8pt; border-top: 1pt solid #d9d3c5; color: #6b7280; font-size: 7.5pt; text-align: center; }

    /* Reference block */
    .ref-block { font-size: 8.5pt; color: #4a5060; margin: 14pt 0 4pt; }

    @media print {
        a { color: inherit; text-decoration: none; }
        .page { padding: 0; }
    }
</style>
</head><body>
<div class="page">

<?= print_header_html($association) ?>

<!-- Notice type pill + heading -->
<div>
    <div class="notice-type <?= e((string)$notice['notice_type']) ?>"><?= e($noticeLabel) ?></div>
    <h1><?= e((string)$association['name']) ?></h1>
    <div style="font-size: 10pt; color: #4a5060; margin-bottom: 6pt;">
        Violation Notice #<?= (int)$notice['violation_id'] ?> — Notice #<?= (int)$notice['id'] ?>
    </div>
</div>

<!-- Meta box -->
<div class="meta-box">
    <table>
        <tr><td>Date issued:</td><td><?= e($issueDate) ?></td></tr>
        <?php if ($recipientLine): ?>
            <tr><td>Issued to:</td><td><?= e($recipientLine) ?></td></tr>
        <?php endif; ?>
        <tr><td>Violation type:</td><td><?= e($vtypeLabel) ?></td></tr>
        <?php if (!empty($notice['rule_title'])): ?>
            <tr><td>Rule cited:</td><td>
                <?= !empty($notice['rule_number']) ? e('#' . $notice['rule_number'] . ' — ') : '' ?><?= e((string)$notice['rule_title']) ?>
                <?= !empty($notice['rule_source']) ? ' <span style="color:#888;">(' . e((string)$notice['rule_source']) . ')</span>' : '' ?>
            </td></tr>
        <?php endif; ?>
        <?php if (!empty($notice['due_date'])): ?>
            <tr><td>Due / Cure by:</td><td><strong><?= e(udate('F j, Y', strtotime((string)$notice['due_date']))) ?></strong></td></tr>
        <?php endif; ?>
    </table>
</div>

<?php if (!empty($notice['fine_amount_cents']) && (string)$notice['notice_type'] === 'fine'): ?>
<!-- Fine callout -->
<div class="fine-box">
    <div class="fine-label">Fine amount levied</div>
    <div class="fine-amount">$<?= number_format((int)$notice['fine_amount_cents'] / 100, 2) ?></div>
    <?php if (!empty($notice['due_date'])): ?>
        <div class="fine-due">Due by <?= e(udate('F j, Y', strtotime((string)$notice['due_date']))) ?></div>
    <?php endif; ?>
</div>
<?php elseif (!empty($notice['due_date']) && in_array($notice['notice_type'], ['cure','hearing'], true)): ?>
<!-- Cure / Hearing deadline callout -->
<div class="cure-box">
    <div>
        <div class="cure-label"><?= $notice['notice_type'] === 'hearing' ? 'Hearing date' : 'Cure deadline' ?></div>
        <div class="cure-date"><?= e(udate('F j, Y', strtotime((string)$notice['due_date']))) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- Body -->
<pre class="body-text"><?= e((string)$notice['body_text']) ?></pre>

<!-- Signature block -->
<div class="sig">
    <div class="sig-line"></div>
    <?php if ($signerName !== ''): ?>
        <div class="sig-name"><?= e($signerName) ?></div>
        <?php if ($signerOffice !== ''): ?><div class="sig-office"><?= e($signerOffice) ?></div><?php endif; ?>
    <?php elseif ($boardAdmin): ?>
        <div class="sig-name"><?= e(trim($boardAdmin['first_name'] . ' ' . $boardAdmin['last_name'])) ?></div>
        <?php if (!empty($boardAdmin['board_office'])): ?>
            <div class="sig-office"><?= e(ucwords(str_replace('_',' ',(string)$boardAdmin['board_office']))) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="sig-name">Authorized Signature</div>
    <?php endif; ?>
    <div class="sig-assoc"><?= e((string)$association['name']) ?> — Board of Directors</div>
</div>

<!-- Reference -->
<div class="ref-block">
    Notice issued via BadassHOA · <?= e($issueDate) ?> · Violation #<?= (int)$notice['violation_id'] ?> / Notice #<?= (int)$notice['id'] ?>
    <?php if (!empty($association['contact_email'])): ?> · <?= e((string)$association['contact_email']) ?><?php endif; ?>
</div>

<?= print_footer_html('Printed ' . udate('M j, Y')) ?>

</div><!-- .page -->
<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

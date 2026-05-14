<?php
// Violation tracking — board + property manager only.
// Status workflow: open → notice_sent → cured | escalated → fined → closed
// Each issued notice (warning / cure / fine / hearing) is a row in violation_notices.
require __DIR__ . '/_bootstrap.php';
require_login();

$user = current_user();
$canManageVio = role_can_manage(viewing_role());

if (!$canManageVio && !can_do('read_violations')) {
    http_response_code(403);
    $page_title = 'Violations';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="padding: var(--sp-8) var(--sp-6);"><div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);"><p class="muted">Violation records are restricted. Contact the board for access.</p></div></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$flashError = null;

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

$STATUSES = [
    'open'        => ['label' => 'Open',        'cls' => 'badge--warning'],
    'notice_sent' => ['label' => 'Notice sent',  'cls' => 'badge--info'],
    'cured'       => ['label' => 'Cured',        'cls' => 'badge--success'],
    'escalated'   => ['label' => 'Escalated',    'cls' => 'badge--error'],
    'fined'       => ['label' => 'Fined',        'cls' => 'badge--orange'],
    'closed'      => ['label' => 'Closed',       'cls' => ''],
];

$NOTICE_TYPES = [
    'warning' => 'Warning',
    'cure'    => 'Cure Notice',
    'fine'    => 'Fine Notice',
    'hearing' => 'Hearing Notice',
];

// --- Create new violation -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManageVio) { http_response_code(403); die('Forbidden'); }
    $vtype     = $_POST['violation_type'] ?? 'rule_violation';
    $desc      = trim((string)($_POST['description'] ?? ''));
    $unitId    = ($_POST['unit_id']   ?? '') !== '' ? (int)$_POST['unit_id']   : null;
    $userId    = ($_POST['user_id']   ?? '') !== '' ? (int)$_POST['user_id']   : null;
    $ruleId    = ($_POST['rule_id']   ?? '') !== '' ? (int)$_POST['rule_id']   : null;
    $concernId = ($_POST['concern_id']?? '') !== '' ? (int)$_POST['concern_id']: null;

    if (!array_key_exists($vtype, $VTYPES)) $vtype = 'rule_violation';

    // Validate FK ownership
    if ($unitId) {
        $chk = db()->prepare('SELECT 1 FROM units WHERE id = ? AND association_id = ?');
        $chk->execute([$unitId, $assocId]);
        if (!$chk->fetchColumn()) $unitId = null;
    }
    if ($userId) {
        $chk = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $chk->execute([$userId, $assocId]);
        if (!$chk->fetchColumn()) $userId = null;
    }
    if ($ruleId) {
        $chk = db()->prepare('SELECT 1 FROM rules WHERE id = ? AND association_id = ?');
        $chk->execute([$ruleId, $assocId]);
        if (!$chk->fetchColumn()) $ruleId = null;
    }
    if ($concernId) {
        $chk = db()->prepare('SELECT 1 FROM concerns WHERE id = ? AND association_id = ?');
        $chk->execute([$concernId, $assocId]);
        if (!$chk->fetchColumn()) $concernId = null;
    }

    if ($desc === '') {
        $flashError = 'Description is required.';
    } else {
        db()->prepare(
            'INSERT INTO violations
                (association_id, unit_id, user_id, concern_id, rule_id, violation_type, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, $unitId, $userId, $concernId, $ruleId, $vtype, $desc, (int)$user['id']]);
        $newId = (int)db()->lastInsertId();
        audit('violation.created', [
            'type' => $vtype, 'unit_id' => $unitId, 'user_id' => $userId, 'concern_id' => $concernId,
        ], $newId, 'violation');
        if ($concernId) {
            audit('concern.converted_to_violation', ['violation_id' => $newId], $concernId, 'concern');
        }
        flash('success', 'Violation recorded.');
        redirect('/dashboard/violations.php?id=' . $newId);
    }
}

// --- Issue a notice -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'issue_notice') {
    csrf_check();
    if (!$canManageVio) { http_response_code(403); die('Forbidden'); }
    $vid        = (int)($_POST['id'] ?? 0);
    $ntype      = $_POST['notice_type'] ?? 'warning';
    $dueDate    = trim((string)($_POST['due_date'] ?? ''));
    $fineRaw    = trim((string)($_POST['fine_amount'] ?? ''));
    $bodyText   = trim((string)($_POST['body_text'] ?? ''));

    if (!array_key_exists($ntype, $NOTICE_TYPES)) $ntype = 'warning';
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $dueDate = '';
    $fineCents = ($fineRaw !== '' && $ntype === 'fine') ? (int)round((float)$fineRaw * 100) : null;

    $stmt = db()->prepare('SELECT * FROM violations WHERE id = ? AND association_id = ?');
    $stmt->execute([$vid, $assocId]);
    $vrow = $stmt->fetch();
    if (!$vrow) { http_response_code(404); die('Violation not found'); }

    if ($bodyText === '') {
        $flashError = 'Notice body is required.';
    } else {
        db()->prepare(
            'INSERT INTO violation_notices (violation_id, notice_type, due_date, fine_amount_cents, body_text, issued_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$vid, $ntype, $dueDate ?: null, $fineCents, $bodyText, (int)$user['id']]);
        $newNoticeId = (int)db()->lastInsertId();

        // Advance violation status
        $newStatus = match ($ntype) {
            'fine'    => 'fined',
            'hearing' => 'escalated',
            default   => 'notice_sent',
        };
        db()->prepare('UPDATE violations SET status = ? WHERE id = ?')->execute([$newStatus, $vid]);

        audit('violation.notice_issued', [
            'notice_id' => $newNoticeId, 'notice_type' => $ntype, 'new_status' => $newStatus,
        ], $vid, 'violation');
        flash('success', $NOTICE_TYPES[$ntype] . ' issued.');
        redirect('/dashboard/violations.php?id=' . $vid);
    }
}

// --- Update status --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    if (!$canManageVio) { http_response_code(403); die('Forbidden'); }
    $vid    = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'open';
    if (!array_key_exists($status, $STATUSES)) $status = 'open';

    $chk = db()->prepare('SELECT 1 FROM violations WHERE id = ? AND association_id = ?');
    $chk->execute([$vid, $assocId]);
    if (!$chk->fetchColumn()) { http_response_code(404); die('Violation not found'); }

    db()->prepare('UPDATE violations SET status = ? WHERE id = ?')->execute([$status, $vid]);
    audit('violation.status_changed', ['status' => $status], $vid, 'violation');
    flash('success', 'Status updated to ' . $STATUSES[$status]['label'] . '.');
    redirect('/dashboard/violations.php?id=' . $vid);
}

// --- View routing ---------------------------------------------------------
$detailId = (int)($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';

// Picker data (new form + detail issue-notice form)
$units = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
$units->execute([$assocId]);
$units = $units->fetchAll();

$members = db()->prepare(
    "SELECT id, first_name, last_name, unit_number FROM users
      WHERE association_id = ? AND status <> 'inactive' ORDER BY last_name, first_name"
);
$members->execute([$assocId]);
$members = $members->fetchAll();

$rules = db()->prepare('SELECT id, rule_number, title, source FROM rules WHERE association_id = ? ORDER BY CAST(rule_number AS UNSIGNED), rule_number');
$rules->execute([$assocId]);
$rules = $rules->fetchAll();

// Detail
$detail  = null;
$notices = [];
if ($detailId > 0) {
    $stmt = db()->prepare(
        "SELECT v.*,
                TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS violator_name,
                u.email  AS violator_email,
                u.unit_number AS violator_unit,
                un.unit_number AS unit_number_direct,
                r.rule_number, r.title AS rule_title, r.source AS rule_source,
                c.subject AS concern_subject
           FROM violations v
           LEFT JOIN users u   ON u.id   = v.user_id
           LEFT JOIN units un  ON un.id  = v.unit_id
           LEFT JOIN rules r   ON r.id   = v.rule_id
           LEFT JOIN concerns c ON c.id  = v.concern_id
          WHERE v.id = ? AND v.association_id = ?"
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;

    if ($detail) {
        $nStmt = db()->prepare(
            "SELECT n.*,
                    TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS issuer_name
               FROM violation_notices n
               LEFT JOIN users u ON u.id = n.issued_by
              WHERE n.violation_id = ?
              ORDER BY n.issued_at DESC"
        );
        $nStmt->execute([$detailId]);
        $notices = $nStmt->fetchAll();
    }
}

// New form: pre-fill from concern
$prefillConcern = null;
if ($action === 'new' && ($cid = (int)($_GET['from_concern'] ?? 0)) > 0) {
    $stmt = db()->prepare(
        'SELECT c.id, c.subject, c.body, c.type, c.target_unit_id, c.target_user_id,
                u.unit_number AS target_unit_number,
                TRIM(CONCAT(IFNULL(p.first_name,""), " ", IFNULL(p.last_name,""))) AS target_name
           FROM concerns c
           LEFT JOIN units u ON u.id = c.target_unit_id
           LEFT JOIN users p ON p.id = c.target_user_id
          WHERE c.id = ? AND c.association_id = ?'
    );
    $stmt->execute([$cid, $assocId]);
    $prefillConcern = $stmt->fetch() ?: null;
}

// List
$listRows      = [];
$countByStatus = [];
if (!$detail && $action === '') {
    $statusFilter = $_GET['status'] ?? 'open';
    if (!array_key_exists($statusFilter, $STATUSES) && $statusFilter !== 'all') $statusFilter = 'open';

    $sql = "SELECT v.*,
                   un.unit_number,
                   TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS violator_name
              FROM violations v
              LEFT JOIN units un ON un.id = v.unit_id
              LEFT JOIN users u  ON u.id  = v.user_id
             WHERE v.association_id = ?";
    $params = [$assocId];
    if ($statusFilter !== 'all') { $sql .= ' AND v.status = ?'; $params[] = $statusFilter; }
    $sql .= ' ORDER BY v.updated_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listRows = $stmt->fetchAll();

    $cStmt = db()->prepare('SELECT status, COUNT(*) AS n FROM violations WHERE association_id = ? GROUP BY status');
    $cStmt->execute([$assocId]);
    foreach ($cStmt->fetchAll() as $r) $countByStatus[$r['status']] = (int)$r['n'];
}

// --- Build per-notice-type body templates (passed to JS) ------------------
// These depend on the current $detail row; only needed on the detail page.
function violation_notice_templates(array $d, array $assoc): array
{
    $unitLabel  = trim((string)($d['unit_number_direct'] ?? $d['violator_unit'] ?? ''));
    $unitLine   = $unitLabel !== '' ? "Unit {$unitLabel}" : 'your unit';
    $vioLabel   = ucwords(str_replace('_', ' ', (string)$d['violation_type']));
    $ruleRef    = '';
    if (!empty($d['rule_number']) && !empty($d['rule_title'])) {
        $ruleRef = "Rule #{$d['rule_number']} — {$d['rule_title']}";
    } elseif (!empty($d['rule_title'])) {
        $ruleRef = (string)$d['rule_title'];
    }
    $desc       = (string)$d['description'];
    $assocName  = (string)$assoc['name'];
    $addrParts  = array_filter([
        $assoc['address']      ?? null,
        $assoc['city']         ?? null,
        $assoc['state_region'] ?? null,
        $assoc['postal_code']  ?? null,
    ]);
    $assocAddr  = implode(', ', $addrParts);

    $ruleBlock = $ruleRef !== '' ? "Cited rule:  {$ruleRef}\n" : '';

    return [
        'warning' =>
            "Dear Resident of {$unitLine},\n\n"
            . "This notice is to inform you that a violation of the {$assocName} governing documents has been observed at {$unitLine}.\n\n"
            . "Violation type: {$vioLabel}\n"
            . $ruleBlock
            . "\nDescription:\n{$desc}\n\n"
            . "You are requested to address and correct this condition as soon as possible. Continued non-compliance may result in a formal Cure Notice or fine.\n\n"
            . "If you believe you have received this notice in error or wish to discuss the matter, please contact the board.\n\n"
            . "Respectfully,\n{$assocName}\nBoard of Directors",

        'cure' =>
            "Dear Resident of {$unitLine},\n\n"
            . "This is a formal Notice to Cure issued pursuant to the governing documents of {$assocName}.\n\n"
            . "Violation type: {$vioLabel}\n"
            . $ruleBlock
            . "\nDescription:\n{$desc}\n\n"
            . "You are hereby required to cure and correct the above violation on or before the due date stated above. "
            . "Failure to cure by that date may result in a fine being levied against {$unitLine}.\n\n"
            . "You have the right to request a hearing before the Board of Directors. "
            . "If you have questions or believe this notice was issued in error, please contact the board promptly.\n\n"
            . "Respectfully,\n{$assocName}\nBoard of Directors",

        'fine' =>
            "Dear Resident of {$unitLine},\n\n"
            . "This notice is to inform you that a fine has been levied against {$unitLine} for the following violation:\n\n"
            . "Violation type: {$vioLabel}\n"
            . $ruleBlock
            . "\nDescription:\n{$desc}\n\n"
            . "The fine stated above is due on or before the due date stated above. "
            . "Failure to pay may result in further collection action in accordance with the governing documents and applicable law.\n\n"
            . "You have the right to request a hearing before the Board of Directors within 14 days of receipt of this notice. "
            . "To request a hearing, contact the board in writing.\n\n"
            . "Respectfully,\n{$assocName}\nBoard of Directors",

        'hearing' =>
            "Dear Resident of {$unitLine},\n\n"
            . "You are hereby notified that a hearing before the Board of Directors of {$assocName} has been scheduled regarding the following violation:\n\n"
            . "Violation type: {$vioLabel}\n"
            . $ruleBlock
            . "\nDescription:\n{$desc}\n\n"
            . "The hearing will be held on the date stated above at:\n{$assocAddr}\n\n"
            . "You have the right to attend and present your case. "
            . "Please contact the board if you need to reschedule or have questions.\n\n"
            . "Respectfully,\n{$assocName}\nBoard of Directors",
    ];
}

$active     = 'violations';
$page_title = 'Violations — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

<?php if ($detail): ?>
    <!-- ========== DETAIL VIEW ========================================== -->
    <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/violations.php">← Back</a>
            <h1 style="font-size: var(--fs-2xl); margin: var(--sp-2) 0 var(--sp-2);">
                Violation #<?= (int)$detail['id'] ?> — <?= e($VTYPES[$detail['violation_type']] ?? $detail['violation_type']) ?>
            </h1>
            <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                <span class="badge <?= $STATUSES[$detail['status']]['cls'] ?>"><?= e($STATUSES[$detail['status']]['label']) ?></span>
                <?php if (!empty($detail['unit_number_direct'])): ?>
                    <a class="badge" href="/dashboard/unit.php?n=<?= urlencode((string)$detail['unit_number_direct']) ?>" style="text-decoration:none;">Unit <?= e((string)$detail['unit_number_direct']) ?></a>
                <?php endif; ?>
                <?php if (!empty($detail['violator_name'])): ?>
                    <span class="muted" style="font-size: var(--fs-sm);">· <?= e(trim((string)$detail['violator_name'])) ?></span>
                <?php endif; ?>
                <span class="muted" style="font-size: var(--fs-sm);">· Filed <?= e(udate('M j, Y', strtotime((string)$detail['created_at']))) ?></span>
            </div>
        </div>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <!-- Meta card -->
    <div class="card card--padded" style="margin-bottom: var(--sp-4);">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-3);">
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Unit</div>
                <div><?= !empty($detail['unit_number_direct'])
                    ? '<a href="/dashboard/unit.php?n=' . urlencode((string)$detail['unit_number_direct']) . '">Unit ' . e((string)$detail['unit_number_direct']) . '</a>'
                    : '<span class="muted">—</span>' ?></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Resident</div>
                <div><?= !empty($detail['violator_name']) ? e(trim((string)$detail['violator_name'])) : '<span class="muted">—</span>' ?></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Type</div>
                <div><?= e($VTYPES[$detail['violation_type']] ?? (string)$detail['violation_type']) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Cited rule</div>
                <div><?php if (!empty($detail['rule_title'])):?>
                    <a href="/dashboard/rule.php?id=<?= (int)$detail['rule_id'] ?>" target="_blank" rel="noopener">
                        <?= !empty($detail['rule_number']) ? '#' . e((string)$detail['rule_number']) . ' · ' : '' ?><?= e((string)$detail['rule_title']) ?>
                    </a>
                <?php else: ?><span class="muted">—</span><?php endif; ?></div>
            </div>
            <?php if (!empty($detail['concern_subject'])): ?>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">From concern</div>
                <div><a href="/dashboard/concerns.php?id=<?= (int)$detail['concern_id'] ?>"><?= e((string)$detail['concern_subject']) ?></a></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <article class="card card--padded" style="margin-bottom: var(--sp-4); white-space: pre-wrap;"><?= e((string)$detail['description']) ?></article>

    <!-- Notices timeline -->
    <h3 style="font-size: var(--fs-lg); margin: var(--sp-6) 0 var(--sp-3);">Notices issued</h3>
    <?php if (!$notices): ?>
        <p class="muted" style="margin-bottom: var(--sp-4);">No notices issued yet. Use the form below to issue the first one.</p>
    <?php else: ?>
        <div class="stack-md" style="margin-bottom: var(--sp-4);">
        <?php foreach ($notices as $n):
            $ntColor = match ((string)$n['notice_type']) {
                'warning' => 'badge--warning',
                'cure'    => 'badge--info',
                'fine'    => 'badge--orange',
                'hearing' => 'badge--error',
                default   => '',
            };
        ?>
            <div class="card card--padded" style="border-left: 3px solid var(--color-navy);">
                <div class="row row--between" style="flex-wrap: wrap; gap: var(--sp-2); margin-bottom: var(--sp-2);">
                    <div class="row" style="gap: var(--sp-2); flex-wrap: wrap; align-items: center;">
                        <span class="badge <?= $ntColor ?>"><?= e($NOTICE_TYPES[$n['notice_type']] ?? (string)$n['notice_type']) ?></span>
                        <?php if (!empty($n['due_date'])): ?>
                            <span class="muted" style="font-size: var(--fs-sm);">Due <?= e(udate('M j, Y', strtotime((string)$n['due_date']))) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($n['fine_amount_cents'])): ?>
                            <strong style="color: var(--color-error);">Fine: $<?= number_format((int)$n['fine_amount_cents'] / 100, 2) ?></strong>
                        <?php endif; ?>
                        <span class="muted" style="font-size: var(--fs-xs);">· Issued <?= e(udate('M j, Y', strtotime((string)$n['issued_at']))) ?> by <?= e(trim((string)$n['issuer_name']) ?: '—') ?></span>
                    </div>
                    <a class="btn btn--ghost" style="font-size: var(--fs-sm); padding: 4px 12px;"
                       href="/dashboard/violation-notice-print.php?notice_id=<?= (int)$n['id'] ?>"
                       target="_blank" rel="noopener">Print notice →</a>
                </div>
                <pre style="white-space: pre-wrap; font-family: inherit; margin: 0; font-size: var(--fs-sm);"><?= e((string)$n['body_text']) ?></pre>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Issue notice form -->
    <?php
    $templates = violation_notice_templates($detail, $association);
    $defaultTemplate = $templates['warning'];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4);" id="issue-notice-card">
        <h3 class="card__title" style="margin-bottom: var(--sp-3);">Issue a notice</h3>
        <form method="post" class="form" id="issue-notice-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="issue_notice">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">

            <div class="form-row form-row--3">
                <div class="field">
                    <label class="field__label" for="nt">Notice type</label>
                    <select class="select" id="nt" name="notice_type">
                        <?php foreach ($NOTICE_TYPES as $val => $lbl): ?>
                            <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="nd">Due date <span class="muted" style="font-weight:400;">(cure / fine / hearing)</span></label>
                    <input class="input" type="date" id="nd" name="due_date" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="field" id="fine-amount-field" style="display:none;">
                    <label class="field__label" for="nf">Fine amount ($)</label>
                    <input class="input" type="number" id="nf" name="fine_amount" step="0.01" min="0" placeholder="250.00">
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="nb">Notice body <span class="muted" style="font-weight:400;">(editable — this is what prints)</span></label>
                <textarea class="textarea" id="nb" name="body_text" rows="18" required><?= e($defaultTemplate) ?></textarea>
                <div class="field__hint">
                    The template above is pre-filled based on the violation. Edit freely — changes here do not affect the violation record.
                </div>
            </div>

            <div class="row" style="justify-content: flex-end; gap: var(--sp-2);">
                <button class="btn btn--primary" type="submit">Issue notice</button>
            </div>
        </form>
    </div>

    <!-- Status controls -->
    <form method="post" class="card card--padded" style="margin-bottom: var(--sp-4);">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="set_status">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Update status</h3>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="vs">Status</label>
                <select class="select" id="vs" name="status">
                    <?php foreach ($STATUSES as $val => $meta): ?>
                        <option value="<?= e($val) ?>" <?= $detail['status']===$val?'selected':'' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="display:flex; align-items:flex-end;">
                <button class="btn btn--primary" type="submit">Update status</button>
            </div>
        </div>
        <div class="field__hint">
            Setting to <strong>Cured</strong> or <strong>Closed</strong> marks the violation resolved.
        </div>
    </form>

    <script>
    (function () {
        var templates = <?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var ntSel   = document.getElementById('nt');
        var bodyTA  = document.getElementById('nb');
        var fineFld = document.getElementById('fine-amount-field');
        var lastTpl = templates['warning']; // track whether user edited the body

        ntSel.addEventListener('change', function () {
            var v   = ntSel.value;
            var tpl = templates[v] || '';
            // Only auto-replace if body is still the previous template (not user-edited).
            if (bodyTA.value.trim() === lastTpl.trim()) {
                bodyTA.value = tpl;
                lastTpl = tpl;
            }
            fineFld.style.display = (v === 'fine') ? '' : 'none';
        });
        bodyTA.addEventListener('input', function () { lastTpl = ''; }); // mark as user-edited
    })();
    </script>

<?php elseif ($action === 'new'): ?>
    <!-- ========== NEW VIOLATION FORM =================================== -->
    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <h1 style="font-size: var(--fs-2xl); margin: 0;">Record a violation</h1>
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/violations.php">← Back</a>
    </div>

    <?php if ($prefillConcern): ?>
        <div class="flash flash--info" style="margin-bottom: var(--sp-4);">
            Pre-filled from concern #<?= (int)$prefillConcern['id'] ?> —
            <a href="/dashboard/concerns.php?id=<?= (int)$prefillConcern['id'] ?>"><?= e((string)$prefillConcern['subject']) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php
    $memberTypeahead = [];
    foreach ($members as $m) {
        $nm = trim((string)$m['first_name'] . ' ' . (string)$m['last_name']);
        if ($nm === '') continue;
        $memberTypeahead[] = ['id' => (int)$m['id'], 'name' => $nm, 'unit' => (string)($m['unit_number'] ?? '')];
    }
    $ruleTypeahead = [];
    foreach ($rules as $r) {
        $ruleTypeahead[] = ['id' => (int)$r['id'], 'num' => (string)($r['rule_number'] ?? ''), 'title' => (string)$r['title'], 'src' => (string)($r['source'] ?? '')];
    }
    // Pre-select from concern
    $preFillUnitId  = $prefillConcern ? (int)($prefillConcern['target_unit_id'] ?? 0) : 0;
    $preFillUserId  = $prefillConcern ? (int)($prefillConcern['target_user_id'] ?? 0) : 0;
    $preFillDesc    = $prefillConcern ? "From concern #{$prefillConcern['id']}: {$prefillConcern['subject']}\n\n{$prefillConcern['body']}" : '';
    ?>

    <form method="post" class="form card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add">
        <?php if ($prefillConcern): ?><input type="hidden" name="concern_id" value="<?= (int)$prefillConcern['id'] ?>"><?php endif; ?>

        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="vt">Violation type</label>
                <select class="select" id="vt" name="violation_type">
                    <?php foreach ($VTYPES as $val => $lbl): ?>
                        <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="v-unit">Unit</label>
                <select class="select" id="v-unit" name="unit_id">
                    <option value="">— not unit-specific —</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $preFillUnitId === (int)$u['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row form-row--2">
            <!-- Person typeahead -->
            <div class="field" style="position: relative;">
                <label class="field__label" for="v-person-search">Resident (violator)</label>
                <?php
                $preFillPersonName = '';
                if ($preFillUserId) {
                    foreach ($memberTypeahead as $mt) {
                        if ($mt['id'] === $preFillUserId) {
                            $preFillPersonName = $mt['name'] . ($mt['unit'] ? ' · Unit ' . $mt['unit'] : '');
                            break;
                        }
                    }
                }
                ?>
                <input class="input" type="text" id="v-person-search" autocomplete="off"
                       placeholder="Type a name…"
                       data-typeahead='<?= e(json_encode($memberTypeahead, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                       value="<?= e($preFillPersonName) ?>">
                <input type="hidden" id="v-person" name="user_id" value="<?= $preFillUserId ?: '' ?>">
                <div id="v-person-results" class="typeahead-list" role="listbox" hidden></div>
            </div>

            <!-- Rule typeahead -->
            <div class="field" style="position: relative;">
                <label class="field__label" for="v-rule-search">Cited rule (optional)</label>
                <input class="input" type="text" id="v-rule-search" autocomplete="off"
                       placeholder="Type a rule number or keyword…"
                       data-rule-typeahead='<?= e(json_encode($ruleTypeahead, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <input type="hidden" id="v-rule" name="rule_id" value="">
                <div id="v-rule-results" class="typeahead-list" role="listbox" hidden></div>
                <div id="v-rule-selected" class="muted" style="font-size: var(--fs-xs); margin-top: 4px;"></div>
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="v-desc">Description <span class="muted" style="font-weight:400;">(what was observed, when, where)</span></label>
            <textarea class="textarea" id="v-desc" name="description" rows="5" required
                      placeholder="Observed dog off-leash in the pool area on 5/12 at 2 PM, violating Rule 4.3."><?= e($preFillDesc) ?></textarea>
        </div>

        <div class="row" style="justify-content: flex-end; gap: var(--sp-2);">
            <a class="btn btn--ghost" href="/dashboard/violations.php">Cancel</a>
            <button class="btn btn--primary" type="submit">Record violation</button>
        </div>
    </form>

<?php else: ?>
    <!-- ========== LIST VIEW ============================================ -->
    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Violations</h1>
            <p class="muted">Formal violation records. Issue notices, track cure deadlines, and print letters.</p>
        </div>
        <a class="btn btn--primary" href="?action=new">+ Record violation</a>
    </div>

    <?php
    $statusFilter = $_GET['status'] ?? 'open';
    if (!array_key_exists($statusFilter, $STATUSES) && $statusFilter !== 'all') $statusFilter = 'open';
    $openCount = ($countByStatus['open'] ?? 0) + ($countByStatus['notice_sent'] ?? 0);
    ?>

    <?php if ($openCount > 0): ?>
    <div class="flash flash--warning" style="margin-bottom: var(--sp-4);">
        <strong><?= (int)$openCount ?> active violation<?= $openCount === 1 ? '' : 's' ?></strong> open or awaiting cure.
    </div>
    <?php endif; ?>

    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
        <?php foreach ($STATUSES as $val => $meta):
            $n = $countByStatus[$val] ?? 0;
            $isActive = $statusFilter === $val;
        ?>
            <a class="badge <?= $isActive ? $meta['cls'] : '' ?>"
               href="?status=<?= e($val) ?>" style="text-decoration:none; <?= !$isActive ? 'opacity:0.6;' : '' ?>">
                <?= e($meta['label']) ?><?php if ($n): ?> · <?= $n ?><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <a class="badge <?= $statusFilter==='all'?'badge--navy':'' ?>"
           href="?status=all" style="text-decoration:none; <?= $statusFilter!=='all'?'opacity:0.6;':'' ?>">All</a>
    </div>

    <?php if (!$listRows): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No violations with status "<?= e($STATUSES[$statusFilter]['label'] ?? $statusFilter) ?>".</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Record the first violation</a></p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Type</th><th>Unit / Resident</th><th>Status</th><th>Updated</th></tr>
            </thead>
            <tbody>
            <?php foreach ($listRows as $row): ?>
                <tr style="cursor:pointer;" onclick="window.location='?id=<?= (int)$row['id'] ?>'">
                    <td>#<?= (int)$row['id'] ?></td>
                    <td><?= e($VTYPES[$row['violation_type']] ?? (string)$row['violation_type']) ?></td>
                    <td>
                        <?= !empty($row['unit_number']) ? 'Unit ' . e((string)$row['unit_number']) : '' ?>
                        <?php if (!empty($row['violator_name'])): ?>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(trim((string)$row['violator_name'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $STATUSES[$row['status']]['cls'] ?>"><?= e($STATUSES[$row['status']]['label']) ?></span></td>
                    <td><?= e(udate('M j, Y', strtotime((string)$row['updated_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php if ($action === 'new' || ($action === '' && !$detail)): ?>
<style>
    .typeahead-list { position: absolute; top: 100%; left: 0; right: 0; z-index: 50; max-height: 280px; overflow-y: auto; background: #fff; border: 1px solid var(--color-border); border-radius: var(--r-md); box-shadow: 0 8px 24px rgba(15,31,61,0.12); margin-top: 2px; }
    .typeahead-list[hidden] { display: none; }
    .typeahead-item { padding: 8px 12px; cursor: pointer; font-size: var(--fs-sm); display: flex; justify-content: space-between; align-items: baseline; gap: var(--sp-3); }
    .typeahead-item:hover, .typeahead-item.is-active { background: var(--color-surface); }
    .typeahead-item .meta { color: var(--color-text-soft); font-size: var(--fs-xs); }
    .typeahead-empty { padding: 8px 12px; color: var(--color-text-soft); font-size: var(--fs-sm); font-style: italic; }
</style>
<script>
(function () {
    function esc(s) {
        return String(s).replace(/[&<>"']/g, function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});
    }

    // Person typeahead (single-select)
    var pInput  = document.getElementById('v-person-search');
    var pHidden = document.getElementById('v-person');
    var pList   = document.getElementById('v-person-results');
    if (pInput && pHidden && pList) {
        var pData = JSON.parse(pInput.getAttribute('data-typeahead') || '[]');
        var pActive = -1, pMatches = [];
        function pRender(q) {
            q = (q || '').toLowerCase().trim();
            pMatches = !q ? pData.slice(0,10) : pData.filter(function(m){ return (m.name+m.unit).toLowerCase().indexOf(q) !== -1; }).slice(0,10);
            pList.innerHTML = '';
            if (!pMatches.length) { pList.innerHTML = '<div class="typeahead-empty">No members match.</div>'; pList.hidden = false; return; }
            pMatches.forEach(function(m,i){
                var row = document.createElement('div');
                row.className = 'typeahead-item' + (i===pActive?' is-active':'');
                row.innerHTML = '<span>' + esc(m.name) + '</span>' + (m.unit?'<span class="meta">Unit '+esc(m.unit)+'</span>':'');
                row.addEventListener('mousedown', function(e){ e.preventDefault(); pPick(m); });
                pList.appendChild(row);
            });
            pList.hidden = false;
        }
        function pPick(m){ pInput.value = m.name + (m.unit?' · Unit '+m.unit:''); pHidden.value = m.id; pList.hidden = true; pActive=-1; }
        pInput.addEventListener('focus', function(){ pRender(pInput.value); });
        pInput.addEventListener('input', function(){ pHidden.value=''; pActive=-1; pRender(pInput.value); });
        pInput.addEventListener('keydown', function(e){
            if(pList.hidden) return;
            if(e.key==='ArrowDown'){e.preventDefault(); pActive=Math.min(pMatches.length-1,pActive+1); pRender(pInput.value);}
            else if(e.key==='ArrowUp'){e.preventDefault(); pActive=Math.max(0,pActive-1); pRender(pInput.value);}
            else if(e.key==='Enter'&&pActive>=0){e.preventDefault(); pPick(pMatches[pActive]);}
            else if(e.key==='Escape'){pList.hidden=true;}
        });
        document.addEventListener('click', function(e){ if(e.target!==pInput&&!pList.contains(e.target)) pList.hidden=true; });
    }

    // Rule typeahead (single-select with display label)
    var rInput    = document.getElementById('v-rule-search');
    var rHidden   = document.getElementById('v-rule');
    var rList     = document.getElementById('v-rule-results');
    var rSelected = document.getElementById('v-rule-selected');
    if (rInput && rHidden && rList) {
        var rData = JSON.parse(rInput.getAttribute('data-rule-typeahead') || '[]');
        var rActive = -1, rMatches = [];
        function rRender(q){
            q = (q||'').toLowerCase().trim();
            rMatches = rData.filter(function(r){ return !q || (r.num+r.title+r.src).toLowerCase().indexOf(q) !== -1; }).slice(0,10);
            rList.innerHTML = '';
            if(!rMatches.length){ rList.innerHTML='<div class="typeahead-empty">No rules match.</div>'; rList.hidden=false; return; }
            rMatches.forEach(function(m,i){
                var row = document.createElement('div');
                row.className = 'typeahead-item'+(i===rActive?' is-active':'');
                row.innerHTML = '<span><strong>'+(m.num?'#'+esc(m.num)+' · ':'')+esc(m.title)+'</strong></span><span class="meta">'+esc(m.src)+'</span>';
                row.addEventListener('mousedown', function(e){ e.preventDefault(); rPick(m); });
                rList.appendChild(row);
            });
            rList.hidden=false;
        }
        function rPick(m){
            rInput.value = '';
            rHidden.value = m.id;
            rList.hidden = true;
            rActive = -1;
            rSelected.textContent = (m.num ? '#'+m.num+' · ' : '') + m.title;
        }
        rInput.addEventListener('focus', function(){ rRender(rInput.value); });
        rInput.addEventListener('input', function(){ rHidden.value=''; rActive=-1; rSelected.textContent=''; rRender(rInput.value); });
        rInput.addEventListener('keydown', function(e){
            if(rList.hidden) return;
            if(e.key==='ArrowDown'){e.preventDefault(); rActive=Math.min(rMatches.length-1,rActive+1); rRender(rInput.value);}
            else if(e.key==='ArrowUp'){e.preventDefault(); rActive=Math.max(0,rActive-1); rRender(rInput.value);}
            else if(e.key==='Enter'&&rActive>=0){e.preventDefault(); rPick(rMatches[rActive]);}
            else if(e.key==='Escape'){rList.hidden=true;}
        });
        document.addEventListener('click', function(e){ if(e.target!==rInput&&!rList.contains(e.target)) rList.hidden=true; });
    }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>

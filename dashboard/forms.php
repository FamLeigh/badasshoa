<?php
// Resident forms — guest registration, temp parking pass, move-in notices,
// key/fob requests, etc. Self-service: residents submit, the system issues
// an immediate confirmation code + printable permit. Board sees the full
// log and can revoke any submission.
//
// Single file routes by action:
//   /dashboard/forms.php                      — list (filtered by role)
//   /dashboard/forms.php?action=new&type=…    — submit form for that type
//   /dashboard/forms.php?id=N                 — detail / permit preview
//   POST form=revoke                          — board revokes a submission
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;

$TYPES = form_types();

// Helper: which units may this user submit for?
function units_for_user(int $userId, int $assocId, bool $canManage): array
{
    // Managers can pick any unit. Everyone else: their own.
    if ($canManage) {
        $s = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? ORDER BY CAST(unit_number AS UNSIGNED), unit_number');
        $s->execute([$assocId]);
        return $s->fetchAll();
    }
    // Pull from unit_occupants first (structured); fall back to users.unit_number.
    $s = db()->prepare(
        'SELECT DISTINCT u.id, u.unit_number
           FROM unit_occupants uo
           JOIN units u ON u.id = uo.unit_id
          WHERE uo.user_id = ? AND u.association_id = ?
          ORDER BY CAST(u.unit_number AS UNSIGNED), u.unit_number'
    );
    $s->execute([$userId, $assocId]);
    $rows = $s->fetchAll();
    if (!$rows) {
        $s = db()->prepare('SELECT unit_number FROM users WHERE id = ?');
        $s->execute([$userId]);
        $un = (string)$s->fetchColumn();
        if ($un !== '') {
            $s = db()->prepare('SELECT id, unit_number FROM units WHERE association_id = ? AND unit_number = ?');
            $s->execute([$assocId, $un]);
            $rows = $s->fetchAll();
        }
    }
    return $rows;
}

// --- Submit a form ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'submit') {
    csrf_check();
    $type   = $_POST['type'] ?? 'guest_registration';
    if (!array_key_exists($type, $TYPES)) $type = 'other';
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $starts = trim((string)($_POST['starts_at'] ?? ''));
    $ends   = trim((string)($_POST['ends_at'] ?? ''));
    if ($starts !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts)) $starts = '';
    if ($ends   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends))   $ends   = '';

    // Validate the unit belongs to this association AND that the submitter
    // is allowed to file for it (their own unit, unless they're a manager).
    $allowedUnits = units_for_user((int)$user['id'], $assocId, $canManage);
    $allowedUnitIds = array_map(fn($u) => (int)$u['id'], $allowedUnits);
    if ($unitId && !in_array($unitId, $allowedUnitIds, true)) {
        $flashError = "You can't file a form for a unit you don't live in.";
    }

    // Type-specific payload + title
    $payload = [];
    $title = '';
    switch ($type) {
        case 'guest_registration':
            $payload = [
                'guest_name'   => trim((string)($_POST['guest_name'] ?? '')),
                'guest_phone'  => trim((string)($_POST['guest_phone'] ?? '')),
                'vehicle_plate'=> trim((string)($_POST['vehicle_plate'] ?? '')),
                'vehicle_desc' => trim((string)($_POST['vehicle_desc'] ?? '')),
            ];
            $title = $payload['guest_name'] !== '' ? 'Guest: ' . $payload['guest_name'] : 'Guest registration';
            if ($payload['guest_name'] === '') $flashError = $flashError ?: 'Guest name is required.';
            break;
        case 'parking_pass':
            $payload = [
                'vehicle_plate'=> trim((string)($_POST['vehicle_plate'] ?? '')),
                'vehicle_desc' => trim((string)($_POST['vehicle_desc'] ?? '')),
                'vehicle_color'=> trim((string)($_POST['vehicle_color'] ?? '')),
                'driver_name'  => trim((string)($_POST['driver_name'] ?? '')),
                'parking_spot' => trim((string)($_POST['parking_spot'] ?? '')),
            ];
            $title = $payload['vehicle_plate'] !== '' ? 'Parking: ' . $payload['vehicle_plate'] : 'Parking pass';
            if ($payload['vehicle_plate'] === '') $flashError = $flashError ?: 'License plate is required.';
            break;
        case 'move_in':
        case 'move_out':
            $payload = [
                'moving_company' => trim((string)($_POST['moving_company'] ?? '')),
                'truck_plate'    => trim((string)($_POST['truck_plate'] ?? '')),
                'elevator_hold'  => isset($_POST['elevator_hold']) ? 1 : 0,
                'contact_phone'  => trim((string)($_POST['contact_phone'] ?? '')),
            ];
            $title = ($type === 'move_in' ? 'Move-in notice' : 'Move-out notice');
            if ($starts === '') $flashError = $flashError ?: 'Move date is required.';
            break;
        case 'key_request':
            $payload = [
                'item_kind'   => trim((string)($_POST['item_kind'] ?? '')),
                'quantity'    => (int)($_POST['quantity'] ?? 1),
                'reason'      => trim((string)($_POST['reason'] ?? '')),
            ];
            $title = ucfirst($payload['item_kind'] ?: 'Key/fob') . ' request';
            break;
        default:
            $payload = [
                'description' => trim((string)($_POST['description'] ?? '')),
            ];
            $title = trim((string)($_POST['title'] ?? '')) ?: 'Form submission';
            if ($payload['description'] === '') $flashError = $flashError ?: 'Tell us what you need.';
            break;
    }

    if (!$flashError) {
        // Generate a unique-per-association confirmation code (retry on collision)
        $code = generate_form_code();
        for ($i = 0; $i < 5; $i++) {
            $c = db()->prepare('SELECT 1 FROM form_submissions WHERE association_id = ? AND confirmation_code = ?');
            $c->execute([$assocId, $code]);
            if (!$c->fetchColumn()) break;
            $code = generate_form_code();
        }

        db()->prepare(
            'INSERT INTO form_submissions
               (association_id, form_type, unit_id, submitter_user_id, title,
                starts_at, ends_at, confirmation_code, payload, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $assocId, $type, $unitId ?: null, (int)$user['id'], $title,
            $starts ?: null, $ends ?: null, $code, json_encode($payload, JSON_UNESCAPED_SLASHES),
            trim((string)($_POST['notes'] ?? '')) ?: null,
        ]);
        $newId = (int)db()->lastInsertId();
        audit('form.submitted', ['type' => $type, 'code' => $code], $newId, 'form_submission');

        // Notify the board — heads-up not approval-required.
        notify_association_managers(
            $assocId,
            "[{$association['name']}] " . form_type_label($type) . ': ' . $title,
            "A new " . strtolower(form_type_label($type)) . " was just issued.\n\n"
            . "Confirmation code: $code\n"
            . "Submitter: " . trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) . "\n"
            . "View: https://badasshoa.com/dashboard/forms.php?id={$newId}\n"
        );

        flash('success', 'Issued — your confirmation code is <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>. Print or share from the next page.');
        redirect('/dashboard/forms.php?id=' . $newId);
    }
}

// --- Revoke (managers only) ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'revoke') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $fid = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['revoke_reason'] ?? ''));
    db()->prepare(
        'UPDATE form_submissions
            SET status = "revoked", revoked_at = NOW(), revoked_by_user_id = ?, revoke_reason = ?
          WHERE id = ? AND association_id = ?'
    )->execute([(int)$user['id'], $reason ?: null, $fid, $assocId]);
    audit('form.revoked', ['reason' => $reason], $fid, 'form_submission');
    flash('success', 'Form revoked.');
    redirect('/dashboard/forms.php?id=' . $fid);
}

// --- Detail load ---------------------------------------------------------
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($detailId > 0) {
    $stmt = db()->prepare(
        'SELECT f.*,
                u.unit_number,
                TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name,
                s.email AS submitter_email,
                TRIM(CONCAT(IFNULL(r.first_name,""), " ", IFNULL(r.last_name,""))) AS revoker_name
           FROM form_submissions f
           LEFT JOIN units u ON u.id = f.unit_id
           LEFT JOIN users s ON s.id = f.submitter_user_id
           LEFT JOIN users r ON r.id = f.revoked_by_user_id
          WHERE f.id = ? AND f.association_id = ?'
    );
    $stmt->execute([$detailId, $assocId]);
    $detail = $stmt->fetch() ?: null;
    // Non-managers can only see forms they submitted (or for their unit).
    if ($detail && !$canManage) {
        $allowedUnits = units_for_user((int)$user['id'], $assocId, false);
        $allowedUnitIds = array_map(fn($u) => (int)$u['id'], $allowedUnits);
        if ((int)$detail['submitter_user_id'] !== (int)$user['id']
            && !in_array((int)$detail['unit_id'], $allowedUnitIds, true)) {
            $detail = null;
        }
    }
}

// --- New form view -------------------------------------------------------
$showNew = ($_GET['action'] ?? '') === 'new';
$newType = $_GET['type'] ?? 'guest_registration';
if (!array_key_exists($newType, $TYPES)) $newType = 'guest_registration';
$prefUnitId = (int)($_GET['unit_id'] ?? 0);

// --- List view -----------------------------------------------------------
$typeFilter = $_GET['filter_type'] ?? '';
$listing = [];
if (!$detail && !$showNew) {
    $sql = 'SELECT f.*, u.unit_number,
                   TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name
              FROM form_submissions f
              LEFT JOIN units u ON u.id = f.unit_id
              LEFT JOIN users s ON s.id = f.submitter_user_id
             WHERE f.association_id = ?';
    $params = [$assocId];
    if (!$canManage) {
        $allowedUnits = units_for_user((int)$user['id'], $assocId, false);
        $allowedUnitIds = array_map(fn($u) => (int)$u['id'], $allowedUnits);
        if ($allowedUnitIds) {
            $ph = implode(',', array_fill(0, count($allowedUnitIds), '?'));
            $sql .= " AND (f.submitter_user_id = ? OR f.unit_id IN ($ph))";
            $params[] = (int)$user['id'];
            $params = array_merge($params, $allowedUnitIds);
        } else {
            $sql .= ' AND f.submitter_user_id = ?';
            $params[] = (int)$user['id'];
        }
    }
    if (array_key_exists($typeFilter, $TYPES)) {
        $sql .= ' AND f.form_type = ?'; $params[] = $typeFilter;
    }
    $sql .= ' ORDER BY f.created_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listing = $stmt->fetchAll();
}

$page_title = 'Forms — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <?php if ($detail): /* ---------- DETAIL / PERMIT ---------- */
        $meta = $TYPES[$detail['form_type']] ?? ['label' => $detail['form_type'], 'icon' => '📝'];
        $payload = is_string($detail['payload']) ? (json_decode($detail['payload'], true) ?: []) : ($detail['payload'] ?? []);
        $isRevoked = $detail['status'] === 'revoked';
        $isExpired = !empty($detail['ends_at']) && strtotime((string)$detail['ends_at']) < strtotime(date('Y-m-d'));
    ?>
        <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/forms.php">← Back to forms</a>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="/dashboard/form-print.php?id=<?= (int)$detail['id'] ?>" target="_blank" rel="noopener">🖨 Print permit</a>
                <?php if ($canManage && !$isRevoked): ?>
                    <form method="post" style="display:inline;" onsubmit="var r = prompt('Reason (optional):'); if (r === null) return false; this.querySelector('[name=revoke_reason]').value = r; return confirm('Revoke this form?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="revoke">
                        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                        <input type="hidden" name="revoke_reason" value="">
                        <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Revoke</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <article class="card card--padded" style="margin-bottom: var(--sp-4); <?= $isRevoked ? 'border-left: 4px solid var(--color-error); background: var(--color-error-bg);' : ($isExpired ? 'opacity: 0.6;' : '') ?>">
            <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                <span class="badge badge--info"><?= e($meta['icon']) ?> <?= e($meta['label']) ?></span>
                <?php if ($isRevoked): ?>
                    <span class="badge badge--error">REVOKED</span>
                <?php elseif ($isExpired): ?>
                    <span class="badge" style="background:#e8e8e8; color:#666;">EXPIRED</span>
                <?php else: ?>
                    <span class="badge badge--success">ACTIVE</span>
                <?php endif; ?>
                <?php if (!empty($detail['unit_number'])): ?>
                    <span class="muted" style="font-size: var(--fs-sm);">· Unit <?= e((string)$detail['unit_number']) ?></span>
                <?php endif; ?>
            </div>

            <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);"><?= e((string)$detail['title']) ?></h1>
            <p class="muted" style="margin: 0;">
                Filed by <?= e(trim((string)$detail['submitter_name']) ?: 'unknown') ?>
                on <?= e(date('M j, Y g:i A', strtotime((string)$detail['created_at']))) ?>
            </p>

            <!-- Big confirmation code -->
            <div style="margin-top: var(--sp-5); padding: var(--sp-4) var(--sp-5); background: var(--color-warning-bg); border: 2px dashed var(--color-warning); border-radius: var(--r-md); text-align:center;">
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em;">Confirmation code</div>
                <div style="font-size: 28pt; font-weight: 800; font-family: 'Syne', sans-serif; letter-spacing: 0.1em; color: var(--color-warning); margin-top: 4px;">
                    <?= e((string)$detail['confirmation_code']) ?>
                </div>
                <?php if (!empty($detail['starts_at']) || !empty($detail['ends_at'])): ?>
                    <div class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-2);">
                        Valid
                        <?php if (!empty($detail['starts_at'])): ?><?= e(date('M j, Y', strtotime((string)$detail['starts_at']))) ?><?php endif; ?>
                        <?php if (!empty($detail['ends_at'])): ?> – <?= e(date('M j, Y', strtotime((string)$detail['ends_at']))) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Type-specific fields -->
            <div style="margin-top: var(--sp-5); display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-3);">
                <?php
                $labels = [
                    'guest_name'    => 'Guest name',
                    'guest_phone'   => 'Guest phone',
                    'vehicle_plate' => 'License plate',
                    'vehicle_desc'  => 'Vehicle',
                    'vehicle_color' => 'Color',
                    'driver_name'   => 'Driver',
                    'parking_spot'  => 'Assigned spot',
                    'moving_company'=> 'Moving company',
                    'truck_plate'   => 'Truck plate',
                    'contact_phone' => 'Contact phone',
                    'elevator_hold' => 'Elevator hold requested',
                    'item_kind'     => 'Item',
                    'quantity'      => 'Quantity',
                    'reason'        => 'Reason',
                    'description'   => 'Description',
                ];
                foreach ($payload as $k => $v) {
                    if ($v === '' || $v === null) continue;
                    $label = $labels[$k] ?? ucwords(str_replace('_',' ', $k));
                    $disp = is_bool($v) ? ($v ? 'Yes' : 'No') : (is_array($v) ? implode(', ', $v) : (string)$v);
                    if ($k === 'elevator_hold') $disp = $v ? 'Yes' : 'No';
                    echo '<div><div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">'.htmlspecialchars($label, ENT_QUOTES,'UTF-8').'</div>'.htmlspecialchars($disp, ENT_QUOTES,'UTF-8').'</div>';
                }
                ?>
            </div>

            <?php if (!empty($detail['notes'])): ?>
                <div style="margin-top: var(--sp-4);">
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase;">Notes</div>
                    <p style="margin: 4px 0 0; white-space: pre-wrap;"><?= e((string)$detail['notes']) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($isRevoked): ?>
                <div style="margin-top: var(--sp-4); padding: var(--sp-3); background: #fff; border-radius: var(--r-md);">
                    <strong style="color: var(--color-error);">Revoked</strong>
                    <?php if (!empty($detail['revoked_at'])): ?> on <?= e(date('M j, Y', strtotime((string)$detail['revoked_at']))) ?><?php endif; ?>
                    <?php if (!empty($detail['revoker_name'])): ?> by <?= e((string)$detail['revoker_name']) ?><?php endif; ?>
                    <?php if (!empty($detail['revoke_reason'])): ?>
                        <p style="margin: 4px 0 0; white-space: pre-wrap;"><?= e((string)$detail['revoke_reason']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>

    <?php elseif ($showNew):
        /* ---------- NEW FORM ---------- */
        $unitOptions = units_for_user((int)$user['id'], $assocId, $canManage);
    ?>
        <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
            <h1 style="font-size: var(--fs-2xl); margin: 0;"><?= e($TYPES[$newType]['icon']) ?> <?= e($TYPES[$newType]['label']) ?></h1>
            <a class="muted" style="font-size: var(--fs-sm); align-self: center;" href="/dashboard/forms.php">← Back</a>
        </div>

        <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

        <?php if (!$unitOptions): ?>
            <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
                <p class="muted">No unit on file for your account — ask the board to attach your unit before filing forms.</p>
            </div>
        <?php else: ?>
        <form method="post" class="form card card--padded">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="submit">
            <input type="hidden" name="type" value="<?= e($newType) ?>">

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="fu">Unit</label>
                    <select class="select" id="fu" name="unit_id" required>
                        <?php foreach ($unitOptions as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $prefUnitId === (int)$u['id'] ? 'selected' : '' ?>>Unit <?= e((string)$u['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <?php if ($newType === 'guest_registration'): ?>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="gn">Guest name</label><input class="input" id="gn" name="guest_name" required></div>
                    <div class="field"><label class="field__label" for="gp">Guest phone</label><input class="input" id="gp" name="guest_phone"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="vp">License plate</label><input class="input" id="vp" name="vehicle_plate" placeholder="ABC-1234"></div>
                    <div class="field"><label class="field__label" for="vd">Vehicle (year / make / model)</label><input class="input" id="vd" name="vehicle_desc" placeholder="2022 Toyota Camry"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Arriving</label><input class="input" type="date" id="fs" name="starts_at" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="field"><label class="field__label" for="fe">Leaving</label><input class="input" type="date" id="fe" name="ends_at" required></div>
                </div>
            <?php elseif ($newType === 'parking_pass'): ?>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="vp">License plate</label><input class="input" id="vp" name="vehicle_plate" required placeholder="ABC-1234"></div>
                    <div class="field"><label class="field__label" for="vc">Color</label><input class="input" id="vc" name="vehicle_color" placeholder="Silver"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="vd">Year / make / model</label><input class="input" id="vd" name="vehicle_desc" placeholder="2022 Toyota Camry"></div>
                    <div class="field"><label class="field__label" for="dn">Driver name</label><input class="input" id="dn" name="driver_name"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="ps">Assigned spot (optional)</label><input class="input" id="ps" name="parking_spot" placeholder="Guest #3"></div>
                    <div class="field"><!-- spacer --></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Valid from</label><input class="input" type="date" id="fs" name="starts_at" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="field"><label class="field__label" for="fe">Valid through</label><input class="input" type="date" id="fe" name="ends_at" required></div>
                </div>
            <?php elseif ($newType === 'move_in' || $newType === 'move_out'): ?>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Move date</label><input class="input" type="date" id="fs" name="starts_at" required></div>
                    <div class="field"><label class="field__label" for="cp">Contact phone</label><input class="input" id="cp" name="contact_phone"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="mc">Moving company</label><input class="input" id="mc" name="moving_company"></div>
                    <div class="field"><label class="field__label" for="tp">Truck plate</label><input class="input" id="tp" name="truck_plate"></div>
                </div>
                <label style="display:flex; align-items:center; gap: var(--sp-2);">
                    <input type="checkbox" name="elevator_hold"> Request elevator hold (board reviews availability)
                </label>
            <?php elseif ($newType === 'key_request'): ?>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="ik">Item</label>
                        <select class="select" id="ik" name="item_kind">
                            <option value="pool fob">Pool key / fob</option>
                            <option value="gate fob">Gate / garage fob</option>
                            <option value="mail key">Mail key</option>
                            <option value="entry key">Building entry key</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="qty">Quantity</label>
                        <input class="input" type="number" id="qty" name="quantity" min="1" max="10" value="1">
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="rsn">Reason</label>
                    <textarea class="textarea" id="rsn" name="reason" rows="3" placeholder="Lost / additional resident / replacement / …"></textarea>
                </div>
            <?php else: /* other */ ?>
                <div class="field"><label class="field__label" for="ot">Title</label><input class="input" id="ot" name="title" required maxlength="255"></div>
                <div class="field"><label class="field__label" for="od">Description</label><textarea class="textarea" id="od" name="description" rows="5" required></textarea></div>
            <?php endif; ?>

            <div class="field">
                <label class="field__label" for="fn">Additional notes (optional)</label>
                <textarea class="textarea" id="fn" name="notes" rows="2"></textarea>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/forms.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Submit + get confirmation code</button>
            </div>
        </form>
        <?php endif; ?>

    <?php else: /* ---------- LIST ---------- */ ?>

        <div class="row row--between" style="margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
            <div>
                <h1 style="font-size: var(--fs-3xl); margin: 0;">Forms</h1>
                <p class="muted">Guest registrations, parking passes, move-in/out notices, key requests. Self-service — submit and get a confirmation code instantly.</p>
            </div>
        </div>

        <!-- New-form launchers -->
        <div class="card card--padded" style="margin-bottom: var(--sp-5);">
            <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Start a new form</h3>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-3);">
                <?php foreach ($TYPES as $key => $meta): ?>
                    <a class="card" href="<?= e($meta['submit_url']) ?>" style="padding: var(--sp-3) var(--sp-4); display:flex; gap: var(--sp-3); align-items:center; text-decoration:none; color: inherit; transition: transform 120ms ease;"
                       onmouseover="this.style.transform='translateY(-1px)'"
                       onmouseout="this.style.transform=''">
                        <span style="font-size: 24px;"><?= e($meta['icon']) ?></span>
                        <strong><?= e($meta['label']) ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <h3 style="font-size: var(--fs-lg); margin: var(--sp-2) 0 var(--sp-3);">
            <?= $canManage ? 'All submissions' : 'Your forms' ?>
            <span class="muted" style="font-weight: 400; font-size: var(--fs-sm);"><?= count($listing) ?> total</span>
        </h3>

        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-3); flex-wrap: wrap;">
            <a class="badge <?= $typeFilter === '' ? 'badge--navy' : '' ?>" href="?" style="text-decoration:none; <?= $typeFilter !== '' ? 'opacity: 0.6;' : '' ?>">All</a>
            <?php foreach ($TYPES as $key => $meta): ?>
                <a class="badge <?= $typeFilter === $key ? 'badge--info' : '' ?>" href="?filter_type=<?= e($key) ?>" style="text-decoration:none; <?= $typeFilter !== $key ? 'opacity: 0.6;' : '' ?>"><?= e($meta['icon']) ?> <?= e($meta['label']) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$listing): ?>
            <div class="card card--padded center" style="padding: var(--sp-8) var(--sp-6);">
                <p class="muted">No forms yet. Pick one above to get started.</p>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr><th>Type</th><th>Title</th><th>Unit</th><th>Submitted by</th><th>Window</th><th>Code</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($listing as $r):
                $meta = $TYPES[$r['form_type']] ?? ['label' => $r['form_type'], 'icon' => '📝'];
                $expiredFlag = !empty($r['ends_at']) && strtotime((string)$r['ends_at']) < strtotime(date('Y-m-d'));
                $statusBadge = $r['status'] === 'revoked' ? 'badge--error' : ($expiredFlag ? '' : 'badge--success');
                $statusLabel = $r['status'] === 'revoked' ? 'revoked' : ($expiredFlag ? 'expired' : 'active');
            ?>
                <tr style="cursor:pointer;" onclick="window.location='?id=<?= (int)$r['id'] ?>'">
                    <td><?= e($meta['icon']) ?> <?= e($meta['label']) ?></td>
                    <td><a href="?id=<?= (int)$r['id'] ?>"><strong><?= e((string)$r['title']) ?></strong></a></td>
                    <td><?= !empty($r['unit_number']) ? e((string)$r['unit_number']) : '—' ?></td>
                    <td><?= e(trim((string)$r['submitter_name']) ?: '—') ?></td>
                    <td style="font-size: var(--fs-sm);">
                        <?= !empty($r['starts_at']) ? e(date('M j', strtotime((string)$r['starts_at']))) : '' ?>
                        <?= !empty($r['ends_at'])   ? ' – ' . e(date('M j', strtotime((string)$r['ends_at']))) : '' ?>
                    </td>
                    <td><code style="font-size: var(--fs-xs);"><?= e((string)$r['confirmation_code']) ?></code></td>
                    <td><span class="badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

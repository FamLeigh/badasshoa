<?php
// Print-friendly single-form permit. Designed for a guest to keep on their
// dashboard / for the resident to print + stash on a vehicle dashboard.
// Auto-fires the browser print dialog on load.
require __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$user      = current_user();
$canManage = role_can_manage(viewing_role());

$stmt = db()->prepare(
    'SELECT f.*,
            u.unit_number,
            TRIM(CONCAT(IFNULL(s.first_name,""), " ", IFNULL(s.last_name,""))) AS submitter_name
       FROM form_submissions f
       LEFT JOIN units u ON u.id = f.unit_id
       LEFT JOIN users s ON s.id = f.submitter_user_id
      WHERE f.id = ? AND f.association_id = ?'
);
$stmt->execute([$id, $assocId]);
$f = $stmt->fetch();
if (!$f) { http_response_code(404); echo 'Form not found.'; exit; }

// Same visibility check as forms.php detail
if (!$canManage && (int)$f['submitter_user_id'] !== (int)$user['id']) {
    // Allow if user is an occupant of the unit
    $allowed = false;
    if (!empty($f['unit_id'])) {
        $c = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
        $c->execute([(int)$f['unit_id'], (int)$user['id']]);
        $allowed = (bool)$c->fetchColumn();
    }
    if (!$allowed) { http_response_code(403); echo 'Forbidden'; exit; }
}

$payload = is_string($f['payload']) ? (json_decode($f['payload'], true) ?: []) : ($f['payload'] ?? []);
$TYPES = form_types();
$meta = $TYPES[$f['form_type']] ?? ['label' => $f['form_type'], 'icon' => '📝'];
$isRevoked = $f['status'] === 'revoked';
$isExpired = !empty($f['ends_at']) && strtotime((string)$f['ends_at']) < strtotime(date('Y-m-d'));
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Permit · <?= e((string)$f['confirmation_code']) ?> — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.5in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; padding: 0; line-height: 1.45; }
    .permit {
        border: 3px solid #0f1f3d; border-radius: 12pt; padding: 24pt;
        margin-top: 12pt;
        <?php if ($isRevoked): ?>
            border-color: #a8322a; background: #fff4f3;
        <?php elseif ($isExpired): ?>
            border-color: #888; opacity: 0.7;
        <?php endif; ?>
    }
    .type-row { display:flex; justify-content: space-between; align-items: center; margin-bottom: 18pt; flex-wrap: wrap; gap: 8pt; }
    .type { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 16pt; color: #0f1f3d; text-transform: uppercase; letter-spacing: 0.06em; }
    .status { display:inline-block; padding: 3pt 12pt; border-radius: 999pt; font-size: 9pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
    .status-active   { background: #d9eddb; color: #2f7a3d; }
    .status-revoked  { background: #f5d7d3; color: #a8322a; }
    .status-expired  { background: #e8e8e8; color: #666; }
    h1 { font-family: 'Syne', sans-serif; font-size: 26pt; margin: 0 0 4pt; color: #0f1f3d; }
    .unit { color: #555; font-size: 11pt; margin-bottom: 18pt; }
    .code-block {
        text-align: center; padding: 20pt;
        background: #fff6e0; border: 2px dashed #d8b54d;
        border-radius: 8pt; margin: 18pt 0;
    }
    .code-label { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.1em; color: #6b4a06; }
    .code { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 36pt; letter-spacing: 0.1em; color: #6b4a06; line-height: 1.1; margin-top: 4pt; }
    .window { color: #6b4a06; font-size: 17pt; font-weight: 700; margin-top: 10pt; letter-spacing: 0.02em; }
    .fields { display:grid; grid-template-columns: repeat(2, 1fr); gap: 14pt; margin-top: 16pt; }
    .fields .label { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #888; }
    .fields .value { font-size: 12pt; font-weight: 600; margin-top: 2pt; }
    .footer-note { font-size: 9pt; color: #555; margin-top: 18pt; padding-top: 12pt; border-top: 1px solid #ccc; }
    @media print {
        a { color: inherit; text-decoration: none; }
    }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<div class="permit">
    <div class="type-row">
        <span class="type"><?= e($meta['icon']) ?> <?= e($meta['label']) ?></span>
        <?php if ($isRevoked): ?>
            <span class="status status-revoked">Revoked</span>
        <?php elseif ($isExpired): ?>
            <span class="status status-expired">Expired</span>
        <?php else: ?>
            <span class="status status-active">Active</span>
        <?php endif; ?>
    </div>

    <h1><?= e((string)$f['title']) ?></h1>
    <div class="unit">
        <?php if (!empty($f['unit_number'])): ?>Unit <?= e((string)$f['unit_number']) ?> · <?php endif; ?>
        Filed by <?= e(trim((string)$f['submitter_name']) ?: 'unknown') ?>
        on <?= e(date('M j, Y', strtotime((string)$f['created_at']))) ?>
    </div>

    <div class="code-block">
        <div class="code-label">Confirmation code</div>
        <div class="code"><?= e((string)$f['confirmation_code']) ?></div>
        <?php if (!empty($f['starts_at']) || !empty($f['ends_at'])): ?>
            <div class="window">
                Valid
                <?php if (!empty($f['starts_at'])): ?><?= e(date('M j, Y', strtotime((string)$f['starts_at']))) ?><?php endif; ?>
                <?php if (!empty($f['ends_at'])): ?> – <?= e(date('M j, Y', strtotime((string)$f['ends_at']))) ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $labels = [
        // Guest registration (Bellair card)
        'name'          => 'Name',
        'street'        => 'Street',
        'city'          => 'City',
        'state'         => 'State',
        'zip'           => 'Zip',
        'cell_phone'    => 'Cell phone',
        'party_size'    => 'Total in party',
        'party_names'   => 'Names of others',
        'car_make'      => 'Car make',
        'car_color'     => 'Car color',
        'car_plate'     => 'Car plate',
        'car_state'     => 'Plate state',
        'relationship'  => 'Role',
        'agreed_rules'  => 'House rules acknowledged',
        'emergency_contact_name'  => 'Emergency contact',
        'emergency_contact_phone' => 'Emergency phone',
        // Parking pass / vehicle reg
        'vehicle_plate' => 'License plate',
        'vehicle_desc'  => 'Vehicle',
        'vehicle_color' => 'Color',
        'vehicle_state' => 'Plate state',
        'driver_name'   => 'Driver',
        'parking_spot'  => 'Assigned spot',
        'assigned_spot' => 'Assigned spot',
        'primary_driver'=> 'Primary driver',
        'secondary_drivers' => 'Other drivers',
        // Maintenance
        'issue_kind'         => 'Issue',
        'urgency'            => 'Urgency',
        'location_in_unit'   => 'Location',
        'access_instructions'=> 'Access',
        // Pet
        'pet_name'             => 'Pet name',
        'species'              => 'Species',
        'breed'                => 'Breed',
        'weight_lbs'           => 'Weight (lbs)',
        'color'                => 'Color',
        'vaccinations_current' => 'Vaccinations current',
        'emergency_vet'        => 'Emergency vet',
        // Contractor
        'contractor_name'  => 'Contractor',
        'contractor_phone' => 'Contractor phone',
        'work_kind'        => 'Work',
        'work_hours'       => 'Work hours',
        // Amenity
        'amenity'              => 'Amenity',
        'event_name'           => 'Event',
        'event_time'           => 'Time',
        'headcount'            => 'Headcount',
        'alcohol_served'       => 'Alcohol served',
        'deposit_acknowledged' => 'Deposit ack',
        'cleanup_responsible'  => 'Cleanup',
        // Hurricane
        'storm_name'      => 'Storm',
        'plan'            => 'Plan',
        'expected_return' => 'Return',
        'balcony_clear'   => 'Balcony cleared',
        'shutters_closed' => 'Shutters closed',
        'water_off'       => 'Water shutoff',
        'power_off'       => 'Power off',
        'evacuation_plan' => 'Evacuation plan',
        'emergency_contact_on_file' => 'EC on file',
        'key_with_neighbor'  => 'Key with neighbor',
        'pet_plan'           => 'Pet plan',
        'insurance_docs_safe'=> 'Insurance docs safe',
        // Emergency contact
        'contact_name'            => 'Contact name',
        'contact_relationship'    => 'Relationship',
        'contact_phone_primary'   => 'Primary phone',
        'contact_phone_secondary' => 'Secondary phone',
        'contact_email'           => 'Email',
        'has_key'                 => 'Has key',
        'pet_info'                => 'Pet info',
        'medical_notes'           => 'Medical notes',
        // Estoppel
        'requesting_party' => 'Requesting party',
        'requestor_email'  => 'Requestor email',
        'requestor_phone'  => 'Requestor phone',
        'closing_date'     => 'Closing date',
        'new_owner_name'   => 'New owner',
        'rush_processing'  => 'Rush',
        'fee_acknowledged' => 'Fee ack',
        // Move / key / generic
        'moving_company'=> 'Moving company',
        'truck_plate'   => 'Truck plate',
        'contact_phone' => 'Contact phone',
        'elevator_hold' => 'Elevator hold',
        'item_kind'     => 'Item',
        'quantity'      => 'Quantity',
        'reason'        => 'Reason',
        'description'   => 'Description',
    ];
    $fields = [];
    foreach ($payload as $k => $v) {
        if ($v === '' || $v === null) continue;
        $disp = is_bool($v) ? ($v ? 'Yes' : 'No') : (is_array($v) ? implode(', ', $v) : (string)$v);
        if ($k === 'elevator_hold') $disp = $v ? 'Yes' : 'No';
        $fields[] = ['label' => $labels[$k] ?? ucwords(str_replace('_',' ',$k)), 'value' => $disp];
    }
    if ($fields): ?>
        <div class="fields">
            <?php foreach ($fields as $fld): ?>
                <div>
                    <div class="label"><?= e($fld['label']) ?></div>
                    <div class="value"><?= e($fld['value']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($f['notes'])): ?>
        <div style="margin-top: 16pt;">
            <div style="font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #888;">Notes</div>
            <div style="font-size: 11pt; margin-top: 2pt; white-space: pre-wrap;"><?= e((string)$f['notes']) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($f['signature_kind'])):
        $tamperOk = empty($f['payload_hash']) || payload_hash($payload) === $f['payload_hash'];
    ?>
        <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&display=swap" rel="stylesheet">
        <div style="margin-top: 20pt; padding-top: 14pt; border-top: 1px solid #ccc;">
            <div style="font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #888; margin-bottom: 6pt;">Electronic signature</div>
            <?php if ($f['signature_kind'] === 'typed'): ?>
                <div style="font-family: 'Caveat', cursive; font-size: 28pt; color: #0f1f3d; border-bottom: 1px solid #888; padding: 4pt 2pt; max-width: 4in;"><?= e((string)$f['signature_typed_name']) ?></div>
                <div style="font-size: 9pt; color: #888; margin-top: 4pt;">Typed signature</div>
            <?php elseif (!empty($f['signature_image_path'])): ?>
                <?php
                // For print, embed the image inline as a data URL so it doesn't
                // depend on a session-authenticated fetch from the print window.
                $sigAbs = storage_path((string)$f['signature_image_path']);
                if (is_file($sigAbs)) {
                    $sigData = file_get_contents($sigAbs);
                    $sigMime = function_exists('mime_content_type') ? mime_content_type($sigAbs) : 'image/png';
                    echo '<img src="data:' . htmlspecialchars($sigMime, ENT_QUOTES, 'UTF-8') . ';base64,' . base64_encode($sigData) . '" style="max-width: 4in; max-height: 1.4in; border-bottom: 1px solid #888;" alt="Signature">';
                }
                ?>
                <div style="font-size: 9pt; color: #888; margin-top: 4pt;"><?= $f['signature_kind'] === 'drawn' ? 'Drawn signature' : 'Uploaded signature' ?></div>
            <?php endif; ?>

            <div style="font-size: 8.5pt; color: #555; margin-top: 8pt; padding-top: 6pt; border-top: 1px dotted #ccc; line-height: 1.5;">
                Signed by <strong><?= e(trim((string)$f['submitter_name']) ?: '—') ?></strong>
                <?php if (!empty($f['signed_at'])): ?> on <?= e(date('M j, Y g:i:s A', strtotime((string)$f['signed_at']))) ?><?php endif; ?>
                <?php if (!empty($f['signed_ip'])): ?> · IP <?= e((string)$f['signed_ip']) ?><?php endif; ?>
                · Consent to electronic records: <strong><?= (int)$f['consent_given'] === 1 ? 'Yes' : 'No' ?></strong>
                <?php if (!empty($f['payload_hash'])): ?> · Record hash <code><?= e(substr((string)$f['payload_hash'], 0, 16)) ?>…</code><?php endif; ?>
                <br><em>Signed electronically under the E-SIGN Act + Florida UETA. This has the same legal effect as a handwritten signature.</em>
                <?php if (!$tamperOk): ?>
                    <br><strong style="color: #a8322a;">⚠ Record was edited after signing — signature may not apply to current values.</strong>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="footer-note">
        This permit was issued by <?= e((string)$association['name']) ?>. Present it (or the confirmation code above) to staff or board members if asked. Any of the rules referenced in your association documents still apply.
        <?php if ($isRevoked): ?>
            <br><strong style="color: #a8322a;">This permit has been revoked and is no longer valid.</strong>
        <?php elseif ($isExpired): ?>
            <br><strong>This permit has expired.</strong>
        <?php endif; ?>
    </div>
</div>

<?= print_footer_html('Printed ' . date('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

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
            // Matches the Bellair Registration Card (10/2025 version).
            $payload = [
                'name'                  => trim((string)($_POST['name'] ?? '')),
                'street'                => trim((string)($_POST['street'] ?? '')),
                'city'                  => trim((string)($_POST['city'] ?? '')),
                'state'                 => trim((string)($_POST['state'] ?? '')),
                'zip'                   => trim((string)($_POST['zip'] ?? '')),
                'cell_phone'            => trim((string)($_POST['cell_phone'] ?? '')),
                'party_size'            => (int)($_POST['party_size'] ?? 1),
                'party_names'           => trim((string)($_POST['party_names'] ?? '')),
                'car_make'              => trim((string)($_POST['car_make'] ?? '')),
                'car_color'             => trim((string)($_POST['car_color'] ?? '')),
                'car_plate'             => trim((string)($_POST['car_plate'] ?? '')),
                'car_state'             => trim((string)($_POST['car_state'] ?? '')),
                'relationship'          => $_POST['relationship'] ?? '',
                'agreed_rules'          => isset($_POST['agreed_rules']) ? 1 : 0,
                'emergency_contact_name'  => trim((string)($_POST['emergency_contact_name'] ?? '')),
                'emergency_contact_phone' => trim((string)($_POST['emergency_contact_phone'] ?? '')),
            ];
            if (!in_array($payload['relationship'], ['owner','family','guest_of_owner','tenant','guest_of_tenant'], true)) $payload['relationship'] = '';
            $title = $payload['name'] !== '' ? 'Guest: ' . $payload['name'] . ($payload['party_size'] > 1 ? ' (party of ' . $payload['party_size'] . ')' : '') : 'Guest registration';
            if ($payload['name'] === '')      $flashError = $flashError ?: 'Name is required.';
            elseif (!$payload['agreed_rules']) $flashError = $flashError ?: 'Please acknowledge the house rules agreement.';
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
        case 'maintenance_request':
            $payload = [
                'issue_kind'         => $_POST['issue_kind'] ?? 'other',
                'urgency'            => $_POST['urgency'] ?? 'medium',
                'description'        => trim((string)($_POST['description'] ?? '')),
                'location_in_unit'   => trim((string)($_POST['location_in_unit'] ?? '')),
                'access_instructions'=> trim((string)($_POST['access_instructions'] ?? '')),
                'contact_phone'      => trim((string)($_POST['contact_phone'] ?? '')),
            ];
            if (!in_array($payload['issue_kind'], ['plumbing','electrical','hvac','appliance','structural','common_area','other'], true)) $payload['issue_kind'] = 'other';
            if (!in_array($payload['urgency'], ['low','medium','high','emergency'], true)) $payload['urgency'] = 'medium';
            $title = 'Maintenance: ' . str_replace('_', ' ', $payload['issue_kind']);
            if ($payload['description'] === '') $flashError = $flashError ?: 'Tell us what needs fixing.';
            break;
        case 'pet_registration':
            $payload = [
                'pet_name'             => trim((string)($_POST['pet_name'] ?? '')),
                'species'              => $_POST['species'] ?? 'dog',
                'breed'                => trim((string)($_POST['breed'] ?? '')),
                'weight_lbs'           => ($_POST['weight_lbs'] ?? '') !== '' ? (float)$_POST['weight_lbs'] : null,
                'color'                => trim((string)($_POST['color'] ?? '')),
                'vaccinations_current' => isset($_POST['vaccinations_current']) ? 1 : 0,
                'emergency_vet'        => trim((string)($_POST['emergency_vet'] ?? '')),
            ];
            if (!in_array($payload['species'], ['dog','cat','fish','bird','reptile','other'], true)) $payload['species'] = 'other';
            $title = 'Pet: ' . ($payload['pet_name'] ?: 'unnamed') . ' (' . $payload['species'] . ')';
            if ($payload['pet_name'] === '') $flashError = $flashError ?: "Pet name is required.";
            break;
        case 'vehicle_registration':
            $payload = [
                'vehicle_plate'    => trim((string)($_POST['vehicle_plate'] ?? '')),
                'vehicle_state'    => trim((string)($_POST['vehicle_state'] ?? '')),
                'vehicle_color'    => trim((string)($_POST['vehicle_color'] ?? '')),
                'vehicle_desc'     => trim((string)($_POST['vehicle_desc'] ?? '')),
                'assigned_spot'    => trim((string)($_POST['assigned_spot'] ?? '')),
                'primary_driver'   => trim((string)($_POST['primary_driver'] ?? '')),
                'secondary_drivers'=> trim((string)($_POST['secondary_drivers'] ?? '')),
            ];
            $title = 'Vehicle: ' . ($payload['vehicle_plate'] ?: 'unspecified');
            if ($payload['vehicle_plate'] === '') $flashError = $flashError ?: 'License plate is required.';
            break;
        case 'contractor_notice':
            $payload = [
                'contractor_name'  => trim((string)($_POST['contractor_name'] ?? '')),
                'contractor_phone' => trim((string)($_POST['contractor_phone'] ?? '')),
                'work_kind'        => trim((string)($_POST['work_kind'] ?? '')),
                'description'      => trim((string)($_POST['description'] ?? '')),
                'work_hours'       => trim((string)($_POST['work_hours'] ?? '')),
                'access_instructions' => trim((string)($_POST['access_instructions'] ?? '')),
            ];
            $title = 'Work: ' . ($payload['work_kind'] ?: 'unspecified');
            if ($payload['contractor_name'] === '') $flashError = $flashError ?: 'Contractor name is required.';
            break;
        case 'amenity_reservation':
            $payload = [
                'amenity'              => $_POST['amenity'] ?? 'clubhouse',
                'event_name'           => trim((string)($_POST['event_name'] ?? '')),
                'event_time'           => trim((string)($_POST['event_time'] ?? '')),
                'headcount'            => (int)($_POST['headcount'] ?? 0),
                'alcohol_served'       => isset($_POST['alcohol_served']) ? 1 : 0,
                'deposit_acknowledged' => isset($_POST['deposit_acknowledged']) ? 1 : 0,
                'cleanup_responsible'  => trim((string)($_POST['cleanup_responsible'] ?? '')),
            ];
            if (!in_array($payload['amenity'], ['clubhouse','pool_deck','bbq_pits','fitness_room','other'], true)) $payload['amenity'] = 'other';
            $title = 'Reservation: ' . str_replace('_', ' ', $payload['amenity']) . ($payload['event_name'] !== '' ? ' — ' . $payload['event_name'] : '');
            if ($starts === '') $flashError = $flashError ?: 'Event date is required.';
            elseif (!$payload['deposit_acknowledged']) $flashError = $flashError ?: 'Please acknowledge the deposit policy.';
            break;
        case 'hurricane_checklist':
            $payload = [
                'storm_name'           => trim((string)($_POST['storm_name'] ?? '')),
                'plan'                 => $_POST['plan'] ?? 'staying',
                'expected_return'      => trim((string)($_POST['expected_return'] ?? '')),
                'balcony_clear'        => isset($_POST['balcony_clear']) ? 1 : 0,
                'shutters_closed'      => isset($_POST['shutters_closed']) ? 1 : 0,
                'water_off'            => isset($_POST['water_off']) ? 1 : 0,
                'power_off'            => isset($_POST['power_off']) ? 1 : 0,
                'evacuation_plan'      => isset($_POST['evacuation_plan']) ? 1 : 0,
                'emergency_contact_on_file' => isset($_POST['emergency_contact_on_file']) ? 1 : 0,
                'key_with_neighbor'    => isset($_POST['key_with_neighbor']) ? 1 : 0,
                'pet_plan'             => isset($_POST['pet_plan']) ? 1 : 0,
                'insurance_docs_safe'  => isset($_POST['insurance_docs_safe']) ? 1 : 0,
            ];
            if (!in_array($payload['plan'], ['staying','evacuating'], true)) $payload['plan'] = 'staying';
            $title = 'Storm prep: ' . ($payload['storm_name'] ?: 'general');
            break;
        case 'emergency_contact':
            $payload = [
                'contact_name'         => trim((string)($_POST['contact_name'] ?? '')),
                'contact_relationship' => trim((string)($_POST['contact_relationship'] ?? '')),
                'contact_phone_primary'   => trim((string)($_POST['contact_phone_primary'] ?? '')),
                'contact_phone_secondary' => trim((string)($_POST['contact_phone_secondary'] ?? '')),
                'contact_email'        => trim((string)($_POST['contact_email'] ?? '')),
                'has_key'              => isset($_POST['has_key']) ? 1 : 0,
                'pet_info'             => trim((string)($_POST['pet_info'] ?? '')),
                'medical_notes'        => trim((string)($_POST['medical_notes'] ?? '')),
            ];
            $title = 'Emergency contact: ' . ($payload['contact_name'] ?: 'unspecified');
            if ($payload['contact_name'] === '')          $flashError = $flashError ?: 'Contact name is required.';
            elseif ($payload['contact_phone_primary'] === '') $flashError = $flashError ?: 'Primary phone is required.';
            break;
        case 'estoppel_request':
            $payload = [
                'requesting_party' => trim((string)($_POST['requesting_party'] ?? '')),
                'requestor_email'  => trim((string)($_POST['requestor_email'] ?? '')),
                'requestor_phone'  => trim((string)($_POST['requestor_phone'] ?? '')),
                'closing_date'     => trim((string)($_POST['closing_date'] ?? '')),
                'new_owner_name'   => trim((string)($_POST['new_owner_name'] ?? '')),
                'rush_processing'  => isset($_POST['rush_processing']) ? 1 : 0,
                'fee_acknowledged' => isset($_POST['fee_acknowledged']) ? 1 : 0,
            ];
            $title = 'Estoppel: Unit ' . ($unitId ? '#' . $unitId : 'request');
            if ($payload['requesting_party'] === '') $flashError = $flashError ?: 'Requesting party (title company) is required.';
            elseif (!$payload['fee_acknowledged'])   $flashError = $flashError ?: 'Please acknowledge the estoppel fee.';
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

    // --- Signature capture ---
    // Required for forms with legal weight (see forms_requiring_signature()).
    // Four input shapes:
    //   * signature_kind = 'typed'    + signature_typed_name
    //   * signature_kind = 'drawn'    + signature_drawn_data (canvas data URL)
    //   * signature_kind = 'uploaded' + $_FILES['signature_upload']
    //   * signature_kind = 'saved'    + use_saved_signature_id (their library)
    // Audit (IP, UA, ts, hash) captured regardless of which shape.
    $sigKind = $_POST['signature_kind'] ?? '';
    if (!in_array($sigKind, ['typed','drawn','uploaded','saved'], true)) $sigKind = '';
    $sigTyped     = trim((string)($_POST['signature_typed_name'] ?? ''));
    $sigDataUrl   = (string)($_POST['signature_drawn_data'] ?? '');
    $useSavedId   = (int)($_POST['use_saved_signature_id'] ?? 0);
    $consentGiven = isset($_POST['consent_given']) ? 1 : 0;

    $sigImagePath = null;

    // 'saved' shape: pull the saved sig from the user's library and copy
    // it onto this submission. The form_submission stores its own typed
    // name / image_path so the saved row is just a convenience cache.
    if ($sigKind === 'saved' && $useSavedId > 0) {
        $s = db()->prepare('SELECT * FROM user_signatures WHERE id = ? AND user_id = ?');
        $s->execute([$useSavedId, (int)$user['id']]);
        $saved = $s->fetch();
        if (!$saved) {
            $flashError = $flashError ?: 'That saved signature is no longer available.';
            $sigKind = '';
        } else {
            $sigKind  = (string)$saved['kind'];          // collapses back to typed/drawn/uploaded
            $sigTyped = (string)($saved['typed_name'] ?? '');
            // For drawn/uploaded, copy the image to a per-submission file
            // so the original library entry can be deleted without breaking
            // already-signed submissions.
            if ($saved['kind'] !== 'typed' && !empty($saved['image_path'])) {
                $src = storage_path((string)$saved['image_path']);
                if (is_file($src)) {
                    $ext = strtolower(pathinfo((string)$saved['image_path'], PATHINFO_EXTENSION)) ?: 'png';
                    $relDir = "uploads/$assocId/signatures";
                    ensure_dir(storage_path($relDir));
                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                    $rel  = "$relDir/$name";
                    if (copy($src, storage_path($rel))) $sigImagePath = $rel;
                }
                if (!$sigImagePath) $flashError = $flashError ?: 'Could not load that saved signature image.';
            }
            // Update last_used_at so the saved tab orders by recency.
            touch_user_signature((int)$user['id'], $useSavedId);
        }
    }

    if ($sigKind === 'drawn' && $sigDataUrl !== '') {
        $sigImagePath = save_signature_data_url($sigDataUrl, $assocId);
        if (!$sigImagePath) { $flashError = $flashError ?: 'We could not save your drawn signature — try again or pick a different method.'; }
    }
    if ($sigKind === 'uploaded' && isset($_FILES['signature_upload']) && $_FILES['signature_upload']['error'] === UPLOAD_ERR_OK) {
        $sz = (int)$_FILES['signature_upload']['size'];
        if ($sz > 3 * 1024 * 1024) {
            $flashError = $flashError ?: 'Signature image must be 3 MB or smaller.';
        } else {
            $ext = strtolower(pathinfo((string)$_FILES['signature_upload']['name'], PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','webp'];
            if (!in_array($ext, $allowed, true)) {
                $flashError = $flashError ?: 'Signature must be PNG, JPG, or WEBP.';
            } else {
                $relDir = "uploads/$assocId/signatures";
                ensure_dir(storage_path($relDir));
                $name = bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                $rel  = "$relDir/$name";
                if (!move_uploaded_file($_FILES['signature_upload']['tmp_name'], storage_path($rel))) {
                    $flashError = $flashError ?: 'Could not save the signature file.';
                } else {
                    $sigImagePath = $rel;
                }
            }
        }
    }

    // Validation for signature-required forms
    if (!$flashError && form_requires_signature($type)) {
        if ($sigKind === '')          $flashError = 'Please sign before submitting (type, draw, or upload).';
        elseif ($sigKind === 'typed' && $sigTyped === '') $flashError = 'Type your full name to sign.';
        elseif ($sigKind === 'drawn' && !$sigImagePath)   $flashError = 'Draw your signature before submitting.';
        elseif ($sigKind === 'uploaded' && !$sigImagePath) $flashError = 'Upload a signature image before submitting.';
        elseif (!$consentGiven)       $flashError = 'You must consent to sign electronically.';
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

        // Compute payload hash + capture audit trail at signing time.
        $hash      = payload_hash($payload);
        $signedAt  = $sigKind !== '' ? date('Y-m-d H:i:s') : null;
        $signedIp  = $sigKind !== '' ? (string)($_SERVER['REMOTE_ADDR'] ?? '') : null;
        $signedUa  = $sigKind !== '' ? mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) : null;

        db()->prepare(
            'INSERT INTO form_submissions
               (association_id, form_type, unit_id, submitter_user_id, title,
                starts_at, ends_at, confirmation_code, payload, notes,
                signature_kind, signature_typed_name, signature_image_path,
                consent_given, signed_at, signed_ip, signed_user_agent, payload_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $assocId, $type, $unitId ?: null, (int)$user['id'], $title,
            $starts ?: null, $ends ?: null, $code, json_encode($payload, JSON_UNESCAPED_SLASHES),
            trim((string)($_POST['notes'] ?? '')) ?: null,
            $sigKind ?: null,
            $sigKind === 'typed' ? $sigTyped : null,
            $sigImagePath,
            $consentGiven,
            $signedAt, $signedIp, $signedUa, $hash,
        ]);
        $newId = (int)db()->lastInsertId();
        audit('form.submitted', [
            'type' => $type, 'code' => $code,
            'signed' => $sigKind !== '', 'sig_kind' => $sigKind ?: null,
        ], $newId, 'form_submission');

        // Optionally save this signature to the user's private library for
        // future forms. Only when the user picked the box AND made a fresh
        // signature this round (not when they reused a saved one).
        $saveForLater = isset($_POST['save_signature_for_later']);
        $usedFreshSig = ($sigKind !== '') && $useSavedId === 0;
        if ($saveForLater && $usedFreshSig) {
            $label = mb_substr(trim((string)($_POST['signature_label'] ?? '')), 0, 80);
            // For drawn/uploaded, copy the per-submission image into a
            // long-lived "library" path so deletion of the submission
            // doesn't also wipe the saved signature.
            $libPath = null;
            if ($sigKind !== 'typed' && $sigImagePath !== null) {
                $src = storage_path($sigImagePath);
                if (is_file($src)) {
                    $ext = strtolower(pathinfo($sigImagePath, PATHINFO_EXTENSION)) ?: 'png';
                    $relDir = 'uploads/' . $assocId . '/signatures/library';
                    ensure_dir(storage_path($relDir));
                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                    $libPath = "$relDir/$name";
                    if (!copy($src, storage_path($libPath))) $libPath = null;
                }
            }
            if ($sigKind === 'typed' || $libPath !== null) {
                db()->prepare(
                    'INSERT INTO user_signatures (user_id, kind, label, typed_name, image_path)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    (int)$user['id'], $sigKind, $label ?: null,
                    $sigKind === 'typed' ? $sigTyped : null,
                    $libPath,
                ]);
                audit('signature.saved', ['kind' => $sigKind, 'label' => $label ?: null], (int)db()->lastInsertId(), 'user_signature');
            }
        }

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
                <?php if ($canManage && $detail['form_type'] === 'maintenance_request'): ?>
                    <a class="btn btn--ghost" href="/dashboard/work-orders.php?action=new&from_form=<?= (int)$detail['id'] ?>">🛠 Convert to Work Order</a>
                <?php endif; ?>
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
                    <div style="font-size: var(--fs-lg); font-weight: 700; color: var(--color-warning); margin-top: var(--sp-3); letter-spacing: 0.02em;">
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
                    // Guest registration (Bellair card)
                    'name'          => 'Name',
                    'street'        => 'Street',
                    'city'          => 'City',
                    'state'         => 'State',
                    'zip'           => 'Zip',
                    'cell_phone'    => 'Cell phone',
                    'party_size'    => 'Total in party',
                    'party_names'   => 'Names of others in party',
                    'car_make'      => 'Car make',
                    'car_color'     => 'Car color',
                    'car_plate'     => 'Car plate',
                    'car_state'     => 'Plate state',
                    'relationship'  => 'Role',
                    'agreed_rules'  => 'House rules acknowledged',
                    'emergency_contact_name'  => 'Emergency contact',
                    'emergency_contact_phone' => 'Emergency phone',
                    // Parking pass
                    'vehicle_plate' => 'License plate',
                    'vehicle_desc'  => 'Vehicle',
                    'vehicle_color' => 'Color',
                    'vehicle_state' => 'Plate state',
                    'driver_name'   => 'Driver',
                    'parking_spot'  => 'Assigned spot',
                    // Maintenance
                    'issue_kind'         => 'Issue',
                    'urgency'            => 'Urgency',
                    'location_in_unit'   => 'Location in unit',
                    'access_instructions'=> 'Access instructions',
                    // Pet
                    'pet_name'             => 'Pet name',
                    'species'              => 'Species',
                    'breed'                => 'Breed',
                    'weight_lbs'           => 'Weight (lbs)',
                    'color'                => 'Color',
                    'vaccinations_current' => 'Vaccinations current',
                    'emergency_vet'        => 'Emergency vet',
                    // Vehicle registration
                    'assigned_spot'     => 'Assigned spot',
                    'primary_driver'    => 'Primary driver',
                    'secondary_drivers' => 'Other drivers',
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
                    'deposit_acknowledged' => 'Deposit acknowledged',
                    'cleanup_responsible'  => 'Cleanup responsible',
                    // Hurricane
                    'storm_name'           => 'Storm',
                    'plan'                 => 'Plan',
                    'expected_return'      => 'Expected return',
                    'balcony_clear'        => 'Balcony cleared',
                    'shutters_closed'      => 'Shutters closed',
                    'water_off'            => 'Water shutoff ready',
                    'power_off'            => 'Major appliances unplugged',
                    'evacuation_plan'      => 'Evacuation plan',
                    'emergency_contact_on_file' => 'Emergency contact on file',
                    'key_with_neighbor'    => 'Key with neighbor',
                    'pet_plan'             => 'Pet plan',
                    'insurance_docs_safe'  => 'Insurance docs safe',
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
                    'rush_processing'  => 'Rush processing',
                    'fee_acknowledged' => 'Fee acknowledged',
                    // Generic
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

            <?php if (!empty($detail['signature_kind'])):
                $tamperOk = empty($detail['payload_hash']) || payload_hash($payload) === $detail['payload_hash'];
            ?>
                <div style="margin-top: var(--sp-5); padding: var(--sp-4); background: #fff; border: 1px solid var(--color-border); border-radius: var(--r-md);">
                    <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: var(--sp-2);">Electronic signature</div>
                    <?php if ($detail['signature_kind'] === 'typed'): ?>
                        <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&display=swap" rel="stylesheet">
                        <div style="font-family: 'Caveat', cursive; font-size: 32pt; color: var(--color-navy); border-bottom: 1px solid #888; padding: 8px 4px; max-width: 480px;"><?= e((string)$detail['signature_typed_name']) ?></div>
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 4px;">Typed signature</div>
                    <?php elseif (!empty($detail['signature_image_path'])): ?>
                        <img src="/dashboard/signature-image.php?id=<?= (int)$detail['id'] ?>" alt="Signature" style="max-width: 480px; max-height: 160px; border-bottom: 1px solid #888;">
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 4px;"><?= $detail['signature_kind'] === 'drawn' ? 'Drawn signature' : 'Uploaded signature' ?></div>
                    <?php endif; ?>

                    <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-3); padding-top: var(--sp-2); border-top: 1px dotted #ccc; line-height: 1.6;">
                        Signed by <strong><?= e(trim((string)$detail['submitter_name']) ?: '—') ?></strong>
                        <?php if (!empty($detail['signed_at'])): ?> on <?= e(date('M j, Y g:i:s A', strtotime((string)$detail['signed_at']))) ?><?php endif; ?>
                        <?php if (!empty($detail['signed_ip'])): ?> · IP <?= e((string)$detail['signed_ip']) ?><?php endif; ?>
                        <br>
                        Consent to electronic records: <strong><?= (int)$detail['consent_given'] === 1 ? 'Yes' : 'No' ?></strong>
                        <?php if (!empty($detail['payload_hash'])): ?>
                            · Record hash <code style="font-size: var(--fs-xs);"><?= e(substr((string)$detail['payload_hash'], 0, 12)) ?>…</code>
                            <?php if (!$tamperOk): ?>
                                <span class="badge badge--error" style="font-size: var(--fs-xs);">⚠ Record edited after signing — signature may not apply to current values</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
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
        <form method="post" enctype="multipart/form-data" class="form card card--padded" data-form-with-sig>
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
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">For owners, family, guests of owner, tenant or tenant guest. Please try to use the orange guest parking hang tags.</p>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Arrival date</label><input class="input" type="date" id="fs" name="starts_at" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="field"><label class="field__label" for="fe">Departure date</label><input class="input" type="date" id="fe" name="ends_at" required></div>
                </div>
                <div class="field"><label class="field__label" for="gn">Name</label><input class="input" id="gn" name="name" required></div>
                <div class="field"><label class="field__label" for="gst">Street</label><input class="input" id="gst" name="street"></div>
                <div class="form-row" style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr 1fr; gap: var(--sp-3);">
                    <div class="field"><label class="field__label" for="gc">City</label><input class="input" id="gc" name="city"></div>
                    <div class="field"><label class="field__label" for="gs">State</label><input class="input" id="gs" name="state" maxlength="3"></div>
                    <div class="field"><label class="field__label" for="gz">Zip</label><input class="input" id="gz" name="zip"></div>
                    <div class="field"><label class="field__label" for="gcp">Cell phone</label><input class="input" id="gcp" name="cell_phone"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="gps">Total # in party</label><input class="input" type="number" id="gps" name="party_size" min="1" max="20" value="1"></div>
                    <div class="field"><!-- spacer --></div>
                </div>
                <div class="field"><label class="field__label" for="gpn">Names of all others in party</label><textarea class="textarea" id="gpn" name="party_names" rows="2" placeholder="One per line"></textarea></div>

                <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                    <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Vehicle (Car License #'s)</legend>
                    <div class="form-row" style="display:grid; grid-template-columns: 1fr 1fr 1.2fr 0.7fr; gap: var(--sp-3);">
                        <div class="field"><label class="field__label" for="gcm">Make</label><input class="input" id="gcm" name="car_make"></div>
                        <div class="field"><label class="field__label" for="gcc">Color</label><input class="input" id="gcc" name="car_color"></div>
                        <div class="field"><label class="field__label" for="gcpl">Plate</label><input class="input" id="gcpl" name="car_plate"></div>
                        <div class="field"><label class="field__label" for="gcs">State</label><input class="input" id="gcs" name="car_state" maxlength="3"></div>
                    </div>
                </fieldset>

                <div class="field">
                    <label class="field__label">Check one</label>
                    <div style="display:flex; gap: var(--sp-4); flex-wrap: wrap; padding: var(--sp-2) 0;">
                        <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="relationship" value="owner" required> Owner</label>
                        <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="relationship" value="family"> Family</label>
                        <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="relationship" value="guest_of_owner"> Guest(s) of Owner</label>
                        <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="relationship" value="tenant"> Tenant</label>
                        <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="relationship" value="guest_of_tenant"> Guest(s) of Tenant</label>
                    </div>
                </div>

                <label style="display:flex; align-items:flex-start; gap: var(--sp-2); padding: var(--sp-3); background: var(--color-warning-bg); border-radius: var(--r-md); margin-bottom: var(--sp-4);">
                    <input type="checkbox" name="agreed_rules" required style="margin-top: 3px;">
                    <span style="font-size: var(--fs-sm);"><strong>I agree to abide by all house rules and regulations</strong>, a copy of which I have received &amp; read.</span>
                </label>

                <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                    <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">In the event of emergency, contact</legend>
                    <div class="form-row form-row--2">
                        <div class="field"><label class="field__label" for="gen">Name</label><input class="input" id="gen" name="emergency_contact_name"></div>
                        <div class="field"><label class="field__label" for="gep">Home or cell phone</label><input class="input" id="gep" name="emergency_contact_phone"></div>
                    </div>
                </fieldset>
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
            <?php elseif ($newType === 'maintenance_request'): ?>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="ik">Issue kind</label>
                        <select class="select" id="ik" name="issue_kind">
                            <option value="plumbing">Plumbing</option>
                            <option value="electrical">Electrical</option>
                            <option value="hvac">HVAC / AC / heat</option>
                            <option value="appliance">Appliance</option>
                            <option value="structural">Structural / drywall / floor</option>
                            <option value="common_area">Common area</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ur">Urgency</label>
                        <select class="select" id="ur" name="urgency">
                            <option value="low">Low — when convenient</option>
                            <option value="medium" selected>Medium — this week</option>
                            <option value="high">High — within 48 hours</option>
                            <option value="emergency">Emergency — now (call 911 if life safety)</option>
                        </select>
                    </div>
                </div>
                <div class="field"><label class="field__label" for="desc">What's wrong?</label><textarea class="textarea" id="desc" name="description" rows="4" required placeholder="The disposal makes a grinding noise and won't turn off…"></textarea></div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="lu">Location in unit</label><input class="input" id="lu" name="location_in_unit" placeholder="Kitchen sink · Master bath · Hallway closet"></div>
                    <div class="field"><label class="field__label" for="cp">Contact phone</label><input class="input" id="cp" name="contact_phone"></div>
                </div>
                <div class="field"><label class="field__label" for="ai">Access instructions</label><textarea class="textarea" id="ai" name="access_instructions" rows="2" placeholder="Lockbox code, neighbor key, best time to come, etc."></textarea></div>

            <?php elseif ($newType === 'pet_registration'): ?>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="pn">Pet name</label><input class="input" id="pn" name="pet_name" required></div>
                    <div class="field">
                        <label class="field__label" for="sp">Species</label>
                        <select class="select" id="sp" name="species">
                            <option value="dog">Dog</option>
                            <option value="cat">Cat</option>
                            <option value="fish">Fish</option>
                            <option value="bird">Bird</option>
                            <option value="reptile">Reptile</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="br">Breed</label><input class="input" id="br" name="breed" placeholder="Golden retriever · Tabby · …"></div>
                    <div class="field"><label class="field__label" for="wt">Weight (lbs)</label><input class="input" type="number" step="0.1" min="0" id="wt" name="weight_lbs"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="cl">Color / markings</label><input class="input" id="cl" name="color"></div>
                    <div class="field"><label class="field__label" for="ev">Emergency vet (clinic + phone)</label><input class="input" id="ev" name="emergency_vet"></div>
                </div>
                <label style="display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-2);">
                    <input type="checkbox" name="vaccinations_current">
                    Vaccinations current (rabies + standard) — proof on file or available on request
                </label>

            <?php elseif ($newType === 'vehicle_registration'): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">Permanent record of vehicles you keep on the property. Different from a temp parking pass.</p>
                <div class="form-row" style="display:grid; grid-template-columns: 1.4fr 0.7fr 1fr; gap: var(--sp-3);">
                    <div class="field"><label class="field__label" for="vp">License plate</label><input class="input" id="vp" name="vehicle_plate" required></div>
                    <div class="field"><label class="field__label" for="vs">State</label><input class="input" id="vs" name="vehicle_state" maxlength="3"></div>
                    <div class="field"><label class="field__label" for="vc">Color</label><input class="input" id="vc" name="vehicle_color"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="vd">Year / make / model</label><input class="input" id="vd" name="vehicle_desc" placeholder="2022 Toyota Camry"></div>
                    <div class="field"><label class="field__label" for="as">Assigned spot</label><input class="input" id="as" name="assigned_spot" placeholder="Garage #12"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="pd">Primary driver</label><input class="input" id="pd" name="primary_driver"></div>
                    <div class="field"><label class="field__label" for="sd">Other drivers (optional)</label><input class="input" id="sd" name="secondary_drivers" placeholder="Spouse, kids, etc."></div>
                </div>

            <?php elseif ($newType === 'contractor_notice'): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">Notify the board (and neighbors) that you'll have a contractor in your unit.</p>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="cn">Contractor name / company</label><input class="input" id="cn" name="contractor_name" required></div>
                    <div class="field"><label class="field__label" for="cph">Contractor phone</label><input class="input" id="cph" name="contractor_phone"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="wk">Work kind</label><input class="input" id="wk" name="work_kind" placeholder="Kitchen reno · Bathroom · Flooring · Paint · …"></div>
                    <div class="field"><label class="field__label" for="wh">Work hours</label><input class="input" id="wh" name="work_hours" placeholder="9 AM – 5 PM weekdays"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Start date</label><input class="input" type="date" id="fs" name="starts_at" required></div>
                    <div class="field"><label class="field__label" for="fe">Estimated completion</label><input class="input" type="date" id="fe" name="ends_at"></div>
                </div>
                <div class="field"><label class="field__label" for="dsc">Description (what's being done)</label><textarea class="textarea" id="dsc" name="description" rows="3"></textarea></div>
                <div class="field"><label class="field__label" for="ai">Access instructions</label><textarea class="textarea" id="ai" name="access_instructions" rows="2" placeholder="Lockbox / neighbor / I'll meet them at the door"></textarea></div>

            <?php elseif ($newType === 'amenity_reservation'): ?>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="am">Amenity</label>
                        <select class="select" id="am" name="amenity">
                            <option value="clubhouse">Clubhouse</option>
                            <option value="pool_deck">Pool deck</option>
                            <option value="bbq_pits">BBQ pits</option>
                            <option value="fitness_room">Fitness room</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field"><label class="field__label" for="hc">Headcount</label><input class="input" type="number" id="hc" name="headcount" min="1" max="200" value="10" required></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="fs">Date</label><input class="input" type="date" id="fs" name="starts_at" required></div>
                    <div class="field"><label class="field__label" for="et">Time</label><input class="input" id="et" name="event_time" placeholder="6 PM – 10 PM"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="en">Event name</label><input class="input" id="en" name="event_name" placeholder="Birthday · Anniversary · …"></div>
                    <div class="field"><label class="field__label" for="cr">Cleanup responsible (name)</label><input class="input" id="cr" name="cleanup_responsible"></div>
                </div>
                <label style="display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-2);">
                    <input type="checkbox" name="alcohol_served"> Alcohol will be served
                </label>
                <label style="display:flex; align-items:flex-start; gap: var(--sp-2); padding: var(--sp-3); background: var(--color-warning-bg); border-radius: var(--r-md);">
                    <input type="checkbox" name="deposit_acknowledged" required style="margin-top: 3px;">
                    <span style="font-size: var(--fs-sm);"><strong>I acknowledge the amenity reservation deposit policy</strong> — typically refundable after the space is left in good condition.</span>
                </label>

            <?php elseif ($newType === 'hurricane_checklist'): ?>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="sn">Storm name</label><input class="input" id="sn" name="storm_name" placeholder="Hurricane Helene"></div>
                    <div class="field">
                        <label class="field__label">Plan</label>
                        <div style="display:flex; gap: var(--sp-3); padding: var(--sp-2) 0;">
                            <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="plan" value="staying" checked> Staying</label>
                            <label style="display:flex; align-items:center; gap: 6px;"><input type="radio" name="plan" value="evacuating"> Evacuating</label>
                        </div>
                    </div>
                </div>
                <div class="field"><label class="field__label" for="er">Expected return (if evacuating)</label><input class="input" type="date" id="er" name="expected_return"></div>

                <p style="margin: var(--sp-3) 0 var(--sp-2); font-weight: 600;">Checklist (tick what you've done)</p>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 6px 16px;">
                    <label><input type="checkbox" name="balcony_clear"> Balcony furniture / planters / grills brought inside</label>
                    <label><input type="checkbox" name="shutters_closed"> Storm shutters closed and locked</label>
                    <label><input type="checkbox" name="water_off"> Water shutoff located (or off, if leaving)</label>
                    <label><input type="checkbox" name="power_off"> Major appliances unplugged</label>
                    <label><input type="checkbox" name="evacuation_plan"> Evacuation destination + route ready</label>
                    <label><input type="checkbox" name="emergency_contact_on_file"> Emergency contact on file with board</label>
                    <label><input type="checkbox" name="key_with_neighbor"> Trusted neighbor has my key</label>
                    <label><input type="checkbox" name="pet_plan"> Pets accounted for</label>
                    <label><input type="checkbox" name="insurance_docs_safe"> Insurance docs in waterproof / cloud storage</label>
                </div>

            <?php elseif ($newType === 'emergency_contact'): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">Who should the board call if there's a fire / flood / medical and you're not reachable?</p>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="cn">Contact name</label><input class="input" id="cn" name="contact_name" required></div>
                    <div class="field"><label class="field__label" for="cr">Relationship</label><input class="input" id="cr" name="contact_relationship" placeholder="Spouse · Sibling · Friend"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="cpp">Primary phone</label><input class="input" id="cpp" name="contact_phone_primary" required></div>
                    <div class="field"><label class="field__label" for="cps">Secondary phone</label><input class="input" id="cps" name="contact_phone_secondary"></div>
                </div>
                <div class="field"><label class="field__label" for="cem">Email</label><input class="input" type="email" id="cem" name="contact_email"></div>
                <label style="display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-2);">
                    <input type="checkbox" name="has_key"> This person has a key (or knows how to get one)
                </label>
                <div class="field"><label class="field__label" for="pi">Pet info (if you're away)</label><textarea class="textarea" id="pi" name="pet_info" rows="2" placeholder="Fido needs feeding, his food is in the pantry…"></textarea></div>
                <div class="field"><label class="field__label" for="mn">Medical notes for responders (optional)</label><textarea class="textarea" id="mn" name="medical_notes" rows="2"></textarea></div>

            <?php elseif ($newType === 'estoppel_request'): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">For title companies / closing agents when a unit is being sold.</p>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="rp">Requesting party (title company)</label><input class="input" id="rp" name="requesting_party" required></div>
                    <div class="field"><label class="field__label" for="cd">Estimated closing date</label><input class="input" type="date" id="cd" name="closing_date"></div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="re">Requestor email</label><input class="input" type="email" id="re" name="requestor_email"></div>
                    <div class="field"><label class="field__label" for="rph">Requestor phone</label><input class="input" id="rph" name="requestor_phone"></div>
                </div>
                <div class="field"><label class="field__label" for="nb">New owner name (if known)</label><input class="input" id="nb" name="new_owner_name"></div>
                <label style="display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-2);">
                    <input type="checkbox" name="rush_processing"> Rush processing (rush fee applies)
                </label>
                <label style="display:flex; align-items:flex-start; gap: var(--sp-2); padding: var(--sp-3); background: var(--color-warning-bg); border-radius: var(--r-md);">
                    <input type="checkbox" name="fee_acknowledged" required style="margin-top: 3px;">
                    <span style="font-size: var(--fs-sm);"><strong>I acknowledge the estoppel fee</strong> set by the association (typically $250; rush adds more).</span>
                </label>

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

            <?php
            $requiresSig = form_requires_signature($newType);
            $savedSigs   = user_saved_signatures((int)$user['id']);
            $defaultTab  = $savedSigs ? 'saved' : 'typed';
            ?>
            <fieldset style="border: 2px solid var(--color-navy); border-radius: var(--r-md); padding: var(--sp-4); margin-bottom: var(--sp-4); background: #fafaf6;" data-sig-pad>
                <legend style="padding: 0 var(--sp-2); color: var(--color-navy); font-weight: 700;">✍️ Electronic signature <?= $requiresSig ? '<span style="color: var(--color-error);">(required)</span>' : '(optional)' ?></legend>

                <!-- Method tabs -->
                <div class="row" style="gap: 0; margin-bottom: var(--sp-3); border-bottom: 1px solid var(--color-border); flex-wrap: wrap;">
                    <?php if ($savedSigs): ?>
                        <button type="button" class="sig-tab is-active" data-sig-tab="saved">⭐ Saved (<?= count($savedSigs) ?>)</button>
                    <?php endif; ?>
                    <button type="button" class="sig-tab <?= !$savedSigs ? 'is-active' : '' ?>" data-sig-tab="typed">Type</button>
                    <button type="button" class="sig-tab"                                       data-sig-tab="drawn">Draw</button>
                    <button type="button" class="sig-tab"                                       data-sig-tab="uploaded">Upload</button>
                </div>
                <input type="hidden" name="signature_kind" value="<?= e($defaultTab === 'saved' ? 'saved' : $defaultTab) ?>" data-sig-kind-input>
                <input type="hidden" name="use_saved_signature_id" value="" data-sig-saved-input>

                <?php if ($savedSigs): ?>
                <!-- Saved -->
                <div data-sig-panel="saved">
                    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&display=swap" rel="stylesheet">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--sp-3);">
                        <?php foreach ($savedSigs as $s): ?>
                            <label style="display:block; cursor: pointer; border: 2px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3); background: #fff; transition: border-color 120ms;">
                                <input type="radio" name="saved_signature_pick" value="<?= (int)$s['id'] ?>" data-sig-saved-pick style="margin-bottom: 6px;">
                                <?php if ($s['kind'] === 'typed'): ?>
                                    <div style="font-family: 'Caveat', cursive; font-size: 20pt; color: var(--color-navy); line-height: 1.1;"><?= e((string)$s['typed_name']) ?></div>
                                <?php else: ?>
                                    <img src="/dashboard/signature-image.php?saved_id=<?= (int)$s['id'] ?>" alt="Saved signature" style="max-width: 100%; max-height: 70px; display: block;">
                                <?php endif; ?>
                                <div class="muted" style="font-size: var(--fs-xs); margin-top: 6px;">
                                    <?= e((string)($s['label'] ?: ucfirst($s['kind']))) ?>
                                    · saved <?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2);">Pick one — or switch to Type / Draw / Upload to use a fresh signature. Manage saved signatures from your <a href="/dashboard/profile.php">profile</a>.</div>
                </div>
                <?php endif; ?>

                <!-- Typed -->
                <div data-sig-panel="typed">
                    <label class="field__label" for="sig-name">Type your full name</label>
                    <input class="input" id="sig-name" name="signature_typed_name" placeholder="John Q. Resident"
                           style="font-family: 'Caveat', 'Brush Script MT', cursive; font-size: 28pt; padding: 8pt 12pt; height: auto;">
                    <div class="muted" style="font-size: var(--fs-xs); margin-top: 6px;">By typing your name, you intend it as your signature.</div>
                </div>

                <!-- Drawn -->
                <div data-sig-panel="drawn" hidden>
                    <label class="field__label">Draw your signature</label>
                    <div style="border: 1px dashed var(--color-border); border-radius: var(--r-md); background: #fff; padding: 4px;">
                        <canvas data-sig-canvas width="700" height="180" style="display:block; width: 100%; height: 180px; cursor: crosshair; touch-action: none;"></canvas>
                    </div>
                    <div class="row" style="justify-content: space-between; margin-top: 6px;">
                        <span class="muted" style="font-size: var(--fs-xs);">Use your finger on touch, mouse on desktop.</span>
                        <button type="button" class="btn btn--ghost" style="padding: 0.3rem 0.7rem; font-size: var(--fs-xs);" data-sig-clear>Clear</button>
                    </div>
                    <input type="hidden" name="signature_drawn_data" data-sig-drawn-input>
                </div>

                <!-- Uploaded -->
                <div data-sig-panel="uploaded" hidden>
                    <label class="field__label" for="sig-up">Upload a signature image (PNG / JPG / WEBP · 3 MB max)</label>
                    <input class="input" type="file" id="sig-up" name="signature_upload" accept="image/png,image/jpeg,image/webp">
                </div>

                <!-- Save for future use (only shown when user makes a NEW signature) -->
                <div data-sig-save-row style="margin-top: var(--sp-3); padding: var(--sp-3); background: #fff; border: 1px dashed var(--color-border); border-radius: var(--r-md);">
                    <label style="display:flex; align-items:center; gap: var(--sp-2);">
                        <input type="checkbox" name="save_signature_for_later" value="1">
                        <span style="font-size: var(--fs-sm);">💾 Save this signature to my account for future forms <span class="muted">(private to you — only you can see and use it)</span></span>
                    </label>
                    <div data-sig-save-label-row style="margin-top: 8px; display:none;">
                        <input class="input" type="text" name="signature_label" placeholder="Label (optional, e.g. 'My signature' or 'Initials')" maxlength="80">
                    </div>
                </div>

                <!-- Consent -->
                <label style="display:flex; align-items:flex-start; gap: var(--sp-2); margin-top: var(--sp-4); padding: var(--sp-3); background: var(--color-warning-bg); border-radius: var(--r-md);">
                    <input type="checkbox" name="consent_given" <?= $requiresSig ? 'required' : '' ?> style="margin-top: 4px;">
                    <span style="font-size: var(--fs-sm);">
                        I consent to sign and receive records electronically. This electronic signature has the same legal effect as a handwritten signature under the federal <strong>E-SIGN Act</strong> and Florida's <strong>Uniform Electronic Transactions Act</strong>. I understand my IP address, timestamp, and device info will be recorded as part of the audit trail.
                    </span>
                </label>
            </fieldset>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/forms.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Submit + get confirmation code</button>
            </div>
        </form>

        <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&display=swap" rel="stylesheet">
        <style>
            .sig-tab {
                background: transparent; border: 0; border-bottom: 3px solid transparent;
                padding: 8px 16px; font: inherit; font-size: var(--fs-sm); color: var(--color-text-soft);
                cursor: pointer; transition: all 120ms ease;
            }
            .sig-tab:hover { color: var(--color-navy); }
            .sig-tab.is-active { color: var(--color-navy); border-bottom-color: var(--color-orange); font-weight: 600; }
        </style>
        <script>
        (function () {
            var pad = document.querySelector('[data-sig-pad]');
            if (!pad) return;
            var tabs    = pad.querySelectorAll('[data-sig-tab]');
            var panels  = pad.querySelectorAll('[data-sig-panel]');
            var kindIn  = pad.querySelector('[data-sig-kind-input]');
            var canvas  = pad.querySelector('[data-sig-canvas]');
            var drawnIn = pad.querySelector('[data-sig-drawn-input]');
            var clearBtn= pad.querySelector('[data-sig-clear]');

            var savedIn   = pad.querySelector('[data-sig-saved-input]');
            var saveRow   = pad.querySelector('[data-sig-save-row]');
            var saveLabel = pad.querySelector('[data-sig-save-label-row]');
            var saveChk   = saveRow ? saveRow.querySelector('input[name="save_signature_for_later"]') : null;

            function switchTo(kind) {
                tabs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.sigTab === kind); });
                panels.forEach(function (p) { p.hidden = p.dataset.sigPanel !== kind; });
                kindIn.value = kind;
                // Saved-tab uses the saved-id; the kind field is sent as 'saved'
                // and the server resolves it from use_saved_signature_id.
                if (kind === 'drawn') initCanvas();
                if (kind === 'saved') {
                    if (saveRow) saveRow.style.display = 'none'; // can't "save again" what's already saved
                    if (savedIn && !savedIn.value) {
                        var first = pad.querySelector('[data-sig-saved-pick]');
                        if (first) { first.checked = true; savedIn.value = first.value; }
                    }
                } else {
                    if (saveRow) saveRow.style.display = '';
                    if (savedIn) savedIn.value = '';
                }
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { switchTo(t.dataset.sigTab); }); });

            // Saved-radio picker
            pad.querySelectorAll('[data-sig-saved-pick]').forEach(function (r) {
                r.addEventListener('change', function () {
                    if (savedIn) savedIn.value = r.value;
                });
            });
            // Reveal label input when "Save this signature" gets ticked
            if (saveChk && saveLabel) {
                saveChk.addEventListener('change', function () { saveLabel.style.display = saveChk.checked ? '' : 'none'; });
            }

            // Canvas setup is lazy — only when the Draw tab is opened
            var initialized = false;
            function initCanvas() {
                if (initialized || !canvas) return;
                initialized = true;
                var ctx = canvas.getContext('2d');
                // Scale to device pixel ratio for crisp lines
                var dpr = Math.max(1, window.devicePixelRatio || 1);
                var rect = canvas.getBoundingClientRect();
                canvas.width  = rect.width  * dpr;
                canvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);
                ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
                ctx.strokeStyle = '#0f1f3d';
                var drawing = false, last = null;
                function pt(e) {
                    var r = canvas.getBoundingClientRect();
                    var t = e.touches ? e.touches[0] : e;
                    return { x: t.clientX - r.left, y: t.clientY - r.top };
                }
                function start(e) { e.preventDefault(); drawing = true; last = pt(e); }
                function move(e)  { if (!drawing) return; e.preventDefault();
                    var p = pt(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; }
                function end()    { drawing = false; last = null;
                    drawnIn.value = canvas.toDataURL('image/png'); }
                canvas.addEventListener('mousedown', start);
                canvas.addEventListener('mousemove', move);
                window.addEventListener('mouseup', end);
                canvas.addEventListener('touchstart', start, { passive: false });
                canvas.addEventListener('touchmove',  move,  { passive: false });
                canvas.addEventListener('touchend',   end);
            }
            if (clearBtn) clearBtn.addEventListener('click', function () {
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawnIn.value = '';
            });
        })();
        </script>
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

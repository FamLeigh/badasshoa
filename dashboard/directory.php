<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;
$importSummary = null;

// --- CSV bulk import (board admin only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'CSV upload failed.';
    } elseif ($_FILES['csv']['size'] > 1 * 1024 * 1024) {
        $flashError = 'Max CSV size is 1 MB.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $flashError = 'Could not read CSV.';
        } else {
            $added = 0; $skipped = 0; $errors = [];
            $row = 0;
            $headerMap = null;

            while (($cols = fgetcsv($fh)) !== false) {
                $row++;
                if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

                // First non-empty row = header
                if ($headerMap === null) {
                    $headerMap = [];
                    foreach ($cols as $i => $name) {
                        $key = strtolower(trim(str_replace(' ', '_', (string)$name)));
                        $headerMap[$key] = $i;
                    }
                    // Only unit_number is required now — names and email can be filled in later.
                    if (!isset($headerMap['unit_number'])) {
                        $flashError = "Missing required column: unit_number. Required: unit_number. Optional: first_name, last_name, email, phone, is_owner.";
                        break;
                    }
                    continue;
                }

                $get = fn(string $k) => isset($headerMap[$k], $cols[$headerMap[$k]]) ? trim((string)$cols[$headerMap[$k]]) : '';
                $unit       = $get('unit_number');
                $first      = $get('first_name');
                $last       = $get('last_name');
                $email      = $get('email');
                // Accept either `email2` or `alt_email` for the secondary email column.
                $email2     = $get('email2') !== '' ? $get('email2') : $get('alt_email');
                $phone      = $get('phone');
                // Same alias support for phone2 / alt_phone.
                $phone2     = $get('phone2') !== '' ? $get('phone2') : $get('alt_phone');
                if ($email2 !== '' && !filter_var($email2, FILTER_VALIDATE_EMAIL)) $email2 = '';

                // Mailing address handling. The CSV can supply either
                //   mailing_address + mailing_city + mailing_state_region + mailing_postal_code
                // (the granular schema fields) or a combined `city_state_zip` string
                // like "Daytona Beach, FL 32118" which we'll parse into pieces.
                $mAddr = $get('mailing_address');
                $mCity = $get('mailing_city');
                $mState = $get('mailing_state_region');
                $mPostal = $get('mailing_postal_code');
                $mCountry = strtoupper($get('mailing_country'));
                $csz = $get('city_state_zip');
                if ($csz !== '' && ($mCity === '' || $mState === '' || $mPostal === '')) {
                    // Match "City, ST ZIP" with optional ZIP+4 like 32124-3784.
                    if (preg_match('/^(.+?),\s*([A-Za-z]{2})\s+([\w\-]+)$/u', $csz, $m)) {
                        if ($mCity === '')   $mCity   = trim($m[1]);
                        if ($mState === '')  $mState  = strtoupper($m[2]);
                        if ($mPostal === '') $mPostal = trim($m[3]);
                    } else {
                        // International / non-standard — stash whole thing in city.
                        if ($mCity === '') $mCity = $csz;
                    }
                }
                if ($mCountry !== '' && !preg_match('/^[A-Z]{2}$/', $mCountry)) $mCountry = '';

                $isOwnerRaw = strtolower($get('is_owner'));
                $isOwner    = in_array($isOwnerRaw, ['1','y','yes','owner','true'], true) ? 1
                            : (in_array($isOwnerRaw, ['0','n','no','renter','false'], true) ? 0 : 1);

                if ($unit === '' && $first === '' && $last === '' && $email === '') {
                    continue; // blank row, ignore
                }

                $hasRealEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

                if ($email !== '' && !$hasRealEmail) {
                    $errors[] = "Row $row: invalid email \"$email\" (left blank instead?)";
                    continue;
                }

                if ($hasRealEmail) {
                    // Skip if a user with this real email already exists.
                    $check = db()->prepare('SELECT id FROM users WHERE email = ?');
                    $check->execute([$email]);
                    if ($check->fetchColumn()) { $skipped++; continue; }
                    $finalEmail = $email;
                } else {
                    // No email on file — synthesize a unique placeholder that satisfies the
                    // UNIQUE NOT NULL constraint and is parseable later when the real email
                    // becomes available. Pattern: noemail+<8-hex>@placeholder.local
                    $finalEmail = 'noemail+' . bin2hex(random_bytes(4)) . '@placeholder.local';
                }

                $tempPass = bin2hex(random_bytes(6));
                $hash     = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $role     = $isOwner ? 'owner' : 'renter';
                db()->prepare(
                    'INSERT INTO users (association_id, first_name, last_name, email, email2, phone, phone2,
                                        mailing_address, mailing_city, mailing_state_region, mailing_postal_code, mailing_country,
                                        password_hash, role, unit_number, is_owner, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
                )->execute([
                    $assocId, $first, $last, $finalEmail, $email2 ?: null, $phone ?: null, $phone2 ?: null,
                    $mAddr ?: null, $mCity ?: null, $mState ?: null, $mPostal ?: null, $mCountry ?: null,
                    $hash, $role, $unit ?: null, $isOwner,
                ]);
                $newId = (int)db()->lastInsertId();

                // Only send the welcome email when we have a real address.
                if ($hasRealEmail) {
                    send_mail($finalEmail, "You've been invited to {$association['name']}",
                        "Hi $first,\n\nYou've been added to {$association['name']} on BadassHOA.\n\nSign in: " .
                        (config()['app']['base_url'] ?? '') . "/login.php\nEmail: $finalEmail\nTemporary password: $tempPass\n(Change it on first sign-in.)\n");
                }
                audit('user.imported', ['email' => $hasRealEmail ? $finalEmail : '(no email)', 'unit' => $unit], $newId, 'user');
                $added++;
            }
            fclose($fh);
            $importSummary = ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
            audit('directory.imported', $importSummary);
        }
    }
}

// --- Invite a new user ---

// --- Add a new member ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'invite') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $first   = trim((string)($_POST['first_name'] ?? ''));
    $last    = trim((string)($_POST['last_name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $role    = $_POST['role'] ?? 'owner';
    $unit    = trim((string)($_POST['unit_number'] ?? ''));
    $isOwner = isset($_POST['is_owner']) ? 1 : 0;

    $allowedRoles = ['owner','renter','board_member','board_admin','property_manager','staff'];
    if (!in_array($role, $allowedRoles, true)) $role = 'owner';
    $showOnLanding = isset($_POST['show_on_public_landing']) ? 1 : 0;

    $raContactId = ($_POST['rental_agent_contact_id'] ?? '') !== '' ? (int)$_POST['rental_agent_contact_id'] : null;

    $office = $_POST['board_office'] ?? '';
    if ($office !== '' && !array_key_exists($office, board_offices())) $office = '';
    // If a board office is selected but the role is currently resident/renter,
    // auto-promote to board_member — picking an office is a clear signal that
    // this person is on the board. (Picking board_admin would over-grant
    // privileges, so we land on the least-privilege board role.) Property
    // managers keep their role.
    if ($office !== '' && in_array($role, ['owner','renter'], true)) {
        $role = 'board_member';
    }
    // Office only applies to board roles + PM. Clear it otherwise.
    if (!in_array($role, ['board_admin','board_member','property_manager'], true)) $office = '';

    // Optional password override — admin can type one or use Generate Random.
    $pw1 = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password_confirm'] ?? '');
    $customPassword = null;
    if ($pw1 !== '' || $pw2 !== '') {
        if ($pw1 !== $pw2) {
            $flashError = "Passwords don't match.";
        } elseif (strlen($pw1) < 8) {
            $flashError = 'Password must be at least 8 characters.';
        } else {
            $customPassword = $pw1;
        }
    }

    if (!$flashError) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Valid email is required.';
        } else {
            $existing = db()->prepare('SELECT id FROM users WHERE email = ?');
            $existing->execute([$email]);
            if ($existing->fetchColumn()) {
                $flashError = 'A user with that email already exists.';
            } else {
                $plaintextPassword = $customPassword ?? bin2hex(random_bytes(6));
                $hash = password_hash($plaintextPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                // Status = active so they can log in immediately and change password via /forgot.php
                // or /dashboard/settings.php.
                $stmt = db()->prepare(
                    'INSERT INTO users (association_id, first_name, last_name, email, phone, password_hash, role, board_office, unit_number, is_owner, show_on_public_landing, rental_agent_contact_id, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
                );
                $stmt->execute([$assocId, $first, $last, $email, $phone ?: null, $hash, $role, $office ?: null, $unit ?: null, $isOwner, $showOnLanding, $raContactId]);
                $newId = (int)db()->lastInsertId();

                $pwLine = $customPassword
                    ? "Your password (set by " . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) . "):\n  $plaintextPassword"
                    : "Temporary password:\n  $plaintextPassword\n\nPlease change it after you sign in (Settings → Change password).";

                send_mail($email, "You've been added to {$association['name']}",
                    "Hi $first,\n\n"
                    . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) . " added you to {$association['name']} on BadassHOA.\n\n"
                    . "Sign in: " . (config()['app']['base_url'] ?? '') . "/login.php\n"
                    . "Email:  $email\n"
                    . $pwLine . "\n");

                audit('user.added', ['email' => $email, 'role' => $role, 'password_set_by_admin' => $customPassword !== null], $newId, 'user');
                $msg = "Added $email.";
                if ($customPassword) $msg .= " Share the password with them via a secure channel.";
                flash('success', $msg);
                redirect('/dashboard/directory.php');
            }
        }
    }
}

// --- Deactivate handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'deactivate') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id !== (int)$user['id']) { // can't deactivate self
        db()->prepare('UPDATE users SET status = "inactive" WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
        audit('user.deactivated', [], $id, 'user');
        flash('success', 'User deactivated.');
    }
    redirect('/dashboard/directory.php');
}

// --- Edit handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $id     = (int)($_POST['id'] ?? 0);
    $first  = trim((string)($_POST['first_name'] ?? ''));
    $last   = trim((string)($_POST['last_name'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $phone  = trim((string)($_POST['phone'] ?? ''));
    $phone2 = trim((string)($_POST['phone2'] ?? ''));
    $email2 = trim((string)($_POST['email2'] ?? ''));
    if ($email2 !== '' && !filter_var($email2, FILTER_VALIDATE_EMAIL)) $email2 = '';
    $mAddr   = trim((string)($_POST['mailing_address'] ?? ''));
    $mCity   = trim((string)($_POST['mailing_city'] ?? ''));
    $mState  = trim((string)($_POST['mailing_state_region'] ?? ''));
    $mPostal = trim((string)($_POST['mailing_postal_code'] ?? ''));
    $mCtry   = strtoupper(trim((string)($_POST['mailing_country'] ?? '')));
    if ($mCtry !== '' && !preg_match('/^[A-Z]{2}$/', $mCtry)) $mCtry = '';
    $unit   = trim((string)($_POST['unit_number'] ?? ''));
    $role   = $_POST['role'] ?? 'owner';
    $isOwner = isset($_POST['is_owner']) ? 1 : 0;
    $status  = $_POST['status'] ?? 'active';

    $allowedRoles  = ['owner','renter','board_member','board_admin','property_manager','staff'];
    $allowedStatus = ['active','pending','inactive'];
    if (!in_array($role, $allowedRoles, true))   $role   = 'owner';
    if (!in_array($status, $allowedStatus, true)) $status = 'active';
    $showOnLanding = isset($_POST['show_on_public_landing']) ? 1 : 0;

    $raContactId = ($_POST['rental_agent_contact_id'] ?? '') !== '' ? (int)$_POST['rental_agent_contact_id'] : null;

    $editPw1 = (string)($_POST['new_password'] ?? '');
    $editPw2 = (string)($_POST['new_password_confirm'] ?? '');
    $newHash = null;
    if ($editPw1 !== '' || $editPw2 !== '') {
        if ($editPw1 !== $editPw2) {
            $flashError = "Passwords don't match.";
        } elseif (strlen($editPw1) < 8) {
            $flashError = 'Password must be at least 8 characters.';
        } else {
            $newHash = password_hash($editPw1, PASSWORD_BCRYPT, ['cost' => 12]);
        }
    }

    $office = $_POST['board_office'] ?? '';
    if ($office !== '' && !array_key_exists($office, board_offices())) $office = '';
    $autoPromoted = false;
    // If a board office is selected but the role is currently resident/renter,
    // auto-promote to board_member. Without this the silent strip-office
    // behavior catches admins by surprise (already happened twice).
    if ($office !== '' && in_array($role, ['owner','renter'], true)) {
        $role = 'board_member';
        $autoPromoted = true;
    }
    // Office only applies to board roles + PM. Clear it otherwise so a demoted
    // board member doesn't keep an orphaned "President" label.
    if (!in_array($role, ['board_admin','board_member','property_manager'], true)) $office = '';

    // Verify the row belongs to this association.
    $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
    $check->execute([$id, $assocId]);
    if (!$check->fetchColumn()) {
        $flashError = 'User not found in this association.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Valid email is required.';
    } else {
        // Email-uniqueness check (allow keeping same email)
        $dupe = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $dupe->execute([$email, $id]);
        if ($dupe->fetchColumn()) {
            $flashError = 'Another user already has that email.';
        } else {
            // Refuse to demote / deactivate yourself, to avoid self-lockout
            if ($id === (int)$user['id']) {
                if ($status !== 'active' || ($role !== 'board_admin' && $user['role'] === 'board_admin')) {
                    $flashError = "You can't change your own role or status. Ask another board admin.";
                }
            }
            if (!$flashError) {
                db()->beginTransaction();
                try {
                    db()->prepare(
                        'UPDATE users SET first_name = ?, last_name = ?, email = ?, email2 = ?, phone = ?, phone2 = ?,
                                           mailing_address = ?, mailing_city = ?, mailing_state_region = ?,
                                           mailing_postal_code = ?, mailing_country = ?,
                                           unit_number = ?, role = ?, board_office = ?, is_owner = ?, status = ?,
                                           show_on_public_landing = ?, rental_agent_contact_id = ?
                         WHERE id = ? AND association_id = ?'
                    )->execute([
                        $first, $last, $email, $email2 ?: null, $phone ?: null, $phone2 ?: null,
                        $mAddr ?: null, $mCity ?: null, $mState ?: null, $mPostal ?: null, $mCtry ?: null,
                        $unit ?: null, $role, $office ?: null, $isOwner, $status, $showOnLanding,
                        $raContactId,
                        $id, $assocId,
                    ]);

                    if ($newHash !== null) {
                        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND association_id = ?')
                            ->execute([$newHash, $id, $assocId]);
                        // Invalidate any pending reset tokens for this user.
                        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);
                    }

                    // Upsert per-unit details. Saved against $assocId + $unit.
                    if ($unit !== '') {
                        $type        = $_POST['unit_type'] ?? 'condo';
                        $allowedTypes = ['condo','townhouse','single_family','apartment','other'];
                        if (!in_array($type, $allowedTypes, true)) $type = 'condo';

                        $bedrooms = ($_POST['bedrooms']         ?? '') !== '' ? (int)$_POST['bedrooms']        : null;
                        $baths    = ($_POST['baths']            ?? '') !== '' ? (float)$_POST['baths']         : null;
                        $sqft     = ($_POST['square_footage']   ?? '') !== '' ? (int)$_POST['square_footage']  : null;
                        $ownPct   = ($_POST['ownership_percent']?? '') !== '' ? (float)$_POST['ownership_percent'] : null;

                        // Only write the row if at least one detail is set OR row already exists
                        // (so we don't litter the table with empty rows for every unit_number assignment).
                        $hasAnyDetail = $bedrooms !== null || $baths !== null || $sqft !== null || $ownPct !== null;
                        $existsStmt = db()->prepare('SELECT id FROM units WHERE association_id = ? AND unit_number = ?');
                        $existsStmt->execute([$assocId, $unit]);
                        $existsId = $existsStmt->fetchColumn();

                        if ($hasAnyDetail || $existsId) {
                            db()->prepare(
                                'INSERT INTO units (association_id, unit_number, type, bedrooms, baths, square_footage, ownership_percent)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE
                                    type = VALUES(type),
                                    bedrooms = VALUES(bedrooms),
                                    baths = VALUES(baths),
                                    square_footage = VALUES(square_footage),
                                    ownership_percent = VALUES(ownership_percent)'
                            )->execute([$assocId, $unit, $type, $bedrooms, $baths, $sqft, $ownPct]);
                        }
                    }

                    db()->commit();
                } catch (Throwable $e) {
                    db()->rollBack();
                    $flashError = 'Update failed: ' . $e->getMessage();
                }

                if (!$flashError) {
                    audit('user.edited', [
                        'email' => $email, 'role' => $role, 'status' => $status,
                        'board_office' => $office ?: null,
                        'auto_promoted_to_board_member' => $autoPromoted,
                    ], $id, 'user');
                    $msg = 'Member updated.';
                    if (!empty($autoPromoted)) {
                        $msg .= ' Role auto-promoted to <strong>Board member</strong> because a board office (' . htmlspecialchars(board_office_label($office), ENT_QUOTES, 'UTF-8') . ') was selected.';
                    }
                    flash('success', $msg);
                    redirect('/dashboard/directory.php');
                }
            }
        }
    }
}

// Board members. Sort by office seniority first (President → ... → Director),
// then anyone without an office (NULL bubbles to the end via FIELD()), then by
// role tier as the previous secondary sort, then by name.
// When a search is active, filter the board section to only matching members.
$boardSql    = "SELECT * FROM users
     WHERE association_id = ? AND role IN ('board_admin','board_member','property_manager') AND status <> 'inactive'";
$boardParams = [$assocId];
if ($qSearch !== '') {
    $boardSql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR unit_number LIKE ?)";
    $boardLike = "%$qSearch%";
    array_push($boardParams, $boardLike, $boardLike, $boardLike, $boardLike);
}
$boardSql .= " ORDER BY FIELD(board_office,
                    'president','vice_president','secretary','treasurer',
                    'secretary_treasurer','director') = 0,
              FIELD(board_office,
                    'president','vice_president','secretary','treasurer',
                    'secretary_treasurer','director'),
              FIELD(role,'board_admin','board_member','property_manager'),
              last_name, first_name";
$boardStmt = db()->prepare($boardSql);
$boardStmt->execute($boardParams);
$board = $boardStmt->fetchAll();

// Residents
$qSearch     = trim((string)($_GET['q'] ?? ''));
$ownersOnly  = isset($_GET['owners_only']);
$roleFilter  = $_GET['role'] ?? '';
$validRoles  = ['owner','renter','staff','board_member','board_admin','property_manager'];
if (!in_array($roleFilter, $validRoles, true)) $roleFilter = '';

// Privacy: renters only see the Board section — not the full resident roster.
// (Their landlord's contact info is the building's responsibility, not a
// neighbor's, and renters don't need to see other unit owners' details.)
$rentersOnly = (viewing_role() === 'renter');

if ($rentersOnly) {
    $residents = [];
} else {
    $sql = "SELECT u.*,
                   (SELECT job_title FROM employees e
                     WHERE e.user_id = u.id AND e.association_id = u.association_id AND e.status = 'active'
                     ORDER BY e.id DESC LIMIT 1) AS employee_job_title
              FROM users u
             WHERE u.association_id = ? AND u.status <> 'inactive'";
    $params = [$assocId];
    if ($qSearch !== '') {
        $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.unit_number LIKE ?)";
        $like = "%$qSearch%";
        array_push($params, $like, $like, $like, $like);
    }
    if ($ownersOnly) {
        $sql .= ' AND u.is_owner = 1';
    }
    if ($roleFilter !== '') {
        $sql .= ' AND u.role = ?';
        $params[] = $roleFilter;
    }
    // Natural alphanumeric sort: numeric prefix first (so "101" < "101A"), then full string lex,
    // then name. Letter-prefixed units (CAST = 0) bubble to the top — acceptable since most
    // condos use number-prefixed units; document if it becomes an issue.
    $sql .= ' ORDER BY CAST(u.unit_number AS UNSIGNED), u.unit_number, u.last_name, u.first_name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $residents = $stmt->fetchAll();
}

// Rental agents for dropdowns and list display
$rentalAgents = [];
$raStmt = db()->prepare("SELECT id, label FROM association_contacts WHERE association_id = ? AND kind = 'rental_agent' ORDER BY label");
$raStmt->execute([$assocId]);
$rentalAgents = $raStmt->fetchAll();
$rentalAgentMap = array_column($rentalAgents, 'label', 'id'); // id => label

$showInvite = ($_GET['action'] ?? '') === 'invite' && $canManage;
$showImport = ($_GET['action'] ?? '') === 'import' && $canManage;

// Edit view loads the target user + their unit's details
$editUser   = null;
$editUnit   = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editUser = $stmt->fetch() ?: null;

    if ($editUser && !empty($editUser['unit_number'])) {
        $u = db()->prepare('SELECT * FROM units WHERE association_id = ? AND unit_number = ?');
        $u->execute([$assocId, $editUser['unit_number']]);
        $editUnit = $u->fetch() ?: null;
    }
}

$page_title = 'Directory — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Directory</h1>
            <p class="muted">Board members and residents.</p>
        </div>
        <?php if ($canManage): ?>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="?action=import">⬆ Import CSV</a>
                <a class="btn btn--primary" href="?action=invite">+ Add member</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($importSummary): ?>
        <div class="flash flash--success">
            Imported <strong><?= (int)$importSummary['added'] ?></strong> new member<?= $importSummary['added']===1?'':'s' ?>.
            <?php if ($importSummary['skipped']): ?>Skipped <?= (int)$importSummary['skipped'] ?> existing email<?= $importSummary['skipped']===1?'':'s' ?>.<?php endif; ?>
            <?php if (!empty($importSummary['errors'])): ?>
                <details style="margin-top: var(--sp-2);">
                    <summary><?= count($importSummary['errors']) ?> row<?= count($importSummary['errors'])===1?'':'s' ?> errored</summary>
                    <ul style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">
                        <?php foreach ($importSummary['errors'] as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($editUser): ?>
    <div class="row" style="margin-bottom: var(--sp-3);">
        <a class="btn btn--ghost" href="/dashboard/directory.php">← Back to directory</a>
    </div>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">Edit member</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit">
            <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ef">First name</label>
                    <input class="input" id="ef" name="first_name" value="<?= e((string)$editUser['first_name']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="el">Last name</label>
                    <input class="input" id="el" name="last_name" value="<?= e((string)$editUser['last_name']) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ee">Email</label>
                    <input class="input" type="email" id="ee" name="email" required value="<?= e(is_placeholder_email((string)$editUser['email']) ? '' : (string)$editUser['email']) ?>" placeholder="<?= is_placeholder_email((string)$editUser['email']) ? 'no email on file yet' : '' ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ep">Phone</label>
                    <input class="input" id="ep" name="phone" value="<?= e((string)($editUser['phone'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ee2">Second email <span class="muted" style="font-weight: 400;">(optional)</span></label>
                    <input class="input" type="email" id="ee2" name="email2" value="<?= e((string)($editUser['email2'] ?? '')) ?>" placeholder="personal or spouse">
                </div>
                <div class="field">
                    <label class="field__label" for="ep2">Second phone <span class="muted" style="font-weight: 400;">(optional)</span></label>
                    <input class="input" id="ep2" name="phone2" value="<?= e((string)($editUser['phone2'] ?? '')) ?>" placeholder="cell or work">
                </div>
            </div>

            <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Mailing address (optional — for absentee owners)</legend>
                <div class="field">
                    <label class="field__label" for="ema">Street address</label>
                    <input class="input" id="ema" name="mailing_address" value="<?= e((string)($editUser['mailing_address'] ?? '')) ?>" placeholder="123 Main St">
                </div>
                <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                    <div class="field">
                        <label class="field__label" for="emc">City</label>
                        <input class="input" id="emc" name="mailing_city" value="<?= e((string)($editUser['mailing_city'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label class="field__label" for="ems">State / Province</label>
                        <input class="input" id="ems" name="mailing_state_region" list="us-ca-states" value="<?= e((string)($editUser['mailing_state_region'] ?? '')) ?>" autocomplete="address-level1">
                    </div>
                    <div class="field">
                        <label class="field__label" for="emp">ZIP / Postal</label>
                        <input class="input" id="emp" name="mailing_postal_code" value="<?= e((string)($editUser['mailing_postal_code'] ?? '')) ?>" autocomplete="postal-code">
                    </div>
                </div>
                <?= function_exists('us_ca_states_datalist') ? us_ca_states_datalist() : '' ?>
                <div class="field">
                    <label class="field__label" for="emy">Country</label>
                    <select class="select" id="emy" name="mailing_country" style="max-width: 280px;">
                        <option value="">— Same as unit —</option>
                        <?php $cur = (string)($editUser['mailing_country'] ?? '');
                        foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                            <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="eu">Unit #</label>
                    <input class="input" id="eu" name="unit_number" value="<?= e((string)($editUser['unit_number'] ?? '')) ?>" placeholder="101A">
                </div>
                <div class="field">
                    <label class="field__label" for="er">Role</label>
                    <select class="select" id="er" name="role">
                        <?php foreach (['owner'=>'Owner','renter'=>'Renter','staff'=>'Staff','board_member'=>'Board member','board_admin'=>'Board admin','property_manager'=>'Property manager'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $editUser['role'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row" style="display:grid; grid-template-columns: 1.4fr 1fr 1fr; gap: var(--sp-3);">
                <div class="field">
                    <label class="field__label" for="eo">Board office <span class="muted" style="font-weight: normal;">(board roles only)</span></label>
                    <select class="select" id="eo" name="board_office">
                        <option value="">— no office —</option>
                        <?php foreach (board_offices() as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= (($editUser['board_office'] ?? '') === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Display label only. Doesn't change admin capabilities — those still flow from the role.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="es">Status</label>
                    <select class="select" id="es" name="status">
                        <?php foreach (['active'=>'Active','pending'=>'Pending','inactive'=>'Inactive'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $editUser['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="justify-content: center;">
                    <label class="field__label">&nbsp;</label>
                    <label style="display:flex; align-items:center; gap: var(--sp-2);">
                        <input type="checkbox" name="is_owner" <?= $editUser['is_owner'] ? 'checked' : '' ?>> Owner (uncheck for renter)
                    </label>
                </div>
            </div>

            <?php if ($rentalAgents && $editUser['role'] === 'renter'): ?>
            <div class="field" style="margin-top: var(--sp-2);">
                <label class="field__label" for="era">Rental agent <span class="muted" style="font-weight:normal;">(optional)</span></label>
                <select class="select" id="era" name="rental_agent_contact_id">
                    <option value="">— none —</option>
                    <?php foreach ($rentalAgents as $ra): ?>
                        <option value="<?= (int)$ra['id'] ?>" <?= (int)($editUser['rental_agent_contact_id'] ?? 0) === (int)$ra['id'] ? 'selected' : '' ?>><?= e($ra['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (in_array($editUser['role'], ['board_admin','board_member','property_manager'], true)): ?>
            <div class="field" style="margin-top: var(--sp-2); padding: var(--sp-3); background: var(--color-info-bg); border: 1px solid rgba(38,96,168,0.2); border-radius: var(--r-md);">
                <label style="display:flex; align-items:center; gap: var(--sp-3); cursor: pointer;">
                    <input type="checkbox" name="show_on_public_landing" value="1" <?= !empty($editUser['show_on_public_landing']) ? 'checked' : '' ?>>
                    <div>
                        <strong>Show this person in the public "Meet your board" section</strong>
                        <div class="muted" style="font-size: var(--fs-sm);">
                            Only their first name + last initial + role badge will be shown — never email or phone. Off by default for privacy.
                        </div>
                    </div>
                </label>
            </div>
            <?php endif; ?>

            <!-- Unit details (per-unit, shared across anyone living there) -->
            <div style="margin-top: var(--sp-4); padding: var(--sp-4); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md);">
                <div style="margin-bottom: var(--sp-3);">
                    <strong>🏠 Unit details</strong>
                    <span class="muted" style="font-size: var(--fs-sm);">
                        — properties of <em>the unit</em>, shared across anyone living there.
                        <?php if ($editUnit): ?>
                            <span style="color: var(--color-success);">Existing record found for unit <?= e((string)$editUnit['unit_number']) ?>.</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="form-row form-row--2" style="grid-template-columns: 1fr 1fr 0.7fr 0.7fr; gap: var(--sp-3);">
                    <div class="field">
                        <label class="field__label" for="utype">Type</label>
                        <select class="select" id="utype" name="unit_type">
                            <?php
                            $cur = $editUnit['type'] ?? 'condo';
                            foreach ([
                                'condo'         => 'Condo',
                                'townhouse'     => 'Townhouse',
                                'single_family' => 'Single-family home',
                                'apartment'     => 'Apartment',
                                'other'         => 'Other',
                            ] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $cur === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="usqft">Square footage</label>
                        <input class="input" type="number" id="usqft" name="square_footage" min="0" max="100000" step="1" value="<?= e((string)($editUnit['square_footage'] ?? '')) ?>" placeholder="1500">
                    </div>
                    <div class="field">
                        <label class="field__label" for="ubeds">Bedrooms</label>
                        <input class="input" type="number" id="ubeds" name="bedrooms" min="0" max="20" step="1" value="<?= e((string)($editUnit['bedrooms'] ?? '')) ?>" placeholder="2">
                    </div>
                    <div class="field">
                        <label class="field__label" for="ubaths">Baths</label>
                        <input class="input" type="number" id="ubaths" name="baths" min="0" max="20" step="0.5" value="<?= e((string)($editUnit['baths'] ?? '')) ?>" placeholder="2.5">
                    </div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="uown">Ownership %</label>
                        <input class="input" type="number" id="uown" name="ownership_percent" min="0" max="100" step="0.0001" value="<?= e((string)($editUnit['ownership_percent'] ?? '')) ?>" placeholder="2.0833">
                        <div class="field__hint">Share of common expenses for this unit. Most associations base it on square footage.</div>
                    </div>
                    <div class="field"><!-- spacer --></div>
                </div>
            </div>

            <div style="margin-top: var(--sp-4); padding: var(--sp-4); background: var(--color-warning-bg); border: 1px solid rgba(182,130,42,0.25); border-radius: var(--r-md);">
                <div class="row row--between" style="margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <strong style="color: var(--color-warning);">🔑 Change password (optional)</strong>
                    <button type="button" class="btn btn--ghost" id="edit-gen-pw" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Generate random</button>
                </div>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">Leave blank to keep the current password. If you set one, share it with them via a secure channel.</p>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="edit-pw1">New password</label>
                        <input class="input" type="text" id="edit-pw1" name="new_password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field__label" for="edit-pw2">Confirm</label>
                        <input class="input" type="text" id="edit-pw2" name="new_password_confirm" minlength="8" autocomplete="new-password" spellcheck="false">
                    </div>
                </div>
            </div>
            <script>
            document.getElementById('edit-gen-pw')?.addEventListener('click', function () {
                var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%';
                var pw = '';
                if (window.crypto && window.crypto.getRandomValues) {
                    var b = new Uint8Array(14);
                    crypto.getRandomValues(b);
                    for (var i = 0; i < b.length; i++) pw += chars.charAt(b[i] % chars.length);
                } else {
                    for (var i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('edit-pw1').value = pw;
                document.getElementById('edit-pw2').value = pw;
            });
            </script>

            <div class="row" style="justify-content: space-between; gap: var(--sp-2); flex-wrap: wrap;">
                <a class="btn btn--ghost" href="/dashboard/directory.php">Cancel</a>
                <div class="row" style="gap: var(--sp-2);">
                    <?php if ((int)$editUser['id'] !== (int)$user['id']): /* never let admin deactivate self */ ?>
                        <button class="btn btn--ghost" type="submit" name="form" value="deactivate"
                                onclick="return confirm('Deactivate <?= e(trim((string)$editUser['first_name'] . ' ' . (string)$editUser['last_name']) ?: (string)$editUser['email']) ?>? They\'ll be hidden from the directory and unable to sign in until you reactivate them.');"
                                style="color: var(--color-error);"
                                title="Mark this member inactive">
                            Deactivate
                        </button>
                    <?php endif; ?>
                    <button class="btn btn--primary" type="submit">Save changes</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showImport): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">Import members from CSV</h3>
        <p class="muted" style="font-size: var(--fs-sm);">
            Required column: <code>unit_number</code>.
            Optional: <code>first_name, last_name, email, email2, phone, phone2, is_owner</code> (1/0 or yes/no).
            Rows without an email are imported with a placeholder address — the user shows in the directory as <em>(no email on file)</em> and gets no welcome email. Edit them later via the Directory's Edit button to set a real email.
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">unit_number,first_name,last_name,email,email2,phone,phone2,is_owner
101,Maria,Rodriguez,maria@example.com,maria.work@example.com,555-0101,555-0202,1
102A,James,Lee,,,,,1
B2,Sam,Garcia,sam@example.com,,,,0</pre>
        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import">
            <div class="field">
                <label class="field__label" for="csv">CSV file (max 1 MB)</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/directory.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Import</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showInvite): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">Add a member</h3>
        <p class="muted" style="font-size: var(--fs-sm);">
            Creates an active member who can sign in immediately. They&rsquo;ll receive an email with their password.
        </p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="invite">
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="iname">First name</label><input class="input" id="iname" name="first_name"></div>
                <div class="field"><label class="field__label" for="ilast">Last name</label><input class="input" id="ilast" name="last_name"></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="iemail">Email</label><input class="input" type="email" id="iemail" name="email" required autocomplete="email"></div>
                <div class="field"><label class="field__label" for="iphone">Phone</label><input class="input" id="iphone" name="phone" autocomplete="tel" placeholder="555-1234"></div>
            </div>
            <div class="form-row form-row--2">
                <div class="field"><label class="field__label" for="iunit">Unit #</label><input class="input" id="iunit" name="unit_number" placeholder="101A"></div>
                <div class="field" style="justify-content: flex-end;">
                    <label class="field__label">&nbsp;</label>
                    <label style="display:flex; align-items:center; gap: var(--sp-2);">
                        <input type="checkbox" name="is_owner" checked> Owner (uncheck for renter)
                    </label>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="irole">Role</label>
                    <select class="select" id="irole" name="role">
                        <option value="owner">Owner</option>
                        <option value="renter">Renter</option>
                        <option value="staff">Staff</option>
                        <option value="board_member">Board member</option>
                        <option value="board_admin">Board admin</option>
                        <option value="property_manager">Property manager</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="ioffice">Board office <span class="muted" style="font-weight: normal;">(board roles only)</span></label>
                    <select class="select" id="ioffice" name="board_office">
                        <option value="">— no office —</option>
                        <?php foreach (board_offices() as $val => $label): ?>
                            <option value="<?= e($val) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field" style="justify-content: center;">
                    <label class="field__label">&nbsp;</label>
                    <label style="display:flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm);">
                        <input type="checkbox" name="show_on_public_landing" value="1"> Show in "Meet your board" (board roles only)
                    </label>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <?php if ($rentalAgents): ?>
            <div class="field" id="add-ra-field" style="display:none;">
                <label class="field__label" for="iara">Rental agent <span class="muted" style="font-weight:normal;">(optional)</span></label>
                <select class="select" id="iara" name="rental_agent_contact_id">
                    <option value="">— none —</option>
                    <?php foreach ($rentalAgents as $ra): ?>
                        <option value="<?= (int)$ra['id'] ?>"><?= e($ra['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <script>
            (function () {
                var roleEl  = document.getElementById('irole');
                var raField = document.getElementById('add-ra-field');
                if (!roleEl || !raField) return;
                function sync() { raField.style.display = roleEl.value === 'renter' ? '' : 'none'; }
                roleEl.addEventListener('change', sync);
                sync();
            })();
            </script>
            <?php endif; ?>

            <!-- Optional password override -->
            <div style="margin-top: var(--sp-2); padding: var(--sp-4); background: var(--color-warning-bg); border: 1px solid rgba(182,130,42,0.25); border-radius: var(--r-md);">
                <div class="row row--between" style="margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <strong style="color: var(--color-warning);">🔑 Set password (optional)</strong>
                    <button type="button" class="btn btn--ghost" id="add-gen-pw" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Generate random</button>
                </div>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">
                    Leave blank and we&rsquo;ll generate a random one and email it. Or set a specific password &mdash; you&rsquo;ll need to share it with them via a secure channel.
                </p>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="add-pw1">Password</label>
                        <input class="input" type="text" id="add-pw1" name="new_password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field__label" for="add-pw2">Confirm</label>
                        <input class="input" type="text" id="add-pw2" name="new_password_confirm" minlength="8" autocomplete="new-password" spellcheck="false">
                    </div>
                </div>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/directory.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Add member</button>
            </div>
        </form>
        <script>
            document.getElementById('add-gen-pw')?.addEventListener('click', function () {
                var chars = 'abcdefghjkmnpqrstuvwxyz' + 'ABCDEFGHJKMNPQRSTUVWXYZ' + '23456789' + '!@#$%';
                var pw = '';
                if (window.crypto && window.crypto.getRandomValues) {
                    var b = new Uint8Array(14);
                    crypto.getRandomValues(b);
                    for (var i = 0; i < b.length; i++) pw += chars.charAt(b[i] % chars.length);
                } else {
                    for (var i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('add-pw1').value = pw;
                document.getElementById('add-pw2').value = pw;
            });
        </script>
    </div>
    <?php endif; ?>

    <?php if (!$editUser && !$showInvite && !$showImport): /* hide the full directory while editing a single member */ ?>

    <h2 id="board" style="font-size: var(--fs-xl); margin-top: var(--sp-2); scroll-margin-top: 80px;">Board</h2>
    <?php if (!$board): ?>
        <p class="muted">No board members on file yet.</p>
    <?php else: ?>
    <style>
        .board-list { display: flex; flex-direction: column; margin-bottom: var(--sp-8); border: 1px solid var(--color-border); border-radius: var(--r-md); overflow: hidden; }
        .board-list__item { border-bottom: 1px solid var(--color-border); }
        .board-list__item:last-child { border-bottom: none; }
        .board-list__item > summary { display: flex; align-items: center; gap: var(--sp-3); padding: var(--sp-3) var(--sp-4); cursor: pointer; list-style: none; background: #fff; }
        .board-list__item > summary::-webkit-details-marker { display: none; }
        .board-list__item > summary::marker { display: none; }
        .board-list__item > summary:hover { background: var(--color-surface-2); }
        .board-list__item[open] > summary { background: var(--color-surface-2); }
        .board-list__chevron { margin-left: auto; color: var(--color-text-soft); font-size: var(--fs-xs); transition: transform 200ms ease; display: inline-block; }
        .board-list__item[open] .board-list__chevron { transform: rotate(180deg); }
        .board-list__detail { padding: var(--sp-5); background: var(--color-surface); border-top: 1px solid var(--color-border); }
    </style>
    <div class="board-list">
        <?php foreach ($board as $b): $officeLbl = board_office_label((string)($b['board_office'] ?? '')); ?>
        <details class="board-list__item">
            <summary>
                <?php if (!empty($b['avatar_path'])): ?>
                    <img src="/user-avatar.php?id=<?= (int)$b['id'] ?>" alt=""
                         style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex: 0 0 40px; border: 2px solid var(--color-border);">
                <?php else: ?>
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--color-navy); color: #fff; display: flex; align-items: center; justify-content: center; font-size: var(--fs-md); font-weight: 700; flex: 0 0 40px;">
                        <?= e(strtoupper(mb_substr((string)($b['first_name'] ?? '?'), 0, 1))) ?>
                    </div>
                <?php endif; ?>
                <div style="flex: 1; min-width: 0;">
                    <div class="row" style="gap: var(--sp-2); align-items: center; flex-wrap: wrap;">
                        <strong><?= e(trim($b['first_name'] . ' ' . $b['last_name']) ?: $b['email']) ?></strong>
                        <?php if ($officeLbl !== ''): ?>
                            <span class="badge badge--orange"><?= e($officeLbl) ?></span>
                        <?php endif; ?>
                        <span class="badge badge--navy"><?= e(str_replace('_',' ',$b['role'])) ?></span>
                    </div>
                    <div class="muted" style="font-size: var(--fs-sm); margin-top: 2px;">
                        <?= is_placeholder_email((string)$b['email']) ? '<em>no email on file</em>' : e((string)$b['email']) ?>
                        <?php if ($b['phone']): ?> &middot; <?= e($b['phone']) ?><?php endif; ?>
                    </div>
                </div>
                <span class="board-list__chevron">▼</span>
            </summary>
            <div class="board-list__detail">
                <div style="display: flex; gap: var(--sp-5); align-items: flex-start; flex-wrap: wrap;">
                    <?php if (!empty($b['avatar_path'])): ?>
                        <img src="/user-avatar.php?id=<?= (int)$b['id'] ?>" alt=""
                             style="width: 88px; height: 88px; border-radius: 50%; object-fit: cover; flex: 0 0 88px; border: 3px solid var(--color-border);">
                    <?php else: ?>
                        <div style="width: 88px; height: 88px; border-radius: 50%; background: var(--color-navy); color: #fff; display: flex; align-items: center; justify-content: center; font-size: var(--fs-2xl); font-weight: 700; flex: 0 0 88px;">
                            <?= e(strtoupper(mb_substr((string)($b['first_name'] ?? '?'), 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <strong style="font-size: var(--fs-lg);"><?= e(trim($b['first_name'] . ' ' . $b['last_name']) ?: $b['email']) ?></strong>
                        <div class="row" style="gap: var(--sp-2); margin: var(--sp-2) 0; flex-wrap: wrap;">
                            <?php if ($officeLbl !== ''): ?>
                                <span class="badge badge--orange"><?= e($officeLbl) ?></span>
                            <?php endif; ?>
                            <span class="badge badge--navy"><?= e(str_replace('_',' ',$b['role'])) ?></span>
                        </div>
                        <div class="muted" style="font-size: var(--fs-sm);">
                            <?= is_placeholder_email((string)$b['email']) ? '<em class="muted">— no email on file —</em>' : e((string)$b['email']) ?>
                            <?php if ($b['phone']): ?><br><?= e($b['phone']) ?><?php endif; ?>
                        </div>
                        <?php if ($b['unit_number']): ?>
                            <div class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);">Unit <?= e($b['unit_number']) ?></div>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <div style="margin-top: var(--sp-3);">
                                <a class="btn btn--ghost" style="padding: 0.3rem 0.7rem; font-size: var(--fs-sm);" href="?action=edit&id=<?= (int)$b['id'] ?>">Edit</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($rentersOnly): ?>
        <div class="card card--padded muted" style="margin-bottom: var(--sp-4);">
            Renter accounts can see board and management contacts above. Other unit owners' details aren't shown — for anything beyond board matters, please reach out to your landlord directly.
        </div>
    <?php else: ?>
    <h2 style="font-size: var(--fs-xl);">Residents <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— sorted by unit number</span></h2>
    <form method="get" class="row" style="margin-bottom: var(--sp-4); gap: var(--sp-3); flex-wrap: wrap;">
        <input class="input" type="search" name="q" placeholder="Search name, email, unit" value="<?= e($qSearch) ?>" style="max-width: 280px;">
        <select class="select" name="role" style="max-width: 200px;">
            <option value="">All roles</option>
            <?php foreach (['owner'=>'Owner','renter'=>'Renter','staff'=>'Staff','board_member'=>'Board member','board_admin'=>'Board admin','property_manager'=>'Property manager'] as $rv => $rl): ?>
                <option value="<?= e($rv) ?>" <?= $roleFilter === $rv ? 'selected' : '' ?>><?= e($rl) ?></option>
            <?php endforeach; ?>
        </select>
        <label style="display:inline-flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm);">
            <input type="checkbox" name="owners_only" value="1" <?= $ownersOnly ? 'checked' : '' ?>> Owners only
        </label>
        <button class="btn btn--ghost" type="submit">Apply</button>
        <?php if ($qSearch !== '' || $roleFilter !== '' || $ownersOnly): ?>
            <a class="muted" href="/dashboard/directory.php" style="font-size: var(--fs-sm); align-self: center;">clear</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if (!$rentersOnly): ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th><th>Unit</th><th>Role</th><th>Owner / Renter</th><th>Email</th><th>Phone</th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($residents as $r): ?>
            <tr>
                <td>
                    <div class="row" style="gap: var(--sp-2); align-items:center;">
                        <?php if (!empty($r['avatar_path'])): ?>
                            <img src="/user-avatar.php?id=<?= (int)$r['id'] ?>" alt="" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex: 0 0 32px;">
                        <?php else: ?>
                            <span class="side-nav__avatar" style="background: var(--color-text-soft);"><?= e(strtoupper(mb_substr((string)($r['first_name'] ?? '?'), 0, 1))) ?></span>
                        <?php endif; ?>
                        <strong><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></strong>
                    </div>
                </td>
                <td>
                    <?php if (!empty($r['unit_number'])): ?>
                        <?php if ($canManage): ?>
                            <a href="/dashboard/unit.php?n=<?= urlencode((string)$r['unit_number']) ?>"><?= e((string)$r['unit_number']) ?></a>
                        <?php else: ?>
                            <?= e((string)$r['unit_number']) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= e(str_replace('_',' ',$r['role'])) ?></td>
                <td>
                    <?= $r['is_owner'] ? '<span class="badge badge--success">Owner</span>' : '<span class="badge">Renter</span>' ?>
                    <?php if (!empty($r['employee_job_title'])): ?>
                        <span class="badge" style="background: #efe7d1; color: #6b4a06; border: 1px solid #d9c97a; font-size: var(--fs-xs);" title="<?= e((string)$r['employee_job_title']) ?>">💼 <?= e(mb_strimwidth((string)$r['employee_job_title'], 0, 22, '…')) ?></span>
                    <?php endif; ?>
                    <?php
                    $raId    = (int)($r['rental_agent_contact_id'] ?? 0);
                    $raLabel = $raId ? ($rentalAgentMap[$raId] ?? null) : null;
                    if ($raLabel): ?>
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;">via <?= e($raLabel) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= is_placeholder_email((string)$r['email']) ? '<em class="muted">—</em>' : e((string)$r['email']) ?></td>
                <td><?= e($r['phone'] ?: '—') ?></td>
                <?php if ($canManage): ?>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php endif; /* close hide-listing-while-editing */ ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

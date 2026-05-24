<?php
require __DIR__ . '/_bootstrap.php';

if (viewing_role() === 'renter') {
    redirect('/dashboard/');
}

$user         = current_user();
$canEdit      = role_can_manage(viewing_role());
$canEditPerms = in_array((string)viewing_role(), ['board_admin', 'super_admin'], true);
$flashError   = null;
$openSection  = null;

// ──────────────────────────────────────────────────────────────────────────
// POST: Change password
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'change_password') {
    csrf_check();
    $curPw  = (string)($_POST['current_password'] ?? '');
    $newPw  = (string)($_POST['new_password'] ?? '');
    $confPw = (string)($_POST['confirm_password'] ?? '');
    if (!password_verify($curPw, (string)$user['password_hash'])) {
        $flashError  = 'Current password is incorrect.';
        $openSection = 'account';
    } elseif (strlen($newPw) < 8) {
        $flashError  = 'New password must be at least 8 characters.';
        $openSection = 'account';
    } elseif ($newPw !== $confPw) {
        $flashError  = 'New passwords do not match.';
        $openSection = 'account';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPw, PASSWORD_DEFAULT), (int)$user['id']]);
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')
            ->execute([(int)$user['id']]);
        audit('user.password_changed', []);
        flash('success', 'Password changed successfully.');
        redirect('/dashboard/settings.php#account');
    }
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Save timezone preference
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_timezone') {
    csrf_check();
    $tz = (string)($_POST['timezone'] ?? '');
    if ($tz !== '' && !@timezone_open($tz)) {
        flash('error', 'Invalid timezone.');
    } else {
        db()->prepare('UPDATE users SET timezone = ? WHERE id = ?')
            ->execute([$tz !== '' ? $tz : null, (int)$user['id']]);
        flash('success', 'Timezone saved.');
    }
    redirect('/dashboard/settings.php#account');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Directory privacy
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'directory_privacy') {
    csrf_check();
    $hide = isset($_POST['hide_from_directory']) ? 1 : 0;
    db()->prepare('UPDATE users SET hide_from_directory = ? WHERE id = ?')
        ->execute([$hide, (int)$user['id']]);
    flash('success', $hide ? 'You are now hidden from the resident directory.' : 'You are now visible in the resident directory.');
    redirect('/dashboard/settings.php#account');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Announcement tag colours (board admin only)
// ──────────────────────────────────────────────────────────────────────────
if ($canEditPerms && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'ann_type_colors') {
    csrf_check();
    $types = array_keys(ann_types());
    $colors = [];
    foreach ($types as $t) {
        $val = strtolower(trim((string)($_POST['color_' . $t] ?? '')));
        if (preg_match('/^#[0-9a-f]{6}$/', $val)) $colors[$t] = $val;
    }
    $isReset = isset($_POST['reset_colors']);
    db()->prepare('UPDATE associations SET ann_type_colors = ? WHERE id = ?')
        ->execute([$isReset ? null : ($colors ? json_encode($colors) : null), $assocId]);
    audit('association.ann_colors_updated', $isReset ? ['reset' => true] : $colors);
    flash('success', $isReset ? 'Tag colors reset to defaults.' : 'Tag colors saved.');
    redirect('/dashboard/settings.php#section-ann-colors');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: TV token (board admin only)
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tv_token' && $canEdit) {
    csrf_check();
    $assocId = (int)($_SESSION['association_id'] ?? 0);
    $action  = (string)($_POST['tv_action'] ?? '');
    if ($action === 'generate') {
        $token = bin2hex(random_bytes(24));
        db()->prepare('UPDATE associations SET tv_token=? WHERE id=?')->execute([$token, $assocId]);
        flash('success', 'TV link generated.');
    } elseif ($action === 'revoke') {
        db()->prepare('UPDATE associations SET tv_token=NULL WHERE id=?')->execute([$assocId]);
        flash('success', 'TV link revoked. The old URL will no longer work.');
    }
    redirect('/dashboard/settings.php#tv');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tv_pin' && $canEdit) {
    csrf_check();
    $newPin = preg_replace('/\D/', '', (string)($_POST['tv_pin'] ?? ''));
    if (strlen($newPin) < 4 || strlen($newPin) > 10) {
        flash('error', 'PIN must be 4–10 digits.');
    } else {
        db()->prepare('UPDATE associations SET tv_pin=? WHERE id=?')->execute([$newPin, $assocId]);
        audit('association.tv_pin_changed', []);
        flash('success', 'TV PIN updated.');
    }
    redirect('/dashboard/settings.php#tv');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tv_mode' && $canEdit) {
    csrf_check();
    $mode = in_array((string)($_POST['tv_mode'] ?? ''), ['columns','ticker']) ? $_POST['tv_mode'] : 'columns';
    $dark = (int)(($_POST['tv_dark'] ?? '1') !== '0');
    db()->prepare('UPDATE associations SET tv_mode=?, tv_dark=? WHERE id=?')->execute([$mode, $dark, $assocId]);
    audit('association.tv_settings_changed', ['mode' => $mode, 'dark' => (bool)$dark]);
    flash('success', 'TV display settings updated.');
    redirect('/dashboard/settings.php#tv');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'visit_info' && $canEdit) {
    csrf_check();
    db()->prepare(
        'UPDATE associations SET visit_directions=?, visit_parking=?, visit_hours=?, visit_notes=? WHERE id=?'
    )->execute([
        mb_substr(trim((string)($_POST['visit_directions'] ?? '')), 0, 4000) ?: null,
        mb_substr(trim((string)($_POST['visit_parking']    ?? '')), 0, 2000) ?: null,
        mb_substr(trim((string)($_POST['visit_hours']      ?? '')), 0, 500)  ?: null,
        mb_substr(trim((string)($_POST['visit_notes']      ?? '')), 0, 2000) ?: null,
        $assocId,
    ]);
    flash('success', 'Visit info saved.');
    redirect('/dashboard/settings.php?open=visit#visit');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Save permissions
// ──────────────────────────────────────────────────────────────────────────
$PERM_ROLES = [
    'renter'       => 'Renter (everyone)',
    'staff'        => 'Staff',
    'owner'        => 'Owner',
    'board_member' => 'Board member',
    'board_admin'  => 'Board admin',
];
$FEATURES = [
    'content' => [
        'label'    => 'Content',
        'features' => [
            'read_documents'      => ['label' => 'View documents',          'desc' => 'Who can browse and download documents in the library.'],
            'read_minutes'        => ['label' => 'Read meeting minutes',    'desc' => 'Who can read the board meeting minutes archive.'],
            'read_contacts'       => ['label' => 'View contacts',           'desc' => 'Who can see the association contact directory — emergency lines, contractors, utilities.'],
            'submit_concerns'     => ['label' => 'Submit concerns',         'desc' => 'Who can file a concern or complaint.'],
        ],
    ],
    'management' => [
        'label'    => 'Management (view-only)',
        'features' => [
            'read_work_orders'    => ['label' => 'View work orders',        'desc' => 'Who can see the work order queue in read-only mode. Creating/editing always requires management role.'],
            'read_violations'     => ['label' => 'View violations',         'desc' => 'Who can see violation records. Issuing notices always requires management role.'],
            'read_employees'      => ['label' => 'View employee roster',    'desc' => 'Who can see the employee list (pay details remain board-admin only regardless).'],
            'read_insurance'      => ['label' => 'View insurance records',  'desc' => 'Who can view the association\'s insurance policies.'],
        ],
    ],
    'directory' => [
        'label'    => 'Directory',
        'features' => [
            'read_full_directory' => ['label' => 'Full resident directory', 'desc' => 'Who can see all resident contact details. Board section is always visible to everyone.'],
            'submit_arc'          => ['label' => 'Submit ARC requests',     'desc' => 'Who can file an architectural review request.'],
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_permissions') {
    csrf_check();
    if (!$canEditPerms) { http_response_code(403); die('Forbidden'); }

    $defaults = permission_defaults();
    $allKeys  = [];
    foreach ($FEATURES as $section) {
        foreach ($section['features'] as $key => $_) $allKeys[] = $key;
    }

    db()->beginTransaction();
    try {
        foreach ($allKeys as $key) {
            $posted = $_POST['perm'][$key] ?? '';
            if (!array_key_exists($posted, $PERM_ROLES)) $posted = $defaults[$key] ?? 'board_member';
            if ($posted === ($defaults[$key] ?? 'board_member')) {
                db()->prepare('DELETE FROM association_permissions WHERE association_id = ? AND permission_key = ?')
                    ->execute([$assocId, $key]);
            } else {
                db()->prepare(
                    'INSERT INTO association_permissions (association_id, permission_key, min_role, updated_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE min_role = VALUES(min_role), updated_by = VALUES(updated_by)'
                )->execute([$assocId, $key, $posted, (int)$user['id']]);
            }
        }
        db()->commit();
        audit('permissions.updated', array_intersect_key($_POST['perm'] ?? [], array_flip($allKeys)));
        flash('success', 'Permission settings saved.');
    } catch (Throwable $e) {
        db()->rollBack();
        $flashError  = 'Save failed: ' . $e->getMessage();
        $openSection = 'permissions';
    }
    if (!$flashError) redirect('/dashboard/settings.php#permissions');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Location CRUD
// ──────────────────────────────────────────────────────────────────────────
if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_location') {
    csrf_check();
    $locName = trim((string)($_POST['name'] ?? ''));
    $locCat  = trim((string)($_POST['category'] ?? ''));
    $locDesc = trim((string)($_POST['description'] ?? ''));
    if ($locName === '') {
        $flashError  = 'Location name is required.';
        $openSection = 'locations';
    } else {
        try {
            db()->prepare(
                'INSERT INTO locations (association_id, name, category, description, sort_order)
                 VALUES (?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM locations AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $locName, $locCat ?: null, $locDesc ?: null, $assocId]);
            audit('location.added', ['name' => $locName, 'category' => $locCat], (int)db()->lastInsertId(), 'location');
            flash('success', "Location \"$locName\" added.");
            redirect('/dashboard/settings.php#locations');
        } catch (PDOException $e) {
            $flashError  = "A location named \"$locName\" already exists.";
            $openSection = 'locations';
        }
    }
}

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_location') {
    csrf_check();
    $lid     = (int)($_POST['id'] ?? 0);
    $locName = trim((string)($_POST['name'] ?? ''));
    $locCat  = trim((string)($_POST['category'] ?? ''));
    $locDesc = trim((string)($_POST['description'] ?? ''));
    $locActive = isset($_POST['is_active']) ? 1 : 0;

    $check = db()->prepare('SELECT name FROM locations WHERE id = ? AND association_id = ?');
    $check->execute([$lid, $assocId]);
    $oldLocName = (string)($check->fetchColumn() ?: '');
    if ($oldLocName === '') {
        $flashError  = 'Location not found.';
        $openSection = 'locations';
    } elseif ($locName === '') {
        $flashError  = 'Location name is required.';
        $openSection = 'locations';
    } else {
        try {
            db()->prepare(
                'UPDATE locations SET name = ?, category = ?, description = ?, is_active = ? WHERE id = ? AND association_id = ?'
            )->execute([$locName, $locCat ?: null, $locDesc ?: null, $locActive, $lid, $assocId]);
            audit('location.edited', ['from' => $oldLocName, 'to' => $locName, 'active' => (bool)$locActive], $lid, 'location');
            flash('success', 'Location updated.');
            redirect('/dashboard/settings.php#locations');
        } catch (PDOException $e) {
            $flashError  = "Another location is already named \"$locName\".";
            $openSection = 'locations';
        }
    }
}

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_location') {
    csrf_check();
    $lid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM locations WHERE id = ? AND association_id = ?')->execute([$lid, $assocId]);
    audit('location.deleted', [], $lid, 'location');
    flash('success', 'Location deleted. Existing event/maintenance entries keep the location label as plain text.');
    redirect('/dashboard/settings.php#locations');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Association profile + public landing content (existing handlers)
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); die('Forbidden'); }
    $name      = trim((string)($_POST['name'] ?? ''));
    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));
    $city      = trim((string)($_POST['city'] ?? ''));
    $stateReg  = trim((string)($_POST['state_region'] ?? ''));
    $postal    = trim((string)($_POST['postal_code'] ?? ''));
    $country   = strtoupper(trim((string)($_POST['country'] ?? 'US')));
    $units     = (int)($_POST['unit_count'] ?? 0);
    $primary   = trim((string)($_POST['primary_color'] ?? '#0f1f3d'));
    $assocTzInput = trim((string)($_POST['assoc_timezone'] ?? 'America/New_York'));
    $publicLanding = isset($_POST['public_landing_enabled']) ? 1 : 0;
    if (!preg_match('/^#[0-9a-f]{6}$/i', $primary)) $primary = '#0f1f3d';
    if (!preg_match('/^[A-Z]{2}$/', $country))      $country = 'US';
    if (!@timezone_open($assocTzInput)) $assocTzInput = 'America/New_York';

    $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower($subdomain));
    $subdomain = trim($subdomain, '-');
    if ($subdomain === '') {
        $flashError  = 'Slug is required.';
        $openSection = 'profile';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $subdomain)) {
        $flashError  = 'Slug must be lowercase letters, numbers, and hyphens — no leading or trailing hyphen.';
        $openSection = 'profile';
    } else {
        $dupe = db()->prepare('SELECT id FROM associations WHERE subdomain = ? AND id <> ?');
        $dupe->execute([$subdomain, $assocId]);
        if ($dupe->fetchColumn()) {
            $flashError  = "Slug \"$subdomain\" is already taken.";
            $openSection = 'profile';
        }
    }

    $uploadBranding = function (string $field, string $filename, int $maxBytes = 1048576) use ($assocId, &$flashError, &$openSection) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
        $allowed = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','webp'=>'image/webp'];
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) { $flashError = ucfirst($filename) . ' must be PNG, JPG, SVG, or WEBP.'; $openSection = 'profile'; return null; }
        if ($_FILES[$field]['size'] > $maxBytes) { $flashError = 'Max ' . ucfirst($filename) . ' size is ' . round($maxBytes / 1048576, 1) . ' MB.'; $openSection = 'profile'; return null; }
        $relDir = "uploads/$assocId/branding";
        $absDir = storage_path($relDir);
        ensure_dir($absDir);
        $newName = "$filename.$ext";
        $relPath = "$relDir/$newName";
        foreach (['png','jpg','jpeg','svg','webp'] as $oldExt) {
            if ($oldExt !== $ext) @unlink("$absDir/$filename.$oldExt");
        }
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$absDir/$newName")) {
            $flashError = 'Could not save ' . $filename . '.'; $openSection = 'profile'; return null;
        }
        return $relPath;
    };

    $newLogoPath = null;
    $removeLogo  = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';
    if (!$flashError) $newLogoPath = $uploadBranding('logo', 'logo', 1 * 1024 * 1024);

    $newHeroPath = null;
    $removeHero  = isset($_POST['remove_hero']) && $_POST['remove_hero'] === '1';
    if (!$flashError) $newHeroPath = $uploadBranding('hero_image', 'hero', 4 * 1024 * 1024);

    $vision        = trim((string)($_POST['vision_statement'] ?? ''));
    $aboutText     = (string)($_POST['about_text'] ?? '');
    $amenitiesText = trim((string)($_POST['amenities_text'] ?? ''));
    $contactEmail  = trim((string)($_POST['contact_email'] ?? ''));
    $contactPhone  = trim((string)($_POST['contact_phone'] ?? ''));
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $flashError  = $flashError ?: 'Public contact email is not valid.';
        $openSection = $openSection ?: 'landing';
    }

    $normalizeUrl = static function (string $u): ?string {
        $u = trim($u);
        if ($u === '') return null;
        if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        return filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
    };
    $websiteUrl   = $normalizeUrl((string)($_POST['website_url']   ?? ''));
    $facebookUrl  = $normalizeUrl((string)($_POST['facebook_url']  ?? ''));
    $instagramUrl = $normalizeUrl((string)($_POST['instagram_url'] ?? ''));
    $twitterUrl   = $normalizeUrl((string)($_POST['twitter_url']   ?? ''));
    $nextdoorUrl  = $normalizeUrl((string)($_POST['nextdoor_url']  ?? ''));
    $youtubeUrl   = $normalizeUrl((string)($_POST['youtube_url']   ?? ''));

    $section = $_POST['section'] ?? 'profile';
    if (!in_array($section, ['profile', 'content'], true)) $section = 'profile';

    if ($section === 'profile' && $name === '') {
        $flashError  = 'Association name is required.';
        $openSection = 'profile';
    } elseif (!$flashError) {
        if ($section === 'profile') {
            $extraSql = ''; $extraArgs = [];
            if ($removeLogo) {
                $extraSql .= ', logo_path = NULL';
                $existing = (string)($association['logo_path'] ?? '');
                if ($existing) { $abs = storage_path($existing); if (is_file($abs)) @unlink($abs); }
            } elseif ($newLogoPath) {
                $extraSql .= ', logo_path = ?'; $extraArgs[] = $newLogoPath;
            }
            if ($removeHero) {
                $extraSql .= ', hero_image_path = NULL';
                $existing = (string)($association['hero_image_path'] ?? '');
                if ($existing) { $abs = storage_path($existing); if (is_file($abs)) @unlink($abs); }
            } elseif ($newHeroPath) {
                $extraSql .= ', hero_image_path = ?'; $extraArgs[] = $newHeroPath;
            }

            $oldAddrSig = trim(($association['address'] ?? '') . '|' . ($association['city'] ?? '') . '|' . ($association['state_region'] ?? '') . '|' . ($association['postal_code'] ?? '') . '|' . ($association['country'] ?? ''));
            $newAddrSig = trim(($address ?? '') . '|' . ($city ?? '') . '|' . ($stateReg ?? '') . '|' . ($postal ?? '') . '|' . ($country ?? ''));
            $hasAddr = $newAddrSig !== '||||';
            $missingCoords = empty($association['latitude']) || empty($association['longitude']);
            if ($hasAddr && ($newAddrSig !== $oldAddrSig || $missingCoords)) {
                $queryStr = trim(implode(' ', array_filter([$address, $city, $stateReg, $postal, $country])));
                $latLon = geocode_address($queryStr);
                $extraSql .= ', latitude = ?, longitude = ?';
                $extraArgs[] = $latLon['lat'] ?? null;
                $extraArgs[] = $latLon['lon'] ?? null;
            }

            $slugChanged = $subdomain !== (string)$association['subdomain'];
            db()->prepare(
                "UPDATE associations
                 SET name = ?, subdomain = ?, address = ?, city = ?, state_region = ?, postal_code = ?, country = ?,
                     timezone = ?, unit_count = ?, primary_color = ? $extraSql
                 WHERE id = ?"
            )->execute(array_merge(
                [$name, $subdomain, $address ?: null, $city ?: null, $stateReg ?: null, $postal ?: null, $country, $assocTzInput, $units, $primary],
                $extraArgs, [$assocId]
            ));
            audit('association.profile_updated', [
                'name' => $name,
                'logo_changed' => $newLogoPath !== null || $removeLogo,
                'hero_changed' => $newHeroPath !== null || $removeHero,
                'slug_changed' => $slugChanged,
                'new_slug' => $slugChanged ? $subdomain : null,
            ]);
            flash('success', 'Profile saved.');
        } else {
            db()->prepare(
                "UPDATE associations
                 SET vision_statement = ?, about_text = ?, amenities_text = ?,
                     contact_email = ?, contact_phone = ?,
                     website_url = ?, facebook_url = ?, instagram_url = ?, twitter_url = ?, nextdoor_url = ?, youtube_url = ?,
                     public_landing_enabled = ?
                 WHERE id = ?"
            )->execute([
                $vision ?: null, $aboutText ?: null, $amenitiesText ?: null,
                $contactEmail ?: null, $contactPhone ?: null,
                $websiteUrl, $facebookUrl, $instagramUrl, $twitterUrl, $nextdoorUrl, $youtubeUrl,
                $publicLanding, $assocId,
            ]);
            audit('association.content_updated', ['has_about' => $aboutText !== '', 'has_vision' => $vision !== '', 'public_landing_enabled' => (bool)$publicLanding]);
            flash('success', 'Landing content saved.');
        }
        redirect('/dashboard/settings.php');
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Data loading
// ──────────────────────────────────────────────────────────────────────────
$assocStmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
$assocStmt->execute([$assocId]);
$association = $assocStmt->fetch();

// Locations
$locRows = db()->prepare('SELECT * FROM locations WHERE association_id = ? ORDER BY is_active DESC, sort_order, name');
$locRows->execute([$assocId]);
$locations = $locRows->fetchAll();

$editLoc = null;
$showLocAdd = false;
if ($canEdit) {
    if (($_GET['action'] ?? '') === 'edit') {
        $eid = (int)($_GET['id'] ?? 0);
        $s = db()->prepare('SELECT * FROM locations WHERE id = ? AND association_id = ?');
        $s->execute([$eid, $assocId]);
        $editLoc = $s->fetch() ?: null;
        if ($editLoc) $openSection = $openSection ?? 'locations';
    }
    if (($_GET['action'] ?? '') === 'new') {
        $showLocAdd = true;
        $openSection = $openSection ?? 'locations';
    }
}

// Audit log (board_admin + super_admin only)
$auditRows = [];
if ($canEditPerms) {
    $aStmt = db()->prepare(
        'SELECT al.id, al.action, al.target_type, al.target_id,
                al.ip_address, al.metadata, al.created_at,
                u.first_name, u.last_name
           FROM audit_log al
      LEFT JOIN users u ON u.id = al.actor_user_id
          WHERE al.association_id = ?
          ORDER BY al.created_at DESC
          LIMIT 200'
    );
    $aStmt->execute([$assocId]);
    $auditRows = $aStmt->fetchAll();
}

// Permissions
$overrides    = [];
$permDefaults = permission_defaults();
if ($canEdit) {
    $pStmt = db()->prepare('SELECT permission_key, min_role FROM association_permissions WHERE association_id = ?');
    $pStmt->execute([$assocId]);
    foreach ($pStmt->fetchAll() as $r) $overrides[$r['permission_key']] = $r['min_role'];
}

$page_title      = 'Settings — ' . $association['name'];
$page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
require __DIR__ . '/../includes/header.php';
?>

<style>
.acc-panel {
    border: 1px solid var(--color-border);
    border-radius: var(--r-lg);
    overflow: hidden;
    margin-top: var(--sp-4);
    background: #fff;
}
.acc-panel > summary {
    display: flex;
    align-items: center;
    gap: var(--sp-3);
    padding: var(--sp-4) var(--sp-5);
    cursor: pointer;
    list-style: none;
    background: var(--color-surface);
    user-select: none;
    border-bottom: 1px solid transparent;
}
.acc-panel > summary::-webkit-details-marker { display: none; }
.acc-panel[open] > summary { border-bottom-color: var(--color-border); }
.acc-panel > summary:hover { background: var(--color-surface-2); }
.acc-title { font-weight: 700; font-size: var(--fs-md); }
.acc-hint { font-size: var(--fs-xs); color: var(--color-text-soft); margin-left: var(--sp-1); }
.acc-chevron { margin-left: auto; color: var(--color-text-soft); transition: transform 200ms ease; flex-shrink: 0; }
.acc-panel[open] .acc-chevron { transform: rotate(180deg); }
.acc-body { padding: var(--sp-6); }
.acc-icon { font-size: 18px; flex-shrink: 0; }
</style>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 980px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-1);"><?= $canEdit ? 'Settings' : 'Your account' ?></h1>
    <p class="muted" style="margin: 0 0 var(--sp-2);"><?= $canEdit ? 'Association configuration, permissions, and account options.' : 'Password and display preferences.' ?></p>

    <?php if ($flashError): ?>
        <div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($flashError) ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         ASSOCIATION PROFILE
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-profile" class="acc-panel" <?= ($openSection === null || $openSection === 'profile') ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🏢</span>
            <div>
                <span class="acc-title">Association profile</span>
                <span class="acc-hint">Name, address, branding, logo</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <form method="post" class="form" enctype="multipart/form-data" data-address-lookup>
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="update">
                <input type="hidden" name="section" value="profile">
                <fieldset style="border:0; padding:0; margin:0;" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label" for="aname">Name</label>
                            <input class="input" id="aname" name="name" required value="<?= e($association['name']) ?>">
                        </div>
                        <div class="field">
                            <label class="field__label" for="aslug">Public URL slug</label>
                            <div style="display:flex; align-items:center; background: var(--color-white); border: 1px solid var(--color-border-strong); border-radius: var(--r-md); padding: 0;">
                                <span class="muted" style="padding: 0.7rem 0 0.7rem 0.85rem; font-size: var(--fs-sm); white-space: nowrap;">badasshoa.com/</span>
                                <input class="input" id="aslug" name="subdomain" required value="<?= e((string)$association['subdomain']) ?>" pattern="[a-z0-9][a-z0-9-]*[a-z0-9]|[a-z0-9]" style="border: 0; padding-left: 2px;">
                            </div>
                            <div class="field__hint">Lowercase, hyphens OK. <strong>Changing this breaks existing bookmarks.</strong></div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="aaddr">Street address</label>
                        <input class="input" id="aaddr" name="address" value="<?= e((string)$association['address']) ?>" autocomplete="street-address" placeholder="123 Main St">
                    </div>
                    <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                        <div class="field">
                            <label class="field__label" for="acity">City</label>
                            <input class="input" id="acity" name="city" value="<?= e((string)($association['city'] ?? '')) ?>" autocomplete="address-level2">
                        </div>
                        <div class="field">
                            <label class="field__label" for="astate">State / Province</label>
                            <input class="input" id="astate" name="state_region" list="us-ca-states" value="<?= e((string)($association['state_region'] ?? '')) ?>" autocomplete="address-level1">
                        </div>
                        <div class="field">
                            <label class="field__label" for="apostal">ZIP / Postal</label>
                            <input class="input" id="apostal" name="postal_code" value="<?= e((string)($association['postal_code'] ?? '')) ?>" autocomplete="postal-code">
                        </div>
                    </div>
                    <?= us_ca_states_datalist() ?>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label" for="acountry">Country</label>
                            <select class="select" id="acountry" name="country">
                                <?php $cur = strtoupper((string)($association['country'] ?? 'US'));
                                foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                                    <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field__label" for="aunits">Unit count</label>
                            <input class="input" type="number" id="aunits" name="unit_count" min="0" value="<?= (int)$association['unit_count'] ?>">
                        </div>
                    </div>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label" for="atimezone">Community timezone</label>
                            <?php
                            $curTz = (string)($association['timezone'] ?? 'America/New_York');
                            $tzGroups = [
                                'US & Canada' => [
                                    'America/New_York'    => 'Eastern (ET)',
                                    'America/Chicago'     => 'Central (CT)',
                                    'America/Denver'      => 'Mountain (MT)',
                                    'America/Phoenix'     => 'Arizona (no DST)',
                                    'America/Los_Angeles' => 'Pacific (PT)',
                                    'America/Anchorage'   => 'Alaska (AKT)',
                                    'Pacific/Honolulu'    => 'Hawaii (HT)',
                                    'America/Puerto_Rico' => 'Puerto Rico (AST)',
                                ],
                                'Other' => [
                                    'UTC'                    => 'UTC',
                                    'Europe/London'          => 'London (GMT/BST)',
                                    'Europe/Paris'           => 'Central Europe (CET)',
                                    'Australia/Sydney'       => 'Sydney (AEST)',
                                    'Pacific/Auckland'       => 'New Zealand (NZST)',
                                ],
                            ];
                            ?>
                            <select class="select" id="atimezone" name="assoc_timezone">
                                <?php foreach ($tzGroups as $grpLabel => $tzList): ?>
                                <optgroup label="<?= e($grpLabel) ?>">
                                    <?php foreach ($tzList as $tzVal => $tzLabel): ?>
                                    <option value="<?= e($tzVal) ?>" <?= $curTz === $tzVal ? 'selected' : '' ?>><?= e($tzLabel) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="field__hint">Used on the Lobby TV to display event times in local time.</div>
                        </div>
                        <div class="field"></div>
                    </div>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label" for="acolor">Primary color</label>
                            <input class="input" type="color" id="acolor" name="primary_color" value="<?= e((string)$association['primary_color']) ?>">
                            <div class="field__hint">Used on the public landing page.</div>
                        </div>
                        <div class="field">
                            <label class="field__label" for="alogo">Association logo</label>
                            <?php if (!empty($association['logo_path'])): ?>
                                <div style="margin-bottom: var(--sp-2); padding: var(--sp-3); background: var(--color-surface-2); border-radius: var(--r-sm); text-align: center;">
                                    <img src="/branding.php?id=<?= (int)$association['id'] ?>" alt="Current logo" style="max-height: 64px; max-width: 100%;">
                                    <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-xs); justify-content: center;">
                                        <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                                    </label>
                                </div>
                            <?php endif; ?>
                            <input class="input" type="file" id="alogo" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                            <div class="field__hint">PNG / JPG / SVG / WEBP, max 1 MB.</div>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                        <div class="row" style="justify-content: flex-end;">
                            <button class="btn btn--primary" type="submit">Save changes</button>
                        </div>
                    <?php endif; ?>
                </fieldset>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         PUBLIC LANDING
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-landing" class="acc-panel" <?= $openSection === 'landing' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🌐</span>
            <div>
                <span class="acc-title">Public landing page</span>
                <span class="acc-hint">Hero image, about text, social links, visibility toggle</span>
            </div>
            <?php if ((int)($association['public_landing_enabled'] ?? 0) === 1): ?>
                <span class="badge badge--success" style="margin-left: auto; margin-right: var(--sp-3); font-size: var(--fs-xs);">live</span>
            <?php else: ?>
                <span class="badge" style="margin-left: auto; margin-right: var(--sp-3); font-size: var(--fs-xs);">off</span>
            <?php endif; ?>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-5);">
                What residents and prospective residents see at
                <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener">badasshoa.com/<?= e((string)$association['subdomain']) ?>/</a>.
                All fields optional — sections without content simply don't render.
            </p>
            <form method="post" class="form" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="update">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="name" value="<?= e((string)$association['name']) ?>">
                <input type="hidden" name="subdomain" value="<?= e((string)$association['subdomain']) ?>">
                <input type="hidden" name="address" value="<?= e((string)($association['address'] ?? '')) ?>">
                <input type="hidden" name="city" value="<?= e((string)($association['city'] ?? '')) ?>">
                <input type="hidden" name="state_region" value="<?= e((string)($association['state_region'] ?? '')) ?>">
                <input type="hidden" name="postal_code" value="<?= e((string)($association['postal_code'] ?? '')) ?>">
                <input type="hidden" name="country" value="<?= e((string)($association['country'] ?? 'US')) ?>">
                <input type="hidden" name="unit_count" value="<?= (int)$association['unit_count'] ?>">
                <input type="hidden" name="primary_color" value="<?= e((string)$association['primary_color']) ?>">

                <div class="field">
                    <label style="display:flex; align-items:center; gap: var(--sp-3); cursor: pointer;">
                        <input type="checkbox" name="public_landing_enabled" value="1" <?= (int)($association['public_landing_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <div>
                            <strong>Show this landing page publicly at <code>/<?= e((string)$association['subdomain']) ?>/</code></strong>
                            <div class="muted" style="font-size: var(--fs-sm);">When off, the URL redirects visitors to the sign-in page.</div>
                        </div>
                    </label>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--sp-5) 0;">

                <div class="field">
                    <label class="field__label" for="ahero">Hero banner image</label>
                    <?php if (!empty($association['hero_image_path'])): ?>
                        <div style="margin-bottom: var(--sp-2); padding: var(--sp-2); background: var(--color-surface-2); border-radius: var(--r-sm);">
                            <img src="/branding.php?id=<?= (int)$association['id'] ?>&kind=hero" alt="Current hero" style="max-height: 140px; max-width: 100%; display: block; margin: 0 auto;">
                            <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-xs); justify-content: center;">
                                <input type="checkbox" name="remove_hero" value="1"> Remove current banner
                            </label>
                        </div>
                    <?php endif; ?>
                    <input class="input" type="file" id="ahero" name="hero_image" accept="image/png,image/jpeg,image/webp">
                    <div class="field__hint">PNG / JPG / WEBP, max 4 MB. Aim for 1600×600 or wider.</div>
                </div>

                <div class="field">
                    <label class="field__label" for="avision">Vision statement</label>
                    <textarea class="textarea" id="avision" name="vision_statement" rows="3" placeholder="A short paragraph describing what your community stands for."><?= e((string)($association['vision_statement'] ?? '')) ?></textarea>
                    <div class="field__hint">Shown above the About section on your landing.</div>
                </div>

                <div class="field">
                    <label class="field__label">About this community</label>
                    <div id="about-editor" data-initial-html="<?= e((string)($association['about_text'] ?? '')) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                    <textarea name="about_text" id="about-source" hidden><?= e((string)($association['about_text'] ?? '')) ?></textarea>
                    <div class="field__hint">Welcome paragraph. Bold/italic, lists, and links are supported.</div>
                </div>

                <div class="field">
                    <label class="field__label" for="aamen">Amenities</label>
                    <textarea class="textarea" id="aamen" name="amenities_text" rows="6" placeholder="Pool&#10;Gym&#10;Beach access"><?= e((string)($association['amenities_text'] ?? '')) ?></textarea>
                    <div class="field__hint">One per line. Rendered as a two-column list on the landing.</div>
                </div>

                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="acemail">Main email</label>
                        <input class="input" type="email" id="acemail" name="contact_email" value="<?= e((string)($association['contact_email'] ?? '')) ?>" placeholder="info@yourcommunity.com">
                    </div>
                    <div class="field">
                        <label class="field__label" for="acphone">Main phone</label>
                        <input class="input" type="tel" id="acphone" name="contact_phone" value="<?= e((string)($association['contact_phone'] ?? '')) ?>" placeholder="555-555-5555">
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--sp-5) 0;">
                <h4 style="font-size: var(--fs-md); margin: 0 0 var(--sp-4);">Social &amp; external links</h4>

                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="aweb">Community website</label>
                        <input class="input" type="url" id="aweb" name="website_url" value="<?= e((string)($association['website_url'] ?? '')) ?>" placeholder="https://your-community.com">
                    </div>
                    <div class="field">
                        <label class="field__label" for="afb">Facebook</label>
                        <input class="input" type="url" id="afb" name="facebook_url" value="<?= e((string)($association['facebook_url'] ?? '')) ?>" placeholder="https://facebook.com/your-page">
                    </div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="aig">Instagram</label>
                        <input class="input" type="url" id="aig" name="instagram_url" value="<?= e((string)($association['instagram_url'] ?? '')) ?>" placeholder="https://instagram.com/your-handle">
                    </div>
                    <div class="field">
                        <label class="field__label" for="atw">X / Twitter</label>
                        <input class="input" type="url" id="atw" name="twitter_url" value="<?= e((string)($association['twitter_url'] ?? '')) ?>" placeholder="https://x.com/your-handle">
                    </div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="and">Nextdoor</label>
                        <input class="input" type="url" id="and" name="nextdoor_url" value="<?= e((string)($association['nextdoor_url'] ?? '')) ?>" placeholder="https://nextdoor.com/neighborhood/...">
                    </div>
                    <div class="field">
                        <label class="field__label" for="ayt">YouTube</label>
                        <input class="input" type="url" id="ayt" name="youtube_url" value="<?= e((string)($association['youtube_url'] ?? '')) ?>" placeholder="https://youtube.com/@your-channel">
                    </div>
                </div>

                <div class="row" style="justify-content: space-between; align-items:center; margin-top: var(--sp-5);">
                    <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener" class="muted" style="font-size: var(--fs-sm);">Preview the public landing &rarr;</a>
                    <button class="btn btn--primary" type="submit">Save landing content</button>
                </div>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         YOUR ACCOUNT
    ════════════════════════════════════════════════════════════════════ -->
    <details id="section-account" class="acc-panel" <?= $openSection === 'account' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🔐</span>
            <div>
                <span class="acc-title">Your account</span>
                <span class="acc-hint">Password and sign-in</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-5);">
                Signed in as <strong><?= e(trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')) ?: (string)$user['email']) ?></strong>
                (<?= e((string)$user['email']) ?>).
            </p>

            <?php
            // Build timezone list: US zones first, then a separator, then the rest alphabetically.
            $usZones = ['America/New_York','America/Chicago','America/Denver','America/Phoenix','America/Los_Angeles','America/Anchorage','America/Adak','Pacific/Honolulu'];
            $allZones = DateTimeZone::listIdentifiers();
            $otherZones = array_diff($allZones, $usZones);
            sort($otherZones);
            $currentTz = !empty($user['timezone']) ? (string)$user['timezone'] : '';
            ?>
            <form method="post" class="form" style="max-width: 480px; margin-bottom: var(--sp-7);">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="save_timezone">
                <div class="field">
                    <label class="field__label" for="tz-select">Display timezone</label>
                    <select class="input" id="tz-select" name="timezone">
                        <option value="">UTC (default)</option>
                        <optgroup label="United States">
                            <?php foreach ($usZones as $z): ?>
                                <option value="<?= e($z) ?>"<?= $currentTz === $z ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="All timezones">
                            <?php foreach ($otherZones as $z): ?>
                                <option value="<?= e($z) ?>"<?= $currentTz === $z ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <div class="field__hint">Dates and times across your dashboard will display in this timezone.</div>
                </div>
                <div class="row" style="justify-content: flex-end;">
                    <button class="btn btn--primary" type="submit">Save timezone</button>
                </div>
            </form>

            <?php if (!role_can_manage(viewing_role())): ?>
            <form method="post" class="form" style="max-width: 480px; margin-bottom: var(--sp-7);">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="directory_privacy">
                <fieldset style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--sp-4) var(--sp-5);">
                    <legend style="font-weight: 600; font-size: var(--fs-sm); padding: 0 var(--sp-2);">Directory privacy</legend>
                    <label style="display: flex; align-items: flex-start; gap: var(--sp-3); cursor: pointer;">
                        <input type="checkbox" name="hide_from_directory" value="1" style="margin-top: 3px; flex-shrink: 0;"
                            <?= !empty($user['hide_from_directory']) ? 'checked' : '' ?>>
                        <span>
                            <strong>Hide me from the resident directory</strong><br>
                            <span class="muted" style="font-size: var(--fs-sm);">Other residents won't see your name, unit, email, or phone in the directory. The board and management can still see your information.</span>
                        </span>
                    </label>
                </fieldset>
                <div class="row" style="justify-content: flex-end; margin-top: var(--sp-3);">
                    <button class="btn btn--secondary" type="submit">Save privacy setting</button>
                </div>
            </form>
            <?php endif; ?>

            <form method="post" class="form" style="max-width: 480px;">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="change_password">
                <div class="field">
                    <label class="field__label" for="cur-pw">Current password</label>
                    <input class="input" type="password" id="cur-pw" name="current_password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label class="field__label" for="new-pw">New password</label>
                    <input class="input" type="password" id="new-pw" name="new_password" required minlength="8" autocomplete="new-password">
                    <div class="field__hint">At least 8 characters.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="conf-pw">Confirm new password</label>
                    <input class="input" type="password" id="conf-pw" name="confirm_password" required autocomplete="new-password">
                </div>
                <div class="row" style="justify-content: flex-end;">
                    <button class="btn btn--primary" type="submit">Change password</button>
                </div>
            </form>
        </div>
    </details>

    <!-- ═══════════════════════════════════════════════════════════════════
         LOBBY TV
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit):
        $tvToken  = (string)($association['tv_token'] ?? '');
        $tvPin    = (string)($association['tv_pin']   ?? '');
        $tvSubdomain = (string)($association['subdomain'] ?? '');
        $tvUrl    = (strlen($tvToken) > 0) ? 'https://badasshoa.com/tv.php?token=' . rawurlencode($tvToken) : '';
        $tvPinUrl = ($tvPin !== '' && $tvSubdomain !== '')
            ? 'https://badasshoa.com/tv?slug=' . rawurlencode($tvSubdomain) . '&pin=' . rawurlencode($tvPin)
            : '';
    ?>
    <details id="section-tv" class="acc-panel" <?= $openSection === 'tv' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">📺</span>
            <div>
                <span class="acc-title">Lobby TV</span>
                <span class="acc-hint">Digital signage URL for your lobby display</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-4);">
                Open the TV display in any browser on your lobby TV. The easiest way is to go to <strong>badasshoa.com/tv</strong>, enter your community ID and PIN, then bookmark the page — the TV just needs to reload that bookmark.
            </p>

            <?php if ($tvPin !== ''): ?>
            <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-4); margin-bottom: var(--sp-4);">
                <div style="font-size: var(--fs-xs); font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--color-text-soft); margin-bottom: var(--sp-2);">TV PIN (easy to type on a remote)</div>
                <div style="display: flex; align-items: center; gap: var(--sp-4); flex-wrap: wrap;">
                    <span style="font-size: var(--fs-3xl); font-weight: 900; letter-spacing: .15em; font-variant-numeric: tabular-nums; color: var(--color-navy);"><?= e($tvPin) ?></span>
                    <div style="font-size: var(--fs-sm); color: var(--color-text-soft); line-height: 1.5;">
                        Community ID: <strong><?= e($tvSubdomain) ?></strong><br>
                        Go to <strong>badasshoa.com/tv</strong> and enter both to sign in.
                    </div>
                </div>
                <?php if ($tvPinUrl): ?>
                <div style="margin-top: var(--sp-3); display: flex; gap: var(--sp-3); align-items: center; flex-wrap: wrap;">
                    <a class="btn btn--primary btn--sm" href="<?= e($tvPinUrl) ?>" target="_blank" rel="noopener">Open TV display ↗</a>
                    <button type="button" class="btn btn--sm"
                        onclick="navigator.clipboard.writeText('<?= e($tvPinUrl) ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy link',1500)})">Copy link</button>
                </div>
                <?php endif; ?>
                <form method="post" style="margin-top: var(--sp-3); display: flex; gap: var(--sp-2); align-items: flex-end; flex-wrap: wrap;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="tv_pin">
                    <div>
                        <label style="display:block; font-size: var(--fs-xs); font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--color-text-soft); margin-bottom:4px;">Change PIN</label>
                        <input type="text" name="tv_pin" inputmode="numeric" pattern="[0-9]{4,10}" maxlength="10"
                               placeholder="4–10 digits" value="<?= e($tvPin) ?>"
                               style="width:140px; font-size:var(--fs-lg); font-weight:700; letter-spacing:.1em; text-align:center; font-variant-numeric:tabular-nums;">
                    </div>
                    <button class="btn btn--sm" type="submit">Save PIN</button>
                </form>
            </div>
            <?php endif; ?>
            <!-- TV display mode -->
            <form method="post" style="margin: var(--sp-4) 0;">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="tv_mode">
                <div style="font-size: var(--fs-xs); font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--color-text-soft); margin-bottom: var(--sp-3);">Display Mode</div>
                <div style="display: flex; gap: var(--sp-3); flex-wrap: wrap; margin-bottom: var(--sp-4);">
                    <?php $tvModeVal = (string)($association['tv_mode'] ?? 'columns'); ?>
                    <label style="display: flex; align-items: flex-start; gap: var(--sp-2); cursor: pointer; flex: 1; min-width: 200px; background: var(--color-surface); border: 2px solid <?= $tvModeVal === 'columns' ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4);">
                        <input type="radio" name="tv_mode" value="columns" <?= $tvModeVal === 'columns' ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                            <strong style="font-size: var(--fs-sm);">3-Column Board</strong>
                            <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;">Announcements, Events, and Marketplace side by side. Each column scrolls independently.</div>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: var(--sp-2); cursor: pointer; flex: 1; min-width: 200px; background: var(--color-surface); border: 2px solid <?= $tvModeVal === 'ticker' ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4);">
                        <input type="radio" name="tv_mode" value="ticker" <?= $tvModeVal === 'ticker' ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                            <strong style="font-size: var(--fs-sm);">Horizontal Ticker</strong>
                            <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;">All content scrolls horizontally as large cards sorted by date. Good for portrait TVs or simple kiosks.</div>
                        </div>
                    </label>
                </div>
                <div style="font-size: var(--fs-xs); font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--color-text-soft); margin-bottom: var(--sp-3);">Theme</div>
                <div style="display: flex; gap: var(--sp-3); flex-wrap: wrap; margin-bottom: var(--sp-4);">
                    <?php $tvDarkVal = (int)($association['tv_dark'] ?? 1); ?>
                    <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer; flex: 1; min-width: 140px; background: var(--color-surface); border: 2px solid <?= $tvDarkVal ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4);">
                        <input type="radio" name="tv_dark" value="1" <?= $tvDarkVal ? 'checked' : '' ?>>
                        <strong style="font-size: var(--fs-sm);">🌙 Dark</strong>
                    </label>
                    <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer; flex: 1; min-width: 140px; background: var(--color-surface); border: 2px solid <?= !$tvDarkVal ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4);">
                        <input type="radio" name="tv_dark" value="0" <?= !$tvDarkVal ? 'checked' : '' ?>>
                        <strong style="font-size: var(--fs-sm);">☀️ Light</strong>
                    </label>
                </div>
                <button class="btn btn--sm" type="submit">Save display settings</button>
            </form>

            <details style="margin-top: var(--sp-2);">
                <summary style="font-size: var(--fs-sm); color: var(--color-text-soft); cursor: pointer;">Legacy token URL</summary>
                <div style="margin-top: var(--sp-3);">
                <?php if ($tvUrl): ?>
                    <div style="background: var(--color-navy-10, #f0f3f8); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); font-family: monospace; font-size: var(--fs-sm); word-break: break-all; margin-bottom: var(--sp-4); display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); flex-wrap: wrap;">
                        <span><?= e($tvUrl) ?></span>
                        <button type="button" onclick="navigator.clipboard.writeText('<?= e($tvUrl) ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)})" class="btn btn--sm">Copy</button>
                    </div>
                    <div class="row" style="gap: var(--sp-3); flex-wrap: wrap;">
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="tv_token">
                            <input type="hidden" name="tv_action" value="generate">
                            <button class="btn btn--sm" type="submit">Regenerate</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Revoke the TV URL? The display will stop working until you generate a new one.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="tv_token">
                            <input type="hidden" name="tv_action" value="revoke">
                            <button class="btn btn--sm btn--error" type="submit">Revoke</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="tv_token">
                        <input type="hidden" name="tv_action" value="generate">
                        <button class="btn btn--sm" type="submit">Generate legacy URL</button>
                    </form>
                <?php endif; ?>
                </div>
            </details>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         PLAN A VISIT
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-visit" class="acc-panel" <?= ($openSection ?? '') === 'visit' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🗺️</span>
            <div>
                <span class="acc-title">Plan a Visit</span>
                <span class="acc-hint">Directions, parking, and visitor info shown on your public landing</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="visit_info">
                <div class="form-grid" style="gap: var(--sp-4);">
                    <div class="field">
                        <label class="field__label" for="v-hours">Visitor hours / gate hours</label>
                        <input class="input" type="text" id="v-hours" name="visit_hours" maxlength="500"
                               value="<?= e((string)($association['visit_hours'] ?? '')) ?>"
                               placeholder="e.g. Gate open 7am–10pm daily">
                        <div class="field__hint">Short one-liner shown prominently on the visit section.</div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="v-dir">Directions</label>
                        <textarea class="textarea" id="v-dir" name="visit_directions" rows="4" maxlength="4000"
                                  placeholder="Turn-by-turn or landmark directions to your community…"><?= e((string)($association['visit_directions'] ?? '')) ?></textarea>
                    </div>
                    <div class="field">
                        <label class="field__label" for="v-park">Parking info</label>
                        <textarea class="textarea" id="v-park" name="visit_parking" rows="3" maxlength="2000"
                                  placeholder="Visitor parking location, permit requirements, overflow…"><?= e((string)($association['visit_parking'] ?? '')) ?></textarea>
                    </div>
                    <div class="field">
                        <label class="field__label" for="v-notes">Additional visitor notes</label>
                        <textarea class="textarea" id="v-notes" name="visit_notes" rows="3" maxlength="2000"
                                  placeholder="ID requirements, check-in procedures, pet policy…"><?= e((string)($association['visit_notes'] ?? '')) ?></textarea>
                    </div>
                </div>
                <div class="row" style="margin-top: var(--sp-4);">
                    <button class="btn btn--primary" type="submit">Save visit info</button>
                </div>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         ANNOUNCEMENT TAG COLOURS
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEditPerms):
        $currentAnnColors = ann_type_colors($assocId);
        $annTypeLabels = array_map(fn($m) => $m['emoji'] . ' ' . $m['label'], ann_types());
    ?>
    <details id="section-ann-colors" class="acc-panel" <?= $openSection === 'ann-colors' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🏷</span>
            <div>
                <span class="acc-title">Announcement tag colors</span>
                <span class="acc-hint">Customize the color of each announcement type badge</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-5);">
                Each announcement type gets a color tag on the dashboard, public landing, and lobby TV. Pick any color — the badge auto-generates a matching tint for the background.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="ann_type_colors">
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--sp-4); margin-bottom: var(--sp-5);">
                    <?php foreach ($annTypeLabels as $typeKey => $typeLabel):
                        $currentHex = $currentAnnColors[$typeKey] ?? '#888888';
                        $sampleStyle = "background:{$currentHex}1a;color:{$currentHex};border:1px solid {$currentHex}33;";
                    ?>
                    <div>
                        <label class="field__label" for="color_<?= $typeKey ?>"><?= e($typeLabel) ?></label>
                        <div style="display:flex; align-items:center; gap: var(--sp-3); margin-top: var(--sp-1);">
                            <input type="color" id="color_<?= $typeKey ?>" name="color_<?= $typeKey ?>" value="<?= e($currentHex) ?>"
                                style="width:44px; height:36px; padding:2px; border:1px solid var(--color-border); border-radius: var(--r-sm); cursor:pointer; background:#fff;"
                                oninput="updatePreview('<?= $typeKey ?>', this.value)">
                            <span class="badge" id="preview_<?= $typeKey ?>" style="<?= $sampleStyle ?>"><?= e(strtolower($typeLabel)) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="row" style="gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
                    <button class="btn btn--primary" type="submit">Save colors</button>
                    <button class="btn btn--ghost" type="submit" name="reset_colors" value="1"
                        onclick="return confirm('Reset all tag colors to the app defaults?')">Reset to defaults</button>
                </div>
            </form>
        </div>
    </details>
    <script>
    function updatePreview(type, hex) {
        var el = document.getElementById('preview_' + type);
        if (!el || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
        el.style.background = hex + '1a';
        el.style.color = hex;
        el.style.borderColor = hex + '33';
    }
    </script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         NEWSLETTER SUBSCRIBERS
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit):
        $nlStmt = db()->prepare('SELECT COUNT(*) FROM newsletter_subscribers WHERE association_id=? AND status="active"');
        $nlStmt->execute([$assocId]);
        $nlCount = (int)$nlStmt->fetchColumn();
    ?>
    <details id="section-newsletter" class="acc-panel" <?= $openSection === 'newsletter' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">📧</span>
            <div>
                <span class="acc-title">Newsletter subscribers</span>
                <span class="acc-hint"><?= $nlCount ?> active subscriber<?= $nlCount !== 1 ? 's' : '' ?></span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <?php
            $nlRows = db()->prepare('SELECT email, name, subscribed_at FROM newsletter_subscribers WHERE association_id=? AND status="active" ORDER BY subscribed_at DESC');
            $nlRows->execute([$assocId]);
            $nlList = $nlRows->fetchAll();
            ?>
            <?php if (empty($nlList)): ?>
                <p class="muted" style="font-size: var(--fs-sm);">No subscribers yet. The signup form appears on your public community landing page.</p>
            <?php else: ?>
                <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-4);"><?= count($nlList) ?> resident<?= count($nlList) !== 1 ? 's' : '' ?> subscribed to community updates via the public landing page.</p>
                <div style="overflow-x:auto; margin-bottom: var(--sp-4);">
                <table class="table">
                    <thead><tr><th>Email</th><th>Name</th><th>Subscribed</th></tr></thead>
                    <tbody>
                    <?php foreach ($nlList as $nl): ?>
                        <tr>
                            <td><?= e((string)$nl['email']) ?></td>
                            <td class="muted"><?= e((string)($nl['name'] ?? '')) ?></td>
                            <td class="muted"><?= udate('M j, Y', strtotime((string)$nl['subscribed_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         PERMISSIONS
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-permissions" class="acc-panel" <?= $openSection === 'permissions' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🔒</span>
            <div>
                <span class="acc-title">Permissions</span>
                <span class="acc-hint">Control which role can access each feature</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-5);">
                Management write actions (creating, editing, deleting) are always restricted to board roles and are not configurable.
            </p>
            <?php if (!$canEditPerms): ?>
                <div class="flash flash--info" style="margin-bottom: var(--sp-4);">You can view permission settings but only board admins can change them.</div>
            <?php endif; ?>

            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="save_permissions">

                <?php foreach ($FEATURES as $secKey => $secDef): ?>
                <div style="margin-bottom: var(--sp-5);">
                    <h4 style="font-size: var(--fs-sm); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-soft); margin: 0 0 var(--sp-3);"><?= e($secDef['label']) ?></h4>
                    <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Feature</th>
                                <?php foreach ($PERM_ROLES as $rval => $rlabel): ?>
                                    <th style="text-align:center; font-size: var(--fs-xs); white-space: nowrap;"><?= e($rlabel) ?></th>
                                <?php endforeach; ?>
                                <th style="text-align:center;">Current</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($secDef['features'] as $key => $feat):
                            $current     = $overrides[$key] ?? $permDefaults[$key] ?? 'board_member';
                            $isCustom    = isset($overrides[$key]);
                            $currentRank = ROLE_RANK[$current] ?? 0;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= e($feat['label']) ?></strong>
                                    <div class="muted" style="font-size: var(--fs-xs);"><?= e($feat['desc']) ?></div>
                                    <?php if ($isCustom): ?>
                                        <span class="badge badge--warning" style="font-size: 10px; margin-top: 2px;">custom</span>
                                    <?php else: ?>
                                        <span class="muted" style="font-size: 10px;">default</span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($PERM_ROLES as $rval => $rlabel):
                                    $rank = ROLE_RANK[$rval] ?? 0;
                                    $isMin = $rval === $current;
                                    $hasAccess = $rank >= $currentRank;
                                ?>
                                <td style="text-align:center; vertical-align:middle;">
                                    <?php if ($canEditPerms): ?>
                                        <label style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:100%;">
                                            <input type="radio" name="perm[<?= e($key) ?>]" value="<?= e($rval) ?>" <?= $isMin ? 'checked' : '' ?>>
                                        </label>
                                    <?php elseif ($isMin): ?>
                                        <span style="font-size:16px;">●</span>
                                    <?php elseif ($hasAccess): ?>
                                        <span class="muted" style="font-size:14px;">✓</span>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:12px; opacity:0.3;">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                                <td style="text-align:center;">
                                    <span class="badge <?= match($current) {
                                        'renter' => 'badge--info',
                                        'staff'  => '',
                                        'owner'  => 'badge--success',
                                        'board_member' => 'badge--navy',
                                        'board_admin'  => 'badge--orange',
                                        default => '',
                                    } ?>"><?= e($PERM_ROLES[$current] ?? $current) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-4); margin-bottom: var(--sp-5);">
                    <h4 style="margin: 0 0 var(--sp-2); font-size: var(--fs-sm);">Always board-only (not configurable)</h4>
                    <p class="muted" style="font-size: var(--fs-xs); margin: 0 0 var(--sp-3);">These require management role or higher. Changing them would expose legally sensitive data.</p>
                    <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                        <?php foreach ([
                            'Financial / pay details', 'Create / edit work orders', 'Issue violation notices',
                            'Manage employees', 'Edit insurance records', 'Change permissions',
                            'Announcement management', 'Board meeting management',
                        ] as $item): ?>
                            <span class="badge" style="background: #f1f5f9; color: #475569;">🔒 <?= e($item) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($canEditPerms): ?>
                <div class="row" style="justify-content: flex-end;">
                    <button class="btn btn--primary" type="submit">Save permissions</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         LOCATIONS
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-locations" class="acc-panel" <?= $openSection === 'locations' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">📍</span>
            <div>
                <span class="acc-title">Locations</span>
                <span class="acc-hint"><?= count($locations) ?> location<?= count($locations)===1?'':'s' ?> — used in event and work-order forms</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">

            <?php if ($showLocAdd || $editLoc):
                $isEdit = $editLoc !== null;
                $vals = $editLoc ?? ['name'=>'','category'=>'','description'=>'','is_active'=>1,'id'=>0];
            ?>
            <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-5); margin-bottom: var(--sp-5);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: var(--sp-4);">
                    <h4 style="margin:0; font-size: var(--fs-md);"><?= $isEdit ? 'Edit location' : 'New location' ?></h4>
                    <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/settings.php#locations">← Cancel</a>
                </div>
                <form method="post" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="<?= $isEdit ? 'edit_location' : 'add_location' ?>">
                    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label" for="loc-name">Name</label>
                            <input class="input" id="loc-name" name="name" required maxlength="120" value="<?= e((string)$vals['name']) ?>" placeholder="Clubhouse · Garage 64 · Roof terrace">
                        </div>
                        <div class="field">
                            <label class="field__label" for="loc-cat">Category (optional)</label>
                            <input class="input" id="loc-cat" name="category" maxlength="50" value="<?= e((string)($vals['category'] ?? '')) ?>" list="loc-cat-list" placeholder="garage · event space · amenity">
                            <datalist id="loc-cat-list">
                                <option value="garage">
                                <option value="parking">
                                <option value="event space">
                                <option value="amenity">
                                <option value="storage">
                                <option value="utility">
                            </datalist>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="loc-desc">Description (optional)</label>
                        <textarea class="textarea" id="loc-desc" name="description" rows="3" placeholder="Capacity, access instructions, etc."><?= e((string)($vals['description'] ?? '')) ?></textarea>
                    </div>
                    <?php if ($isEdit): ?>
                    <div class="field">
                        <label style="display:flex; align-items:center; gap: var(--sp-2); cursor:pointer;">
                            <input type="checkbox" name="is_active" <?= (int)$vals['is_active'] === 1 ? 'checked' : '' ?>>
                            <span>Active — shows in the location picker on event and work-order forms</span>
                        </label>
                    </div>
                    <?php endif; ?>
                    <div class="row" style="justify-content: flex-end;">
                        <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create location' ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!$locations && !$showLocAdd && !$editLoc): ?>
                <div class="center" style="padding: var(--sp-8) var(--sp-6);">
                    <p class="muted">No locations yet. Add the first one — it'll appear in the location dropdown when creating events.</p>
                    <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new#locations">Add your first location</a></p>
                </div>
            <?php else: ?>
                <div style="display:flex; justify-content:flex-end; margin-bottom: var(--sp-3);">
                    <?php if (!$showLocAdd && !$editLoc): ?>
                        <a class="btn btn--primary" href="?action=new#locations">+ New location</a>
                    <?php endif; ?>
                </div>
                <?php if ($locations): ?>
                <div style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Name</th><th>Category</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($locations as $loc): ?>
                        <tr style="<?= (int)$loc['is_active'] === 0 ? 'opacity: 0.55;' : '' ?>">
                            <td><strong><?= e((string)$loc['name']) ?></strong></td>
                            <td><span class="muted" style="font-size: var(--fs-sm);"><?= e((string)($loc['category'] ?? '')) ?: '—' ?></span></td>
                            <td><span class="muted" style="font-size: var(--fs-sm);"><?= e(mb_strimwidth((string)($loc['description'] ?? ''), 0, 60, '…')) ?: '—' ?></span></td>
                            <td>
                                <?php if ((int)$loc['is_active'] === 1): ?>
                                    <span class="badge badge--success">active</span>
                                <?php else: ?>
                                    <span class="badge">inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space: nowrap;">
                                <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$loc['id'] ?>#locations">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete location &quot;<?= e((string)$loc['name']) ?>&quot;? Existing event/maintenance entries keep the label as text.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="delete_location">
                                    <input type="hidden" name="id" value="<?= (int)$loc['id'] ?>">
                                    <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         AUDIT LOG
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEditPerms && $auditRows): ?>
    <details id="section-audit" class="acc-panel" <?= $openSection === 'audit' ? 'open' : '' ?>>
        <summary>
            <span class="acc-icon">🔍</span>
            <div>
                <span class="acc-title">Activity log</span>
                <span class="acc-hint">Last 200 actions in this community</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <div style="display:flex; gap:var(--sp-2); align-items:center; margin-bottom:var(--sp-3); flex-wrap:wrap;">
                <input class="input" type="search" id="audit-filter" placeholder="Filter by action, name, or IP…" style="max-width:320px;">
                <span class="muted" style="font-size:var(--fs-xs);" id="audit-count"><?= count($auditRows) ?> entries shown</span>
            </div>
            <div style="overflow-x:auto;">
            <table class="table" id="audit-table">
                <thead>
                    <tr>
                        <th style="white-space:nowrap;">When</th>
                        <th>Who</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($auditRows as $a):
                    $actor = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                    $meta  = $a['metadata'] ? @json_decode((string)$a['metadata'], true) : [];
                    $targetStr = '';
                    if ($a['target_type'] && $a['target_id']) {
                        $targetStr = $a['target_type'] . ' #' . $a['target_id'];
                    }
                ?>
                <tr>
                    <td style="white-space:nowrap; font-size:var(--fs-xs); color:var(--color-text-soft);"><?= e(udate('M j, Y g:i A', strtotime((string)$a['created_at']))) ?></td>
                    <td style="font-size:var(--fs-sm);"><?= e($actor ?: '(system)') ?></td>
                    <td style="font-size:var(--fs-sm);">
                        <code style="font-size:11px; background:var(--color-surface-2); padding:1px 5px; border-radius:3px;"><?= e($a['action']) ?></code>
                        <?php if ($meta): ?>
                            <span class="muted" style="font-size:var(--fs-xs); display:block; margin-top:2px;">
                                <?= e(implode(' · ', array_map(
                                    fn($k,$v) => $k . ': ' . (is_array($v) ? json_encode($v) : mb_strimwidth((string)$v, 0, 60, '…')),
                                    array_keys($meta), $meta
                                ))) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--fs-xs); color:var(--color-text-soft);"><?= e($targetStr) ?></td>
                    <td style="font-size:var(--fs-xs); color:var(--color-text-soft);"><?= e((string)($a['ip_address'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </details>
    <script>
    (function(){
        var inp  = document.getElementById('audit-filter');
        var tbl  = document.getElementById('audit-table');
        var cnt  = document.getElementById('audit-count');
        if (!inp || !tbl) return;
        inp.addEventListener('input', function(){
            var q = this.value.toLowerCase();
            var rows = tbl.querySelectorAll('tbody tr');
            var visible = 0;
            rows.forEach(function(r){
                var show = !q || r.textContent.toLowerCase().includes(q);
                r.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            cnt.textContent = visible + ' entr' + (visible === 1 ? 'y' : 'ies') + ' shown';
        });
    })();
    </script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         SUBSCRIPTION
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-subscription" class="acc-panel">
        <summary>
            <span class="acc-icon">💳</span>
            <div>
                <span class="acc-title">Subscription</span>
                <span class="acc-hint">Plan and billing</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p>
                <strong>Plan:</strong> <?= e(ucfirst((string)$association['plan'])) ?>
                <?php if ($association['status'] === 'trial'): ?>&nbsp;<span class="badge badge--warning">Trial</span><?php endif; ?>
            </p>
            <p class="muted" style="font-size: var(--fs-sm); margin: 0;">
                Plan upgrades and billing are coming in Phase 2. Email <a href="mailto:billing@badasshoa.com">billing@badasshoa.com</a> for changes.
            </p>
        </div>
    </details>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         DANGER ZONE
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <details id="section-danger" class="acc-panel" style="border-color: var(--color-error); margin-top: var(--sp-6);">
        <summary style="background: var(--color-error-bg);">
            <span class="acc-icon">⚠️</span>
            <div>
                <span class="acc-title" style="color: var(--color-error);">Danger zone</span>
                <span class="acc-hint">Irreversible actions</span>
            </div>
            <svg class="acc-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="acc-body">
            <p>Deactivating an association suspends all access for all members. Only super admins can perform this action.</p>
            <button class="btn btn--danger" type="button" disabled aria-disabled="true">Deactivate association</button>
            <span class="muted" style="font-size: var(--fs-xs); margin-left: var(--sp-3);">Available to super admins only.</span>
        </div>
    </details>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function () {
    if (typeof Quill === 'undefined') return;
    var el = document.getElementById('about-editor');
    if (!el) return;
    var hidden = document.getElementById('about-source');
    var initial = el.getAttribute('data-initial-html') || '';
    var quill = new Quill('#about-editor', {
        theme: 'snow',
        placeholder: 'Tell visitors what makes your community special...',
        modules: { toolbar: [['bold','italic','underline'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['link'], ['clean']] }
    });
    el.querySelector('.ql-editor').style.minHeight = '160px';
    if (initial) quill.clipboard.dangerouslyPasteHTML(0, initial);
    var form = el.closest('form');
    if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
})();

// Open accordion from URL hash (e.g. after redirect with #permissions)
document.addEventListener('DOMContentLoaded', function () {
    var hash = location.hash.replace('#', '');
    if (!hash) return;
    var panel = document.getElementById('section-' + hash);
    if (panel) {
        panel.open = true;
        setTimeout(function () { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80);
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

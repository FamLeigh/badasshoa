<?php
// Self-service profile editor — any signed-in user can update their own
// name, phone, mailing address, headshot, and bio. Distinct from
// /dashboard/settings.php (which manages association-wide settings).
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    csrf_check();

    $first   = trim((string)($_POST['first_name'] ?? ''));
    $last    = trim((string)($_POST['last_name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $phone2  = trim((string)($_POST['phone2'] ?? ''));
    $email2  = trim((string)($_POST['email2'] ?? ''));
    if ($email2 !== '' && !filter_var($email2, FILTER_VALIDATE_EMAIL)) $email2 = '';
    $bio     = trim((string)($_POST['bio'] ?? ''));
    $mAddr   = trim((string)($_POST['mailing_address'] ?? ''));
    $mCity   = trim((string)($_POST['mailing_city'] ?? ''));
    $mState  = trim((string)($_POST['mailing_state_region'] ?? ''));
    $mPostal = trim((string)($_POST['mailing_postal_code'] ?? ''));
    $mCtry   = strtoupper(trim((string)($_POST['mailing_country'] ?? '')));
    if ($mCtry !== '' && !preg_match('/^[A-Z]{2}$/', $mCtry)) $mCtry = '';
    $removeAvatar = isset($_POST['remove_avatar']);

    // --- Avatar upload (optional) ---
    $avatarPath = $user['avatar_path'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['avatar']['size'] > 4 * 1024 * 1024) {
            $flashError = 'Headshot must be 4 MB or smaller.';
        } else {
            $orig = $_FILES['avatar']['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
            if (!isset($allowed[$ext])) {
                $flashError = 'Headshot must be PNG, JPG, or WEBP.';
            } else {
                $newName = uuid_filename($orig);
                $relDir  = "uploads/$assocId/avatars";
                $absDir  = storage_path($relDir);
                ensure_dir($absDir);
                $relPath = "$relDir/$newName";
                $absPath = "$absDir/$newName";
                if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $absPath)) {
                    $flashError = 'Could not save the headshot. Try again.';
                } else {
                    // Best-effort: remove the previous avatar file so the storage
                    // dir doesn't accumulate orphans.
                    if (!empty($user['avatar_path'])) {
                        $prevAbs = storage_path((string)$user['avatar_path']);
                        if (is_file($prevAbs)) @unlink($prevAbs);
                    }
                    $avatarPath = $relPath;
                }
            }
        }
    } elseif ($removeAvatar && !empty($user['avatar_path'])) {
        $prevAbs = storage_path((string)$user['avatar_path']);
        if (is_file($prevAbs)) @unlink($prevAbs);
        $avatarPath = null;
    }

    if (!$flashError) {
        db()->prepare(
            'UPDATE users
                SET first_name = ?, last_name = ?, phone = ?, phone2 = ?, email2 = ?,
                    bio = ?, avatar_path = ?,
                    mailing_address = ?, mailing_city = ?, mailing_state_region = ?,
                    mailing_postal_code = ?, mailing_country = ?
              WHERE id = ?'
        )->execute([
            $first, $last, $phone ?: null, $phone2 ?: null, $email2 ?: null,
            $bio ?: null, $avatarPath,
            $mAddr ?: null, $mCity ?: null, $mState ?: null, $mPostal ?: null, $mCtry ?: null,
            (int)$user['id'],
        ]);
        // Keep the session display name fresh.
        $_SESSION['name'] = trim($first . ' ' . $last);
        audit('profile.updated', ['has_avatar' => !empty($avatarPath), 'has_bio' => $bio !== '']);
        flash('success', 'Profile updated.');
        redirect('/dashboard/profile.php');
    }
}

// Refresh user data after possible save
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$me = $stmt->fetch();

// Delete a saved signature (owner-only).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_signature') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    $s = db()->prepare('SELECT image_path FROM user_signatures WHERE id = ? AND user_id = ?');
    $s->execute([$sid, (int)$user['id']]);
    $row = $s->fetch();
    if ($row) {
        if (!empty($row['image_path'])) {
            $abs = storage_path((string)$row['image_path']);
            if (is_file($abs)) @unlink($abs);
        }
        db()->prepare('DELETE FROM user_signatures WHERE id = ? AND user_id = ?')->execute([$sid, (int)$user['id']]);
        audit('signature.deleted', [], $sid, 'user_signature');
        flash('success', 'Saved signature deleted.');
    }
    redirect('/dashboard/profile.php');
}

// Saved signatures (private library for this user).
$mySignatures = user_saved_signatures((int)$user['id']);

// Employment records for this user (read-only on profile — board edits via /dashboard/employees.php).
$myJobsStmt = db()->prepare(
    "SELECT * FROM employees
      WHERE association_id = ? AND user_id = ?
      ORDER BY (status = 'active') DESC, COALESCE(start_date, '1900-01-01') DESC"
);
$myJobsStmt->execute([$assocId, (int)$user['id']]);
$myJobs = $myJobsStmt->fetchAll();

// Documents the board has scoped to me (e.g. a lease, an appointment letter).
// Respects access_level: board_only is hidden from non-managers; everything
// else is visible to the member it's scoped to.
$myDocsSql = "SELECT id, title, description, category, file_path, file_type, created_at
                FROM documents
               WHERE association_id = ? AND user_id = ?";
$myDocsParams = [$assocId, (int)$user['id']];
if (!role_can_manage(viewing_role())) {
    $myDocsSql .= " AND access_level <> 'board_only'";
}
$myDocsSql .= ' ORDER BY created_at DESC';
$myDocsStmt = db()->prepare($myDocsSql);
$myDocsStmt->execute($myDocsParams);
$myDocs = $myDocsStmt->fetchAll();

$active = 'profile';
$page_title = 'My profile — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 800px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-2);">My profile</h1>
    <p class="muted" style="margin-bottom: var(--sp-6);">
        Your name, phone, mailing address, headshot, and a short bio. Anything you put here is visible to others in your association (and on the public landing if you're on the board and opted in to "show on public landing" on your account).
    </p>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="save">

        <!-- Headshot -->
        <div style="display:flex; gap: var(--sp-4); align-items: flex-start; margin-bottom: var(--sp-4);">
            <div>
                <?php if (!empty($me['avatar_path'])): ?>
                    <img src="/user-avatar.php?id=<?= (int)$me['id'] ?>&v=<?= e(substr(md5((string)$me['avatar_path']), 0, 8)) ?>"
                         alt="Your headshot"
                         style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 2px solid var(--color-border);">
                <?php else: ?>
                    <span class="side-nav__avatar" style="width: 96px; height: 96px; flex: 0 0 96px; font-size: var(--fs-2xl);"><?= e(strtoupper(mb_substr((string)$me['first_name'], 0, 1) ?: '?')) ?></span>
                <?php endif; ?>
            </div>
            <div style="flex: 1;">
                <label class="field__label" for="avatar">Headshot</label>
                <input class="input" type="file" id="avatar" name="avatar" accept="image/png,image/jpeg,image/webp">
                <div class="field__hint">PNG, JPG, or WEBP. 4 MB max. Square works best — auto-cropped to a circle on display.</div>
                <?php if (!empty($me['avatar_path'])): ?>
                <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-sm);">
                    <input type="checkbox" name="remove_avatar"> Remove current headshot
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="pf-first">First name</label>
                <input class="input" id="pf-first" name="first_name" value="<?= e((string)$me['first_name']) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="pf-last">Last name</label>
                <input class="input" id="pf-last" name="last_name" value="<?= e((string)$me['last_name']) ?>">
            </div>
        </div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="pf-email">Email</label>
                <input class="input" type="email" id="pf-email" value="<?= e((string)$me['email']) ?>" disabled>
                <div class="field__hint">Primary email changes are handled by an admin — ask the board.</div>
            </div>
            <div class="field">
                <label class="field__label" for="pf-phone">Phone</label>
                <input class="input" type="tel" id="pf-phone" name="phone" value="<?= e((string)($me['phone'] ?? '')) ?>">
            </div>
        </div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="pf-email2">Second email <span class="muted" style="font-weight: 400;">(optional)</span></label>
                <input class="input" type="email" id="pf-email2" name="email2" value="<?= e((string)($me['email2'] ?? '')) ?>" placeholder="personal or spouse">
            </div>
            <div class="field">
                <label class="field__label" for="pf-phone2">Second phone <span class="muted" style="font-weight: 400;">(optional)</span></label>
                <input class="input" type="tel" id="pf-phone2" name="phone2" value="<?= e((string)($me['phone2'] ?? '')) ?>" placeholder="cell or work">
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="pf-bio">About me</label>
            <textarea class="textarea" id="pf-bio" name="bio" rows="4" placeholder="A short bio. Shown to other members of your association — and on the public landing if you're on the board."><?= e((string)($me['bio'] ?? '')) ?></textarea>
            <div class="field__hint">Plain text. Keep it brief — a sentence or two works great.</div>
        </div>

        <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
            <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Mailing address (optional — only set this if your mail should go somewhere other than the unit)</legend>
            <div class="field">
                <label class="field__label" for="pf-maddr">Street address</label>
                <input class="input" id="pf-maddr" name="mailing_address" value="<?= e((string)($me['mailing_address'] ?? '')) ?>" placeholder="123 Main St">
            </div>
            <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                <div class="field"><label class="field__label" for="pf-mcity">City</label>
                    <input class="input" id="pf-mcity" name="mailing_city" value="<?= e((string)($me['mailing_city'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="pf-mstate">State / Province</label>
                    <input class="input" id="pf-mstate" name="mailing_state_region" list="us-ca-states" value="<?= e((string)($me['mailing_state_region'] ?? '')) ?>"></div>
                <div class="field"><label class="field__label" for="pf-mpostal">ZIP / Postal</label>
                    <input class="input" id="pf-mpostal" name="mailing_postal_code" value="<?= e((string)($me['mailing_postal_code'] ?? '')) ?>"></div>
            </div>
            <?= function_exists('us_ca_states_datalist') ? us_ca_states_datalist() : '' ?>
            <div class="field">
                <label class="field__label" for="pf-mctry">Country</label>
                <select class="select" id="pf-mctry" name="mailing_country" style="max-width: 280px;">
                    <option value="">— Same as unit —</option>
                    <?php $cur = (string)($me['mailing_country'] ?? '');
                    foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                        <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>

        <div class="row" style="justify-content: flex-end;">
            <button class="btn btn--primary" type="submit">Save profile</button>
        </div>
    </form>

    <?php if ($mySignatures): ?>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&display=swap" rel="stylesheet">
    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">✍️ My signatures</h2>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
            Private to you — these never appear anywhere except your own signature picker on forms. When you sign a form using a saved signature, the system embeds a copy on that submission, so deleting a saved signature here doesn't affect already-submitted forms.
        </p>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--sp-3);">
            <?php foreach ($mySignatures as $s): ?>
                <div style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3); background: #fff;">
                    <?php if ($s['kind'] === 'typed'): ?>
                        <div style="font-family: 'Caveat', cursive; font-size: 22pt; color: var(--color-navy); line-height: 1.1; min-height: 50px;"><?= e((string)$s['typed_name']) ?></div>
                    <?php else: ?>
                        <img src="/dashboard/signature-image.php?saved_id=<?= (int)$s['id'] ?>" alt="Saved signature" style="max-width: 100%; max-height: 80px; display: block;">
                    <?php endif; ?>
                    <div class="muted" style="font-size: var(--fs-xs); margin-top: 8px;">
                        <strong><?= e((string)($s['label'] ?: ucfirst($s['kind']))) ?></strong>
                        · saved <?= e(udate('M j, Y', strtotime((string)$s['created_at']))) ?>
                        <?php if (!empty($s['last_used_at'])): ?> · last used <?= e(udate('M j, Y', strtotime((string)$s['last_used_at']))) ?><?php endif; ?>
                    </div>
                    <form method="post" style="display:inline; margin-top: 8px;" onsubmit="return confirm('Delete this saved signature? Already-signed forms keep their copy.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete_signature">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.3rem 0.7rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($myJobs): ?>
    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">💼 Your employment</h2>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">Edits are handled by the board. If anything below is wrong, let them know.</p>
        <div class="stack-md">
        <?php foreach ($myJobs as $j):
            $pay = match ($j['pay_type']) {
                'hourly' => $j['hourly_rate'] !== null ? '$' . number_format((float)$j['hourly_rate'], 2) . '/hr' : '',
                'salary' => $j['salary']      !== null ? '$' . number_format((float)$j['salary'], 0) . '/yr' : '',
                'flat'   => $j['flat_amount'] !== null ? '$' . number_format((float)$j['flat_amount'], 2) . '/job' : '',
                default  => 'Unpaid',
            };
        ?>
            <div style="<?= $j['status'] === 'inactive' ? 'opacity: 0.6;' : '' ?>">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                    <strong><?= e((string)$j['job_title']) ?></strong>
                    <span class="badge" style="font-size: var(--fs-xs);"><?= e(ucfirst((string)$j['employment_type'])) ?></span>
                    <span class="badge <?= $j['status']==='active' ? 'badge--success' : '' ?>" style="font-size: var(--fs-xs);"><?= e((string)$j['status']) ?></span>
                </div>
                <div class="muted" style="font-size: var(--fs-sm);">
                    <?= e($pay) ?>
                    <?php if (!empty($j['start_date']) || !empty($j['end_date'])): ?>
                        <?= !empty($pay) ? ' · ' : '' ?>
                        <?php if (!empty($j['start_date'])): ?><?= e(udate('M Y', strtotime((string)$j['start_date']))) ?><?php endif; ?>
                        <?php if (!empty($j['end_date'])): ?> – <?= e(udate('M Y', strtotime((string)$j['end_date']))) ?><?php elseif (!empty($j['start_date'])): ?> – present<?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($j['notes'])): ?>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0; white-space: pre-wrap;"><?= e((string)$j['notes']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($myDocs): ?>
    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">My documents</h2>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
            Documents the board has attached to your account — leases, letters, etc.
        </p>
        <table class="table">
            <thead><tr><th>Title</th><th>Category</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($myDocs as $d): ?>
                <tr>
                    <td>
                        <strong><?= e((string)$d['title']) ?></strong>
                        <?php if (!empty($d['description'])): ?>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$d['description'], 0, 100, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string)($d['category'] ?? '')) ?></td>
                    <td><?= e(udate('M j, Y', strtotime((string)$d['created_at']))) ?></td>
                    <td style="text-align:right; white-space: nowrap;">
                        <?php if (!empty($d['file_path'])): ?>
                            <a class="btn btn--ghost" href="/dashboard/file.php?type=document&id=<?= (int)$d['id'] ?>" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Download</a>
                        <?php else: ?>
                            <a class="btn btn--ghost" href="/dashboard/document.php?id=<?= (int)$d['id'] ?>" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

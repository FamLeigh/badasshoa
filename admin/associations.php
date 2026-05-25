<?php
require __DIR__ . '/_bootstrap.php';

$flashError = null;

// --- Approve a pending signup -> create association + initial board admin ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'approve_signup') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM signups WHERE id = ? AND status = "pending"');
    $stmt->execute([$sid]);
    $s = $stmt->fetch();
    if ($s) {
        $base = slugify((string)$s['association_name']);
        // ensure subdomain uniqueness
        $sub = $base; $i = 2;
        while (true) {
            $check = db()->prepare('SELECT 1 FROM associations WHERE subdomain = ?');
            $check->execute([$sub]);
            if (!$check->fetchColumn()) break;
            $sub = "$base-$i"; $i++;
        }
        db()->beginTransaction();
        try {
            db()->prepare(
                'INSERT INTO associations
                 (name, subdomain, address, city, state_region, postal_code, country, unit_count, plan, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "trial")'
            )->execute([
                $s['association_name'], $sub,
                $s['address'] ?: null, $s['city'] ?: null, $s['state_region'] ?: null,
                $s['postal_code'] ?: null, $s['country'] ?: 'US',
                (int)$s['unit_count'], $s['plan_selected'] ?: 'starter',
            ]);
            $newAssocId = (int)db()->lastInsertId();

            $tempPass = bin2hex(random_bytes(6));
            $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $first = strtok((string)$s['contact_name'], ' ') ?: '';
            $last  = trim(substr((string)$s['contact_name'], strlen($first)));

            db()->prepare(
                'INSERT INTO users (association_id, first_name, last_name, email, phone, password_hash, role, status)
                 VALUES (?, ?, ?, ?, ?, ?, "board_admin", "active")'
            )->execute([$newAssocId, $first, $last, $s['contact_email'], $s['contact_phone'], $hash]);

            db()->prepare('UPDATE signups SET status = "approved" WHERE id = ?')->execute([$sid]);
            db()->commit();

            send_mail((string)$s['contact_email'],
                'Your BadassHOA portal is live',
                "Hi {$first},\n\nYour portal for {$s['association_name']} is ready.\n\nSign in: https://badasshoa.com/login.php\nEmail: {$s['contact_email']}\nTemporary password: $tempPass\n(Change it on first sign-in.)\n");

            audit('signup.approved', ['signup_id' => $sid, 'association_id' => $newAssocId, 'subdomain' => $sub], $newAssocId, 'association');
            flash('success', "Approved &mdash; new association \"{$s['association_name']}\" provisioned.");
        } catch (Throwable $e) {
            db()->rollBack();
            $flashError = 'Approval failed: ' . $e->getMessage();
        }
        if (!$flashError) redirect('/admin/associations.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'reject_signup') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE signups SET status = "rejected" WHERE id = ?')->execute([$sid]);
    audit('signup.rejected', [], $sid, 'signup');
    flash('success', 'Signup rejected.');
    redirect('/admin/associations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    $aid = (int)($_POST['id'] ?? 0);
    $st  = $_POST['status'] ?? 'active';
    if (in_array($st, ['active','inactive','trial','gifted'], true)) {
        db()->prepare('UPDATE associations SET status = ? WHERE id = ?')->execute([$st, $aid]);
        audit('association.status_changed', ['status' => $st], $aid, 'association');
        flash('success', "Set association #$aid status to $st.");
    }
    redirect('/admin/associations.php');
}

// --- Create association (super admin direct entry, no signup required) ---
$createError = null;
$createDefaults = [
    'name' => '', 'subdomain' => '', 'address' => '', 'city' => '',
    'state_region' => '', 'postal_code' => '', 'country' => 'US',
    'unit_count' => 0, 'plan' => 'starter', 'status' => 'trial',
    'primary_color' => '#0f1f3d', 'public_landing_enabled' => 0,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_assoc') {
    csrf_check();
    $name      = trim((string)($_POST['name'] ?? ''));
    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));
    $city      = trim((string)($_POST['city'] ?? ''));
    $stateReg  = trim((string)($_POST['state_region'] ?? ''));
    $postal    = trim((string)($_POST['postal_code'] ?? ''));
    $country   = strtoupper(trim((string)($_POST['country'] ?? 'US')));
    $units     = max(0, (int)($_POST['unit_count'] ?? 0));
    $plan      = $_POST['plan'] ?? 'starter';
    $status    = $_POST['status'] ?? 'trial';
    $color     = trim((string)($_POST['primary_color'] ?? '#0f1f3d'));
    $publicLanding = isset($_POST['public_landing_enabled']) ? 1 : 0;

    if (!in_array($plan, ['starter','growth','professional','enterprise'], true))              $plan = 'starter';
    if (!in_array($status, ['active','inactive','trial','gifted'], true))                     $status = 'trial';
    if (!preg_match('/^#[0-9a-f]{6}$/i', $color))                                      $color = '#0f1f3d';
    if (!preg_match('/^[A-Z]{2}$/', $country))                                         $country = 'US';

    // Slug normalize / fall back to slugify(name)
    $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower($subdomain));
    if ($subdomain === '') $subdomain = slugify($name);

    if ($name === '') {
        $createError = 'Association name is required.';
    } elseif ($subdomain === '') {
        $createError = 'Slug is required (or give a name we can slugify).';
    } else {
        $dupe = db()->prepare('SELECT 1 FROM associations WHERE subdomain = ?');
        $dupe->execute([$subdomain]);
        if ($dupe->fetchColumn()) {
            $createError = "Slug \"$subdomain\" is already taken.";
        } else {
            db()->prepare(
                'INSERT INTO associations
                 (name, subdomain, address, city, state_region, postal_code, country,
                  unit_count, plan, status, primary_color, public_landing_enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name, $subdomain,
                $address ?: null, $city ?: null, $stateReg ?: null, $postal ?: null, $country,
                $units, $plan, $status, $color, $publicLanding,
            ]);
            $newId = (int)db()->lastInsertId();
            audit('association.created_admin', ['name' => $name, 'subdomain' => $subdomain, 'plan' => $plan], $newId, 'association');
            flash('success', "Created association \"$name\" (#$newId). Add board users via the Users page.");
            redirect('/admin/associations.php?action=edit&id=' . $newId);
        }
    }
    // On error, preserve what they typed so the form re-renders with their values
    $createDefaults = [
        'name' => $name, 'subdomain' => $subdomain, 'address' => $address, 'city' => $city,
        'state_region' => $stateReg, 'postal_code' => $postal, 'country' => $country,
        'unit_count' => $units, 'plan' => $plan, 'status' => $status,
        'primary_color' => $color, 'public_landing_enabled' => $publicLanding,
    ];
}

// --- Platform messages ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_platform_msg') {
    csrf_check();
    $aid     = (int)($_POST['assoc_id'] ?? 0);
    $msg     = trim((string)($_POST['message'] ?? ''));
    $aud     = in_array($_POST['audience'] ?? '', ['all','board'], true) ? $_POST['audience'] : 'all';
    $expires = trim((string)($_POST['expires_at'] ?? '')) ?: null;
    if ($msg && $aid) {
        db()->prepare(
            'INSERT INTO platform_messages (association_id, message, audience, active, expires_at, created_by)
             VALUES (?,?,?,1,?,?)'
        )->execute([$aid, $msg, $aud, $expires, (int)$user['id']]);
        flash('success', 'Message added.');
    }
    redirect('/admin/associations.php?action=edit&id=' . $aid . '#platform-messages');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'toggle_platform_msg') {
    csrf_check();
    $pmid = (int)($_POST['msg_id'] ?? 0);
    $aid  = (int)($_POST['assoc_id'] ?? 0);
    db()->prepare('UPDATE platform_messages SET active = 1 - active WHERE id = ?')->execute([$pmid]);
    redirect('/admin/associations.php?action=edit&id=' . $aid . '#platform-messages');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_platform_msg') {
    csrf_check();
    $pmid = (int)($_POST['msg_id'] ?? 0);
    $aid  = (int)($_POST['assoc_id'] ?? 0);
    db()->prepare('DELETE FROM platform_messages WHERE id = ?')->execute([$pmid]);
    flash('success', 'Message deleted.');
    redirect('/admin/associations.php?action=edit&id=' . $aid . '#platform-messages');
}

// --- Full edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_assoc') {
    csrf_check();
    $aid       = (int)($_POST['id'] ?? 0);
    $name      = trim((string)($_POST['name'] ?? ''));
    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));
    $city      = trim((string)($_POST['city'] ?? ''));
    $stateReg  = trim((string)($_POST['state_region'] ?? ''));
    $postal    = trim((string)($_POST['postal_code'] ?? ''));
    $country   = strtoupper(trim((string)($_POST['country'] ?? 'US')));
    $units     = max(0, (int)($_POST['unit_count'] ?? 0));
    $plan      = $_POST['plan'] ?? 'starter';
    $status    = $_POST['status'] ?? 'trial';
    $color     = trim((string)($_POST['primary_color'] ?? '#0f1f3d'));
    $publicLanding = isset($_POST['public_landing_enabled']) ? 1 : 0;
    $paidGb    = max(0, (int)($_POST['storage_paid_extra_gb'] ?? 0));
    $customDomain = strtolower(trim((string)($_POST['custom_domain'] ?? '')));
    $customDomain = preg_replace('/^www\./', '', $customDomain);
    $customDomain = preg_replace('/^https?:\/\//', '', $customDomain);
    $customDomain = rtrim($customDomain, '/');
    $customDomain = $customDomain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $customDomain) ? $customDomain : null;

    if (!in_array($plan, ['starter','growth','professional','enterprise'], true))              $plan = 'starter';
    if (!in_array($status, ['active','inactive','trial','gifted'], true))                     $status = 'trial';
    if (!preg_match('/^#[0-9a-f]{6}$/i', $color))                                      $color = '#0f1f3d';
    if (!preg_match('/^[A-Z]{2}$/', $country))                                         $country = 'US';
    // Slug normalize
    $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower($subdomain));
    if ($subdomain === '') $subdomain = slugify($name);

    $check = db()->prepare('SELECT 1 FROM associations WHERE id = ?');
    $check->execute([$aid]);
    if (!$check->fetchColumn()) {
        $flashError = 'Association not found.';
    } elseif ($name === '') {
        $flashError = 'Association name is required.';
    } else {
        $dupe = db()->prepare('SELECT id FROM associations WHERE subdomain = ? AND id <> ?');
        $dupe->execute([$subdomain, $aid]);
        if ($dupe->fetchColumn()) {
            $flashError = "Slug \"$subdomain\" is already taken by another association.";
        } elseif ($customDomain !== null) {
            $dupeDomain = db()->prepare('SELECT id FROM associations WHERE custom_domain = ? AND id <> ?');
            $dupeDomain->execute([$customDomain, $aid]);
            if ($dupeDomain->fetchColumn()) {
                $flashError = "Custom domain \"$customDomain\" is already in use by another association.";
            }
        }
        if (!$flashError) {
            db()->prepare(
                'UPDATE associations
                 SET name = ?, subdomain = ?, address = ?, city = ?, state_region = ?, postal_code = ?, country = ?,
                     unit_count = ?, plan = ?, status = ?, primary_color = ?, public_landing_enabled = ?,
                     storage_paid_extra_gb = ?, custom_domain = ?
                 WHERE id = ?'
            )->execute([
                $name, $subdomain,
                $address ?: null, $city ?: null, $stateReg ?: null, $postal ?: null, $country,
                $units, $plan, $status, $color, $publicLanding, $paidGb, $customDomain, $aid,
            ]);
            audit('association.edited', ['name' => $name, 'plan' => $plan, 'status' => $status, 'public_landing' => $publicLanding, 'paid_extra_gb' => $paidGb], $aid, 'association');
            flash('success', "Association \"$name\" updated.");
            redirect('/admin/associations.php');
        }
    }
}

$signups = db()->query('SELECT * FROM signups WHERE status = "pending" ORDER BY created_at DESC')->fetchAll();
$assocs  = db()->query(
    'SELECT a.*, (SELECT COUNT(*) FROM users WHERE association_id = a.id) AS user_count
     FROM associations a ORDER BY a.created_at DESC'
)->fetchAll();

// Show create form when ?action=new (or after a failed create POST that set $createError)
$showCreate = ($_GET['action'] ?? '') === 'new' || $createError !== null;

// Edit target
$editAssoc = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
    $stmt->execute([$eid]);
    $editAssoc = $stmt->fetch() ?: null;
}

// Signup detail view
$viewSignup = null;
if (($_GET['action'] ?? '') === 'view_signup') {
    $sid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM signups WHERE id = ?');
    $stmt->execute([$sid]);
    $viewSignup = $stmt->fetch() ?: null;
}

$page_title = 'Associations — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="align-items: flex-start; flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Associations</h1>
            <p class="muted">Approve signups and manage tenant status.</p>
        </div>
        <?php if (!$showCreate && !$editAssoc && !$viewSignup): ?>
            <a class="btn btn--primary" href="?action=new">+ New association</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($createError): ?><div class="flash flash--error"><?= e($createError) ?></div><?php endif; ?>

    <?php if ($viewSignup): ?>
    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="card__head">
            <h3 class="card__title">Signup detail</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php">← Back to list</a>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--sp-6); font-size: var(--fs-sm);">
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Association</div>
                <div style="font-size: var(--fs-lg); font-weight: 700; margin: var(--sp-1) 0 var(--sp-3);"><?= e((string)$viewSignup['association_name']) ?></div>

                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Address</div>
                <div style="margin: var(--sp-1) 0 var(--sp-3);">
                    <?php if ($viewSignup['address']): ?><?= e((string)$viewSignup['address']) ?><br><?php endif; ?>
                    <?php
                    $line2 = trim(
                        ($viewSignup['city'] ?? '')
                        . (($viewSignup['city'] && $viewSignup['state_region']) ? ', ' : '')
                        . ($viewSignup['state_region'] ?? '')
                        . ' ' . ($viewSignup['postal_code'] ?? '')
                    );
                    if ($line2 !== '') echo e($line2) . '<br>';
                    if ($viewSignup['country']) echo e((string)$viewSignup['country']);
                    if (!$viewSignup['address'] && $line2 === '' && !$viewSignup['country']) echo '<span class="muted">— not provided —</span>';
                    ?>
                </div>

                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Units &amp; plan</div>
                <div style="margin: var(--sp-1) 0 var(--sp-3);">
                    <?= (int)$viewSignup['unit_count'] ?> units · <?= e((string)$viewSignup['plan_selected']) ?>
                </div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Contact</div>
                <div style="margin: var(--sp-1) 0 var(--sp-3);">
                    <strong><?= e((string)$viewSignup['contact_name']) ?></strong><br>
                    <a href="mailto:<?= e((string)$viewSignup['contact_email']) ?>"><?= e((string)$viewSignup['contact_email']) ?></a>
                    <?php if ($viewSignup['contact_phone']): ?>
                        <br><a href="tel:<?= e((string)$viewSignup['contact_phone']) ?>"><?= e((string)$viewSignup['contact_phone']) ?></a>
                    <?php endif; ?>
                </div>

                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Submitted</div>
                <div style="margin: var(--sp-1) 0 var(--sp-3);">
                    <?= e(date('M j, Y g:i A', strtotime((string)$viewSignup['created_at']))) ?> UTC
                </div>

                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Status</div>
                <div style="margin: var(--sp-1) 0 var(--sp-3);">
                    <?php
                    $sCls = $viewSignup['status']==='approved' ? 'badge--success' : ($viewSignup['status']==='rejected' ? 'badge--error' : 'badge--warning');
                    ?>
                    <span class="badge <?= $sCls ?>"><?= e((string)$viewSignup['status']) ?></span>
                </div>
            </div>
        </div>

        <?php if ($viewSignup['notes']): ?>
        <div style="margin-top: var(--sp-4); padding-top: var(--sp-4); border-top: 1px solid var(--color-border);">
            <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Notes</div>
            <div style="margin-top: var(--sp-2); white-space: pre-wrap;"><?= e((string)$viewSignup['notes']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($viewSignup['status'] === 'pending'): ?>
        <div class="row" style="justify-content: flex-end; gap: var(--sp-2); margin-top: var(--sp-6); padding-top: var(--sp-4); border-top: 1px solid var(--color-border);">
            <form method="post" style="display:inline;" onsubmit="return confirm('Reject this signup?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="reject_signup">
                <input type="hidden" name="id" value="<?= (int)$viewSignup['id'] ?>">
                <button class="btn btn--ghost" type="submit">Reject</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Approve this signup and provision the association?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="approve_signup">
                <input type="hidden" name="id" value="<?= (int)$viewSignup['id'] ?>">
                <button class="btn btn--primary" type="submit">Approve &amp; provision</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showCreate): ?>
    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="card__head">
            <h3 class="card__title">New association</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php">← Back to list</a>
        </div>
        <p class="muted" style="margin-bottom: var(--sp-4); font-size: var(--fs-sm);">
            Direct super-admin entry. Use this for pilot customers, manual onboarding, or test data.
            For self-service signups from the marketing site, use "Approve" on the Pending signups list instead.
        </p>
        <form method="post" class="form" data-address-lookup>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="create_assoc">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="na-name">Name</label>
                    <input class="input" id="na-name" name="name" required value="<?= e((string)$createDefaults['name']) ?>" placeholder="Bellair Condo Association">
                </div>
                <div class="field">
                    <label class="field__label" for="na-slug">Slug (URL-safe)</label>
                    <input class="input" id="na-slug" name="subdomain" value="<?= e((string)$createDefaults['subdomain']) ?>" pattern="[a-z0-9-]*" placeholder="auto from name if blank">
                    <div class="field__hint">Lowercase letters, numbers, and hyphens. Leave blank to auto-derive from the name.</div>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="na-addr">Street address</label>
                <input class="input" id="na-addr" name="address" value="<?= e((string)$createDefaults['address']) ?>" placeholder="123 Main St">
            </div>
            <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                <div class="field">
                    <label class="field__label" for="na-city">City</label>
                    <input class="input" id="na-city" name="city" value="<?= e((string)$createDefaults['city']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="na-state">State / Province</label>
                    <input class="input" id="na-state" name="state_region" list="us-ca-states" value="<?= e((string)$createDefaults['state_region']) ?>" autocomplete="address-level1">
                </div>
                <div class="field">
                    <label class="field__label" for="na-postal">ZIP / Postal</label>
                    <input class="input" id="na-postal" name="postal_code" value="<?= e((string)$createDefaults['postal_code']) ?>" autocomplete="postal-code" placeholder="12345 or A1A 1A1">
                </div>
            </div>
            <?= us_ca_states_datalist() ?>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="na-country">Country</label>
                    <select class="select" id="na-country" name="country">
                        <?php $cur = strtoupper((string)$createDefaults['country']);
                        foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                            <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="na-units">Unit count</label>
                    <input class="input" type="number" min="0" max="10000" id="na-units" name="unit_count" value="<?= (int)$createDefaults['unit_count'] ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="na-plan">Plan</label>
                    <select class="select" id="na-plan" name="plan">
                        <?php
                        // Professional was dropped 2026-05-13. We still tolerate it as a legacy
                        // value (in_array allowlist below) so existing rows render, but new
                        // selections are limited to the current three tiers.
                        $planOptions = ['starter'=>'Starter','growth'=>'Growth','enterprise'=>'Enterprise'];
                        if (isset($editAssoc['plan']) && $editAssoc['plan'] === 'professional') {
                            $planOptions['professional'] = 'Professional (legacy)';
                        }
                        foreach ($planOptions as $val=>$lbl): ?>
                            <option value="<?= e($val) ?>" <?= $createDefaults['plan']===$val?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="na-status">Status</label>
                    <select class="select" id="na-status" name="status">
                        <?php foreach (['trial'=>'Trial','active'=>'Active','inactive'=>'Inactive'] as $val=>$lbl): ?>
                            <option value="<?= e($val) ?>" <?= $createDefaults['status']===$val?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="na-color">Primary color</label>
                    <input class="input" type="color" id="na-color" name="primary_color" value="<?= e((string)$createDefaults['primary_color']) ?>">
                </div>
                <div class="field">
                    <label style="display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-3); background: var(--color-surface); border-radius: var(--r-sm); height: 100%; box-sizing: border-box;">
                        <input type="checkbox" name="public_landing_enabled" value="1" <?= (int)$createDefaults['public_landing_enabled'] === 1 ? 'checked' : '' ?>>
                        <span>Enable public landing at <code>/{slug}/</code></span>
                    </label>
                </div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/admin/associations.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Create association</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($editAssoc): ?>
    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="card__head">
            <h3 class="card__title">Edit association</h3>
            <div class="row" style="gap: var(--sp-3); align-items: center;">
                <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);"
                   href="/admin/users.php?action=new&association_id=<?= (int)$editAssoc['id'] ?>">
                    + Invite a user to this association
                </a>
                <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php">← Back to list</a>
            </div>
        </div>
        <form method="post" class="form" data-address-lookup>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit_assoc">
            <input type="hidden" name="id" value="<?= (int)$editAssoc['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ea-name">Name</label>
                    <input class="input" id="ea-name" name="name" required value="<?= e($editAssoc['name']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ea-slug">Slug (URL-safe)</label>
                    <input class="input" id="ea-slug" name="subdomain" required value="<?= e($editAssoc['subdomain']) ?>" pattern="[a-z0-9-]+">
                    <div class="field__hint">Lowercase letters, numbers, and hyphens only. Must be unique across BadassHOA.</div>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="ea-addr">Street address</label>
                <input class="input" id="ea-addr" name="address" value="<?= e((string)$editAssoc['address']) ?>" placeholder="123 Main St, Suite 100">
            </div>
            <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                <div class="field">
                    <label class="field__label" for="ea-city">City</label>
                    <input class="input" id="ea-city" name="city" value="<?= e((string)($editAssoc['city'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ea-state">State / Province</label>
                    <input class="input" id="ea-state" name="state_region" list="us-ca-states" value="<?= e((string)($editAssoc['state_region'] ?? '')) ?>" autocomplete="address-level1">
                </div>
                <div class="field">
                    <label class="field__label" for="ea-postal">ZIP / Postal</label>
                    <input class="input" id="ea-postal" name="postal_code" value="<?= e((string)($editAssoc['postal_code'] ?? '')) ?>" autocomplete="postal-code" placeholder="12345 or A1A 1A1">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ea-country">Country</label>
                    <select class="select" id="ea-country" name="country">
                        <?php $cur = strtoupper((string)($editAssoc['country'] ?? 'US'));
                        foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                            <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <?= us_ca_states_datalist() ?>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ea-units">Unit count</label>
                    <input class="input" type="number" min="0" max="10000" id="ea-units" name="unit_count" value="<?= (int)$editAssoc['unit_count'] ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ea-color">Primary color</label>
                    <input class="input" type="color" id="ea-color" name="primary_color" value="<?= e((string)$editAssoc['primary_color']) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ea-plan">Plan</label>
                    <select class="select" id="ea-plan" name="plan">
                        <?php
                        // Professional was dropped 2026-05-13. We still tolerate it as a legacy
                        // value (in_array allowlist below) so existing rows render, but new
                        // selections are limited to the current three tiers.
                        $planOptions = ['starter'=>'Starter','growth'=>'Growth','enterprise'=>'Enterprise'];
                        if (isset($editAssoc['plan']) && $editAssoc['plan'] === 'professional') {
                            $planOptions['professional'] = 'Professional (legacy)';
                        }
                        foreach ($planOptions as $val=>$lbl): ?>
                            <option value="<?= e($val) ?>" <?= $editAssoc['plan']===$val?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="ea-status">Status</label>
                    <select class="select" id="ea-status" name="status">
                        <?php foreach (['active'=>'Active','trial'=>'Trial','inactive'=>'Inactive','gifted'=>'Gifted (free)'] as $val=>$lbl): ?>
                            <option value="<?= e($val) ?>" <?= $editAssoc['status']===$val?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-3); cursor: pointer; padding: var(--sp-3); background: var(--color-surface); border-radius: var(--r-sm);">
                    <input type="checkbox" name="public_landing_enabled" value="1" <?= (int)($editAssoc['public_landing_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <div>
                        <strong>Show public landing at <code>/<?= e((string)$editAssoc['subdomain']) ?>/</code></strong>
                        <div class="muted" style="font-size: var(--fs-sm);">When enabled, an unauthenticated visitor at <a href="/<?= e((string)$editAssoc['subdomain']) ?>/" target="_blank" rel="noopener">/<?= e((string)$editAssoc['subdomain']) ?>/</a> sees a branded landing page with a sign-in CTA. Otherwise the URL bounces to /login.php.</div>
                    </div>
                </label>
            </div>

            <div class="field">
                <label class="field__label" for="ea-custom-domain">Custom domain <span class="muted" style="font-weight:400; font-size: var(--fs-xs);">(optional)</span></label>
                <input class="input" id="ea-custom-domain" name="custom_domain"
                       value="<?= e((string)($editAssoc['custom_domain'] ?? '')) ?>"
                       placeholder="bellaircondos.com"
                       pattern="[a-z0-9.\-]+"
                       autocomplete="off">
                <div class="field__hint">
                    Enter the bare domain (no http:// or www.). Point the domain's A record at this server's IP, then add it as an addon domain in hPanel. Leave blank to use only the default <code>/<?= e((string)$editAssoc['subdomain']) ?>/</code> URL.
                </div>
            </div>

            <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Storage</legend>
                <?php
                $storUsed  = association_storage_used_bytes((int)$editAssoc['id']);
                $storQuota = association_storage_quota_bytes($editAssoc);
                ?>
                <div class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
                    Currently using <strong><?= e(format_bytes($storUsed)) ?></strong> of <strong><?= e(format_bytes($storQuota)) ?></strong>
                    (1 GB free + <?= (int)($editAssoc['storage_paid_extra_gb'] ?? 0) ?> GB paid add-on).
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="ea-gb">Paid extra GB</label>
                        <input class="input" type="number" id="ea-gb" name="storage_paid_extra_gb" min="0" step="1" value="<?= (int)($editAssoc['storage_paid_extra_gb'] ?? 0) ?>">
                        <div class="field__hint">$5/mo per GB. Set after the customer pays — billing isn't automated yet.</div>
                    </div>
                    <div class="field"><!-- spacer --></div>
                </div>
            </fieldset>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/admin/associations.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
    </div>

    <?php
    // Platform messages for this association
    $_pmRows = db()->prepare(
        'SELECT id, message, audience, active, expires_at, created_at
           FROM platform_messages
          WHERE association_id = ?
          ORDER BY created_at DESC'
    );
    $_pmRows->execute([(int)$editAssoc['id']]);
    $_pmList = $_pmRows->fetchAll();
    ?>
    <div class="card" id="platform-messages" style="margin-top: var(--sp-6);">
        <div class="card__header">
            <h2 class="card__title" style="font-size: var(--fs-xl);">Messages from BadassHOA</h2>
            <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);">Banners pushed to this association's dashboard. Audience "Board" shows only to board-level roles; "All" shows to every logged-in member.</p>
        </div>

        <?php if (!$_pmList): ?>
            <p class="muted" style="padding: var(--sp-2) 0;">No messages yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" style="margin-bottom: var(--sp-4);">
            <thead>
                <tr><th>Message</th><th>Audience</th><th>Expires</th><th>Active</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($_pmList as $_pm): ?>
                <tr style="<?= (int)$_pm['active'] === 0 ? 'opacity:.5' : '' ?>">
                    <td style="max-width:460px;"><?= e((string)$_pm['message']) ?></td>
                    <td><span class="badge badge--<?= $_pm['audience'] === 'board' ? 'info' : 'success' ?>"><?= e((string)$_pm['audience']) ?></span></td>
                    <td class="muted" style="white-space:nowrap;"><?= $_pm['expires_at'] ? e(date('M j, Y', strtotime((string)$_pm['expires_at']))) : 'Never' ?></td>
                    <td>
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="toggle_platform_msg">
                            <input type="hidden" name="msg_id" value="<?= (int)$_pm['id'] ?>">
                            <input type="hidden" name="assoc_id" value="<?= (int)$editAssoc['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding:2px 10px;font-size:var(--fs-sm);">
                                <?= (int)$_pm['active'] === 1 ? 'Pause' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this message?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete_platform_msg">
                            <input type="hidden" name="msg_id" value="<?= (int)$_pm['id'] ?>">
                            <input type="hidden" name="assoc_id" value="<?= (int)$editAssoc['id'] ?>">
                            <button class="btn btn--danger-ghost" type="submit" style="padding:2px 10px;font-size:var(--fs-sm);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <form method="post" style="border-top: 1px solid var(--color-border); padding-top: var(--sp-4); margin-top: var(--sp-2);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="add_platform_msg">
            <input type="hidden" name="assoc_id" value="<?= (int)$editAssoc['id'] ?>">
            <div class="field" style="margin-bottom: var(--sp-3);">
                <label class="field__label" for="pm-message">New message</label>
                <textarea class="input" id="pm-message" name="message" rows="3" required placeholder="Type a message to display on this association's dashboard…" style="resize:vertical;"></textarea>
            </div>
            <div class="form-row form-row--2" style="margin-bottom: var(--sp-3);">
                <div class="field">
                    <label class="field__label" for="pm-audience">Audience</label>
                    <select class="select" id="pm-audience" name="audience">
                        <option value="all">All members</option>
                        <option value="board">Board only</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="pm-expires">Expires (optional)</label>
                    <input class="input" type="date" id="pm-expires" name="expires_at">
                    <div class="field__hint">Leave blank to show indefinitely until manually paused.</div>
                </div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Add message</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <h2 id="signups" style="font-size: var(--fs-xl); margin-top: var(--sp-8);">Pending signups</h2>
    <?php if (!$signups): ?>
        <p class="muted">No pending signups.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Association</th><th>Contact</th><th>Units</th><th>Plan</th><th>Submitted</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($signups as $s): ?>
            <tr>
                <td>
                    <strong><?= e((string)$s['association_name']) ?></strong>
                    <?php
                    $loc = trim(
                        ($s['city'] ?? '')
                        . (($s['city'] && $s['state_region']) ? ', ' : '')
                        . ($s['state_region'] ?? '')
                    );
                    ?>
                    <?php if ($loc !== ''): ?>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e($loc) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e((string)$s['contact_name']) ?>
                    <div class="muted" style="font-size: var(--fs-xs);">
                        <?= e((string)$s['contact_email']) ?>
                        <?php if ($s['contact_phone']): ?> &middot; <?= e((string)$s['contact_phone']) ?><?php endif; ?>
                    </div>
                </td>
                <td><?= (int)$s['unit_count'] ?></td>
                <td><?= e((string)$s['plan_selected']) ?></td>
                <td><?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=view_signup&id=<?= (int)$s['id'] ?>">View</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this signup and provision the association?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="approve_signup">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn--primary" type="submit">Approve</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Reject this signup?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="reject_signup">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn--ghost" type="submit">Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h2 style="font-size: var(--fs-xl); margin-top: var(--sp-12);">All associations</h2>
    <?php if (!$assocs): ?>
        <p class="muted">No associations yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Name</th><th>Slug</th><th>Units</th><th>Users</th><th>Plan</th><th>Status</th><th>Created</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($assocs as $a): ?>
            <tr>
                <td>
                    <strong><?= e((string)$a['name']) ?></strong>
                    <?php if ($a['address']): ?>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$a['address'], 0, 60, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td><code><?= e((string)$a['subdomain']) ?></code></td>
                <td><?= (int)$a['unit_count'] ?></td>
                <td><?= (int)$a['user_count'] ?></td>
                <td><?= e((string)$a['plan']) ?></td>
                <td>
                    <?php
                    $cls = match((string)$a['status']) {
                        'active'   => 'badge--success',
                        'trial'    => 'badge--warning',
                        'gifted'   => 'badge--info',
                        default    => 'badge--error',
                    };
                    ?>
                    <span class="badge <?= $cls ?>"><?= e((string)$a['status']) ?></span>
                </td>
                <td><?= e(date('M j, Y', strtotime((string)$a['created_at']))) ?></td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$a['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <select name="status" class="select" onchange="this.form.submit()" style="padding: 0.4rem 0.5rem; font-size: var(--fs-xs);">
                            <option value="active"   <?= $a['status']==='active'?'selected':'' ?>>active</option>
                            <option value="trial"    <?= $a['status']==='trial'?'selected':'' ?>>trial</option>
                            <option value="inactive" <?= $a['status']==='inactive'?'selected':'' ?>>inactive</option>
                            <option value="gifted"   <?= $a['status']==='gifted'?'selected':'' ?>>gifted</option>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

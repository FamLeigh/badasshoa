<?php
// Per-association permission configuration. Board admin and up can change which
// minimum role is required for each feature. Management-write actions are NOT
// configurable here — those are hardcoded for legal/liability safety.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

// Only board_admin and super_admin can change permission settings.
$canEdit = in_array((string)($_SESSION['role'] ?? ''), ['board_admin', 'super_admin'], true);

// Roles available as minimum thresholds, in ascending order.
$ROLES = [
    'renter'           => 'Renter (everyone)',
    'staff'            => 'Staff',
    'owner'            => 'Owner',
    'board_member'     => 'Board member',
    'board_admin'      => 'Board admin',
];

// Configurable feature definitions.
$FEATURES = [
    'content' => [
        'label'    => 'Content',
        'features' => [
            'read_documents'      => ['label' => 'View documents',         'desc' => 'Who can browse and download documents in the library.'],
            'read_minutes'        => ['label' => 'Read meeting minutes',   'desc' => 'Who can read the board meeting minutes archive.'],
            'read_contacts'       => ['label' => 'View contacts',          'desc' => 'Who can see the association contact directory — emergency lines, contractors, utilities.'],
            'submit_concerns'     => ['label' => 'Submit concerns',        'desc' => 'Who can file a concern or complaint.'],
        ],
    ],
    'management' => [
        'label'    => 'Management (view-only)',
        'features' => [
            'read_work_orders'    => ['label' => 'View work orders',       'desc' => 'Who can see the work order queue in read-only mode. Creating/editing always requires management role.'],
            'read_violations'     => ['label' => 'View violations',        'desc' => 'Who can see violation records. Issuing notices always requires management role.'],
            'read_employees'      => ['label' => 'View employee roster',   'desc' => 'Who can see the employee list (pay details remain board-admin only regardless).'],
            'read_insurance'      => ['label' => 'View insurance records', 'desc' => 'Who can view the association\'s insurance policies.'],
        ],
    ],
    'directory' => [
        'label'    => 'Directory',
        'features' => [
            'read_full_directory' => ['label' => 'Full resident directory','desc' => 'Who can see all resident contact details. Board section is always visible to everyone.'],
            'submit_arc'          => ['label' => 'Submit ARC requests',    'desc' => 'Who can file an architectural review request.'],
        ],
    ],
];

// --- Save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); die('Forbidden'); }

    $defaults  = permission_defaults();
    $allKeys   = [];
    foreach ($FEATURES as $section) {
        foreach ($section['features'] as $key => $_) $allKeys[] = $key;
    }

    db()->beginTransaction();
    try {
        foreach ($allKeys as $key) {
            $posted = $_POST['perm'][$key] ?? '';
            if (!array_key_exists($posted, $ROLES)) $posted = $defaults[$key] ?? 'board_member';

            if ($posted === ($defaults[$key] ?? 'board_member')) {
                // If it matches the default, delete any override so we fall back to the hardcoded default.
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
        $flashError = 'Save failed: ' . $e->getMessage();
    }
    redirect('/dashboard/permissions.php');
}

// --- Load current overrides ---
$overrides = [];
$stmt = db()->prepare('SELECT permission_key, min_role FROM association_permissions WHERE association_id = ?');
$stmt->execute([$assocId]);
foreach ($stmt->fetchAll() as $r) $overrides[$r['permission_key']] = $r['min_role'];

$defaults = permission_defaults();

$active     = 'permissions';
$page_title = 'Permissions — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <div class="row row--between" style="margin-bottom: var(--sp-2); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Permissions</h1>
            <p class="muted">Control which role can access each feature. Management-write actions (creating, editing, deleting) are always restricted to board roles and are not configurable here.</p>
        </div>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if (!$canEdit): ?>
    <div class="flash flash--info">You can view permission settings but only board admins can change them.</div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="save">

    <?php foreach ($FEATURES as $section): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-5);">
        <h3 class="card__title" style="margin-bottom: var(--sp-4);"><?= e($section['label']) ?></h3>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 30%;">Feature</th>
                    <?php foreach ($ROLES as $rval => $rlabel): ?>
                        <th style="text-align:center; font-size: var(--fs-xs); white-space: nowrap;"><?= e($rlabel) ?></th>
                    <?php endforeach; ?>
                    <th style="text-align:center;">Current</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($section['features'] as $key => $feat):
                $current  = $overrides[$key] ?? $defaults[$key] ?? 'board_member';
                $isCustom = isset($overrides[$key]);
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
                    <?php foreach ($ROLES as $rval => $rlabel):
                        $rank = ROLE_RANK[$rval] ?? 0;
                        $isMin = $rval === $current;
                        $hasAccess = $rank >= $currentRank;
                    ?>
                    <td style="text-align:center; vertical-align:middle;">
                        <?php if ($canEdit): ?>
                            <label style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:100%;">
                                <input type="radio" name="perm[<?= e($key) ?>]" value="<?= e($rval) ?>"
                                       <?= $isMin ? 'checked' : '' ?>>
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
                        } ?>"><?= e($ROLES[$current] ?? $current) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($canEdit): ?>
    <div class="card card--padded" style="background: var(--color-surface); border: 2px solid var(--color-border);">
        <h4 style="margin: 0 0 var(--sp-2);">Always board-only (not configurable)</h4>
        <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-3);">These actions are hardcoded to require management role or higher. Changing them would expose legally sensitive association data.</p>
        <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
            <?php foreach ([
                'Financial/pay details', 'Create/edit/delete work orders', 'Issue violation notices',
                'Manage employees', 'Edit insurance records', 'Change permissions',
                'Board meeting management', 'Announcement management',
            ] as $item): ?>
                <span class="badge" style="background: #f1f5f9; color: #475569;">🔒 <?= e($item) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="row" style="justify-content: flex-end; margin-top: var(--sp-5);">
        <button class="btn btn--primary" type="submit">Save permissions</button>
    </div>
    <?php endif; ?>

    </form>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

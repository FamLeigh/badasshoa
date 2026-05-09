<?php
require __DIR__ . '/_bootstrap.php';

$assocCount = (int)db()->query('SELECT COUNT(*) FROM associations')->fetchColumn();
$userCount  = (int)db()->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
$signupPending = (int)db()->query('SELECT COUNT(*) FROM signups WHERE status = "pending"')->fetchColumn();
$auditCount = (int)db()->query('SELECT COUNT(*) FROM audit_log WHERE created_at > (NOW() - INTERVAL 24 HOUR)')->fetchColumn();

$recentSignups = db()->query(
    'SELECT * FROM signups WHERE status = "pending" ORDER BY created_at DESC LIMIT 10'
)->fetchAll();

$recentAudit = db()->query(
    'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS actor, asn.name AS assoc_name
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.actor_user_id
     LEFT JOIN associations asn ON asn.id = a.association_id
     ORDER BY a.created_at DESC LIMIT 20'
)->fetchAll();

$page_title = 'Admin overview — BadassHOA';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0;">Super admin</h1>
    <p class="muted">Cross-tenant overview. Be careful — what you do here affects every association.</p>

    <div class="grid grid--4" style="margin-top: var(--sp-6); margin-bottom: var(--sp-8);">
        <div class="stat"><div class="stat__label">Associations</div><div class="stat__value"><?= $assocCount ?></div></div>
        <div class="stat"><div class="stat__label">Active users</div><div class="stat__value"><?= $userCount ?></div></div>
        <div class="stat"><div class="stat__label">Pending signups</div><div class="stat__value"><?= $signupPending ?></div></div>
        <div class="stat"><div class="stat__label">Audit events / 24h</div><div class="stat__value"><?= $auditCount ?></div></div>
    </div>

    <div class="grid grid--2" style="align-items:start;">

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Pending signups</h2>
                <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php#signups">Manage →</a>
            </div>
            <?php if (!$recentSignups): ?>
                <p class="muted">No pending signups.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="table">
                <thead><tr><th>Association</th><th>Contact</th><th>Units</th><th>Plan</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($recentSignups as $s): ?>
                    <tr>
                        <td><strong><?= e((string)$s['association_name']) ?></strong></td>
                        <td>
                            <?= e((string)$s['contact_name']) ?>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$s['contact_email']) ?></div>
                        </td>
                        <td><?= (int)$s['unit_count'] ?></td>
                        <td><?= e((string)$s['plan_selected']) ?></td>
                        <td><?= e(date('M j', strtotime((string)$s['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Recent audit events</h2>
            </div>
            <?php if (!$recentAudit): ?>
                <p class="muted">No audit events yet.</p>
            <?php else: ?>
            <div class="stack">
                <?php foreach ($recentAudit as $a): ?>
                <div style="font-size: var(--fs-sm); padding-bottom: var(--sp-2); border-bottom: 1px solid var(--color-border);">
                    <span class="badge badge--navy"><?= e((string)$a['action']) ?></span>
                    <span class="muted" style="font-size: var(--fs-xs);">
                        <?= e(date('M j H:i', strtotime((string)$a['created_at']))) ?>
                        <?php if ($a['actor']): ?> &middot; <?= e(trim((string)$a['actor'])) ?><?php endif; ?>
                        <?php if ($a['assoc_name']): ?> &middot; <?= e((string)$a['assoc_name']) ?><?php endif; ?>
                        <?php if ($a['target_type']): ?> &middot; <?= e((string)$a['target_type']) ?>#<?= (int)$a['target_id'] ?><?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

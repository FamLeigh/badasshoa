<?php
require __DIR__ . '/_bootstrap.php';

$assocCount = (int)db()->query('SELECT COUNT(*) FROM associations')->fetchColumn();
$userCount  = (int)db()->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
$signupPending = (int)db()->query('SELECT COUNT(*) FROM signups WHERE status = "pending"')->fetchColumn();
$auditCount = (int)db()->query('SELECT COUNT(*) FROM audit_log WHERE created_at > (NOW() - INTERVAL 24 HOUR)')->fetchColumn();

$recentSignups = db()->query(
    'SELECT * FROM signups WHERE status = "pending" ORDER BY created_at DESC LIMIT 10'
)->fetchAll();

$page_title = 'Admin overview — BadassHOA';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0;">Super admin</h1>
    <p class="muted">Cross-tenant overview. Be careful — what you do here affects every association.</p>

    <div class="grid grid--4" style="margin-top: var(--sp-6); margin-bottom: var(--sp-8);">
        <a class="stat" href="/admin/associations.php">
            <div class="stat__label">Associations</div>
            <div class="stat__value"><?= $assocCount ?></div>
        </a>
        <a class="stat" href="/admin/users.php">
            <div class="stat__label">Active users</div>
            <div class="stat__value"><?= $userCount ?></div>
        </a>
        <a class="stat" href="/admin/associations.php#signups">
            <div class="stat__label">Pending signups</div>
            <div class="stat__value"><?= $signupPending ?></div>
        </a>
        <a class="stat" href="/admin/activity.php">
            <div class="stat__label">Audit events / 24h</div>
            <div class="stat__value"><?= $auditCount ?></div>
        </a>
    </div>

    <div class="card card--padded">
        <div class="card__head">
            <h2 class="card__title">Pending signups</h2>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php#signups">Manage all &rarr;</a>
        </div>
        <?php if (!$recentSignups): ?>
            <p class="muted">No pending signups. New applications from <a href="/signup.php">/signup.php</a> land here.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Association</th><th>Location</th><th>Contact</th><th>Units</th><th>Plan</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentSignups as $s):
                $loc = trim(
                    ($s['city'] ?? '')
                    . (($s['city'] && $s['state_region']) ? ', ' : '')
                    . ($s['state_region'] ?? '')
                );
            ?>
                <tr>
                    <td><strong><?= e((string)$s['association_name']) ?></strong></td>
                    <td><?= $loc !== '' ? e($loc) : '<span class="muted">—</span>' ?></td>
                    <td>
                        <?= e((string)$s['contact_name']) ?>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$s['contact_email']) ?></div>
                    </td>
                    <td><?= (int)$s['unit_count'] ?></td>
                    <td><?= e((string)$s['plan_selected']) ?></td>
                    <td><?= e(date('M j', strtotime((string)$s['created_at']))) ?></td>
                    <td style="text-align:right;">
                        <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/admin/associations.php?action=view_signup&id=<?= (int)$s['id'] ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

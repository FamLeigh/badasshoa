<?php
require __DIR__ . '/_bootstrap.php';
require_management(); // PM, board_member, board_admin (super_admin via /admin/activity.php instead)

// --- Filters ---
$qAction = trim((string)($_GET['action'] ?? ''));
$qActor  = trim((string)($_GET['actor'] ?? ''));
$qSince  = trim((string)($_GET['since'] ?? '')); // YYYY-MM-DD

// Tenant scoping is non-negotiable: this page only ever shows this association's events.
$where  = ['a.association_id = ?'];
$params = [$assocId];

if ($qAction !== '') { $where[] = 'a.action LIKE ?'; $params[] = "%$qAction%"; }
if ($qActor !== '') {
    $where[] = '(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = "%$qActor%";
    array_push($params, $like, $like, $like);
}
if ($qSince !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qSince)) {
    $where[] = 'a.created_at >= ?';
    $params[] = $qSince . ' 00:00:00';
}
$whereSql = implode(' AND ', $where);

// --- Pagination ---
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM audit_log a
     LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT a.id, a.action, a.target_type, a.target_id, a.metadata, a.created_at,
               TRIM(CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,''))) AS actor_name,
               u.email AS actor_email
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.actor_user_id
        WHERE $whereSql
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- Helper: build query string with overrides for pagination links ---
function tenant_activity_link(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return '/dashboard/activity.php' . ($params ? '?' . http_build_query($params) : '');
}

// Member activity stats (board_admin / super_admin only)
$activityStats = $noEmailMembers = $neverLoggedIn = $recentlyActive = null;
if (viewing_role() === 'board_admin' || viewing_role() === 'super_admin') {
    $aStmt = db()->prepare(
        "SELECT
             COUNT(*) AS total_active,
             SUM(CASE WHEN last_login_at IS NOT NULL THEN 1 ELSE 0 END) AS ever_logged_in,
             SUM(CASE WHEN last_login_at IS NULL THEN 1 ELSE 0 END) AS never_logged_in,
             SUM(CASE WHEN email LIKE '%@placeholder.local' THEN 1 ELSE 0 END) AS no_email
           FROM users
          WHERE association_id = ? AND status = 'active' AND role NOT IN ('super_admin')"
    );
    $aStmt->execute([$assocId]);
    $activityStats = $aStmt->fetch();

    $noEmailStmt = db()->prepare(
        "SELECT id, first_name, last_name, unit_number, role
           FROM users
          WHERE association_id = ? AND status = 'active'
            AND email LIKE '%@placeholder.local'
          ORDER BY last_name, first_name LIMIT 50"
    );
    $noEmailStmt->execute([$assocId]);
    $noEmailMembers = $noEmailStmt->fetchAll();

    $neverStmt = db()->prepare(
        "SELECT id, first_name, last_name, email, unit_number, role, created_at
           FROM users
          WHERE association_id = ? AND status = 'active'
            AND last_login_at IS NULL
            AND email NOT LIKE '%@placeholder.local'
          ORDER BY created_at DESC LIMIT 50"
    );
    $neverStmt->execute([$assocId]);
    $neverLoggedIn = $neverStmt->fetchAll();

    $recentStmt = db()->prepare(
        "SELECT id, first_name, last_name, email, unit_number, role, last_login_at
           FROM users
          WHERE association_id = ? AND status = 'active'
            AND last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          ORDER BY last_login_at DESC LIMIT 50"
    );
    $recentStmt->execute([$assocId]);
    $recentlyActive = $recentStmt->fetchAll();
}

$active = 'activity';
$page_title = 'Activity — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-2);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Activity</h1>
            <p class="muted">Who did what in <?= e((string)$association['name']) ?>. <?= number_format($total) ?> total<?= $whereSql !== 'a.association_id = ?' ? ' matching your filters' : '' ?>.</p>
        </div>
    </div>

    <?php if ($activityStats): ?>
    <div class="card card--padded" style="margin: var(--sp-5) 0; background: var(--color-surface-2);">
        <div class="row row--between" style="align-items: center; margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
            <h3 style="margin: 0; font-size: var(--fs-lg);">Member activity snapshot</h3>
            <div class="row" style="gap: var(--sp-4); font-size: var(--fs-sm); flex-wrap: wrap;">
                <span class="muted"><?= (int)$activityStats['total_active'] ?> active members</span>
                <span style="color: var(--color-success);"><?= (int)$activityStats['ever_logged_in'] ?> have logged in</span>
                <?php if ($activityStats['never_logged_in'] > 0): ?>
                    <span style="color: var(--color-warning);"><?= (int)$activityStats['never_logged_in'] ?> never logged in</span>
                <?php endif; ?>
                <?php if ($activityStats['no_email'] > 0): ?>
                    <span style="color: var(--color-error);"><?= (int)$activityStats['no_email'] ?> no email on file</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($noEmailMembers): ?>
        <details style="margin-bottom: var(--sp-3);">
            <summary style="cursor: pointer; font-weight: 600; font-size: var(--fs-sm); color: var(--color-error); margin-bottom: var(--sp-2);">
                ⚠ <?= count($noEmailMembers) ?> member<?= count($noEmailMembers) === 1 ? '' : 's' ?> with no email — cannot log in
            </summary>
            <div style="margin-top: var(--sp-2); overflow-x: auto;">
            <table class="table" style="font-size: var(--fs-sm);">
                <thead><tr><th>Name</th><th>Unit</th><th>Role</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($noEmailMembers as $m): ?>
                    <tr>
                        <td><?= e(trim($m['first_name'] . ' ' . $m['last_name']) ?: '—') ?></td>
                        <td><?= e($m['unit_number'] ?: '—') ?></td>
                        <td><?= e(str_replace('_', ' ', (string)$m['role'])) ?></td>
                        <td style="text-align:right;"><a class="btn btn--ghost" style="padding: 0.3rem 0.6rem; font-size: var(--fs-xs);" href="/dashboard/directory.php?action=edit&id=<?= (int)$m['id'] ?>">Add email</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($neverLoggedIn): ?>
        <details style="margin-bottom: var(--sp-3);">
            <summary style="cursor: pointer; font-weight: 600; font-size: var(--fs-sm); color: var(--color-warning); margin-bottom: var(--sp-2);">
                👋 <?= count($neverLoggedIn) ?> member<?= count($neverLoggedIn) === 1 ? '' : 's' ?> have never logged in
            </summary>
            <div style="margin-top: var(--sp-2); overflow-x: auto;">
            <table class="table" style="font-size: var(--fs-sm);">
                <thead><tr><th>Name</th><th>Unit</th><th>Email</th><th>Added</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($neverLoggedIn as $m): ?>
                    <tr>
                        <td><?= e(trim($m['first_name'] . ' ' . $m['last_name']) ?: '—') ?></td>
                        <td><?= e($m['unit_number'] ?: '—') ?></td>
                        <td><?= e((string)$m['email']) ?></td>
                        <td class="muted"><?= e(date('M j, Y', strtotime((string)$m['created_at']))) ?></td>
                        <td style="text-align:right;"><a class="btn btn--ghost" style="padding: 0.3rem 0.6rem; font-size: var(--fs-xs);" href="/dashboard/directory.php?action=edit&id=<?= (int)$m['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($recentlyActive): ?>
        <details>
            <summary style="cursor: pointer; font-weight: 600; font-size: var(--fs-sm); color: var(--color-success); margin-bottom: var(--sp-2);">
                ✓ <?= count($recentlyActive) ?> active in the last 30 days
            </summary>
            <div style="margin-top: var(--sp-2); overflow-x: auto;">
            <table class="table" style="font-size: var(--fs-sm);">
                <thead><tr><th>Name</th><th>Unit</th><th>Role</th><th>Last login</th></tr></thead>
                <tbody>
                <?php foreach ($recentlyActive as $m): ?>
                    <tr>
                        <td><?= e(trim($m['first_name'] . ' ' . $m['last_name']) ?: '—') ?></td>
                        <td><?= e($m['unit_number'] ?: '—') ?></td>
                        <td><?= e(str_replace('_', ' ', (string)$m['role'])) ?></td>
                        <td class="muted"><?= e(date('M j, Y g:i a', strtotime((string)$m['last_login_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="get" action="/dashboard/activity.php" class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="form-row" style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
            <div class="field">
                <label class="field__label" for="f-action">Action contains</label>
                <input class="input" id="f-action" name="action" value="<?= e($qAction) ?>" placeholder="e.g. document, deleted, login">
            </div>
            <div class="field">
                <label class="field__label" for="f-actor">Actor</label>
                <input class="input" id="f-actor" name="actor" value="<?= e($qActor) ?>" placeholder="name or email">
            </div>
            <div class="field">
                <label class="field__label" for="f-since">Since</label>
                <input class="input" type="date" id="f-since" name="since" value="<?= e($qSince) ?>">
            </div>
        </div>
        <div class="row" style="justify-content: flex-end; margin-top: var(--sp-3);">
            <?php if ($qAction !== '' || $qActor !== '' || $qSince !== ''): ?>
                <a class="btn btn--ghost" href="/dashboard/activity.php">Clear</a>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit">Filter</button>
        </div>
    </form>

    <?php if (!$rows): ?>
        <div class="card card--padded center"><p class="muted">No activity yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 150px;">When</th>
                <th>Action</th>
                <th>Actor</th>
                <th>Target</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $meta = $r['metadata'] ? json_decode((string)$r['metadata'], true) : null;
            $impersonatedBy = is_array($meta) ? ($meta['impersonated_by'] ?? null) : null;
            $metaPretty = '';
            if (is_array($meta)) {
                $bits = [];
                foreach ($meta as $k => $v) {
                    if ($k === 'impersonated_by') continue; // shown separately
                    if (is_scalar($v) || $v === null) {
                        $bits[] = e((string)$k) . ': ' . e((string)$v);
                    }
                }
                $metaPretty = implode(' &middot; ', $bits);
            }
        ?>
            <tr>
                <td>
                    <?= e(date('M j, Y', strtotime((string)$r['created_at']))) ?>
                    <div class="muted" style="font-size: var(--fs-xs);"><?= e(date('H:i:s', strtotime((string)$r['created_at']))) ?> UTC</div>
                </td>
                <td><span class="badge badge--navy"><?= e((string)$r['action']) ?></span></td>
                <td>
                    <?php if ($r['actor_email']): ?>
                        <strong><?= e(trim((string)$r['actor_name']) ?: (string)$r['actor_email']) ?></strong>
                        <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$r['actor_email']) ?></div>
                        <?php if ($impersonatedBy): ?>
                            <div class="muted" style="font-size: var(--fs-xs); color: var(--color-orange);" title="A BadassHOA admin was helping by signing in as this user">
                                via BadassHOA support
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">system</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['target_type']): ?>
                        <code style="font-size: var(--fs-xs);"><?= e((string)$r['target_type']) ?>#<?= (int)$r['target_id'] ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="muted" style="font-size: var(--fs-xs);"><?= $metaPretty ?: '—' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <div class="row row--between" style="margin-top: var(--sp-6);">
        <div class="muted" style="font-size: var(--fs-sm);">
            Showing <?= number_format($offset + 1) ?>&ndash;<?= number_format(min($offset + $perPage, $total)) ?> of <?= number_format($total) ?>
        </div>
        <div class="row" style="gap: var(--sp-2);">
            <?php if ($page > 1): ?>
                <a class="btn btn--ghost" href="<?= e(tenant_activity_link(['page' => $page - 1])) ?>">&larr; Prev</a>
            <?php else: ?>
                <span class="btn btn--ghost" aria-disabled="true" style="opacity:0.4; pointer-events:none;">&larr; Prev</span>
            <?php endif; ?>
            <span class="muted" style="align-self: center; font-size: var(--fs-sm); padding: 0 var(--sp-3);">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn--ghost" href="<?= e(tenant_activity_link(['page' => $page + 1])) ?>">Next &rarr;</a>
            <?php else: ?>
                <span class="btn btn--ghost" aria-disabled="true" style="opacity:0.4; pointer-events:none;">Next &rarr;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

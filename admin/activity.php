<?php
require __DIR__ . '/_bootstrap.php';

// --- Filters ---
$qAssoc  = (int)($_GET['association_id'] ?? 0);
$qAction = trim((string)($_GET['action'] ?? ''));
$qActor  = trim((string)($_GET['actor'] ?? ''));
$qSince  = trim((string)($_GET['since'] ?? '')); // YYYY-MM-DD

$where  = ['1=1'];
$params = [];
if ($qAssoc)            { $where[] = 'a.association_id = ?'; $params[] = $qAssoc; }
if ($qAction !== '')    { $where[] = 'a.action LIKE ?';      $params[] = "%$qAction%"; }
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
$offset  = ($page - 1) * $perPage;

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

$sql = "SELECT a.id, a.action, a.target_type, a.target_id, a.ip_address, a.metadata, a.created_at,
               TRIM(CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,''))) AS actor_name,
               u.email AS actor_email,
               asn.name AS assoc_name, asn.id AS assoc_id
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.actor_user_id
        LEFT JOIN associations asn ON asn.id = a.association_id
        WHERE $whereSql
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $perPage OFFSET $offset"; // perPage and offset are ints; whereSql uses placeholders
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$assocs = db()->query('SELECT id, name FROM associations ORDER BY name')->fetchAll();

// --- Helper: build query string with overrides for pagination links ---
function activity_link(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return '/admin/activity.php' . ($params ? '?' . http_build_query($params) : '');
}

$page_title = 'Activity log — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-2);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Activity log</h1>
            <p class="muted">Every audit event across all associations. <?= number_format($total) ?> total<?= $whereSql !== '1=1' ? ' matching your filters' : '' ?>.</p>
        </div>
        <a class="btn btn--ghost" href="/admin/">&larr; Back to overview</a>
    </div>

    <form method="get" action="/admin/activity.php" class="card card--padded" style="margin: var(--sp-6) 0;">
        <div class="form-row form-row--2" style="grid-template-columns: 1.4fr 1fr 1fr 0.8fr; gap: var(--sp-3);">
            <div class="field">
                <label class="field__label" for="f-assoc">Association</label>
                <select class="select" id="f-assoc" name="association_id">
                    <option value="0">All associations</option>
                    <?php foreach ($assocs as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $qAssoc === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= e((string)$a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="f-action">Action contains</label>
                <input class="input" id="f-action" name="action" value="<?= e($qAction) ?>" placeholder="e.g. login, document, deleted">
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
            <?php if ($qAssoc || $qAction !== '' || $qActor !== '' || $qSince !== ''): ?>
                <a class="btn btn--ghost" href="/admin/activity.php">Clear</a>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit">Filter</button>
        </div>
    </form>

    <?php if (!$rows): ?>
        <div class="card card--padded center"><p class="muted">No events match your filters.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 150px;">When</th>
                <th>Action</th>
                <th>Actor</th>
                <th>Association</th>
                <th>Target</th>
                <th>IP</th>
                <th>Metadata</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $meta = $r['metadata'] ? json_decode((string)$r['metadata'], true) : null;
            $metaPretty = '';
            if (is_array($meta)) {
                $bits = [];
                foreach ($meta as $k => $v) {
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
                    <?php else: ?>
                        <span class="muted">system</span>
                    <?php endif; ?>
                </td>
                <td><?= $r['assoc_name'] ? e((string)$r['assoc_name']) : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($r['target_type']): ?>
                        <code style="font-size: var(--fs-xs);"><?= e((string)$r['target_type']) ?>#<?= (int)$r['target_id'] ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="muted" style="font-size: var(--fs-xs);"><?= e((string)($r['ip_address'] ?? '')) ?: '—' ?></span></td>
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
                <a class="btn btn--ghost" href="<?= e(activity_link(['page' => $page - 1])) ?>">&larr; Prev</a>
            <?php else: ?>
                <span class="btn btn--ghost" aria-disabled="true" style="opacity:0.4; pointer-events:none;">&larr; Prev</span>
            <?php endif; ?>
            <span class="muted" style="align-self: center; font-size: var(--fs-sm); padding: 0 var(--sp-3);">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn--ghost" href="<?= e(activity_link(['page' => $page + 1])) ?>">Next &rarr;</a>
            <?php else: ?>
                <span class="btn btn--ghost" aria-disabled="true" style="opacity:0.4; pointer-events:none;">Next &rarr;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

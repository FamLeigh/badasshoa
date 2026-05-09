<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'set_status') {
    csrf_check();
    $uid = (int)($_POST['id'] ?? 0);
    $st  = $_POST['status'] ?? 'active';
    if (in_array($st, ['active','inactive','pending'], true)) {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$st, $uid]);
        audit('user.status_changed_admin', ['status' => $st], $uid, 'user');
        flash('success', "User #$uid → $st");
    }
    redirect('/admin/users.php');
}

$qSearch = trim((string)($_GET['q'] ?? ''));
$qAssoc  = (int)($_GET['association_id'] ?? 0);

$sql = 'SELECT u.*, a.name AS assoc_name
        FROM users u LEFT JOIN associations a ON a.id = u.association_id
        WHERE 1';
$params = [];
if ($qSearch !== '') {
    $sql .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = "%$qSearch%";
    array_push($params, $like, $like, $like);
}
if ($qAssoc) { $sql .= ' AND u.association_id = ?'; $params[] = $qAssoc; }
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$assocs = db()->query('SELECT id, name FROM associations ORDER BY name')->fetchAll();

$page_title = 'Users — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">
    <h1 style="font-size: var(--fs-3xl); margin: 0;">Users</h1>
    <p class="muted">Across every association.</p>

    <form method="get" class="row" style="margin: var(--sp-6) 0 var(--sp-4);">
        <input class="input" type="search" name="q" placeholder="Search name or email" value="<?= e($qSearch) ?>" style="max-width: 320px;">
        <select class="select" name="association_id" style="max-width: 280px;">
            <option value="0">All associations</option>
            <?php foreach ($assocs as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $qAssoc===(int)$a['id']?'selected':'' ?>><?= e((string)$a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
    </form>

    <?php if (!$users): ?>
        <p class="muted">No users match.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Association</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e(trim($u['first_name'] . ' ' . $u['last_name']) ?: '—') ?></strong></td>
                <td><?= e((string)$u['email']) ?></td>
                <td><?= e((string)($u['assoc_name'] ?? '—')) ?></td>
                <td><?= e(str_replace('_',' ',(string)$u['role'])) ?></td>
                <td>
                    <?php $cls = $u['status']==='active' ? 'badge--success' : ($u['status']==='pending' ? 'badge--warning' : 'badge--error'); ?>
                    <span class="badge <?= $cls ?>"><?= e((string)$u['status']) ?></span>
                </td>
                <td><?= $u['last_login_at'] ? e(date('M j', strtotime((string)$u['last_login_at']))) : '<span class="muted">never</span>' ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <select name="status" class="select" onchange="this.form.submit()" style="padding: 0.4rem 0.5rem; font-size: var(--fs-xs);">
                            <option value="active"   <?= $u['status']==='active'?'selected':'' ?>>active</option>
                            <option value="pending"  <?= $u['status']==='pending'?'selected':'' ?>>pending</option>
                            <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>>inactive</option>
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

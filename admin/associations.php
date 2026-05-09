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
                'INSERT INTO associations (name, subdomain, unit_count, plan, status)
                 VALUES (?, ?, ?, ?, "trial")'
            )->execute([$s['association_name'], $sub, (int)$s['unit_count'], $s['plan_selected'] ?: 'starter']);
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
    if (in_array($st, ['active','inactive','trial'], true)) {
        db()->prepare('UPDATE associations SET status = ? WHERE id = ?')->execute([$st, $aid]);
        audit('association.status_changed', ['status' => $st], $aid, 'association');
        flash('success', "Set association #$aid status to $st.");
    }
    redirect('/admin/associations.php');
}

$signups = db()->query('SELECT * FROM signups WHERE status = "pending" ORDER BY created_at DESC')->fetchAll();
$assocs  = db()->query(
    'SELECT a.*, (SELECT COUNT(*) FROM users WHERE association_id = a.id) AS user_count
     FROM associations a ORDER BY a.created_at DESC'
)->fetchAll();

$page_title = 'Associations — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

    <h1 style="font-size: var(--fs-3xl); margin: 0;">Associations</h1>
    <p class="muted">Approve signups and manage tenant status.</p>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

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
                <td><strong><?= e((string)$s['association_name']) ?></strong></td>
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
                <td style="text-align:right;">
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
        <thead><tr><th>Name</th><th>Slug</th><th>Units</th><th>Users</th><th>Plan</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assocs as $a): ?>
            <tr>
                <td><strong><?= e((string)$a['name']) ?></strong></td>
                <td><code><?= e((string)$a['subdomain']) ?></code></td>
                <td><?= (int)$a['unit_count'] ?></td>
                <td><?= (int)$a['user_count'] ?></td>
                <td><?= e((string)$a['plan']) ?></td>
                <td>
                    <?php
                    $cls = $a['status']==='active' ? 'badge--success' : ($a['status']==='trial' ? 'badge--warning' : 'badge--error');
                    ?>
                    <span class="badge <?= $cls ?>"><?= e((string)$a['status']) ?></span>
                </td>
                <td><?= e(date('M j, Y', strtotime((string)$a['created_at']))) ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="set_status">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <select name="status" class="select" onchange="this.form.submit()" style="padding: 0.4rem 0.5rem; font-size: var(--fs-xs);">
                            <option value="active"   <?= $a['status']==='active'?'selected':'' ?>>active</option>
                            <option value="trial"    <?= $a['status']==='trial'?'selected':'' ?>>trial</option>
                            <option value="inactive" <?= $a['status']==='inactive'?'selected':'' ?>>inactive</option>
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

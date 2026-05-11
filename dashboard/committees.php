<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$flashError = null;

// --- Create committee ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    if ($name === '') {
        $flashError = 'Committee name is required.';
    } else {
        $stmt = db()->prepare('INSERT INTO committees (association_id, name, description) VALUES (?, ?, ?)');
        $stmt->execute([$assocId, $name, $desc ?: null]);
        $newId = (int)db()->lastInsertId();
        audit('committee.created', ['name' => $name], $newId, 'committee');
        flash('success', "Committee &ldquo;$name&rdquo; created.");
        redirect('/dashboard/committees.php#c' . $newId);
    }
}

// --- Edit committee (name + description) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid  = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $check = db()->prepare('SELECT 1 FROM committees WHERE id = ? AND association_id = ?');
    $check->execute([$cid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Committee not found'); }
    if ($name === '') {
        $flashError = 'Committee name is required.';
    } else {
        db()->prepare('UPDATE committees SET name = ?, description = ? WHERE id = ? AND association_id = ?')
            ->execute([$name, $desc ?: null, $cid, $assocId]);
        audit('committee.edited', ['name' => $name], $cid, 'committee');
        flash('success', "Committee &ldquo;$name&rdquo; updated.");
        redirect('/dashboard/committees.php#c' . $cid);
    }
}

// --- Self-join a committee (any signed-in member) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'join') {
    csrf_check();
    $cid = (int)($_POST['id'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM committees WHERE id = ? AND association_id = ?');
    $check->execute([$cid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Committee not found'); }
    // Skip if already on the committee (UNIQUE on committee_id+user_id would
    // throw, but a friendly no-op is nicer).
    $exists = db()->prepare('SELECT 1 FROM committee_members WHERE committee_id = ? AND user_id = ?');
    $exists->execute([$cid, (int)$user['id']]);
    if ($exists->fetchColumn()) {
        flash('info', "You're already on that committee.");
    } else {
        db()->prepare('INSERT INTO committee_members (committee_id, user_id, role) VALUES (?, ?, "member")')
            ->execute([$cid, (int)$user['id']]);
        audit('committee.joined', [], $cid, 'committee');
        flash('success', 'Welcome — you joined the committee.');
    }
    redirect('/dashboard/committees.php#c' . $cid);
}

// --- Self-leave a committee ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'leave') {
    csrf_check();
    $cid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM committee_members WHERE committee_id = ? AND user_id = ?')
        ->execute([$cid, (int)$user['id']]);
    audit('committee.left', [], $cid, 'committee');
    flash('success', 'You left the committee.');
    redirect('/dashboard/committees.php#c' . $cid);
}

// --- Add member ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_member') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid  = (int)($_POST['committee_id'] ?? 0);
    $uid  = (int)($_POST['user_id'] ?? 0);
    $role = ($_POST['role'] ?? '') === 'chair' ? 'chair' : 'member';

    $check = db()->prepare('SELECT 1 FROM committees WHERE id = ? AND association_id = ?');
    $check->execute([$cid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Committee not found'); }

    $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
    $check->execute([$uid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('User not found'); }

    // Promoting to chair? Demote any existing chair to member first.
    if ($role === 'chair') {
        db()->prepare('UPDATE committee_members SET role = "member" WHERE committee_id = ? AND role = "chair"')
            ->execute([$cid]);
    }

    db()->prepare(
        'INSERT INTO committee_members (committee_id, user_id, role)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)'
    )->execute([$cid, $uid, $role]);

    audit('committee.member_added', ['user_id' => $uid, 'role' => $role], $cid, 'committee');
    flash('success', 'Member added.');
    redirect('/dashboard/committees.php#c' . $cid);
}

// --- Remove member ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'remove_member') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid = (int)($_POST['committee_id'] ?? 0);
    $uid = (int)($_POST['user_id'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM committees WHERE id = ? AND association_id = ?');
    $check->execute([$cid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Not found'); }
    db()->prepare('DELETE FROM committee_members WHERE committee_id = ? AND user_id = ?')
        ->execute([$cid, $uid]);
    audit('committee.member_removed', ['user_id' => $uid], $cid, 'committee');
    flash('success', 'Member removed.');
    redirect('/dashboard/committees.php#c' . $cid);
}

// --- Delete committee ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT name FROM committees WHERE id = ? AND association_id = ?');
    $stmt->execute([$cid, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('DELETE FROM committees WHERE id = ? AND association_id = ?')->execute([$cid, $assocId]);
        audit('committee.deleted', ['name' => $row['name']], $cid, 'committee');
        flash('success', "Committee &ldquo;{$row['name']}&rdquo; deleted.");
    }
    redirect('/dashboard/committees.php');
}

// --- Load all committees + members in two queries (no N+1) ---
$cStmt = db()->prepare('SELECT * FROM committees WHERE association_id = ? ORDER BY name');
$cStmt->execute([$assocId]);
$committees = $cStmt->fetchAll();

$members = [];
if ($committees) {
    $ids = array_map(fn($c) => (int)$c['id'], $committees);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT cm.committee_id, cm.role,
                u.id, u.first_name, u.last_name, u.email, u.unit_number
         FROM committee_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.committee_id IN ($placeholders)
         ORDER BY (cm.role = 'chair') DESC, u.last_name, u.first_name"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        $members[(int)$r['committee_id']][] = $r;
    }
}

// All active users for the "add member" dropdowns
$uStmt = db()->prepare(
    "SELECT id, first_name, last_name, email, unit_number FROM users
     WHERE association_id = ? AND status = 'active'
     ORDER BY CAST(unit_number AS UNSIGNED), unit_number, last_name, first_name"
);
$uStmt->execute([$assocId]);
$allUsers = $uStmt->fetchAll();

$showCreate = ($_GET['action'] ?? '') === 'new' && $canManage;

$editCommittee = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM committees WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editCommittee = $stmt->fetch() ?: null;
}
$showForm = $showCreate || $editCommittee;

$page_title = 'Committees — ' . $association['name'];
if ($showForm) {
    $page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
}
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Committees</h1>
            <p class="muted">Standing committees, chairs, and members.
                <?php if ($committees): ?><?= count($committees) ?> committee<?= count($committees)===1?'':'s' ?>.<?php endif; ?>
            </p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ New committee</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showForm):
        $isEdit = $editCommittee !== null;
        $cv = $editCommittee ?? ['name' => '', 'description' => '', 'id' => 0];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit committee' : 'New committee' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/committees.php">← Back</a>
        </div>
        <form method="post" class="form" data-committee-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'create' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$cv['id'] ?>"><?php endif; ?>
            <div class="field">
                <label class="field__label" for="cname">Name</label>
                <input class="input" id="cname" name="name" required value="<?= e((string)$cv['name']) ?>" placeholder="e.g. Rules Committee, Beautification Committee">
            </div>
            <div class="field">
                <label class="field__label">Description (optional)</label>
                <div id="committee-editor" data-initial-html="<?= e((string)($cv['description'] ?? '')) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                <textarea name="description" id="cdesc" hidden></textarea>
                <div class="field__hint">Use the toolbar to format. What the committee does, when it meets, who to contact.</div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/committees.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create committee' ?></button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
    (function () {
        if (typeof Quill === 'undefined') return;
        var editorEl = document.getElementById('committee-editor');
        if (!editorEl) return;
        var hidden  = document.getElementById('cdesc');
        var initial = editorEl.getAttribute('data-initial-html') || '';

        var quill = new Quill('#committee-editor', {
            theme: 'snow',
            placeholder: 'What does this committee do? When does it meet?',
            modules: {
                toolbar: [
                    [{ 'header': [3, false] }],
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['link'],
                    ['clean']
                ]
            }
        });
        editorEl.querySelector('.ql-editor').style.minHeight = '160px';
        if (initial) quill.clipboard.dangerouslyPasteHTML(0, initial);

        var form = document.querySelector('form[data-committee-form]');
        if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
    })();
    </script>
    <?php endif; ?>

    <?php if (!$committees): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No committees yet.</p>
            <?php if ($canManage): ?>
                <p style="margin-top: var(--sp-4);">
                    <a class="btn btn--primary" href="?action=new">Create your first committee</a>
                </p>
                <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-4);">
                    Common ones: Rules Committee, Beautification Committee, Architectural Review, Finance, Welcome.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="stack-lg">
    <?php foreach ($committees as $c):
        $cid       = (int)$c['id'];
        $cMembers  = $members[$cid] ?? [];
        $chair     = null;
        foreach ($cMembers as $m) { if ($m['role'] === 'chair') { $chair = $m; break; } }
        $memberIds = array_map(fn($m) => (int)$m['id'], $cMembers);
    ?>
    <div class="card card--padded" id="c<?= $cid ?>">
        <div class="card__head" style="align-items: flex-start;">
            <div>
                <h2 class="card__title"><?= e($c['name']) ?></h2>
                <div style="margin-top: var(--sp-1); font-size: var(--fs-sm);">
                    <?php if ($chair): ?>
                        <span class="muted">Chair:</span>
                        <strong><?= e(trim($chair['first_name'].' '.$chair['last_name']) ?: $chair['email']) ?></strong>
                        <?php if ($chair['unit_number']): ?>
                            <span class="muted">· Unit <?= e($chair['unit_number']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">No chair yet</span>
                    <?php endif; ?>
                    <span class="muted">·</span>
                    <span class="muted"><?= count($cMembers) ?> member<?= count($cMembers)===1?'':'s' ?></span>
                </div>
                <?php if ($c['description']): ?>
                    <div class="muted" style="font-size: var(--fs-sm); margin: var(--sp-3) 0 0; max-width: 60ch; line-height: var(--lh-loose);"><?= (string)$c['description'] /* HTML from Quill — board-trusted */ ?></div>
                <?php endif; ?>
            </div>
            <?php $isOnCommittee = in_array((int)$user['id'], $memberIds, true); ?>
            <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
                <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/committee-flyer.php?id=<?= $cid ?>" target="_blank" rel="noopener" title="Print or save as PDF a one-page flyer to promote this committee">🖨 Flyer</a>
                <?php if (!$isOnCommittee): ?>
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="join">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <button class="btn btn--primary" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Join</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Leave this committee?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="leave">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" title="You're a member — click to leave">✓ Joined</button>
                    </form>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= $cid ?>">Edit</a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete this committee? Members will be removed.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($cMembers): ?>
        <div style="overflow-x:auto; margin-top: var(--sp-4);">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Unit</th>
                    <th>Email</th>
                    <th>Role</th>
                    <?php if ($canManage): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cMembers as $m): ?>
                <tr>
                    <td><strong><?= e(trim($m['first_name'].' '.$m['last_name']) ?: '—') ?></strong></td>
                    <td><?= e($m['unit_number'] ?: '—') ?></td>
                    <td><?= e($m['email']) ?></td>
                    <td>
                        <?php if ($m['role'] === 'chair'): ?>
                            <span class="badge badge--orange">Chair</span>
                        <?php else: ?>
                            <span class="badge">Member</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($canManage): ?>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove from committee?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="remove_member">
                            <input type="hidden" name="committee_id" value="<?= $cid ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn--ghost" type="submit">Remove</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="muted" style="margin-top: var(--sp-4);">No members yet.</p>
        <?php endif; ?>

        <?php if ($canManage):
            $availableUsers = array_filter($allUsers, fn($u) => !in_array((int)$u['id'], $memberIds, true));
            if ($availableUsers):
        ?>
        <form method="post" class="row" style="margin-top: var(--sp-5); gap: var(--sp-2); flex-wrap: wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="add_member">
            <input type="hidden" name="committee_id" value="<?= $cid ?>">
            <select name="user_id" class="select" required style="flex: 1; min-width: 240px; max-width: 360px;">
                <option value="">Add a member…</option>
                <?php foreach ($availableUsers as $u):
                    $label = trim($u['first_name'].' '.$u['last_name']) ?: $u['email'];
                    if ($u['unit_number']) $label .= ' · Unit ' . $u['unit_number'];
                ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="role" class="select" style="max-width: 140px;">
                <option value="member">Member</option>
                <option value="chair">Chair</option>
            </select>
            <button class="btn btn--primary" type="submit">Add</button>
        </form>
            <?php elseif (count($cMembers) === count($allUsers) && $allUsers): ?>
                <p class="muted" style="margin-top: var(--sp-4); font-size: var(--fs-sm);">All active members are on this committee.</p>
            <?php endif;
        endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

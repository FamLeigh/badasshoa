<?php
// HOA employees / contractors / volunteers — manager-only roster.
// Orthogonal to users.role: a unit owner can also be a maintenance employee
// without changing their resident role. Multiple rows per user are allowed
// so this table doubles as employment history.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

$TYPES = [
    'employee'   => 'Employee',
    'contractor' => 'Contractor',
    'volunteer'  => 'Volunteer',
];
$PAY_TYPES = [
    'hourly' => 'Hourly',
    'salary' => 'Salary',
    'flat'   => 'Flat / per-job',
    'none'   => 'Unpaid',
];

// --- Add / edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['form'] ?? ''), ['add','edit'], true)) {
    csrf_check();
    $isEdit  = $_POST['form'] === 'edit';
    $empId   = (int)($_POST['id'] ?? 0);
    $uid     = (int)($_POST['user_id'] ?? 0);
    $title   = trim((string)($_POST['job_title'] ?? ''));
    $type    = $_POST['employment_type'] ?? 'employee';
    $payType = $_POST['pay_type'] ?? 'hourly';
    $hourly  = ($_POST['hourly_rate'] ?? '') !== '' ? (float)$_POST['hourly_rate'] : null;
    $salary  = ($_POST['salary']      ?? '') !== '' ? (float)$_POST['salary']      : null;
    $flat    = ($_POST['flat_amount'] ?? '') !== '' ? (float)$_POST['flat_amount'] : null;
    $start   = trim((string)($_POST['start_date'] ?? ''));
    $end     = trim((string)($_POST['end_date'] ?? ''));
    $status  = $_POST['status'] ?? 'active';
    $notes   = trim((string)($_POST['notes'] ?? ''));
    if (!array_key_exists($type, $TYPES))       $type    = 'employee';
    if (!array_key_exists($payType, $PAY_TYPES)) $payType = 'hourly';
    if (!in_array($status, ['active','inactive'], true)) $status = 'active';
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = '';
    if ($end   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = '';
    // Auto-flip to inactive when an end_date is set in the past.
    if ($end !== '' && strtotime($end) < strtotime(date('Y-m-d'))) $status = 'inactive';

    // Validate that the chosen user belongs to this association.
    $userOk = false;
    if ($uid > 0) {
        $check = db()->prepare('SELECT 1 FROM users WHERE id = ? AND association_id = ?');
        $check->execute([$uid, $assocId]);
        $userOk = (bool)$check->fetchColumn();
    }

    if (!$userOk)                $flashError = 'Pick a valid member of this association.';
    elseif ($title === '')       $flashError = 'Job title is required.';
    else {
        if ($isEdit) {
            $check = db()->prepare('SELECT 1 FROM employees WHERE id = ? AND association_id = ?');
            $check->execute([$empId, $assocId]);
            if (!$check->fetchColumn()) { http_response_code(404); die('Employee record not found'); }
            db()->prepare(
                'UPDATE employees
                    SET user_id = ?, job_title = ?, employment_type = ?, pay_type = ?,
                        hourly_rate = ?, salary = ?, flat_amount = ?,
                        start_date = ?, end_date = ?, status = ?, notes = ?
                  WHERE id = ? AND association_id = ?'
            )->execute([
                $uid, $title, $type, $payType,
                $hourly, $salary, $flat,
                $start ?: null, $end ?: null, $status, $notes ?: null,
                $empId, $assocId,
            ]);
            audit('employee.edited', ['user_id' => $uid, 'title' => $title], $empId, 'employee');
            flash('success', 'Employment record updated.');
        } else {
            db()->prepare(
                'INSERT INTO employees
                    (association_id, user_id, job_title, employment_type, pay_type,
                     hourly_rate, salary, flat_amount, start_date, end_date, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $assocId, $uid, $title, $type, $payType,
                $hourly, $salary, $flat,
                $start ?: null, $end ?: null, $status, $notes ?: null,
            ]);
            $newId = (int)db()->lastInsertId();
            audit('employee.added', ['user_id' => $uid, 'title' => $title, 'type' => $type], $newId, 'employee');
            flash('success', "Added \"$title\".");
        }
        redirect('/dashboard/employees.php');
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $empId = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM employees WHERE id = ? AND association_id = ?')->execute([$empId, $assocId]);
    audit('employee.deleted', [], $empId, 'employee');
    flash('success', 'Employment record deleted.');
    redirect('/dashboard/employees.php');
}

// --- List ---
$statusFilter = $_GET['status'] ?? 'active';
if (!in_array($statusFilter, ['active','inactive','all'], true)) $statusFilter = 'active';

$sql = "SELECT e.*,
               TRIM(CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,''))) AS member_name,
               u.email, u.unit_number, u.avatar_path, u.role
          FROM employees e
          JOIN users u ON u.id = e.user_id
         WHERE e.association_id = ?";
$params = [$assocId];
if ($statusFilter !== 'all') {
    $sql .= ' AND e.status = ?';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY (e.status = 'active') DESC, e.job_title, u.last_name";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Counts for filter tabs
$counts = ['active' => 0, 'inactive' => 0];
$c = db()->prepare('SELECT status, COUNT(*) AS n FROM employees WHERE association_id = ? GROUP BY status');
$c->execute([$assocId]);
foreach ($c->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];

// Members list for the picker
$members = db()->prepare(
    "SELECT id, first_name, last_name, unit_number
       FROM users
      WHERE association_id = ? AND status <> 'inactive'
      ORDER BY last_name, first_name"
);
$members->execute([$assocId]);
$members = $members->fetchAll();

$editEmp = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM employees WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editEmp = $stmt->fetch() ?: null;
}
$showAdd = ($_GET['action'] ?? '') === 'new';

$page_title = 'Employees — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Employees</h1>
            <p class="muted">Anyone the association pays or relies on — maintenance, front desk, contractors on retainer, volunteers. Owner status is tracked separately (an owner can also be an employee).</p>
        </div>
        <?php if (!$showAdd && !$editEmp): ?>
            <a class="btn btn--primary" href="?action=new">+ New employment record</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showAdd || $editEmp):
        $vals = $editEmp ?? [
            'id'=>0, 'user_id'=>0, 'job_title'=>'', 'employment_type'=>'employee', 'pay_type'=>'hourly',
            'hourly_rate'=>null, 'salary'=>null, 'flat_amount'=>null,
            'start_date'=>null, 'end_date'=>null, 'status'=>'active', 'notes'=>'',
        ];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $editEmp ? 'Edit employment record' : 'New employment record' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/employees.php">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $editEmp ? 'edit' : 'add' ?>">
            <?php if ($editEmp): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="em-user">Member</label>
                    <select class="select" id="em-user" name="user_id" required>
                        <option value="">— pick a member —</option>
                        <?php foreach ($members as $m):
                            $nm = trim($m['first_name'] . ' ' . $m['last_name']);
                            if ($nm === '') continue;
                        ?>
                            <option value="<?= (int)$m['id'] ?>" <?= (int)$vals['user_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($nm) ?><?= !empty($m['unit_number']) ? ' · ' . e((string)$m['unit_number']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">An owner can be picked here too — owner status stays where it is.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="em-title">Job title</label>
                    <input class="input" id="em-title" name="job_title" required maxlength="120" value="<?= e((string)$vals['job_title']) ?>" placeholder="Maintenance, Front desk, Bookkeeper, …">
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="em-type">Type</label>
                    <select class="select" id="em-type" name="employment_type">
                        <?php foreach ($TYPES as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $vals['employment_type'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="em-pay">Pay type</label>
                    <select class="select" id="em-pay" name="pay_type" data-pay-select>
                        <?php foreach ($PAY_TYPES as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $vals['pay_type'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row form-row--2" data-pay-hourly>
                <div class="field">
                    <label class="field__label" for="em-hr">Hourly rate ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="em-hr" name="hourly_rate" value="<?= e((string)($vals['hourly_rate'] ?? '')) ?>">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <div class="form-row form-row--2" data-pay-salary>
                <div class="field">
                    <label class="field__label" for="em-sal">Annual salary ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="em-sal" name="salary" value="<?= e((string)($vals['salary'] ?? '')) ?>">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <div class="form-row form-row--2" data-pay-flat>
                <div class="field">
                    <label class="field__label" for="em-flat">Flat amount per job ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="em-flat" name="flat_amount" value="<?= e((string)($vals['flat_amount'] ?? '')) ?>">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="em-start">Start date</label>
                    <input class="input" type="date" id="em-start" name="start_date" value="<?= e((string)($vals['start_date'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="em-end">End date</label>
                    <input class="input" type="date" id="em-end" name="end_date" value="<?= e((string)($vals['end_date'] ?? '')) ?>">
                    <div class="field__hint">Setting a past end date auto-flips status to Inactive.</div>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="em-status">Status</label>
                    <select class="select" id="em-status" name="status">
                        <option value="active"   <?= $vals['status']==='active'?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $vals['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <div class="field">
                <label class="field__label" for="em-notes">Notes</label>
                <textarea class="textarea" id="em-notes" name="notes" rows="3" placeholder="W-9 on file, weekly schedule, conflict-of-interest disclosure, etc."><?= e((string)($vals['notes'] ?? '')) ?></textarea>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/employees.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $editEmp ? 'Save changes' : 'Add employment record' ?></button>
            </div>
        </form>
        <script>
            (function () {
                var sel = document.querySelector('[data-pay-select]');
                if (!sel) return;
                function sync() {
                    document.querySelector('[data-pay-hourly]').style.display = sel.value === 'hourly' ? '' : 'none';
                    document.querySelector('[data-pay-salary]').style.display = sel.value === 'salary' ? '' : 'none';
                    document.querySelector('[data-pay-flat]').style.display   = sel.value === 'flat'   ? '' : 'none';
                }
                sel.addEventListener('change', sync); sync();
            })();
        </script>
    </div>
    <?php endif; ?>

    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
        <?php foreach (['active'=>'Active','inactive'=>'Inactive','all'=>'All'] as $val => $lbl):
            $count = $counts[$val] ?? ($val === 'all' ? array_sum($counts) : 0);
            $isActive = $statusFilter === $val;
        ?>
            <a class="badge <?= $isActive ? ($val === 'active' ? 'badge--success' : ($val === 'inactive' ? '' : 'badge--navy')) : '' ?>" href="?status=<?= e($val) ?>" style="text-decoration:none; <?= !$isActive ? 'opacity: 0.6;' : '' ?>">
                <?= e($lbl) ?><?php if ($val !== 'all'): ?> · <?= (int)$count ?><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No <?= e($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>employment records.</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Add the first one</a></p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Name</th><th>Job</th><th>Type</th><th>Pay</th><th>Dates</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $pay = match ($r['pay_type']) {
                'hourly' => $r['hourly_rate'] !== null ? '$' . number_format((float)$r['hourly_rate'], 2) . '/hr' : '',
                'salary' => $r['salary']      !== null ? '$' . number_format((float)$r['salary'], 0) . '/yr' : '',
                'flat'   => $r['flat_amount'] !== null ? '$' . number_format((float)$r['flat_amount'], 2) . '/job' : '',
                default  => 'Unpaid',
            };
        ?>
            <tr<?= $r['status'] === 'inactive' ? ' style="opacity:0.55;"' : '' ?>>
                <td>
                    <div class="row" style="gap: var(--sp-2); align-items:center;">
                        <?php if (!empty($r['avatar_path'])): ?>
                            <img src="/user-avatar.php?id=<?= (int)$r['user_id'] ?>" alt="" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex: 0 0 32px;">
                        <?php else: ?>
                            <span class="side-nav__avatar" style="width: 32px; height: 32px; flex: 0 0 32px; background: var(--color-text-soft); font-size: var(--fs-sm);"><?= e(strtoupper(mb_substr((string)$r['member_name'], 0, 1) ?: '?')) ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?= e($r['member_name']) ?></strong>
                            <?php if (!empty($r['unit_number'])): ?>
                                <div class="muted" style="font-size: var(--fs-xs);">Unit <?= e((string)$r['unit_number']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><strong><?= e((string)$r['job_title']) ?></strong></td>
                <td><span class="badge" style="font-size: var(--fs-xs);"><?= e($TYPES[$r['employment_type']] ?? $r['employment_type']) ?></span></td>
                <td><?= e($pay) ?: '<span class="muted">—</span>' ?></td>
                <td style="font-size: var(--fs-sm);">
                    <?php if (!empty($r['start_date'])): ?><?= e(date('M Y', strtotime((string)$r['start_date']))) ?><?php endif; ?>
                    <?php if (!empty($r['end_date'])): ?> – <?= e(date('M Y', strtotime((string)$r['end_date']))) ?><?php elseif (!empty($r['start_date'])): ?> – present<?php endif; ?>
                </td>
                <td><span class="badge <?= $r['status']==='active' ? 'badge--success' : '' ?>"><?= e($r['status']) ?></span></td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this employment record?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
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

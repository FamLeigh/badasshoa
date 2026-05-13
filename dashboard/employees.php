<?php
// HOA employees / contractors / volunteers — manager-only roster.
// Orthogonal to users.role: a unit owner can also be a maintenance employee
// without changing their resident role. Multiple rows per user are allowed
// so this table doubles as employment history.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

// Financial details (pay rates, salary) restricted to board_admin and super_admin.
// Board members and property managers see the roster but not compensation figures.
$canSeeFinancials = in_array((string)($_SESSION['role'] ?? ''), ['board_admin', 'super_admin'], true);

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
    if ($canSeeFinancials) {
        $payType = $_POST['pay_type'] ?? 'hourly';
        $hourly  = ($_POST['hourly_rate'] ?? '') !== '' ? (float)$_POST['hourly_rate'] : null;
        $salary  = ($_POST['salary']      ?? '') !== '' ? (float)$_POST['salary']      : null;
        $flat    = ($_POST['flat_amount'] ?? '') !== '' ? (float)$_POST['flat_amount'] : null;
    } else {
        // Preserve existing pay data on edit; default to unpaid on add.
        if ($isEdit && $empId > 0) {
            $pres = db()->prepare('SELECT pay_type, hourly_rate, salary, flat_amount FROM employees WHERE id = ? AND association_id = ?');
            $pres->execute([$empId, $assocId]);
            $prev = $pres->fetch() ?: [];
        }
        $payType = $isEdit ? ($prev['pay_type'] ?? 'none') : 'none';
        $hourly  = $isEdit ? ($prev['hourly_rate']  !== null ? (float)$prev['hourly_rate']  : null) : null;
        $salary  = $isEdit ? ($prev['salary']        !== null ? (float)$prev['salary']        : null) : null;
        $flat    = $isEdit ? ($prev['flat_amount']   !== null ? (float)$prev['flat_amount']   : null) : null;
    }
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

// --- Employee document upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload_doc') {
    csrf_check();
    if (!$canSeeFinancials) { http_response_code(403); die('Forbidden'); }
    $empId = (int)($_POST['employee_id'] ?? 0);
    $chk = db()->prepare('SELECT 1 FROM employees WHERE id = ? AND association_id = ?');
    $chk->execute([$empId, $assocId]);
    if (!$chk->fetchColumn()) { http_response_code(404); die('Not found'); }

    $docTitle = trim((string)($_POST['title'] ?? ''));
    if ($docTitle === '') $docTitle = (string)($_FILES['file']['name'] ?? 'Document');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed.');
    } elseif ($_FILES['file']['size'] > 25 * 1024 * 1024) {
        flash('error', 'Max file size is 25 MB.');
    } elseif (storage_over_quota_by($association, (int)$_FILES['file']['size'])) {
        flash('error', 'Storage quota exceeded.');
    } else {
        $allowed = [
            'pdf'=>'application/pdf','doc'=>'application/msword',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'=>'application/vnd.ms-excel',
            'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','txt'=>'text/plain',
        ];
        $origName = (string)($_FILES['file']['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        if (!isset($allowed[$ext])) {
            flash('error', 'File type not allowed.');
        } else {
            $relDir = "uploads/$assocId/employees";
            $absDir = storage_path($relDir);
            if (!is_dir($absDir)) mkdir($absDir, 0755, true);
            $relPath = "$relDir/" . bin2hex(random_bytes(12)) . ".$ext";
            $absPath = storage_path($relPath);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $absPath)) {
                flash('error', 'Could not save file.');
            } else {
                db()->prepare(
                    'INSERT INTO documents (association_id, employee_id, title, file_path, file_type, access_level, uploaded_by, category, version)
                     VALUES (?, ?, ?, ?, ?, "board_only", ?, "Employment", "1.0")'
                )->execute([$assocId, $empId, $docTitle, $relPath, $allowed[$ext], (int)$user['id']]);
                audit('employee.doc_uploaded', ['title' => $docTitle], $empId, 'employee');
                flash('success', "\"$docTitle\" attached.");
            }
        }
    }
    redirect("/dashboard/employees.php?action=edit&id=$empId");
}

// --- Employee document delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_doc') {
    csrf_check();
    if (!$canSeeFinancials) { http_response_code(403); die('Forbidden'); }
    $docId = (int)($_POST['doc_id'] ?? 0);
    $empId = (int)($_POST['employee_id'] ?? 0);
    $row = db()->prepare('SELECT file_path FROM documents WHERE id = ? AND association_id = ? AND employee_id = ?');
    $row->execute([$docId, $assocId, $empId]);
    if ($r = $row->fetch()) {
        $abs = storage_path((string)$r['file_path']);
        if ($abs && file_exists($abs)) @unlink($abs);
        db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);
        audit('employee.doc_deleted', ['doc_id' => $docId], $empId, 'employee');
        flash('success', 'Document removed.');
    }
    redirect("/dashboard/employees.php?action=edit&id=$empId");
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

$empDocs = [];
if ($editEmp) {
    $ds = db()->prepare('SELECT * FROM documents WHERE employee_id = ? AND association_id = ? ORDER BY created_at DESC');
    $ds->execute([(int)$editEmp['id'], $assocId]);
    $empDocs = $ds->fetchAll();
}

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
                <div class="field" style="position: relative;">
                    <label class="field__label" for="em-user-search">Member</label>
                    <?php
                    // Build typeahead source: id, full name, unit. JSON-encoded into a
                    // data attribute so the script has a single source of truth.
                    $tahead = [];
                    foreach ($members as $m) {
                        $nm = trim($m['first_name'] . ' ' . $m['last_name']);
                        if ($nm === '') continue;
                        $tahead[] = [
                            'id'   => (int)$m['id'],
                            'name' => $nm,
                            'unit' => (string)($m['unit_number'] ?? ''),
                        ];
                    }
                    $selectedLabel = '';
                    if ((int)$vals['user_id'] > 0) {
                        foreach ($tahead as $t) {
                            if ($t['id'] === (int)$vals['user_id']) {
                                $selectedLabel = $t['name'] . ($t['unit'] !== '' ? ' · Unit ' . $t['unit'] : '');
                                break;
                            }
                        }
                    }
                    ?>
                    <input class="input" type="text" id="em-user-search" autocomplete="off"
                           placeholder="Type a name or unit number…"
                           value="<?= e($selectedLabel) ?>"
                           data-typeahead='<?= e(json_encode($tahead, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                           aria-autocomplete="list" aria-controls="em-user-results">
                    <input type="hidden" id="em-user" name="user_id" value="<?= (int)$vals['user_id'] ?>" required>
                    <div id="em-user-results" class="typeahead-list" role="listbox" hidden></div>
                    <div class="field__hint">Start typing to filter. An owner can be picked too — owner status stays where it is.</div>
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
                <?php if ($canSeeFinancials): ?>
                <div class="field">
                    <label class="field__label" for="em-pay">Pay type</label>
                    <select class="select" id="em-pay" name="pay_type" data-pay-select>
                        <?php foreach ($PAY_TYPES as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $vals['pay_type'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="field"><div class="field__hint" style="padding-top: var(--sp-6); font-style: italic;">Pay details visible to board admins only.</div></div>
                <?php endif; ?>
            </div>

            <?php if ($canSeeFinancials): ?>
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
            <?php endif; ?>

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

    <?php if ($editEmp && $canSeeFinancials): ?>
    <!-- ===== EMPLOYEE DOCUMENTS ===== -->
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head" style="margin-bottom: var(--sp-3);">
            <h3 class="card__title">Documents</h3>
            <span class="muted" style="font-size: var(--fs-xs);">W-9s, contracts, background checks, certifications — visible to board admins only.</span>
        </div>

        <?php if ($empDocs): ?>
        <div class="stack-sm" style="margin-bottom: var(--sp-4);">
        <?php foreach ($empDocs as $d): ?>
            <div class="row row--between" style="align-items: center; padding: var(--sp-2) var(--sp-3); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md);">
                <div>
                    <a href="/dashboard/file.php?doc=<?= (int)$d['id'] ?>" target="_blank" style="font-weight: 600;"><?= e((string)$d['title']) ?></a>
                    <div class="muted" style="font-size: var(--fs-xs);"><?= e(strtoupper((string)($d['file_type'] ?? ''))) ?> · <?= e(date('M j, Y', strtotime((string)$d['created_at']))) ?></div>
                </div>
                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this document?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="delete_doc">
                    <input type="hidden" name="employee_id" value="<?= (int)$editEmp['id'] ?>">
                    <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn--ghost" type="submit" style="font-size: var(--fs-xs); padding: 0.3rem 0.6rem; color: var(--color-error);">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">No documents attached yet.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form" style="border-top: 1px solid var(--color-border); padding-top: var(--sp-4);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="upload_doc">
            <input type="hidden" name="employee_id" value="<?= (int)$editEmp['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="edf-title">Document title</label>
                    <input class="input" id="edf-title" name="title" placeholder="W-9, Background check, Contract…">
                </div>
                <div class="field">
                    <label class="field__label" for="edf-file">File <span class="muted" style="font-weight:400;">(PDF, Word, Excel, image — max 25 MB)</span></label>
                    <input class="input" type="file" id="edf-file" name="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt">
                </div>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <button class="btn btn--primary" type="submit">Attach file</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

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
            <tr><th>Name</th><th>Job</th><th>Type</th><?php if ($canSeeFinancials): ?><th>Pay</th><?php endif; ?><th>Dates</th><th>Status</th><th></th></tr>
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
                <?php if ($canSeeFinancials): ?><td><?= e($pay) ?: '<span class="muted">—</span>' ?></td><?php endif; ?>
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

<style>
    .typeahead-list {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
        max-height: 280px; overflow-y: auto;
        background: #fff; border: 1px solid var(--color-border); border-radius: var(--r-md);
        box-shadow: 0 8px 24px rgba(15,31,61,0.12);
        margin-top: 2px;
    }
    .typeahead-list[hidden] { display: none; }
    .typeahead-item {
        padding: 8px 12px; cursor: pointer; font-size: var(--fs-sm);
        display:flex; justify-content: space-between; align-items: baseline; gap: var(--sp-3);
    }
    .typeahead-item:hover, .typeahead-item.is-active { background: var(--color-surface); }
    .typeahead-item .unit { color: var(--color-text-soft); font-size: var(--fs-xs); font-variant-numeric: tabular-nums; }
    .typeahead-empty { padding: 8px 12px; color: var(--color-text-soft); font-size: var(--fs-sm); font-style: italic; }
</style>
<script>
    (function () {
        var search = document.getElementById('em-user-search');
        if (!search) return;
        var hidden = document.getElementById('em-user');
        var list   = document.getElementById('em-user-results');
        var data   = JSON.parse(search.getAttribute('data-typeahead') || '[]');
        var active = -1;
        var matches = [];

        function norm(s) { return (s || '').toLowerCase(); }
        function fmtLabel(m) { return m.name + (m.unit ? ' · Unit ' + m.unit : ''); }

        function render(q) {
            q = norm(q.trim());
            // Show top 10 matches. Empty query → first 10 alphabetically.
            matches = !q ? data.slice(0, 10) :
                data.filter(function (m) {
                    return norm(m.name).indexOf(q) !== -1 || norm(m.unit).indexOf(q) !== -1;
                }).slice(0, 10);
            list.innerHTML = '';
            if (!matches.length) {
                list.innerHTML = '<div class="typeahead-empty">No members match.</div>';
                list.hidden = false; return;
            }
            matches.forEach(function (m, i) {
                var row = document.createElement('div');
                row.className = 'typeahead-item' + (i === active ? ' is-active' : '');
                row.setAttribute('role', 'option');
                row.dataset.id = m.id;
                row.innerHTML = '<span>' + escapeHtml(m.name) + '</span>' +
                    (m.unit ? '<span class="unit">Unit ' + escapeHtml(m.unit) + '</span>' : '');
                row.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(m); });
                list.appendChild(row);
            });
            list.hidden = false;
        }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        }
        function pick(m) {
            search.value = fmtLabel(m);
            hidden.value = m.id;
            list.hidden  = true;
            active = -1;
        }

        search.addEventListener('focus', function () { render(search.value); });
        search.addEventListener('input', function () {
            hidden.value = ''; // clear selection when typing
            active = -1;
            render(search.value);
        });
        search.addEventListener('keydown', function (ev) {
            if (list.hidden) return;
            if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(matches.length - 1, active + 1); render(search.value); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(0, active - 1); render(search.value); }
            else if (ev.key === 'Enter' && active >= 0) { ev.preventDefault(); pick(matches[active]); }
            else if (ev.key === 'Escape') { list.hidden = true; }
        });
        document.addEventListener('click', function (ev) {
            if (ev.target !== search && !list.contains(ev.target)) list.hidden = true;
        });

        // Guard the submit: if there's text but no selected id, refuse.
        var form = search.closest('form');
        if (form) form.addEventListener('submit', function (ev) {
            if (!hidden.value) {
                ev.preventDefault();
                search.focus();
                alert('Pick a member from the list (type to filter, then click or press Enter).');
            }
        });
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

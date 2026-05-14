<?php
// Insurance + contractor COI tracker. Manager-only.
//
// Tracks: the association's own policies (property, GL, D&O, umbrella, WC,
// flood, earthquake…) and certificates of insurance from contractors who
// work for the association (their GL/WC proof). One table, kind column
// distinguishes them.
//
// Each row has effective + expires dates; the dashboard banner warns when
// any policy is expiring within 30 days.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

$KINDS = [
    'policy'         => 'Association policy',
    'contractor_coi' => 'Contractor COI',
];
$POLICY_TYPES = [
    'Property', 'General liability (GL)', 'Directors & officers (D&O)',
    'Umbrella', 'Workers compensation', 'Flood', 'Earthquake', 'Fidelity / crime',
    'Cyber', 'Other',
];

// --- Add / edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['form'] ?? ''), ['add','edit'], true)) {
    csrf_check();
    $isEdit  = $_POST['form'] === 'edit';
    $pid     = (int)($_POST['id'] ?? 0);
    $kind    = $_POST['kind'] ?? 'policy';
    $ptype   = trim((string)($_POST['policy_type'] ?? ''));
    $carrier = trim((string)($_POST['carrier'] ?? ''));
    $polno   = trim((string)($_POST['policy_number'] ?? ''));
    $cov     = ($_POST['coverage_amount'] ?? '') !== '' ? (float)$_POST['coverage_amount'] : null;
    $prem    = ($_POST['annual_premium']  ?? '') !== '' ? (float)$_POST['annual_premium']  : null;
    $ded     = ($_POST['deductible']      ?? '') !== '' ? (float)$_POST['deductible']      : null;
    $cId     = ($_POST['contractor_contact_id'] ?? '') !== '' ? (int)$_POST['contractor_contact_id'] : null;
    $cName   = trim((string)($_POST['contractor_name'] ?? ''));
    $docId   = ($_POST['document_id'] ?? '') !== '' ? (int)$_POST['document_id'] : null;
    $eff     = trim((string)($_POST['effective_at'] ?? ''));
    $exp     = trim((string)($_POST['expires_at'] ?? ''));
    $aN      = trim((string)($_POST['agent_name'] ?? ''));
    $aP      = trim((string)($_POST['agent_phone'] ?? ''));
    $aE      = trim((string)($_POST['agent_email'] ?? ''));
    $notes   = trim((string)($_POST['notes'] ?? ''));

    if (!array_key_exists($kind, $KINDS)) $kind = 'policy';
    if ($eff !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff)) $eff = '';
    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = '';
    if ($aE  !== '' && !filter_var($aE, FILTER_VALIDATE_EMAIL))    $aE  = '';

    if ($carrier === '') {
        $flashError = 'Carrier is required.';
    } else {
        $args = [
            $kind, $ptype ?: null, $carrier, $polno ?: null, $cov, $prem, $ded,
            $cId, $cName ?: null, $docId,
            $eff ?: null, $exp ?: null,
            $aN ?: null, $aP ?: null, $aE ?: null,
            $notes ?: null,
        ];
        if ($isEdit) {
            db()->prepare(
                'UPDATE insurance_policies
                    SET kind = ?, policy_type = ?, carrier = ?, policy_number = ?,
                        coverage_amount = ?, annual_premium = ?, deductible = ?,
                        contractor_contact_id = ?, contractor_name = ?, document_id = ?,
                        effective_at = ?, expires_at = ?,
                        agent_name = ?, agent_phone = ?, agent_email = ?,
                        notes = ?
                  WHERE id = ? AND association_id = ?'
            )->execute(array_merge($args, [$pid, $assocId]));
            audit('insurance.edited', ['carrier' => $carrier, 'kind' => $kind], $pid, 'insurance');
            flash('success', 'Policy updated.');
        } else {
            db()->prepare(
                'INSERT INTO insurance_policies
                    (association_id, kind, policy_type, carrier, policy_number,
                     coverage_amount, annual_premium, deductible,
                     contractor_contact_id, contractor_name, document_id,
                     effective_at, expires_at,
                     agent_name, agent_phone, agent_email, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array_merge([$assocId], $args));
            $newId = (int)db()->lastInsertId();
            audit('insurance.added', ['carrier' => $carrier, 'kind' => $kind], $newId, 'insurance');
            flash('success', "Added \"$carrier\".");
        }
        redirect('/dashboard/insurance.php');
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $pid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM insurance_policies WHERE id = ? AND association_id = ?')->execute([$pid, $assocId]);
    audit('insurance.deleted', [], $pid, 'insurance');
    flash('success', 'Policy deleted.');
    redirect('/dashboard/insurance.php');
}

// --- Insurance document upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload_doc') {
    csrf_check();
    $pid = (int)($_POST['insurance_id'] ?? 0);
    $chk = db()->prepare('SELECT 1 FROM insurance_policies WHERE id = ? AND association_id = ?');
    $chk->execute([$pid, $assocId]);
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
            $relDir = "uploads/$assocId/insurance";
            $absDir = storage_path($relDir);
            if (!is_dir($absDir)) mkdir($absDir, 0755, true);
            $relPath = "$relDir/" . bin2hex(random_bytes(12)) . ".$ext";
            $absPath = storage_path($relPath);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $absPath)) {
                flash('error', 'Could not save file.');
            } else {
                db()->prepare(
                    'INSERT INTO documents (association_id, insurance_id, title, file_path, file_type, access_level, uploaded_by, category, version)
                     VALUES (?, ?, ?, ?, ?, "board_only", ?, "Insurance", "1.0")'
                )->execute([$assocId, $pid, $docTitle, $relPath, $allowed[$ext], (int)$user['id']]);
                audit('insurance.doc_uploaded', ['title' => $docTitle], $pid, 'insurance');
                flash('success', "\"$docTitle\" attached.");
            }
        }
    }
    redirect("/dashboard/insurance.php?action=edit&id=$pid");
}

// --- Insurance document delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_doc') {
    csrf_check();
    $docId = (int)($_POST['doc_id'] ?? 0);
    $pid   = (int)($_POST['insurance_id'] ?? 0);
    $row = db()->prepare('SELECT file_path FROM documents WHERE id = ? AND association_id = ? AND insurance_id = ?');
    $row->execute([$docId, $assocId, $pid]);
    if ($r = $row->fetch()) {
        $abs = storage_path((string)$r['file_path']);
        if ($abs && file_exists($abs)) @unlink($abs);
        db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);
        audit('insurance.doc_deleted', ['doc_id' => $docId], $pid, 'insurance');
        flash('success', 'Document removed.');
    }
    redirect("/dashboard/insurance.php?action=edit&id=$pid");
}

// --- List ---
$kindFilter = $_GET['kind'] ?? 'all';
$sql = "SELECT p.*,
               c.label AS contractor_label,
               d.title AS document_title
          FROM insurance_policies p
          LEFT JOIN association_contacts c ON c.id = p.contractor_contact_id
          LEFT JOIN documents d           ON d.id = p.document_id
         WHERE p.association_id = ?";
$params = [$assocId];
if (array_key_exists($kindFilter, $KINDS)) {
    $sql .= ' AND p.kind = ?';
    $params[] = $kindFilter;
}
$sql .= " ORDER BY (p.expires_at IS NULL), p.expires_at, p.carrier";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Contractors picker
$contractors = db()->prepare(
    "SELECT id, label, trade FROM association_contacts
      WHERE association_id = ? AND kind = 'contractor'
      ORDER BY label"
);
$contractors->execute([$assocId]);
$contractors = $contractors->fetchAll();

// Documents picker (just titles)
$docs = db()->prepare(
    "SELECT id, title FROM documents
      WHERE association_id = ?
      ORDER BY created_at DESC LIMIT 200"
);
$docs->execute([$assocId]);
$docs = $docs->fetchAll();

$editPolicy = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM insurance_policies WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editPolicy = $stmt->fetch() ?: null;
}
$showAdd = ($_GET['action'] ?? '') === 'new';

$insDocs = [];
if ($editPolicy) {
    $ds = db()->prepare('SELECT * FROM documents WHERE insurance_id = ? AND association_id = ? ORDER BY created_at DESC');
    $ds->execute([(int)$editPolicy['id'], $assocId]);
    $insDocs = $ds->fetchAll();
}

// Renewal-warning helper
function expiry_status(?string $expires_at): array
{
    if (empty($expires_at)) return ['cls' => '', 'label' => '—', 'tone' => 'muted'];
    $days = (int)floor((strtotime($expires_at) - time()) / 86400);
    if ($days < 0)   return ['cls' => 'badge--error',   'label' => 'Expired ' . (-$days) . 'd ago', 'tone' => 'error'];
    if ($days <= 30) return ['cls' => 'badge--warning', 'label' => 'Expires in ' . $days . 'd',     'tone' => 'warning'];
    if ($days <= 90) return ['cls' => 'badge--info',    'label' => 'Expires in ' . $days . 'd',     'tone' => 'info'];
    return ['cls' => 'badge--success', 'label' => 'Expires ' . udate('M j, Y', strtotime($expires_at)), 'tone' => 'ok'];
}

$page_title = 'Insurance — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Insurance</h1>
            <p class="muted">Association policies and contractor COIs. Expiring policies surface as warnings on the dashboard.</p>
        </div>
        <?php if (!$showAdd && !$editPolicy): ?>
            <a class="btn btn--primary" href="?action=new">+ New record</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showAdd || $editPolicy):
        $vals = $editPolicy ?? [
            'id'=>0, 'kind'=>'policy', 'policy_type'=>'', 'carrier'=>'', 'policy_number'=>'',
            'coverage_amount'=>null, 'annual_premium'=>null, 'deductible'=>null,
            'contractor_contact_id'=>null, 'contractor_name'=>'', 'document_id'=>null,
            'effective_at'=>null, 'expires_at'=>null,
            'agent_name'=>'', 'agent_phone'=>'', 'agent_email'=>'', 'notes'=>'',
        ];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $editPolicy ? 'Edit insurance record' : 'New insurance record' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/insurance.php">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $editPolicy ? 'edit' : 'add' ?>">
            <?php if ($editPolicy): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ik">Kind</label>
                    <select class="select" id="ik" name="kind" data-kind>
                        <?php foreach ($KINDS as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $vals['kind'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="ipt">Type / coverage</label>
                    <input class="input" id="ipt" name="policy_type" list="ptype-list" value="<?= e((string)$vals['policy_type']) ?>" placeholder="Property, GL, D&O, …">
                    <datalist id="ptype-list">
                        <?php foreach ($POLICY_TYPES as $t): ?>
                            <option value="<?= e($t) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ica">Carrier</label>
                    <input class="input" id="ica" name="carrier" required value="<?= e((string)$vals['carrier']) ?>" placeholder="State Farm, Citizens, …">
                </div>
                <div class="field">
                    <label class="field__label" for="ipn">Policy number</label>
                    <input class="input" id="ipn" name="policy_number" value="<?= e((string)$vals['policy_number']) ?>">
                </div>
            </div>

            <div class="form-row form-row--2" data-coi-only style="<?= $vals['kind']==='contractor_coi'?'':'display:none;' ?>">
                <div class="field">
                    <label class="field__label" for="icid">Contractor</label>
                    <select class="select" id="icid" name="contractor_contact_id">
                        <option value="">— pick or use free-text below —</option>
                        <?php foreach ($contractors as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($vals['contractor_contact_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['label']) ?><?= !empty($c['trade']) ? ' · ' . e((string)$c['trade']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="icn">Or contractor name</label>
                    <input class="input" id="icn" name="contractor_name" value="<?= e((string)($vals['contractor_name'] ?? '')) ?>" placeholder="If not in your Contacts list yet">
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ieff">Effective date</label>
                    <input class="input" type="date" id="ieff" name="effective_at" value="<?= e((string)($vals['effective_at'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="iexp">Expires</label>
                    <input class="input" type="date" id="iexp" name="expires_at" value="<?= e((string)($vals['expires_at'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="icov">Coverage ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="icov" name="coverage_amount" value="<?= e((string)($vals['coverage_amount'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="iprem">Annual premium ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="iprem" name="annual_premium" value="<?= e((string)($vals['annual_premium'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ided">Deductible ($)</label>
                    <input class="input" type="number" step="0.01" min="0" id="ided" name="deductible" value="<?= e((string)($vals['deductible'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="idoc">Document (PDF on file)</label>
                    <select class="select" id="idoc" name="document_id">
                        <option value="">— none —</option>
                        <?php foreach ($docs as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= (int)($vals['document_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e((string)$d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Upload the cert/COI on the Documents page first, then link it here.</div>
                </div>
            </div>

            <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Agent</legend>
                <div class="form-row form-row--2">
                    <div class="field"><label class="field__label" for="ian">Name</label><input class="input" id="ian" name="agent_name" value="<?= e((string)($vals['agent_name'] ?? '')) ?>"></div>
                    <div class="field"><label class="field__label" for="iap">Phone</label><input class="input" id="iap" name="agent_phone" value="<?= e((string)($vals['agent_phone'] ?? '')) ?>"></div>
                </div>
                <div class="field"><label class="field__label" for="iae">Email</label><input class="input" type="email" id="iae" name="agent_email" value="<?= e((string)($vals['agent_email'] ?? '')) ?>"></div>
            </fieldset>

            <div class="field">
                <label class="field__label" for="ino">Notes</label>
                <textarea class="textarea" id="ino" name="notes" rows="3"><?= e((string)($vals['notes'] ?? '')) ?></textarea>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/insurance.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $editPolicy ? 'Save changes' : 'Add record' ?></button>
            </div>
        </form>
        <script>
            (function () {
                var sel = document.querySelector('[data-kind]');
                var coi = document.querySelector('[data-coi-only]');
                if (!sel || !coi) return;
                function sync() { coi.style.display = sel.value === 'contractor_coi' ? '' : 'none'; }
                sel.addEventListener('change', sync); sync();
            })();
        </script>
    </div>

    <?php if ($editPolicy): ?>
    <!-- ===== INSURANCE DOCUMENTS ===== -->
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head" style="margin-bottom: var(--sp-3);">
            <h3 class="card__title">Attached documents</h3>
            <span class="muted" style="font-size: var(--fs-xs);">Policy declarations, certificates, endorsements — board only.</span>
        </div>

        <?php if ($insDocs): ?>
        <div class="stack-sm" style="margin-bottom: var(--sp-4);">
        <?php foreach ($insDocs as $d): ?>
            <div class="row row--between" style="align-items: center; padding: var(--sp-2) var(--sp-3); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-md);">
                <div>
                    <a href="/dashboard/file.php?doc=<?= (int)$d['id'] ?>" target="_blank" style="font-weight: 600;"><?= e((string)$d['title']) ?></a>
                    <div class="muted" style="font-size: var(--fs-xs);"><?= e(strtoupper((string)($d['file_type'] ?? ''))) ?> · <?= e(udate('M j, Y', strtotime((string)$d['created_at']))) ?></div>
                </div>
                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this document?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="delete_doc">
                    <input type="hidden" name="insurance_id" value="<?= (int)$editPolicy['id'] ?>">
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
            <input type="hidden" name="insurance_id" value="<?= (int)$editPolicy['id'] ?>">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ins-doc-title">Document title</label>
                    <input class="input" id="ins-doc-title" name="title" placeholder="Policy dec, Certificate, Endorsement…">
                </div>
                <div class="field">
                    <label class="field__label" for="ins-doc-file">File <span class="muted" style="font-weight:400;">(PDF, Word, Excel, image — max 25 MB)</span></label>
                    <input class="input" type="file" id="ins-doc-file" name="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt">
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
        <a class="badge <?= $kindFilter === 'all' ? 'badge--navy' : '' ?>" href="?kind=all" style="text-decoration:none; <?= $kindFilter !== 'all' ? 'opacity: 0.6;' : '' ?>">All</a>
        <?php foreach ($KINDS as $val => $lbl): ?>
            <a class="badge <?= $kindFilter === $val ? 'badge--info' : '' ?>" href="?kind=<?= e($val) ?>" style="text-decoration:none; <?= $kindFilter !== $val ? 'opacity: 0.6;' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No insurance records on file yet.</p>
            <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">+ Add your first record</a></p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Carrier / Type</th><th>Policy #</th><th>Coverage</th><th>Dates</th><th>Status</th><th>PDF</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $st = expiry_status($r['expires_at']);
        ?>
            <tr>
                <td>
                    <strong><?= e((string)$r['carrier']) ?></strong>
                    <div class="muted" style="font-size: var(--fs-xs);">
                        <?= e($KINDS[$r['kind']] ?? $r['kind']) ?>
                        <?php if (!empty($r['policy_type'])): ?> · <?= e((string)$r['policy_type']) ?><?php endif; ?>
                        <?php if ($r['kind'] === 'contractor_coi'): ?>
                            · <?= e((string)($r['contractor_label'] ?: $r['contractor_name'] ?: '—')) ?>
                        <?php endif; ?>
                    </div>
                </td>
                <td><?= e((string)($r['policy_number'] ?? '')) ?></td>
                <td>
                    <?php if ($r['coverage_amount'] !== null): ?>$<?= number_format((float)$r['coverage_amount'], 0) ?><?php endif; ?>
                    <?php if ($r['deductible'] !== null): ?><div class="muted" style="font-size: var(--fs-xs);">Ded $<?= number_format((float)$r['deductible'], 0) ?></div><?php endif; ?>
                </td>
                <td style="font-size: var(--fs-sm); white-space: nowrap;">
                    <?php if (!empty($r['effective_at'])): ?><?= e(udate('M j, Y', strtotime((string)$r['effective_at']))) ?><?php endif; ?>
                    <?php if (!empty($r['expires_at'])): ?><br><span class="muted">→ <?= e(udate('M j, Y', strtotime((string)$r['expires_at']))) ?></span><?php endif; ?>
                </td>
                <td><span class="badge <?= e($st['cls']) ?>"><?= e($st['label']) ?></span></td>
                <td>
                    <?php if (!empty($r['document_id']) && !empty($r['document_title'])): ?>
                        <a href="/dashboard/file.php?type=document&id=<?= (int)$r['document_id'] ?>" target="_blank" rel="noopener" title="<?= e((string)$r['document_title']) ?>">📄 View</a>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this insurance record?');">
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

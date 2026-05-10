<?php
// Per-association contact directory — emergency lines, non-emergency lines,
// recommended contractors, utility providers. Manager-only CRUD. Items
// flagged is_public also show on the public community landing.
require __DIR__ . '/_bootstrap.php';
require_management();

$user = current_user();
$flashError = null;

$KINDS = [
    'emergency'     => ['label' => 'Emergency',       'cls' => 'badge--error'],
    'non_emergency' => ['label' => 'Non-emergency',   'cls' => 'badge--warning'],
    'contractor'    => ['label' => 'Contractor',      'cls' => 'badge--info'],
    'utility'       => ['label' => 'Utility',         'cls' => 'badge--navy'],
    'other'         => ['label' => 'Other',           'cls' => ''],
];

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    $kind     = $_POST['kind'] ?? 'other';
    $label    = trim((string)($_POST['label'] ?? ''));
    $trade    = trim((string)($_POST['trade'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $url      = trim((string)($_POST['url'] ?? ''));
    $notes    = trim((string)($_POST['notes'] ?? ''));
    $public   = isset($_POST['is_public']) ? 1 : 0;
    if (!array_key_exists($kind, $KINDS)) $kind = 'other';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $url = '';

    if ($label === '') {
        $flashError = 'Label is required.';
    } elseif ($phone === '' && $email === '' && $url === '') {
        $flashError = 'Add at least one of phone, email, or website.';
    } else {
        db()->prepare(
            'INSERT INTO association_contacts (association_id, kind, label, trade, phone, email, url, notes, is_public, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM association_contacts AS x WHERE x.association_id = ?), 0) + 10)'
        )->execute([
            $assocId, $kind, $label, $trade ?: null, $phone ?: null, $email ?: null, $url ?: null, $notes ?: null, $public, $assocId,
        ]);
        $newId = (int)db()->lastInsertId();
        audit('contact.added', ['kind' => $kind, 'label' => $label, 'public' => (bool)$public], $newId, 'contact');
        flash('success', "\"$label\" added.");
        redirect('/dashboard/contacts.php');
    }
}

// --- Edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    $cid    = (int)($_POST['id'] ?? 0);
    $kind   = $_POST['kind'] ?? 'other';
    $label  = trim((string)($_POST['label'] ?? ''));
    $trade  = trim((string)($_POST['trade'] ?? ''));
    $phone  = trim((string)($_POST['phone'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $url    = trim((string)($_POST['url'] ?? ''));
    $notes  = trim((string)($_POST['notes'] ?? ''));
    $public = isset($_POST['is_public']) ? 1 : 0;
    if (!array_key_exists($kind, $KINDS)) $kind = 'other';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $url = '';

    if ($label === '') {
        $flashError = 'Label is required.';
    } else {
        db()->prepare(
            'UPDATE association_contacts
                SET kind = ?, label = ?, trade = ?, phone = ?, email = ?, url = ?, notes = ?, is_public = ?
              WHERE id = ? AND association_id = ?'
        )->execute([$kind, $label, $trade ?: null, $phone ?: null, $email ?: null, $url ?: null, $notes ?: null, $public, $cid, $assocId]);
        audit('contact.edited', ['label' => $label], $cid, 'contact');
        flash('success', 'Contact updated.');
        redirect('/dashboard/contacts.php');
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $cid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM association_contacts WHERE id = ? AND association_id = ?')->execute([$cid, $assocId]);
    audit('contact.deleted', [], $cid, 'contact');
    flash('success', 'Contact deleted.');
    redirect('/dashboard/contacts.php');
}

// --- Load all + group by kind ---
$rows = db()->prepare('SELECT * FROM association_contacts WHERE association_id = ? ORDER BY kind, sort_order, label');
$rows->execute([$assocId]);
$all = $rows->fetchAll();
$byKind = array_fill_keys(array_keys($KINDS), []);
foreach ($all as $r) $byKind[$r['kind']][] = $r;

$showAdd = ($_GET['action'] ?? '') === 'new';
$editContact = null;
if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM association_contacts WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editContact = $stmt->fetch() ?: null;
}

$active = 'contacts';
$page_title = 'Contacts — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <div class="row row--between" style="margin-bottom: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Contacts</h1>
            <p class="muted">Emergency &amp; non-emergency phone numbers, recommended contractors, utility providers — anything the board points residents to.</p>
        </div>
        <?php if (!$showAdd && !$editContact): ?>
            <a class="btn btn--primary" href="?action=new">+ New contact</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showAdd || $editContact):
        $isEdit = $editContact !== null;
        $vals = $editContact ?? [
            'kind' => ($_GET['kind'] ?? 'other'),
            'label' => '', 'trade' => '', 'phone' => '', 'email' => '', 'url' => '', 'notes' => '',
            'is_public' => 0, 'id' => 0,
        ];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit contact' : 'New contact' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/contacts.php">← Back</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ck">Type</label>
                    <select class="select" id="ck" name="kind">
                        <?php foreach ($KINDS as $k => $meta): ?>
                            <option value="<?= e($k) ?>" <?= $vals['kind']===$k?'selected':'' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="cl">Label / name</label>
                    <input class="input" id="cl" name="label" required maxlength="120" value="<?= e((string)$vals['label']) ?>" placeholder="e.g. Daytona PD non-emergency · ABC Plumbing">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ct">Trade (contractors only)</label>
                    <input class="input" id="ct" name="trade" maxlength="80" value="<?= e((string)($vals['trade'] ?? '')) ?>" list="trade-list" placeholder="Plumbing · Electrical · HVAC">
                    <datalist id="trade-list">
                        <option value="Plumbing">
                        <option value="Electrical">
                        <option value="HVAC">
                        <option value="Roofing">
                        <option value="Pest control">
                        <option value="Painting">
                        <option value="Landscaping">
                        <option value="Locksmith">
                        <option value="Cleaning">
                        <option value="Appliance repair">
                    </datalist>
                </div>
                <div class="field">
                    <label class="field__label" for="cp">Phone</label>
                    <input class="input" type="tel" id="cp" name="phone" maxlength="40" value="<?= e((string)($vals['phone'] ?? '')) ?>" placeholder="555-555-5555">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ce">Email</label>
                    <input class="input" type="email" id="ce" name="email" value="<?= e((string)($vals['email'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="cu">Website</label>
                    <input class="input" type="url" id="cu" name="url" value="<?= e((string)($vals['url'] ?? '')) ?>" placeholder="https://example.com">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="cn">Notes</label>
                <textarea class="textarea" id="cn" name="notes" rows="2" placeholder="Anything residents should know — hours, prior work history, response time."><?= e((string)($vals['notes'] ?? '')) ?></textarea>
            </div>
            <label style="display:flex; align-items:center; gap: var(--sp-2);">
                <input type="checkbox" name="is_public" <?= (int)($vals['is_public'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>Show on public landing — emergency lines should always be public; contractors usually no.</span>
            </label>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/contacts.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save' : 'Add contact' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php foreach ($KINDS as $kind => $kmeta):
        $items = $byKind[$kind];
    ?>
    <div style="margin-bottom: var(--sp-6);">
        <div class="row row--between" style="margin-bottom: var(--sp-3); align-items: baseline;">
            <h2 style="font-size: var(--fs-xl); margin: 0;"><?= e($kmeta['label']) ?> <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">— <?= count($items) ?></span></h2>
            <a class="muted" style="font-size: var(--fs-sm);" href="?action=new&kind=<?= e($kind) ?>">+ Add <?= e(strtolower($kmeta['label'])) ?></a>
        </div>

        <?php if ($kind === 'contractor' && $items): ?>
            <p class="muted" style="font-size: var(--fs-xs); padding: var(--sp-2) var(--sp-3); background: var(--color-warning-bg); border-radius: var(--r-sm); margin-bottom: var(--sp-3);">
                <strong>Disclaimer:</strong> Contractors below are listed as a convenience for residents. <?= e((string)$association['name']) ?> doesn't guarantee their work and isn't responsible for the quality, pricing, or outcome of any service performed. Get your own quotes and references.
            </p>
        <?php endif; ?>

        <?php if (!$items): ?>
            <p class="muted" style="font-size: var(--fs-sm);">— none yet —</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr><th>Label</th><?php if ($kind === 'contractor'): ?><th>Trade</th><?php endif; ?><th>Phone</th><th>Email / Web</th><th>Public?</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr>
                    <td>
                        <strong><?= e((string)$r['label']) ?></strong>
                        <?php if (!empty($r['notes'])): ?>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$r['notes'], 0, 80, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if ($kind === 'contractor'): ?>
                        <td><?= e((string)($r['trade'] ?? '')) ?: '—' ?></td>
                    <?php endif; ?>
                    <td><?= $r['phone'] ? '<a href="tel:' . e((string)$r['phone']) . '">' . e((string)$r['phone']) . '</a>' : '<span class="muted">—</span>' ?></td>
                    <td>
                        <?php if (!empty($r['email'])): ?><a href="mailto:<?= e((string)$r['email']) ?>"><?= e((string)$r['email']) ?></a><br><?php endif; ?>
                        <?php if (!empty($r['url'])): ?><a href="<?= e((string)$r['url']) ?>" target="_blank" rel="noopener"><?= e(parse_url((string)$r['url'], PHP_URL_HOST) ?: $r['url']) ?></a><?php endif; ?>
                        <?php if (empty($r['email']) && empty($r['url'])): ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td><?= (int)$r['is_public'] === 1 ? '<span class="badge badge--success">yes</span>' : '<span class="muted">no</span>' ?></td>
                    <td style="text-align:right; white-space: nowrap;">
                        <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this contact?');">
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
    <?php endforeach; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_admin'];
$flashError = null;

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $q = trim((string)($_POST['question'] ?? ''));
    $a = trim((string)($_POST['answer'] ?? ''));
    if ($q === '' || $a === '') {
        $flashError = 'Question and answer are both required.';
    } else {
        db()->prepare(
            'INSERT INTO faqs (association_id, question, answer, sort_order)
             VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order) FROM faqs AS x WHERE x.association_id = ?), 0) + 10)'
        )->execute([$assocId, $q, $a, $assocId]);
        audit('faq.added', ['question' => mb_strimwidth($q, 0, 60, '…')], (int)db()->lastInsertId(), 'faq');
        flash('success', 'FAQ added.');
        redirect('/dashboard/faq.php');
    }
}

// --- Edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $fid = (int)($_POST['id'] ?? 0);
    $q   = trim((string)($_POST['question'] ?? ''));
    $a   = trim((string)($_POST['answer'] ?? ''));
    $ord = (int)($_POST['sort_order'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM faqs WHERE id = ? AND association_id = ?');
    $check->execute([$fid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('FAQ not found'); }
    if ($q === '' || $a === '') {
        $flashError = 'Question and answer are both required.';
    } else {
        db()->prepare('UPDATE faqs SET question = ?, answer = ?, sort_order = ? WHERE id = ? AND association_id = ?')
            ->execute([$q, $a, $ord, $fid, $assocId]);
        audit('faq.edited', [], $fid, 'faq');
        flash('success', 'FAQ updated.');
        redirect('/dashboard/faq.php');
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $fid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM faqs WHERE id = ? AND association_id = ?')->execute([$fid, $assocId]);
    audit('faq.deleted', [], $fid, 'faq');
    flash('success', 'FAQ deleted.');
    redirect('/dashboard/faq.php');
}

$faqs = db()->prepare('SELECT * FROM faqs WHERE association_id = ? ORDER BY sort_order, id');
$faqs->execute([$assocId]);
$rows = $faqs->fetchAll();

// Edit target
$editFaq = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM faqs WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editFaq = $stmt->fetch() ?: null;
}
$showCreate = ($_GET['action'] ?? '') === 'new' && $canManage;

$page_title = 'FAQ — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 980px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">FAQ</h1>
            <p class="muted">
                Common questions visitors and prospective residents ask. Shown on
                <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener">your public landing</a>.
            </p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ New FAQ</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showCreate || $editFaq):
        $vals = $editFaq ?? ['question'=>'','answer'=>'','sort_order'=>0,'id'=>0];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title"><?= $editFaq ? 'Edit FAQ' : 'New FAQ' ?></h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $editFaq ? 'edit' : 'add' ?>">
            <?php if ($editFaq): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="field">
                <label class="field__label" for="fq">Question</label>
                <input class="input" id="fq" name="question" required maxlength="500" value="<?= e((string)$vals['question']) ?>" placeholder="Are pets allowed?">
            </div>
            <div class="field">
                <label class="field__label" for="fa">Answer</label>
                <textarea class="textarea" id="fa" name="answer" rows="6" required><?= e((string)$vals['answer']) ?></textarea>
                <div class="field__hint">Plain text, supports paragraph breaks (a blank line between paragraphs).</div>
            </div>
            <?php if ($editFaq): ?>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="fo">Sort order</label>
                    <input class="input" type="number" id="fo" name="sort_order" min="0" max="9999" step="10" value="<?= (int)$vals['sort_order'] ?>">
                    <div class="field__hint">Lower numbers appear first. Default 10/20/30… leaves room to insert.</div>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <?php endif; ?>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/faq.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $editFaq ? 'Save changes' : 'Add FAQ' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No FAQs yet.</p>
            <?php if ($canManage): ?>
                <p style="margin-top: var(--sp-4);">
                    <a class="btn btn--primary" href="?action=new">Add your first one</a>
                </p>
                <p class="muted" style="font-size: var(--sp-sm); margin-top: var(--sp-4);">
                    Common starters: pets, parking, quiet hours, guest policy, amenity hours, fees.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="stack-lg">
    <?php foreach ($rows as $f): ?>
        <div class="card card--padded">
            <div class="row row--between" style="align-items:flex-start; margin-bottom: var(--sp-3);">
                <div style="flex: 1; min-width: 0;">
                    <h3 style="font-size: var(--fs-lg); margin: 0;"><?= e((string)$f['question']) ?></h3>
                    <span class="muted" style="font-size: var(--fs-xs);">sort: <?= (int)$f['sort_order'] ?> &middot; id: <?= (int)$f['id'] ?></span>
                </div>
                <?php if ($canManage): ?>
                <div class="row" style="gap: var(--sp-2);">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$f['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this FAQ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <p style="margin: 0; white-space: pre-wrap; color: var(--color-text-soft);"><?= e((string)$f['answer']) ?></p>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

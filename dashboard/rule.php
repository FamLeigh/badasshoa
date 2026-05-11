<?php
// Single rule view. Anyone signed in can open; managers see Edit/Delete.
// Append ?print=1 for a print-friendly layout that auto-opens the browser
// print dialog on load.
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = role_can_manage(viewing_role());

$rid = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM rules WHERE id = ? AND association_id = ?');
$stmt->execute([$rid, $assocId]);
$rule = $stmt->fetch();
if (!$rule) { http_response_code(404); die('Rule not found'); }

$printMode = isset($_GET['print']);

$page_title = ($rule['rule_number'] ? '#' . $rule['rule_number'] . ' — ' : '') . $rule['title'] . ' — Rules';

if ($printMode) {
    // Minimal print layout — no sidebar, no chrome.
    ?><!doctype html>
    <html lang="en"><head>
    <meta charset="utf-8">
    <title><?= e($page_title) ?></title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 1in; line-height: 1.5; }
        h1 { font-size: 22pt; margin: 0 0 0.5em; }
        .meta { color: #555; font-size: 10pt; margin-bottom: 1em; }
        .meta strong { color: #111; }
        .body { font-size: 12pt; white-space: pre-wrap; }
        .footer { margin-top: 2em; padding-top: 1em; border-top: 1px solid #ccc; color: #888; font-size: 9pt; }
        @media print { @page { margin: 0.7in; } }
    </style>
    </head><body>
    <div class="meta">
        <?php if ($rule['rule_number']): ?><strong>#<?= e((string)$rule['rule_number']) ?></strong> · <?php endif; ?>
        <?= e(ucfirst(str_replace('_',' ',(string)$rule['source']))) ?>
        <?php if ($rule['category']): ?> · <?= e((string)$rule['category']) ?><?php endif; ?>
        <?php if ($rule['effective_date']): ?> · In effect <?= e(date('M j, Y', strtotime((string)$rule['effective_date']))) ?><?php endif; ?>
    </div>
    <h1><?= e((string)$rule['title']) ?></h1>
    <div class="body"><?= e(trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$rule['body'])))) ?></div>
    <div class="footer">
        <?= e((string)$association['name']) ?> — Rules · Printed <?= e(date('M j, Y')) ?>
    </div>
    <script>window.addEventListener('load', function(){ window.print(); });</script>
    </body></html>
    <?php
    exit;
}

$active = 'rules';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 780px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4); align-items:flex-start; flex-wrap: wrap; gap: var(--sp-2);">
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← All rules</a>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= (int)$rule['id'] ?>&print=1" target="_blank" rel="noopener">🖨 Print this rule</a>
            <?php if ($canManage): ?>
                <a class="btn btn--ghost" href="/dashboard/search.php?action=edit&id=<?= (int)$rule['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row" style="gap: var(--sp-2); align-items: baseline; margin-bottom: var(--sp-2); flex-wrap: wrap;">
        <?php if ($rule['rule_number']): ?>
            <strong style="font-size: var(--fs-xl); color: var(--color-navy);">#<?= e((string)$rule['rule_number']) ?></strong>
        <?php endif; ?>
        <span class="badge badge--<?= $rule['source']==='bylaw'?'navy':($rule['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ', (string)$rule['source'])) ?></span>
        <?php if ($rule['category']): ?>
            <span class="badge" style="background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid rgba(182,130,42,0.25);"><?= e((string)$rule['category']) ?></span>
        <?php endif; ?>
        <?php if ($rule['effective_date']): ?>
            <span class="muted" style="font-size: var(--fs-sm);">In effect <?= e(date('M j, Y', strtotime((string)$rule['effective_date']))) ?></span>
        <?php endif; ?>
    </div>

    <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-4); letter-spacing: -0.01em;"><?= e((string)$rule['title']) ?></h1>

    <?php if (!empty($rule['review_flag'])): ?>
    <div class="flash flash--warning" style="margin-bottom: var(--sp-4);">
        <strong>🚩 Flagged for review.</strong>
        <?php if (!empty($rule['review_note'])): ?>
            <span style="opacity: 0.85;"><?= e((string)$rule['review_note']) ?></span>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="/dashboard/search.php" style="display:inline; margin-left: var(--sp-2);">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="review_flag">
                <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                <input type="hidden" name="on" value="0">
                <input type="hidden" name="back" value="/dashboard/rule.php?id=<?= (int)$rule['id'] ?>">
                <button class="btn btn--ghost" type="submit" style="padding: 0.2rem 0.6rem; font-size: var(--fs-xs);">Clear flag</button>
            </form>
        <?php endif; ?>
    </div>
    <?php elseif ($canManage): ?>
    <p style="margin-bottom: var(--sp-4);">
        <form method="post" action="/dashboard/search.php" style="display:inline;" onsubmit="var n = prompt('Optional note about why this rule needs review:'); if (n === null) return false; this.querySelector('[name=review_note]').value = n; return true;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="review_flag">
            <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
            <input type="hidden" name="on" value="1">
            <input type="hidden" name="review_note" value="">
            <input type="hidden" name="back" value="/dashboard/rule.php?id=<?= (int)$rule['id'] ?>">
            <button class="btn btn--ghost" type="submit" style="font-size: var(--fs-xs);">🚩 Flag this rule for board review</button>
        </form>
    </p>
    <?php endif; ?>

    <div class="card card--padded" style="white-space: pre-wrap; line-height: var(--lh-loose);">
        <?= e(trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$rule['body'])))) ?>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

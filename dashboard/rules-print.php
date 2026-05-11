<?php
// Print-all rules in rule_number order. Minimal HTML so the browser print
// dialog produces a clean PDF or paper copy. Auto-opens the print dialog
// on load.
require __DIR__ . '/_bootstrap.php';

$stmt = db()->prepare(
    'SELECT * FROM rules
      WHERE association_id = ?
      ORDER BY CAST(rule_number AS UNSIGNED), rule_number, title'
);
$stmt->execute([$assocId]);
$rules = $stmt->fetchAll();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Rules &amp; Bylaws — <?= e((string)$association['name']) ?></title>
<style>
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 1in; line-height: 1.45; }
    h1.cover { font-size: 26pt; margin: 0 0 0.25em; }
    .cover-meta { color: #555; font-size: 11pt; margin-bottom: 2em; padding-bottom: 1em; border-bottom: 2px solid #111; }
    .rule { page-break-inside: avoid; margin-bottom: 1.25em; }
    .rule h2 { font-size: 14pt; margin: 0 0 0.25em; }
    .rule .num { color: #0f1f3d; font-weight: 800; margin-right: 0.4em; }
    .rule .meta { color: #555; font-size: 9.5pt; margin-bottom: 0.4em; }
    .rule .meta .pill { display: inline-block; padding: 1px 6px; border-radius: 8px; background: #f3edd9; color: #6b4a06; border: 1px solid #d9c97a; font-size: 9pt; margin-right: 6px; }
    .rule .meta .src { display: inline-block; padding: 1px 6px; border-radius: 8px; background: #fdecdf; color: #b73f0c; border: 1px solid #f5a675; font-size: 9pt; margin-right: 6px; }
    .rule .body { font-size: 11pt; white-space: pre-wrap; }
    hr { border: 0; border-top: 1px solid #ddd; margin: 0.5em 0 1em; }
    @media print { @page { margin: 0.7in; } }
</style>
</head><body>

<?= print_header_html($association) ?>

<h1 class="cover">Rules &amp; Bylaws</h1>
<div class="cover-meta">
    <?= count($rules) ?> rule<?= count($rules)===1?'':'s' ?> · Printed <?= e(date('M j, Y')) ?>
</div>

<?php if (!$rules): ?>
    <p>No rules on file.</p>
<?php else: ?>
    <?php foreach ($rules as $r): ?>
    <div class="rule">
        <div class="meta">
            <?php if (!empty($r['rule_number'])): ?><span class="num">#<?= e((string)$r['rule_number']) ?></span><?php endif; ?>
            <span class="src"><?= e(ucfirst(str_replace('_',' ',(string)$r['source']))) ?></span>
            <?php if (!empty($r['category'])): ?><span class="pill"><?= e((string)$r['category']) ?></span><?php endif; ?>
            <?php if (!empty($r['effective_date'])): ?>In effect <?= e(date('M j, Y', strtotime((string)$r['effective_date']))) ?><?php endif; ?>
        </div>
        <h2><?= e((string)$r['title']) ?></h2>
        <div class="body"><?= e(trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$r['body'])))) ?></div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= print_footer_html('Printed ' . date('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

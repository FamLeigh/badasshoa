<?php
// Print-all rules in rule_number order. Minimal HTML so the browser print
// dialog produces a clean PDF or paper copy. Auto-opens the print dialog
// on load.
require __DIR__ . '/_bootstrap.php';

// Optional filters — match the search.php query params so a "Print these
// results" link from the search bar honors the current filter.
$q      = trim((string)($_GET['q'] ?? ''));
$source = $_GET['source'] ?? '';
$validSource = in_array($source, ['bylaw','board_rule','policy'], true);

if ($q !== '') {
    $sql = 'SELECT * FROM rules
             WHERE association_id = ?
               AND MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)';
    $params = [$assocId, $q];
    if ($validSource) { $sql .= ' AND source = ?'; $params[] = $source; }
    $sql .= ' ORDER BY CAST(rule_number AS UNSIGNED), rule_number, title';
} else {
    $sql = 'SELECT * FROM rules WHERE association_id = ?';
    $params = [$assocId];
    if ($validSource) { $sql .= ' AND source = ?'; $params[] = $source; }
    $sql .= ' ORDER BY CAST(rule_number AS UNSIGNED), rule_number, title';
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rules = $stmt->fetchAll();

// Build a human-readable filter line for the cover-meta strip.
$filterBits = [];
if ($q !== '')      $filterBits[] = 'matching "' . $q . '"';
if ($validSource)   $filterBits[] = ucfirst(str_replace('_', ' ', $source)) . 's only';
$filterLabel = $filterBits ? ' · ' . implode(' · ', $filterBits) : '';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Rules &amp; Bylaws — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.4in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; padding: 0; line-height: 1.35; }
    h1.cover { font-size: 22pt; margin: 0 0 0.2em; }
    .cover-meta { color: #555; font-size: 10pt; margin-bottom: 0.8em; padding-bottom: 0.6em; border-bottom: 2px solid #111; }
    .rules-grid {
        column-count: 2;
        column-gap: 0.35in;
        column-rule: 1px solid #ddd;
    }
    .rule {
        break-inside: avoid;
        page-break-inside: avoid;
        margin: 0 0 0.6em;
        padding-bottom: 0.4em;
        border-bottom: 1px solid #eee;
    }
    .rule h2 { font-size: 11pt; margin: 0 0 0.15em; line-height: 1.2; }
    .rule .num { color: #0f1f3d; font-weight: 800; margin-right: 0.25em; font-size: 11pt; }
    .rule .meta { color: #555; font-size: 8pt; margin-bottom: 0.25em; }
    .rule .meta .pill { display: inline-block; padding: 0 4pt; border-radius: 6pt; background: #f3edd9; color: #6b4a06; border: 1px solid #d9c97a; font-size: 7.5pt; margin-right: 3pt; }
    .rule .meta .src  { display: inline-block; padding: 0 4pt; border-radius: 6pt; background: #fdecdf; color: #b73f0c; border: 1px solid #f5a675; font-size: 7.5pt; margin-right: 3pt; }
    .rule .body { font-size: 9pt; white-space: pre-wrap; line-height: 1.3; }
    hr { border: 0; border-top: 1px solid #ddd; margin: 0.3em 0 0.6em; }
    @media print { a { color: inherit; text-decoration: none; } }
</style>
</head><body style="padding: 0.4in;">

<?= print_header_html($association) ?>

<h1 class="cover">Rules &amp; Bylaws</h1>
<div class="cover-meta">
    <?= count($rules) ?> rule<?= count($rules)===1?'':'s' ?><?= e($filterLabel) ?> · Printed <?= e(date('M j, Y')) ?>
</div>

<?php if (!$rules): ?>
    <p>No rules on file.</p>
<?php else: ?>
<div class="rules-grid">
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
</div>
<?php endif; ?>

<?= print_footer_html('Printed ' . date('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

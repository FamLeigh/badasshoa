<?php
// Print-friendly list of rule suggestions. ?status=pending|approved|rejected|all
// (default: pending). Includes the suggester, submission date, title, source,
// category, and body. Manager-only — suggestions are board-internal review
// material until approved.
require __DIR__ . '/_bootstrap.php';
require_management();

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending','approved','rejected','all'], true)) $statusFilter = 'pending';

$STATUS_COLORS = [
    'pending'  => '#a8782a',
    'approved' => '#2f7a3d',
    'rejected' => '#a8322a',
];

$sql = 'SELECT s.*,
               TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS suggester_name,
               u.email AS suggester_email
          FROM rule_suggestions s LEFT JOIN users u ON u.id = s.suggester_user_id
         WHERE s.association_id = ?';
$params = [$assocId];
if ($statusFilter !== 'all') {
    $sql .= ' AND s.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY s.suggested_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Rule suggestions — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.4in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; padding: 0; line-height: 1.35; }
    h1 { font-size: 22pt; margin: 0 0 0.25em; }
    .meta { color: #555; font-size: 10pt; margin-bottom: 0.8em; padding-bottom: 0.6em; border-bottom: 2px solid #111; }
    .sug { break-inside: avoid; page-break-inside: avoid; margin-bottom: 0.9em; padding: 6pt 8pt; border: 1px solid #ddd; border-radius: 4pt; }
    .sug h2 { font-size: 12pt; margin: 2pt 0 4pt; }
    .sug .head { font-size: 9pt; color: #555; margin-bottom: 4pt; }
    .sug .body { font-size: 10pt; white-space: pre-wrap; }
    .sug .decision { font-size: 9pt; color: #555; margin-top: 4pt; padding-top: 4pt; border-top: 1px dotted #ccc; }
    .pill   { display: inline-block; padding: 0 6pt; border-radius: 999pt; color: #fff; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 4pt; }
    .pill-soft { display: inline-block; padding: 0 5pt; border-radius: 6pt; background: #fdecdf; color: #b73f0c; border: 1px solid #f5a675; font-size: 8pt; margin-right: 4pt; }
    .cat-pill  { display: inline-block; padding: 0 5pt; border-radius: 6pt; background: #f3edd9; color: #6b4a06; border: 1px solid #d9c97a; font-size: 8pt; margin-right: 4pt; }
    @media print { a { color: inherit; text-decoration: none; } }
</style>
</head><body style="padding: 0.4in;">

<?= print_header_html($association) ?>

<h1>Rule suggestions <span style="font-size:11pt; color:#888;">(<?= e($statusFilter) ?>)</span></h1>
<div class="meta">
    <?= count($rows) ?> suggestion<?= count($rows)===1?'':'s' ?> · Printed <?= e(udate('M j, Y')) ?>
</div>

<?php if (!$rows): ?>
    <p>No <?= e($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>suggestions.</p>
<?php else: ?>
    <?php foreach ($rows as $s):
        $color = $STATUS_COLORS[$s['status']] ?? '#555';
        $name  = trim((string)$s['suggester_name']) ?: (string)($s['suggester_email'] ?? '') ?: '— suggester removed —';
        $bodyText = trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$s['body'])));
    ?>
    <div class="sug">
        <div class="head">
            <span class="pill" style="background: <?= e($color) ?>;"><?= e((string)$s['status']) ?></span>
            <span class="pill-soft"><?= e(ucfirst(str_replace('_',' ',(string)$s['source']))) ?></span>
            <?php if (!empty($s['category'])): ?>
                <span class="cat-pill"><?= e((string)$s['category']) ?></span>
            <?php endif; ?>
            Suggested by <strong><?= e($name) ?></strong>
            on <?= e(udate('M j, Y', strtotime((string)$s['suggested_at']))) ?>
        </div>
        <h2><?= e((string)$s['title']) ?></h2>
        <div class="body"><?= e($bodyText) ?></div>
        <?php if ($s['status'] !== 'pending'): ?>
            <div class="decision">
                <?= e(ucfirst((string)$s['status'])) ?>
                <?php if (!empty($s['reviewed_at'])): ?>on <?= e(udate('M j, Y', strtotime((string)$s['reviewed_at']))) ?><?php endif; ?>
                <?php if (!empty($s['decision_note'])): ?> · "<em><?= e((string)$s['decision_note']) ?></em>"<?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= print_footer_html('Printed ' . udate('M j, Y') . ' · suggestions (' . $statusFilter . ')') ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

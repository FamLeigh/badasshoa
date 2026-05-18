<?php
// Print-friendly announcements list.
// ?period=today|week|month  — filter by publish date window (default = all active)
// ?type=...                 — optional type filter
// ?audience=...             — optional audience filter
// Auto-fires the browser print dialog on load.
require __DIR__ . '/_bootstrap.php';

$qPeriod = $_GET['period'] ?? '';
if (!in_array($qPeriod, ['today','week','month'], true)) $qPeriod = '';

$qType = $_GET['type'] ?? '';
if (!array_key_exists($qType, ann_types())) $qType = '';

$qAud = $_GET['audience'] ?? '';
if (!in_array($qAud, ['all','owners','renters','board'], true)) $qAud = '';

// Compute period window (UTC).
$rangeTitle = 'All announcements';
$periodStart = $periodEnd = null;
if ($qPeriod !== '') {
    $todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($qPeriod === 'today') {
        $periodStart = $todayStart;
        $periodEnd   = $todayStart + 86400 - 1;
        $rangeTitle  = 'Today — ' . udate('l, F j, Y', $todayStart);
    } elseif ($qPeriod === 'week') {
        $dow         = (int)date('w', $todayStart);
        $periodStart = $todayStart - $dow * 86400;
        $periodEnd   = $periodStart + 7 * 86400 - 1;
        $rangeTitle  = 'Week of ' . udate('M j', $periodStart) . ' – ' . udate('M j, Y', $periodEnd);
    } elseif ($qPeriod === 'month') {
        $periodStart = strtotime(date('Y-m-01') . ' 00:00:00');
        $periodEnd   = strtotime(date('Y-m-t') . ' 23:59:59');
        $rangeTitle  = udate('F Y', $periodStart);
    }
}

$sql = 'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
        FROM announcements a LEFT JOIN users u ON u.id = a.author_id
        WHERE a.association_id = ?';
$params = [$assocId];
if ($qType !== '')  { $sql .= ' AND a.type = ?';     $params[] = $qType; }
if ($qAud !== '')   { $sql .= ' AND a.audience = ?'; $params[] = $qAud; }
if ($periodStart !== null) {
    $sql .= ' AND a.published_at >= ? AND a.published_at <= ?';
    $params[] = date('Y-m-d H:i:s', $periodStart);
    $params[] = date('Y-m-d H:i:s', $periodEnd);
} else {
    $sql .= ' AND a.published_at <= NOW() AND (a.expires_at IS NULL OR a.expires_at >= NOW())';
}
$sql .= ' ORDER BY a.published_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$typeColors = [
    'emergency'     => '#a8322a',
    'event'         => '#1f4f9c',
    'maintenance'   => '#a8782a',
    'beautification'=> '#2f7a3d',
    'general'       => '#c25a1e',
    'birth_notice'  => '#6d28d9',
    'death_notice'  => '#374151',
];

$subtitle = '';
if ($qType !== '')  $subtitle .= ' · ' . ucfirst($qType);
if ($qAud  !== '')  $subtitle .= ' · ' . ucfirst($qAud) . ' only';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Announcements (<?= e($rangeTitle) ?>) — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.6in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; line-height: 1.45; }
    h1 { font-size: 20pt; margin: 0 0 0.2em; }
    .subtitle { color: #555; font-size: 10pt; margin-bottom: 1.2em; }
    .ann { display: flex; gap: 14pt; align-items: flex-start; padding: 10pt 0;
           border-bottom: 1px solid #ddd; break-inside: avoid; page-break-inside: avoid; }
    .ann:last-child { border-bottom: none; }
    .date-block { flex: 0 0 56pt; text-align: center; padding: 5pt 8pt;
                  border: 2px solid #0f1f3d; border-radius: 6pt; background: #f8f7f4; }
    .date-block .m { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em; color: #555; font-weight: 700; }
    .date-block .d { font-size: 24pt; line-height: 1; font-weight: 800; color: #0f1f3d; margin: 2pt 0; }
    .date-block .y { font-size: 8pt; color: #555; }
    .ann-body { flex: 1; min-width: 0; }
    .ann-title { font-size: 13pt; font-weight: 700; margin: 2pt 0 3pt; }
    .ann-meta { font-size: 9pt; color: #555; margin-bottom: 4pt; }
    .ann-text { font-size: 10pt; color: #333; white-space: pre-wrap; line-height: 1.5; }
    .pill { display: inline-block; padding: 1pt 7pt; border-radius: 999pt; color: #fff;
            font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 4pt; }
    .pill-soft { display: inline-block; padding: 1pt 7pt; border-radius: 999pt;
                 background: #f3edd9; color: #6b4a06; font-size: 8pt; margin-right: 4pt; }
    .expired { opacity: 0.5; }
    @media print { a { color: inherit; text-decoration: none; } }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<h1><?= e($rangeTitle) ?></h1>
<div class="subtitle">
    <?= count($rows) ?> announcement<?= count($rows) === 1 ? '' : 's' ?><?= e($subtitle) ?> · Printed <?= e(udate('M j, Y')) ?>
</div>

<?php if (!$rows): ?>
    <p style="color: #555;">No announcements for this period.</p>
<?php else: ?>
    <?php foreach ($rows as $a):
        $startTs   = strtotime((string)$a['published_at']);
        $expTs     = !empty($a['expires_at']) ? strtotime((string)$a['expires_at']) : null;
        $isExpired = $expTs !== null && $expTs < time();
        $color     = $typeColors[$a['type']] ?? '#c25a1e';
    ?>
    <div class="ann<?= $isExpired ? ' expired' : '' ?>">
        <div class="date-block">
            <div class="m"><?= e(udate('M', $startTs)) ?></div>
            <div class="d"><?= e(udate('j', $startTs)) ?></div>
            <div class="y"><?= e(udate('Y', $startTs)) ?></div>
        </div>
        <div class="ann-body">
            <div class="ann-meta">
                <span class="pill" style="background: <?= e($color) ?>;"><?= e(ann_types()[(string)$a['type']]['label'] ?? ucfirst((string)$a['type'])) ?></span>
                <span class="pill-soft"><?= e((string)$a['audience']) ?></span>
                <?= e(udate('g:i A', $startTs)) ?>
                · <?= e(trim((string)$a['author']) ?: 'Unknown') ?>
                <?php if ($expTs): ?> · expires <?= e(udate('M j, Y', $expTs)) ?><?php endif; ?>
                <?php if ($isExpired): ?> · <em>expired</em><?php endif; ?>
            </div>
            <div class="ann-title"><?= e((string)$a['title']) ?></div>
            <div class="ann-text"><?= e((string)$a['body']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= print_footer_html('Printed ' . udate('M j, Y g:i A') . ($subtitle !== '' ? ' ·' . $subtitle : '')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

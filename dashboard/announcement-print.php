<?php
// Print-friendly single-announcement view. Letterhead + date-first card +
// full body. Auto-fires the print dialog on load.
require __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
       FROM announcements a LEFT JOIN users u ON u.id = a.author_id
      WHERE a.id = ? AND a.association_id = ?'
);
$stmt->execute([$id, $assocId]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); echo 'Announcement not found.'; exit; }

$startTs = strtotime((string)$a['published_at']);
$expTs   = !empty($a['expires_at']) ? strtotime((string)$a['expires_at']) : null;

$typeColor = match ($a['type']) {
    'emergency'    => '#a8322a',
    'event'        => '#1f4f9c',
    'maintenance'  => '#a8782a',
    'beautification'=> '#2f7a3d',
    'birth_notice' => '#6d28d9',
    'death_notice' => '#374151',
    default        => '#c25a1e',
};
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title><?= e((string)$a['title']) ?> — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.5in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; padding: 0; line-height: 1.5; }
    .meta { color: #555; font-size: 10pt; margin: 6pt 0; }
    .date-block { display:inline-block; text-align:center; padding: 6pt 10pt; border: 2px solid #0f1f3d; border-radius: 6pt; background: #f8f7f4; vertical-align: middle; margin-right: 14pt; }
    .date-block .m { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.08em; color: #555; font-weight: 700; }
    .date-block .d { font-size: 28pt; line-height: 1; font-weight: 800; color: #0f1f3d; margin: 2pt 0; }
    .date-block .y { font-size: 9pt; color: #555; }
    .head { display:flex; gap: 14pt; align-items: center; margin: 14pt 0 18pt; padding-bottom: 12pt; border-bottom: 2px solid #0f1f3d; }
    h1 { font-size: 22pt; margin: 0 0 6pt; }
    .pill { display:inline-block; padding: 2pt 8pt; border-radius: 999pt; color: #fff; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 5pt; }
    .pill-soft { display:inline-block; padding: 2pt 8pt; border-radius: 999pt; background: #f3edd9; color: #6b4a06; font-size: 9pt; margin-right: 5pt; }
    .body { font-size: 12pt; white-space: pre-wrap; }
    @media print { a { color: inherit; text-decoration: none; } }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<div class="head">
    <div class="date-block">
        <div class="m"><?= e(udate('M', $startTs)) ?></div>
        <div class="d"><?= e(udate('j', $startTs)) ?></div>
        <div class="y"><?= e(udate('Y', $startTs)) ?></div>
    </div>
    <div style="flex: 1;">
        <div>
            <span class="pill" style="background: <?= e($typeColor) ?>;"><?= e((string)$a['type']) ?></span>
            <span class="pill-soft"><?= e((string)$a['audience']) ?></span>
        </div>
        <h1><?= e((string)$a['title']) ?></h1>
        <div class="meta">
            <?= e(udate('l, F j, Y · g:i A', $startTs)) ?>
            · by <?= e(trim((string)$a['author']) ?: 'Unknown') ?>
            <?php if ($expTs): ?> · expires <?= e(udate('M j, Y', $expTs)) ?><?php endif; ?>
        </div>
    </div>
</div>

<div class="body"><?= e((string)$a['body']) ?></div>

<?= print_footer_html('Printed ' . udate('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

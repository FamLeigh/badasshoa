<?php
// Print-friendly parking spot list. Two layout modes:
//   - default: full table (kind, number, unit, owner, notes)
//   - ?cols=3: compact three-column grid showing just kind+number+unit/owner
// Both auto-trigger the browser print dialog.
require __DIR__ . '/_bootstrap.php';

$KINDS = [
    'garage'  => 'Garage',
    'surface' => 'Surface',
    'covered' => 'Covered',
    'tandem'  => 'Tandem',
    'other'   => 'Other',
];

$stmt = db()->prepare(
    "SELECT s.*,
            u.unit_number,
            (SELECT TRIM(CONCAT(IFNULL(usr.first_name,''), ' ', IFNULL(usr.last_name,'')))
               FROM unit_occupants uo
               JOIN users usr ON usr.id = uo.user_id
              WHERE uo.unit_id = u.id AND uo.is_primary = 1
              LIMIT 1) AS primary_owner_name
       FROM parking_spots s
       LEFT JOIN units u ON u.id = s.assigned_unit_id
      WHERE s.association_id = ? AND s.is_active = 1
      ORDER BY FIELD(s.kind,'garage','surface','covered','tandem','other'),
               CAST(s.number AS UNSIGNED), s.number"
);
$stmt->execute([$assocId]);
$spots = $stmt->fetchAll();

$threeCol = ($_GET['cols'] ?? '') === '3';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Parking — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.6in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; line-height: 1.4; }
    h1 { font-size: 22pt; margin: 0 0 0.25em; }
    .meta { color: #555; font-size: 10pt; margin-bottom: 1em; }
    table { border-collapse: collapse; width: 100%; font-size: 10pt; }
    th, td { text-align: left; padding: 6pt 8pt; border-bottom: 1px solid #ddd; vertical-align: top; }
    th { background: #f3edd9; color: #5d4a00; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8pt; }
    .grid-3 .cell {
        border: 1px solid #ddd; border-radius: 4pt; padding: 6pt 8pt;
        font-size: 10pt; break-inside: avoid; page-break-inside: avoid;
    }
    .grid-3 .num { font-size: 13pt; font-weight: 800; color: #0f1f3d; }
    .grid-3 .kind { font-size: 8pt; color: #6b4a06; text-transform: uppercase; letter-spacing: 0.06em; }
    .grid-3 .who  { font-size: 9pt; color: #4a5060; margin-top: 2pt; }
    @media print {
        @page { margin: 0.5in; }
        a { color: inherit; text-decoration: none; }
    }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<h1>Parking spots <?= $threeCol ? '<span style="font-size:11pt; color:#888;">(compact)</span>' : '' ?></h1>
<div class="meta"><?= count($spots) ?> active spot<?= count($spots)===1?'':'s' ?> · Printed <?= e(date('M j, Y')) ?></div>

<?php if (!$spots): ?>
    <p>No parking spots on file.</p>
<?php elseif ($threeCol): ?>
    <div class="grid-3">
    <?php foreach ($spots as $s):
        $unitLabel = !empty($s['unit_number']) ? ('Unit ' . $s['unit_number']) : '— unassigned —';
    ?>
        <div class="cell">
            <div class="kind"><?= e((string)$KINDS[$s['kind']]) ?></div>
            <div class="num"><?= e((string)$s['number']) ?></div>
            <div class="who">
                <?= e($unitLabel) ?>
                <?php if (!empty($s['primary_owner_name'])): ?>
                    · <?= e((string)$s['primary_owner_name']) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <table>
        <thead><tr><th>Kind</th><th>Number</th><th>Unit</th><th>Primary owner</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($spots as $s): ?>
            <tr>
                <td><?= e((string)$KINDS[$s['kind']]) ?></td>
                <td><strong><?= e((string)$s['number']) ?></strong></td>
                <td><?= !empty($s['unit_number']) ? e((string)$s['unit_number']) : '—' ?></td>
                <td><?= !empty($s['primary_owner_name']) ? e((string)$s['primary_owner_name']) : '' ?></td>
                <td><?= !empty($s['notes']) ? e(mb_strimwidth((string)$s['notes'], 0, 80, '…')) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?= print_footer_html('Printed ' . date('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

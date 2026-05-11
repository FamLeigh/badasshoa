<?php
// Print-friendly contacts list. Single flat table grouped by type with a
// colored Type pill on each row. `?include_board=1` adds a second section
// containing this association's board members + property managers (name +
// office + role + phone + email, skipping placeholder addresses) so the
// printed sheet doubles as a "who do I call" reference.
require __DIR__ . '/_bootstrap.php';

$includeBoard = isset($_GET['include_board']);

$KINDS = [
    'emergency'     => ['label' => 'Emergency',     'color' => '#a8322a'],
    'non_emergency' => ['label' => 'Non-emergency', 'color' => '#a8782a'],
    'utility'       => ['label' => 'Utility',       'color' => '#274988'],
    'contractor'    => ['label' => 'Contractor',    'color' => '#2f6296'],
    'other'         => ['label' => 'Other',         'color' => '#555555'],
];

$stmt = db()->prepare(
    "SELECT * FROM association_contacts
      WHERE association_id = ?
      ORDER BY FIELD(kind, 'emergency','non_emergency','utility','contractor','other'),
               sort_order, label"
);
$stmt->execute([$assocId]);
$contacts = $stmt->fetchAll();

$boardMembers = [];
if ($includeBoard) {
    $bStmt = db()->prepare(
        "SELECT first_name, last_name, role, board_office, email, phone, unit_number
           FROM users
          WHERE association_id = ?
            AND status = 'active'
            AND role IN ('board_admin','board_member','property_manager')
          ORDER BY FIELD(board_office,
                         'president','vice_president','secretary','treasurer',
                         'secretary_treasurer','director') = 0,
                   FIELD(board_office,
                         'president','vice_president','secretary','treasurer',
                         'secretary_treasurer','director'),
                   FIELD(role,'board_admin','property_manager','board_member'),
                   last_name, first_name"
    );
    $bStmt->execute([$assocId]);
    $boardMembers = $bStmt->fetchAll();
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Contacts — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.6in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; line-height: 1.4; }
    h1 { font-size: 22pt; margin: 0 0 0.25em; }
    h2 { font-size: 14pt; margin: 18pt 0 6pt; padding-bottom: 4pt; border-bottom: 2px solid #0f1f3d; color: #0f1f3d; }
    .meta { color: #555; font-size: 10pt; margin-bottom: 1em; }
    table { border-collapse: collapse; width: 100%; font-size: 10pt; margin-bottom: 6pt; }
    th, td { text-align: left; padding: 5pt 7pt; border-bottom: 1px solid #ddd; vertical-align: top; }
    th { background: #f3edd9; color: #5d4a00; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; }
    tr { break-inside: avoid; page-break-inside: avoid; }
    .pill { display: inline-block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em;
        padding: 1pt 6pt; border-radius: 999pt; color: #fff; white-space: nowrap; }
    .office { display: inline-block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em;
        padding: 1pt 6pt; border-radius: 999pt; color: #fff; background: #c25a1e; white-space: nowrap; }
    .role  { display: inline-block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em;
        padding: 1pt 6pt; border-radius: 999pt; color: #fff; background: #0f1f3d; white-space: nowrap; }
    .notes { color: #555; font-size: 9pt; margin-top: 2pt; }
    .disclaimer { font-size: 9pt; padding: 5pt 8pt; background: #fff6e0; border: 1px solid #d8b54d;
        border-radius: 3pt; margin: 8pt 0; }
    @media print {
        @page { margin: 0.5in; }
        a { color: inherit; text-decoration: none; }
    }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<h1>Contacts<?= $includeBoard ? ' + board' : '' ?></h1>
<div class="meta">
    <?= count($contacts) ?> contact<?= count($contacts)===1?'':'s' ?>
    <?php if ($includeBoard): ?> · <?= count($boardMembers) ?> board / management member<?= count($boardMembers)===1?'':'s' ?><?php endif; ?>
    · Printed <?= e(date('M j, Y')) ?>
</div>

<?php if (!$contacts): ?>
    <p>No contacts on file.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Type</th><th>Label</th><th>Trade</th><th>Phone</th><th>Email / Web</th></tr>
        </thead>
        <tbody>
        <?php
        $hasContractor = false;
        foreach ($contacts as $r):
            if ($r['kind'] === 'contractor') $hasContractor = true;
            $k = $KINDS[$r['kind']] ?? ['label' => $r['kind'], 'color' => '#555'];
        ?>
            <tr>
                <td><span class="pill" style="background: <?= e($k['color']) ?>;"><?= e($k['label']) ?></span></td>
                <td>
                    <strong><?= e((string)$r['label']) ?></strong>
                    <?php if (!empty($r['notes'])): ?>
                        <div class="notes"><?= e(mb_strimwidth((string)$r['notes'], 0, 160, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= !empty($r['trade']) ? e((string)$r['trade']) : '' ?></td>
                <td><?= !empty($r['phone']) ? e((string)$r['phone']) : '' ?></td>
                <td>
                    <?php if (!empty($r['email'])): ?><?= e((string)$r['email']) ?><br><?php endif; ?>
                    <?php if (!empty($r['url'])): ?><?= e((string)$r['url']) ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($hasContractor): ?>
        <div class="disclaimer">
            <strong>Disclaimer:</strong> Contractors listed above are listed as a convenience for residents.
            <?= e((string)$association['name']) ?> doesn't guarantee their work and isn't responsible for the quality, pricing, or outcome of any service performed. Get your own quotes and references.
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($includeBoard): ?>
    <h2>Board members &amp; management</h2>
    <?php if (!$boardMembers): ?>
        <p class="notes">No board members or property managers on file.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Office</th><th>Role</th><th>Name</th><th>Unit</th><th>Phone</th><th>Email</th></tr>
            </thead>
            <tbody>
            <?php foreach ($boardMembers as $m):
                $officeLbl = board_office_label((string)($m['board_office'] ?? ''));
                $roleLbl   = ucwords(str_replace('_', ' ', (string)$m['role']));
                $email     = is_placeholder_email((string)$m['email']) ? '' : (string)$m['email'];
            ?>
                <tr>
                    <td><?= $officeLbl !== '' ? '<span class="office">' . e($officeLbl) . '</span>' : '' ?></td>
                    <td><span class="role"><?= e($roleLbl) ?></span></td>
                    <td><strong><?= e(trim($m['first_name'] . ' ' . $m['last_name']) ?: '—') ?></strong></td>
                    <td><?= !empty($m['unit_number']) ? e((string)$m['unit_number']) : '' ?></td>
                    <td><?= !empty($m['phone']) ? e((string)$m['phone']) : '' ?></td>
                    <td><?= e($email) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<?= print_footer_html('Printed ' . date('M j, Y') . ($includeBoard ? ' · contacts + board' : ' · contacts')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

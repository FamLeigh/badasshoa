<?php
// Print-friendly events list. Range = ?range=day|week|month (default month).
// Honors the viewer's audience scope: tenants don't see board-only events.
// Recurring events are expanded via expand_events() then filtered to the range.
require __DIR__ . '/_bootstrap.php';

$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['day', 'week', 'month'], true)) $range = 'month';

$rangeLabels = [
    'day'   => 'Today',
    'week'  => 'This week',
    'month' => 'This month',
];

// Compute date window in the app's tz (UTC — see CLAUDE.md). All event
// timestamps are stored in UTC and compared as such.
$todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
switch ($range) {
    case 'day':
        $rangeStart = $todayStart;
        $rangeEnd   = $todayStart + 86400 - 1;
        $rangeTitle = 'Events for ' . date('l, F j, Y', $todayStart);
        break;
    case 'week':
        // Sunday → Saturday week.
        $dow = (int)date('w', $todayStart);
        $rangeStart = $todayStart - $dow * 86400;
        $rangeEnd   = $rangeStart + 7 * 86400 - 1;
        $rangeTitle = 'Events for the week of '
            . date('M j', $rangeStart) . ' – ' . date('M j, Y', $rangeEnd);
        break;
    case 'month':
    default:
        $rangeStart = strtotime(date('Y-m-01') . ' 00:00:00');
        $rangeEnd   = strtotime(date('Y-m-t') . ' 23:59:59');
        $rangeTitle = 'Events for ' . date('F Y', $rangeStart);
        break;
}

// Viewer-scope audience filter. Mirrors events.php logic so a tenant printing
// from a view-as preview gets the same list they see on screen.
$allowedAudiences = ['all', 'members'];
if (role_can_manage(viewing_role())) {
    $allowedAudiences[] = 'board';
}
$placeholders = implode(',', array_fill(0, count($allowedAudiences), '?'));

// Coarse SQL prune: keep anything that *could* land in the window. The PHP
// expansion does the fine-grained matching (recurring series share one row).
$sql = "SELECT * FROM events
         WHERE association_id = ?
           AND audience IN ($placeholders)
           AND ((recurrence_type = 'none' AND starts_at <= ?)
                OR (recurrence_type <> 'none'
                    AND starts_at <= ?
                    AND (recurrence_until IS NULL OR recurrence_until >= ?)))
         LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute(array_merge(
    [$assocId],
    $allowedAudiences,
    [date('Y-m-d H:i:s', $rangeEnd), date('Y-m-d H:i:s', $rangeEnd), date('Y-m-d', $rangeStart)]
));
$raw = $stmt->fetchAll();

// Expand recurring across a window big enough to cover any month boundary.
$expanded = expand_events($raw, false, 60);
// expand_events drops past occurrences (vs. now). For a "day" or "week" view
// the user expects to see *all* events in the window, including ones earlier
// today, so re-run a small past pass and merge.
$past = expand_events($raw, true, 60);

$all = array_merge($expanded, $past);
$events = array_filter($all, function ($e) use ($rangeStart, $rangeEnd) {
    $ts = strtotime((string)$e['starts_at']);
    return $ts !== false && $ts >= $rangeStart && $ts <= $rangeEnd;
});
usort($events, fn($a, $b) => strtotime((string)$a['starts_at']) <=> strtotime((string)$b['starts_at']));

// Group by date for readable printout.
$byDate = [];
foreach ($events as $e) {
    $key = date('Y-m-d', strtotime((string)$e['starts_at']));
    $byDate[$key][] = $e;
}

$audClass = [
    'all'     => '#2f7a3d',  // green-ish
    'members' => '#1f4f9c',  // blue-ish
    'board'   => '#5d3a8a',  // purple-ish
];
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Events (<?= e($rangeLabels[$range]) ?>) — <?= e((string)$association['name']) ?></title>
<style>
    @page { size: letter; margin: 0.6in; }
    body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; line-height: 1.4; }
    h1 { font-size: 22pt; margin: 0 0 0.25em; }
    h2 { font-size: 13pt; margin: 14pt 0 4pt; padding-bottom: 3pt; border-bottom: 1px solid #ccc; color: #0f1f3d; }
    .meta { color: #555; font-size: 10pt; margin-bottom: 1em; }
    .event { padding: 6pt 0; border-bottom: 1px dotted #ddd; break-inside: avoid; page-break-inside: avoid; }
    .event:last-child { border-bottom: none; }
    .event .time { font-weight: 700; color: #0f1f3d; font-size: 11pt; }
    .event .title { font-size: 12pt; font-weight: 600; margin: 1pt 0 2pt; }
    .event .meta-row { font-size: 9pt; color: #555; }
    .pill { display: inline-block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em;
        padding: 1pt 6pt; border-radius: 999pt; color: #fff; margin-right: 4pt; vertical-align: middle; }
    .recur { display:inline-block; font-size: 8pt; color:#6b4a06; background:#f3edd9;
        padding: 1pt 6pt; border-radius: 999pt; margin-left: 4pt; }
    .desc { font-size: 10pt; color: #333; white-space: pre-wrap; margin-top: 3pt; }
    @media print {
        @page { margin: 0.5in; }
        a { color: inherit; text-decoration: none; }
    }
</style>
</head><body style="padding: 0.5in;">

<?= print_header_html($association) ?>

<h1><?= e($rangeTitle) ?></h1>
<div class="meta"><?= count($events) ?> event<?= count($events)===1?'':'s' ?> · Printed <?= e(date('M j, Y')) ?></div>

<?php if (!$events): ?>
    <p>No events scheduled in this range.</p>
<?php else: ?>
    <?php foreach ($byDate as $dateKey => $dayEvents): ?>
        <h2><?= e(date('l, F j, Y', strtotime($dateKey))) ?></h2>
        <?php foreach ($dayEvents as $ev):
            $startTs = strtotime((string)$ev['starts_at']);
            $endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
            $sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
            $aud     = (string)($ev['audience'] ?? 'members');
            $color   = $audClass[$aud] ?? '#1f4f9c';
        ?>
            <div class="event">
                <div class="meta-row">
                    <span class="pill" style="background: <?= e($color) ?>;"><?= e($aud) ?></span>
                    <span class="time">
                        <?= e(date('g:i A', $startTs)) ?>
                        <?php if ($endTs): ?>
                            – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?>
                        <?php endif; ?>
                    </span>
                    <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                        <span class="recur">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="title"><?= e((string)$ev['title']) ?></div>
                <?php if (!empty($ev['location'])): ?>
                    <div class="meta-row">📍 <?= e((string)$ev['location']) ?></div>
                <?php endif; ?>
                <?php if (!empty($ev['description'])): ?>
                    <div class="desc"><?= e(mb_strimwidth((string)$ev['description'], 0, 400, '…')) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?= print_footer_html('Printed ' . date('M j, Y') . ' · ' . $rangeLabels[$range]) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

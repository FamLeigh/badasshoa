<?php
// Print-friendly events list. Three modes:
//   ?id=N                          — single event detail (or recurring series
//                                    with its next 12 occurrences)
//   ?range=day|week|month          — date-window list (default: month)
//   ?upcoming=1                    — all upcoming events (next 60 days)
// Honors the viewer's audience scope: tenants don't see board-only events.
// Recurring events are expanded via expand_events() then filtered to the range.
require __DIR__ . '/_bootstrap.php';

$singleId = (int)($_GET['id'] ?? 0);
$upcoming = isset($_GET['upcoming']);

// ---------- SINGLE EVENT MODE ----------
if ($singleId > 0) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND association_id = ?');
    $stmt->execute([$singleId, $assocId]);
    $ev = $stmt->fetch();
    if (!$ev) { http_response_code(404); echo 'Event not found.'; exit; }
    if (!role_can_manage(viewing_role()) && $ev['audience'] === 'board') {
        http_response_code(403); echo 'Forbidden'; exit;
    }
    $startTs = strtotime((string)$ev['starts_at']);
    $endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
    $sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
    $occurrences = [];
    if (($ev['recurrence_type'] ?? 'none') !== 'none') {
        $expanded = expand_events([$ev], false, 90);
        $occurrences = array_slice($expanded, 0, 12);
    }
    ?><!doctype html>
    <html lang="en"><head>
    <meta charset="utf-8">
    <title><?= e((string)$ev['title']) ?> — <?= e((string)$association['name']) ?></title>
    <style>
        @page { size: letter; margin: 0.5in; }
        body { font-family: Inter, system-ui, sans-serif; color: #111; margin: 0; line-height: 1.4; font-size: 10pt; }
        .date-block { display:inline-block; text-align:center; padding: 5pt 10pt; border: 2px solid #0f1f3d; border-radius: 6pt; background: #f8f7f4; vertical-align: middle; margin-right: 12pt; }
        .date-block .m { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em; color: #555; font-weight: 700; }
        .date-block .d { font-size: 26pt; line-height: 1; font-weight: 800; color: #0f1f3d; margin: 2pt 0; }
        .date-block .dow { font-size: 8pt; color: #555; }
        .head { display:flex; gap: 12pt; align-items: center; margin: 10pt 0 14pt; padding-bottom: 10pt; border-bottom: 2px solid #0f1f3d; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        .pill { display:inline-block; padding: 2pt 7pt; border-radius: 999pt; color: #fff; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 4pt; }
        .recur { display:inline-block; padding: 2pt 7pt; border-radius: 999pt; background: #f3edd9; color: #6b4a06; font-size: 8pt; }
        .when { color: #555; font-size: 10pt; margin-top: 3pt; }
        .ev-img { float: right; margin: 0 0 10pt 14pt; max-width: 2.6in; max-height: 2.6in; object-fit: contain; border: 1px solid #ddd; border-radius: 4pt; }
        .desc { font-size: 10pt; margin-bottom: 12pt; }
        .desc p { margin: 0 0 6pt; } .desc ul, .desc ol { margin: 0 0 6pt; padding-left: 1.2em; }
        .clearfix::after { content: ''; display: table; clear: both; }
        .occ h2 { font-size: 10pt; margin: 12pt 0 4pt; padding-bottom: 3pt; border-bottom: 1px solid #ccc; color: #0f1f3d; }
        .occ ul { padding-left: 1.2em; font-size: 9pt; columns: 2; column-gap: 1em; }
        @media print { a { color: inherit; text-decoration: none; } }
    </style>
    </head><body style="padding: 0.5in;">

    <?= print_header_html($association) ?>

    <div class="head">
        <div class="date-block">
            <div class="m"><?= e(date('M', $startTs)) ?></div>
            <div class="d"><?= e(date('j', $startTs)) ?></div>
            <div class="dow"><?= e(date('D', $startTs)) ?></div>
        </div>
        <div style="flex:1;">
            <div>
                <span class="pill" style="background: <?= e($ev['audience']==='all' ? '#2f7a3d' : ($ev['audience']==='board' ? '#5d3a8a' : '#1f4f9c')) ?>;"><?= e((string)$ev['audience']) ?></span>
                <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                    <span class="recur">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                <?php endif; ?>
            </div>
            <h1><?= e((string)$ev['title']) ?></h1>
            <div class="when">
                <?= e(date('l, F j, Y · g:i A', $startTs)) ?>
                <?php if ($endTs): ?> – <?= e(date($sameDay ? 'g:i A' : 'M j, Y g:i A', $endTs)) ?><?php endif; ?>
            </div>
            <?php if (!empty($ev['location'])): ?>
                <div class="when">📍 <?= e((string)$ev['location']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="desc clearfix">
        <?php if (!empty($ev['image_path'])): ?>
            <img class="ev-img" src="/event-image.php?id=<?= (int)$ev['id'] ?>" alt="">
        <?php endif; ?>
        <?php if (!empty($ev['description'])): ?>
            <?php $desc = (string)$ev['description'];
                  if (strpos($desc, '<') !== false): ?>
                <?= $desc /* Quill HTML — board-authored */ ?>
            <?php else: ?>
                <?= nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($occurrences): ?>
        <div class="occ">
            <h2>Upcoming occurrences</h2>
            <ul>
                <?php foreach ($occurrences as $occ): $oTs = strtotime((string)$occ['starts_at']); ?>
                    <li><?= e(date('D, M j, Y · g:i A', $oTs)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?= print_footer_html('Printed ' . date('M j, Y')) ?>

    <script>window.addEventListener('load', function(){ window.print(); });</script>
    </body></html>
    <?php
    exit;
}

// ---------- UPCOMING (next 60 days) MODE ----------
if ($upcoming) {
    $rangeStart = strtotime(date('Y-m-d') . ' 00:00:00');
    $rangeEnd   = $rangeStart + 60 * 86400;
    $rangeTitle = 'Upcoming events';
} else {

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
} /* end else (range mode) */

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
<title>Events (<?= e($upcoming ? 'upcoming' : ($rangeLabels[$range] ?? 'list')) ?>) — <?= e((string)$association['name']) ?></title>
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

<?= print_footer_html('Printed ' . date('M j, Y') . ' · ' . ($upcoming ? 'upcoming' : ($rangeLabels[$range] ?? ''))) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

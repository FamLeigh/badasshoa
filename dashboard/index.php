<?php
require __DIR__ . '/_bootstrap.php';

// Quick stats (tenant-scoped).
$stats = [];
// Registered units come from the units table (the structured source).
$unitsStmt = db()->prepare('SELECT COUNT(*) FROM units WHERE association_id = ?');
$unitsStmt->execute([$assocId]);
$stats['units'] = (int)$unitsStmt->fetchColumn();

// Members = everyone on file (active + pending — many imports come in as 'pending')
$memberCount = db()->prepare("SELECT COUNT(*) FROM users WHERE association_id = ? AND status <> 'inactive'");
$memberCount->execute([$assocId]);
$stats['members'] = (int)$memberCount->fetchColumn();

// Rules / bylaws / policies (all sources)
$ruleCount = db()->prepare('SELECT COUNT(*) FROM rules WHERE association_id = ?');
$ruleCount->execute([$assocId]);
$stats['rules'] = (int)$ruleCount->fetchColumn();

// Pending rule changes = member-submitted suggestions awaiting board action
// + rules the board flagged for review. Surfaced as a badge on the Rules tile.
$pendingSug = db()->prepare("SELECT COUNT(*) FROM rule_suggestions WHERE association_id = ? AND status = 'pending'");
$pendingSug->execute([$assocId]);
$flaggedRules = db()->prepare('SELECT COUNT(*) FROM rules WHERE association_id = ? AND review_flag = 1');
$flaggedRules->execute([$assocId]);
$stats['rule_changes_pending'] = (int)$pendingSug->fetchColumn() + (int)$flaggedRules->fetchColumn();

// Photos = media rows with an image MIME type
$photoCount = db()->prepare("SELECT COUNT(*) FROM media WHERE association_id = ? AND file_type LIKE 'image/%'");
$photoCount->execute([$assocId]);
$stats['photos'] = (int)$photoCount->fetchColumn();

// Total announcements (no time filter)
$annCount = db()->prepare('SELECT COUNT(*) FROM announcements WHERE association_id = ?');
$annCount->execute([$assocId]);
$stats['announcements'] = (int)$annCount->fetchColumn();

$docs = db()->prepare('SELECT COUNT(*) FROM documents WHERE association_id = ?');
$docs->execute([$assocId]);
$stats['documents'] = (int)$docs->fetchColumn();

$comms = db()->prepare('SELECT COUNT(*) FROM committees WHERE association_id = ?');
$comms->execute([$assocId]);
$stats['committees'] = (int)$comms->fetchColumn();

// Upcoming events (today and forward)
$evStmt = db()->prepare(
    'SELECT COUNT(*) FROM events
      WHERE association_id = ? AND starts_at >= NOW()'
);
$evStmt->execute([$assocId]);
$stats['events'] = (int)$evStmt->fetchColumn();

// Open concerns (anything not closed/resolved)
$conStmt = db()->prepare(
    "SELECT COUNT(*) FROM concerns
      WHERE association_id = ? AND status NOT IN ('closed','resolved')"
);
$conStmt->execute([$assocId]);
$stats['concerns_open'] = (int)$conStmt->fetchColumn();

$conTotalStmt = db()->prepare('SELECT COUNT(*) FROM concerns WHERE association_id = ?');
$conTotalStmt->execute([$assocId]);
$stats['concerns'] = (int)$conTotalStmt->fetchColumn();

$faqStmt = db()->prepare('SELECT COUNT(*) FROM faqs WHERE association_id = ?');
$faqStmt->execute([$assocId]);
$stats['faqs'] = (int)$faqStmt->fetchColumn();

// Latest 5 announcements.
$annStmt = db()->prepare(
    'SELECT a.id, a.title, a.body, a.type, a.published_at,
            CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
     FROM announcements a LEFT JOIN users u ON u.id = a.author_id
     WHERE a.association_id = ?
     ORDER BY a.published_at DESC LIMIT 5'
);
$annStmt->execute([$assocId]);
$announcements = $annStmt->fetchAll();

// Upcoming events (next ~60 days, expanded for recurring). Honors viewer
// audience scope so view-as preview matches what tenants would see.
$allowedAudiences = ['all', 'members'];
if (role_can_manage(viewing_role())) {
    $allowedAudiences[] = 'board';
}
$evPh = implode(',', array_fill(0, count($allowedAudiences), '?'));
$upStmt = db()->prepare(
    "SELECT * FROM events
      WHERE association_id = ?
        AND audience IN ($evPh)
        AND ((recurrence_type = 'none' AND starts_at >= NOW())
             OR (recurrence_type <> 'none'
                 AND (recurrence_until IS NULL OR recurrence_until >= CURDATE())))
      LIMIT 100"
);
$upStmt->execute(array_merge([$assocId], $allowedAudiences));
$upcomingEvents = expand_events($upStmt->fetchAll(), false, 60);
if (count($upcomingEvents) > 5) $upcomingEvents = array_slice($upcomingEvents, 0, 5);

$user = current_user();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: var(--sp-8); padding-bottom: var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <span class="badge badge--orange"><?= e($association['name']) ?></span>
            <h1 style="font-size: var(--fs-3xl); margin: var(--sp-3) 0 var(--sp-1);"><?= e($greet) ?>, <?= e($user['first_name'] ?: 'there') ?>.</h1>
            <p class="muted">Here&rsquo;s what&rsquo;s happening at <?= e($association['name']) ?> today.</p>
        </div>
        <div class="row">
            <a class="btn btn--ghost" href="/dashboard/communications.php?action=new">Post announcement</a>
            <a class="btn btn--primary" href="/dashboard/documents.php?action=new">Upload document</a>
        </div>
    </div>

    <div class="dashboard-stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--sp-3); margin-bottom: var(--sp-8);">
        <a class="stat" href="/dashboard/directory.php">
            <div class="stat__label">Members</div>
            <div class="stat__value"><?= (int)$stats['members'] ?></div>
        </a>
        <a class="stat" href="/dashboard/units.php">
            <div class="stat__label">Units</div>
            <div class="stat__value"><?= (int)$stats['units'] ?></div>
        </a>
        <a class="stat" href="/dashboard/search.php" style="position: relative;">
            <div class="stat__label">Rules</div>
            <div class="stat__value"><?= (int)$stats['rules'] ?></div>
            <?php if ($stats['rule_changes_pending'] > 0): ?>
                <div style="margin-top: 4px;">
                    <span class="badge badge--orange" style="font-size: var(--fs-xs);">🚩 <?= (int)$stats['rule_changes_pending'] ?> pending</span>
                </div>
            <?php endif; ?>
        </a>
        <a class="stat" href="/dashboard/documents.php">
            <div class="stat__label">Documents</div>
            <div class="stat__value"><?= (int)$stats['documents'] ?></div>
        </a>
        <a class="stat" href="/dashboard/media.php">
            <div class="stat__label">Photos</div>
            <div class="stat__value"><?= (int)$stats['photos'] ?></div>
        </a>
        <a class="stat" href="/dashboard/communications.php">
            <div class="stat__label">Posts</div>
            <div class="stat__value"><?= (int)$stats['announcements'] ?></div>
        </a>
        <a class="stat" href="/dashboard/committees.php">
            <div class="stat__label">Committees</div>
            <div class="stat__value"><?= (int)$stats['committees'] ?></div>
        </a>
        <a class="stat" href="/dashboard/events.php">
            <div class="stat__label">Events</div>
            <div class="stat__value"><?= (int)$stats['events'] ?></div>
            <?php if ($stats['events'] > 0): ?>
                <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;">upcoming</div>
            <?php endif; ?>
        </a>
        <a class="stat" href="/dashboard/concerns.php" style="position: relative;">
            <div class="stat__label">Concerns</div>
            <div class="stat__value"><?= (int)$stats['concerns'] ?></div>
            <?php if ($stats['concerns_open'] > 0): ?>
                <div style="margin-top: 4px;">
                    <span class="badge badge--orange" style="font-size: var(--fs-xs);"><?= (int)$stats['concerns_open'] ?> open</span>
                </div>
            <?php endif; ?>
        </a>
        <a class="stat" href="/dashboard/faq.php">
            <div class="stat__label">FAQs</div>
            <div class="stat__value"><?= (int)$stats['faqs'] ?></div>
        </a>
    </div>

    <div class="dash-split" style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--sp-5); align-items: start;">

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Recent announcements</h2>
                <a href="/dashboard/communications.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
            </div>
            <?php if (!$announcements): ?>
                <p class="muted">No announcements yet. <a href="/dashboard/communications.php?action=new">Post the first one</a>.</p>
            <?php else: ?>
                <div class="stack-lg">
                <?php foreach ($announcements as $a): ?>
                    <div>
                        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1);">
                            <span class="badge <?= $a['type'] === 'emergency' ? 'badge--error' : ($a['type'] === 'event' ? 'badge--info' : 'badge--orange') ?>">
                                <?= e($a['type']) ?>
                            </span>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(date('M j, Y', strtotime($a['published_at']))) ?> &middot; <?= e(trim($a['author']) ?: 'Unknown') ?></span>
                        </div>
                        <strong><?= e($a['title']) ?></strong>
                        <p class="muted" style="margin: var(--sp-1) 0 0; font-size: var(--fs-sm);">
                            <?= e(mb_strimwidth(strip_tags($a['body']), 0, 160, '…')) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Upcoming events</h2>
                <a href="/dashboard/events.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
            </div>
            <?php if (!$upcomingEvents): ?>
                <p class="muted">No upcoming events. <a href="/dashboard/events.php?action=new">Add one</a>.</p>
            <?php else: ?>
                <div class="stack-lg">
                <?php foreach ($upcomingEvents as $ev):
                    $startTs = strtotime((string)$ev['starts_at']);
                    $endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
                    $sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
                    $audClass = match ($ev['audience']) {
                        'all'     => 'badge--success',
                        'board'   => 'badge--navy',
                        default   => 'badge--info',
                    };
                ?>
                    <div>
                        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                            <span class="badge <?= $audClass ?>"><?= e((string)$ev['audience']) ?></span>
                            <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                                <span class="badge" style="background: var(--color-surface); color: var(--color-text-soft); font-size: var(--fs-xs);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                            <?php endif; ?>
                            <span class="muted" style="font-size: var(--fs-xs);">
                                <?= e(date('D, M j · g:i A', $startTs)) ?>
                                <?php if ($endTs): ?> – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?><?php endif; ?>
                            </span>
                        </div>
                        <strong><?= e((string)$ev['title']) ?></strong>
                        <?php if (!empty($ev['location'])): ?>
                            <p class="muted" style="margin: var(--sp-1) 0 0; font-size: var(--fs-sm);">📍 <?= e((string)$ev['location']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<style>
    @media (max-width: 800px) { .dash-split { grid-template-columns: 1fr !important; } }
</style>

<?php require __DIR__ . '/../includes/footer.php'; ?>

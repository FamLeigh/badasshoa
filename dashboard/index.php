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

// Board members + property managers (anyone who runs the association)
$boardCount = db()->prepare(
    "SELECT COUNT(*) FROM users
      WHERE association_id = ?
        AND status <> 'inactive'
        AND role IN ('board_admin','board_member','property_manager')"
);
$boardCount->execute([$assocId]);
$stats['board'] = (int)$boardCount->fetchColumn();

// Upcoming events count is computed AFTER the upcoming-events expansion below
// (a single recurring series seeded back in March still produces future
// occurrences — a naive `starts_at >= NOW()` count misses those). $stats['events']
// is filled in once $upcomingEvents is available.
$stats['events'] = 0;

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
$expandedUpcoming = expand_events($upStmt->fetchAll(), false, 60);
// Tile count = total expanded upcoming occurrences in the 60-day window
// (this includes every recurring occurrence, so a weekly meeting counts once
// per upcoming week, matching what's actually on the schedule).
$stats['events']  = count($expandedUpcoming);
$upcomingEvents   = array_slice($expandedUpcoming, 0, 5);

$user = current_user();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: var(--sp-8); padding-bottom: var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6); align-items: flex-start; gap: var(--sp-4); flex-wrap: wrap;">
        <div>
            <span class="badge badge--orange"><?= e($association['name']) ?></span>
            <h1 style="font-size: var(--fs-3xl); margin: var(--sp-3) 0 var(--sp-1);"><span data-greet><?= e($greet) ?></span>, <?= e($user['first_name'] ?: 'there') ?>.</h1>
            <p class="muted" style="margin: 0;">Here&rsquo;s what&rsquo;s happening at <?= e($association['name']) ?> today.</p>
        </div>
        <div style="text-align: right;">
            <div data-now-time style="font-size: var(--fs-2xl); font-weight: 700; color: var(--color-navy); line-height: 1.1; font-variant-numeric: tabular-nums;">—</div>
            <div data-now-date class="muted" style="font-size: var(--fs-sm); margin-top: 2px;">—</div>
            <div class="row" style="gap: var(--sp-2); margin-top: var(--sp-3); justify-content: flex-end;">
                <a class="btn btn--ghost"   href="/dashboard/communications.php?action=new">Post announcement</a>
                <a class="btn btn--primary" href="/dashboard/documents.php?action=new">Upload document</a>
            </div>
        </div>
    </div>
    <script>
        // Greeting + live clock — both derived from the browser's local time
        // because the server is UTC-pinned (CLAUDE.md). Time refreshes every
        // 30s so the user sees a live clock while sitting on the dashboard.
        (function () {
            var gEl = document.querySelector('[data-greet]');
            var tEl = document.querySelector('[data-now-time]');
            var dEl = document.querySelector('[data-now-date]');
            function tick() {
                var d = new Date();
                if (gEl) {
                    var h = d.getHours();
                    gEl.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
                }
                if (tEl) tEl.textContent = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                if (dEl) dEl.textContent = d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
            }
            tick();
            setInterval(tick, 30000);
        })();
    </script>

    <style>
        /* 4-up tile grid that collapses gracefully on narrow viewports. */
        .dashboard-stats { display:grid; grid-template-columns: repeat(4, 1fr); gap: var(--sp-3); margin-bottom: var(--sp-8); }
        @media (max-width: 900px) { .dashboard-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 500px) { .dashboard-stats { grid-template-columns: 1fr; } }
        /* Tile internals — icon left, label + value right. Hover gets a subtle lift. */
        .stat { position: relative; display:flex; align-items:center; gap: var(--sp-3); padding: var(--sp-3) var(--sp-4); transition: transform 120ms ease, box-shadow 120ms ease; }
        .stat:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(15,31,61,0.10); }
        .stat__icon { font-size: 28px; line-height: 1; flex: 0 0 36px; }
        .stat__body { display:flex; flex-direction: column; min-width: 0; }
        .stat__label { font-size: var(--fs-xs); color: var(--color-text-soft); text-transform: uppercase; letter-spacing: 0.06em; }
        .stat__value { font-size: var(--fs-xl); font-weight: 800; color: var(--color-navy); line-height: 1.1; font-variant-numeric: tabular-nums; }
        .stat__hint  { font-size: var(--fs-xs); color: var(--color-text-soft); margin-top: 2px; }
        .stat--alert { border-left: 3px solid var(--color-orange); }
    </style>
    <div class="dashboard-stats">
        <a class="stat" href="/dashboard/directory.php">
            <div class="stat__icon">👥</div>
            <div class="stat__body">
                <div class="stat__label">Members</div>
                <div class="stat__value"><?= (int)$stats['members'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/units.php">
            <div class="stat__icon">🏠</div>
            <div class="stat__body">
                <div class="stat__label">Units</div>
                <div class="stat__value"><?= (int)$stats['units'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/directory.php#board" title="Board members + property manager">
            <div class="stat__icon">🎩</div>
            <div class="stat__body">
                <div class="stat__label">Board &amp; mgmt</div>
                <div class="stat__value"><?= (int)$stats['board'] ?></div>
                <div class="stat__hint">incl. PM</div>
            </div>
        </a>
        <a class="stat" href="/dashboard/search.php">
            <div class="stat__icon">📜</div>
            <div class="stat__body">
                <div class="stat__label">Rules</div>
                <div class="stat__value"><?= (int)$stats['rules'] ?></div>
            </div>
        </a>
        <a class="stat<?= $stats['rule_changes_pending'] > 0 ? ' stat--alert' : '' ?>" href="/dashboard/search.php?action=suggestions" title="Pending rule suggestions + flagged-for-review">
            <div class="stat__icon">🚩</div>
            <div class="stat__body">
                <div class="stat__label">Pending rules</div>
                <div class="stat__value"><?= (int)$stats['rule_changes_pending'] ?></div>
                <?php if ($stats['rule_changes_pending'] > 0): ?>
                    <div class="stat__hint">awaiting review</div>
                <?php endif; ?>
            </div>
        </a>
        <a class="stat" href="/dashboard/documents.php">
            <div class="stat__icon">📄</div>
            <div class="stat__body">
                <div class="stat__label">Documents</div>
                <div class="stat__value"><?= (int)$stats['documents'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/media.php">
            <div class="stat__icon">📷</div>
            <div class="stat__body">
                <div class="stat__label">Photos</div>
                <div class="stat__value"><?= (int)$stats['photos'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/communications.php">
            <div class="stat__icon">📣</div>
            <div class="stat__body">
                <div class="stat__label">Announcements</div>
                <div class="stat__value"><?= (int)$stats['announcements'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/committees.php">
            <div class="stat__icon">🤝</div>
            <div class="stat__body">
                <div class="stat__label">Committees</div>
                <div class="stat__value"><?= (int)$stats['committees'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/events.php">
            <div class="stat__icon">📅</div>
            <div class="stat__body">
                <div class="stat__label">Events</div>
                <div class="stat__value"><?= (int)$stats['events'] ?></div>
                <?php if ($stats['events'] > 0): ?>
                    <div class="stat__hint">upcoming</div>
                <?php endif; ?>
            </div>
        </a>
        <a class="stat<?= $stats['concerns_open'] > 0 ? ' stat--alert' : '' ?>" href="/dashboard/concerns.php">
            <div class="stat__icon">💬</div>
            <div class="stat__body">
                <div class="stat__label">Concerns</div>
                <div class="stat__value"><?= (int)$stats['concerns'] ?></div>
                <?php if ($stats['concerns_open'] > 0): ?>
                    <div class="stat__hint"><?= (int)$stats['concerns_open'] ?> open</div>
                <?php endif; ?>
            </div>
        </a>
        <a class="stat" href="/dashboard/faq.php">
            <div class="stat__icon">❓</div>
            <div class="stat__body">
                <div class="stat__label">FAQs</div>
                <div class="stat__value"><?= (int)$stats['faqs'] ?></div>
            </div>
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
                <div class="dash-list">
                <?php foreach ($announcements as $a):
                    $aTs = strtotime((string)$a['published_at']);
                    $typeBadge = $a['type'] === 'emergency' ? 'badge--error' : ($a['type'] === 'event' ? 'badge--info' : 'badge--orange');
                ?>
                    <a class="dash-row" href="/dashboard/communications.php?id=<?= (int)$a['id'] ?>">
                        <div class="dash-date">
                            <div class="m"><?= e(date('M', $aTs)) ?></div>
                            <div class="d"><?= e(date('j', $aTs)) ?></div>
                        </div>
                        <div class="dash-body">
                            <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                                <span class="badge <?= $typeBadge ?>" style="font-size: var(--fs-xs);"><?= e($a['type']) ?></span>
                                <span class="muted" style="font-size: var(--fs-xs);"><?= e(date('g:i A', $aTs)) ?> · <?= e(trim($a['author']) ?: 'Unknown') ?></span>
                            </div>
                            <strong><?= e($a['title']) ?></strong>
                            <p class="muted" style="margin: 2px 0 0; font-size: var(--fs-sm);"><?= e(mb_strimwidth(strip_tags($a['body']), 0, 120, '…')) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Upcoming events</h2>
                <div class="row" style="gap: var(--sp-3);">
                    <a href="/dashboard/events-print.php?upcoming=1" target="_blank" rel="noopener" class="muted" style="font-size: var(--fs-sm);" title="Print the upcoming events list">🖨 Print</a>
                    <a href="/dashboard/events.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
                </div>
            </div>
            <?php if (!$upcomingEvents): ?>
                <p class="muted">No upcoming events. <a href="/dashboard/events.php?action=new">Add one</a>.</p>
            <?php else: ?>
                <div class="dash-list">
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
                    <a class="dash-row" href="/dashboard/event.php?id=<?= (int)$ev['id'] ?>">
                        <div class="dash-date">
                            <div class="m"><?= e(date('M', $startTs)) ?></div>
                            <div class="d"><?= e(date('j', $startTs)) ?></div>
                            <div class="dow"><?= e(date('D', $startTs)) ?></div>
                        </div>
                        <div class="dash-body">
                            <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                                <span class="badge <?= $audClass ?>" style="font-size: var(--fs-xs);"><?= e((string)$ev['audience']) ?></span>
                                <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                                    <span class="badge" style="background: var(--color-surface); color: var(--color-text-soft); font-size: var(--fs-xs);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                                <?php endif; ?>
                                <span class="muted" style="font-size: var(--fs-xs);">
                                    <?= e(date('g:i A', $startTs)) ?>
                                    <?php if ($endTs): ?> – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?><?php endif; ?>
                                </span>
                            </div>
                            <strong><?= e((string)$ev['title']) ?></strong>
                            <?php if (!empty($ev['location'])): ?>
                                <p class="muted" style="margin: 2px 0 0; font-size: var(--fs-sm);">📍 <?= e((string)$ev['location']) ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<style>
    @media (max-width: 800px) { .dash-split { grid-template-columns: 1fr !important; } }
    /* Date-first clickable cards in dashboard columns */
    .dash-list { display:flex; flex-direction: column; gap: var(--sp-3); }
    .dash-row {
        display:flex; gap: var(--sp-3); align-items: flex-start;
        padding: var(--sp-3); border: 1px solid var(--color-border); border-radius: var(--r-md);
        background: var(--color-surface-2);
        text-decoration: none; color: inherit;
        transition: transform 120ms ease, box-shadow 120ms ease;
    }
    .dash-row:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(15,31,61,0.08); }
    .dash-date {
        flex: 0 0 56px; text-align: center; padding: 4px 6px;
        border: 2px solid var(--color-navy); border-radius: 6px; background: #fff;
    }
    .dash-date .m  { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-soft); font-weight: 700; }
    .dash-date .d  { font-size: 20pt; line-height: 1; font-weight: 800; color: var(--color-navy); margin: 1px 0; }
    .dash-date .dow{ font-size: 8pt; color: var(--color-text-soft); }
    .dash-body { flex: 1 1 auto; min-width: 0; }
</style>

<?php require __DIR__ . '/../includes/footer.php'; ?>

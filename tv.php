<?php
// Lobby TV / digital signage — no login required, token-authenticated.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') { http_response_code(404); die('Not found.'); }

$stmt = db()->prepare('SELECT * FROM associations WHERE tv_token = ? AND status IN ("active","trial") LIMIT 1');
$stmt->execute([$token]);
$assoc = $stmt->fetch();
if (!$assoc) { http_response_code(404); die('Not found.'); }

$assocId = (int)$assoc['id'];

// Active announcements (all-audience, non-expired)
$anns = db()->prepare(
    "SELECT title, body, type, published_at FROM announcements
      WHERE association_id = ? AND audience = 'all'
        AND published_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())
      ORDER BY type = 'emergency' DESC, published_at DESC
      LIMIT 20"
);
$anns->execute([$assocId]);
$announcements = $anns->fetchAll();

// Upcoming events (next 30 days, all-audience) — expand recurrences in PHP.
$evts = db()->prepare(
    "SELECT title, starts_at, ends_at, location, recurrence_type, recurrence_until
       FROM events
      WHERE association_id = ? AND audience = 'all'
        AND (
            (recurrence_type = 'none' AND starts_at >= NOW() AND starts_at <= NOW() + INTERVAL 30 DAY)
            OR
            (recurrence_type <> 'none' AND (recurrence_until IS NULL OR recurrence_until >= CURDATE()))
        )
      ORDER BY starts_at LIMIT 50"
);
$evts->execute([$assocId]);
$rawEvents = $evts->fetchAll();

$now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$horizon  = $now->modify('+30 days');
$events   = [];

foreach ($rawEvents as $ev) {
    $start = new DateTimeImmutable((string)$ev['starts_at'], new DateTimeZone('UTC'));
    $end   = !empty($ev['ends_at']) ? new DateTimeImmutable((string)$ev['ends_at'], new DateTimeZone('UTC')) : null;
    $until = !empty($ev['recurrence_until'])
        ? new DateTimeImmutable($ev['recurrence_until'] . ' 23:59:59', new DateTimeZone('UTC'))
        : $horizon;
    $rtype = (string)$ev['recurrence_type'];

    if ($rtype === 'none') {
        if ($start >= $now && $start <= $horizon) {
            $events[] = [
                'title'     => $ev['title'],
                'location'  => $ev['location'],
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at'   => $end ? $end->format('Y-m-d H:i:s') : null,
            ];
        }
        continue;
    }

    $intervals = [
        'daily'     => 'P1D',
        'weekly'    => 'P7D',
        'biweekly'  => 'P14D',
        'monthly'   => 'P1M',
    ];
    $step = $intervals[$rtype] ?? null;
    if (!$step) continue;

    // Walk from the series start until we find occurrences in the window.
    $cur = $start;
    $endUntil = $until < $horizon ? $until : $horizon;
    $safety = 0;
    while ($cur <= $endUntil && $safety++ < 200) {
        if ($cur >= $now) {
            $duration = $end ? ($end->getTimestamp() - $start->getTimestamp()) : 0;
            $events[] = [
                'title'     => $ev['title'],
                'location'  => $ev['location'],
                'starts_at' => $cur->format('Y-m-d H:i:s'),
                'ends_at'   => $duration > 0 ? $cur->modify("+{$duration} seconds")->format('Y-m-d H:i:s') : null,
            ];
        }
        $cur = $cur->add(new DateInterval($step));
    }
}

// Sort by starts_at and cap at 20.
usort($events, fn($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
$events = array_slice($events, 0, 20);

// Active marketplace listings
$mkt = db()->prepare(
    "SELECT m.title, m.description, m.price_cents, m.category, m.condition_label,
            u.first_name
       FROM marketplace_listings m
       JOIN users u ON u.id = m.seller_user_id
      WHERE m.association_id = ? AND m.status = 'active'
      ORDER BY m.created_at DESC
      LIMIT 20"
);
$mkt->execute([$assocId]);
$listings = $mkt->fetchAll();

$hasLogo  = !empty($assoc['logo_path']);
$primary  = preg_match('/^#[0-9a-f]{6}$/i', (string)$assoc['primary_color']) ? $assoc['primary_color'] : '#0f1f3d';
$refreshSec = 60;

$CONDITION_LABELS = [
    'new' => 'New', 'like_new' => 'Like new', 'good' => 'Good',
    'fair' => 'Fair', 'for_parts' => 'For parts',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="<?= $refreshSec ?>">
<title><?= e((string)$assoc['name']) ?> — Community Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --primary: <?= e($primary) ?>;
    --bg:      #08111f;
    --panel:   #111c2e;
    --card:    #182438;
    --border:  rgba(255,255,255,.09);
    --text:    #fff;
    --muted:   rgba(255,255,255,.5);
    --orange:  #f05a28;
    --green:   #22c55e;
    --red:     #ef4444;
    --amber:   #f59e0b;
    --r:       14px;
}

html, body {
    width: 100%; height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    overflow: hidden;
}

/* ── Full-screen grid ─────────────────────────────────────── */
body {
    display: grid;
    grid-template-rows: auto 1fr auto;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    padding: 28px 32px;
    height: 100vh;
}

/* ── Header ───────────────────────────────────────────────── */
header {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
    padding-bottom: 16px;
}
.brand { display: flex; align-items: center; gap: 18px; }
.brand img { max-height: 60px; object-fit: contain; }
.brand-name { font-size: clamp(1.6rem, 2.4vw, 2.4rem); font-weight: 900; letter-spacing: -0.03em; }
.brand-tag {
    font-size: clamp(.75rem, 1.1vw, 1rem);
    font-weight: 700;
    color: var(--muted);
    letter-spacing: .08em;
    text-transform: uppercase;
    padding-left: 2px;
}
.clock-block { text-align: right; }
.clock {
    font-size: clamp(2.8rem, 5.5vw, 5rem);
    font-weight: 900;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.04em;
    color: var(--primary);
    line-height: 1;
}
.dateline {
    font-size: clamp(.85rem, 1.3vw, 1.15rem);
    color: var(--muted);
    margin-top: 4px;
    font-weight: 600;
}

/* ── Columns ──────────────────────────────────────────────── */
.col {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--r);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}
.col-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 22px 12px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.col-icon { font-size: 1.4rem; }
.col-label {
    font-size: clamp(.85rem, 1.2vw, 1.1rem);
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
}
.col-count {
    margin-left: auto;
    font-size: .75rem;
    font-weight: 700;
    color: var(--muted);
    background: var(--border);
    padding: 2px 8px;
    border-radius: 999px;
}

/* ── Scroll viewport ──────────────────────────────────────── */
.scroll-viewport {
    flex: 1;
    overflow: hidden;
    position: relative;
    min-height: 0;
}
.scroll-track {
    padding: 16px 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Cards ────────────────────────────────────────────────── */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px 20px;
    flex-shrink: 0;
}
.card--emergency {
    border-color: var(--red);
    background: rgba(239,68,68,.14);
}

/* ── Announcement card ────────────────────────────────────── */
.ann-badge {
    display: inline-block;
    font-size: clamp(.6rem, .9vw, .8rem);
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 6px;
    margin-bottom: 10px;
    background: var(--border);
    color: var(--text);
}
.ann-badge--emergency   { background: var(--red); }
.ann-badge--maintenance { background: var(--amber); color: #000; }
.ann-badge--info,
.ann-badge--general     { background: var(--primary); }
.ann-badge--event       { background: #7c3aed; }

.ann-title {
    font-size: clamp(1.1rem, 1.8vw, 1.6rem);
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 8px;
}
.ann-body {
    font-size: clamp(.9rem, 1.3vw, 1.2rem);
    color: var(--muted);
    line-height: 1.55;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ann-date {
    font-size: clamp(.7rem, 1vw, .9rem);
    color: rgba(255,255,255,.3);
    margin-top: 10px;
    font-weight: 600;
}

/* ── Event card ───────────────────────────────────────────── */
.evt-item { display: flex; gap: 18px; align-items: flex-start; }
.evt-cal {
    flex-shrink: 0;
    width: clamp(52px, 7vw, 72px);
    text-align: center;
    background: var(--primary);
    border-radius: 10px;
    padding: 8px 6px;
}
.evt-cal .m {
    font-size: clamp(.6rem, .9vw, .8rem);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: rgba(255,255,255,.75);
}
.evt-cal .d {
    font-size: clamp(1.7rem, 3vw, 2.6rem);
    font-weight: 900;
    line-height: 1;
    color: #fff;
}
.evt-info { flex: 1; min-width: 0; }
.evt-title {
    font-size: clamp(1.05rem, 1.7vw, 1.5rem);
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 6px;
}
.evt-meta {
    font-size: clamp(.8rem, 1.15vw, 1.05rem);
    color: var(--muted);
    line-height: 1.4;
}
.evt-loc { margin-top: 3px; color: rgba(255,255,255,.35); }

/* ── Marketplace card ─────────────────────────────────────── */
.mkt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.mkt-title {
    font-size: clamp(1.05rem, 1.7vw, 1.5rem);
    font-weight: 800;
    line-height: 1.2;
    flex: 1;
}
.mkt-price {
    font-size: clamp(1rem, 1.6vw, 1.4rem);
    font-weight: 900;
    color: var(--green);
    white-space: nowrap;
    flex-shrink: 0;
}
.mkt-price--free { color: var(--orange); }
.mkt-desc {
    font-size: clamp(.85rem, 1.2vw, 1.1rem);
    color: var(--muted);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 10px;
}
.mkt-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.mkt-tag {
    font-size: clamp(.6rem, .85vw, .75rem);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 3px 9px;
    border-radius: 6px;
    background: var(--border);
    color: var(--muted);
}

/* ── Empty state ──────────────────────────────────────────── */
.empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
    font-size: clamp(1rem, 1.4vw, 1.2rem);
    font-style: italic;
}

/* ── Footer ───────────────────────────────────────────────── */
footer {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--border);
    padding-top: 12px;
}
.footer-note {
    font-size: clamp(.6rem, .9vw, .8rem);
    color: rgba(255,255,255,.2);
    font-weight: 600;
    letter-spacing: .04em;
}
</style>
</head>
<body>

<header>
    <div class="brand">
        <?php if ($hasLogo): ?>
            <img src="/branding.php?id=<?= $assocId ?>" alt="<?= e((string)$assoc['name']) ?>">
        <?php else: ?>
            <div>
                <div class="brand-name"><?= e((string)$assoc['name']) ?></div>
                <div class="brand-tag">Community Board</div>
            </div>
        <?php endif; ?>
    </div>
    <div class="clock-block">
        <div class="clock" id="clock">--:--</div>
        <div class="dateline" id="dateline"></div>
    </div>
</header>

<!-- Announcements -->
<div class="col">
    <div class="col-header">
        <span class="col-icon">📢</span>
        <span class="col-label">Announcements</span>
        <?php if ($announcements): ?>
            <span class="col-count"><?= count($announcements) ?></span>
        <?php endif; ?>
    </div>
    <div class="scroll-viewport" id="vp-ann">
        <div class="scroll-track" id="tr-ann">
        <?php if (!$announcements): ?>
            <div class="empty">No active announcements.</div>
        <?php else: foreach ($announcements as $a):
            $isEmergency = $a['type'] === 'emergency';
        ?>
            <div class="card <?= $isEmergency ? 'card--emergency' : '' ?>">
                <span class="ann-badge ann-badge--<?= e((string)$a['type']) ?>"><?= e((string)$a['type']) ?></span>
                <div class="ann-title"><?= e((string)$a['title']) ?></div>
                <?php $body = trim(strip_tags((string)$a['body'])); if ($body): ?>
                    <div class="ann-body"><?= e($body) ?></div>
                <?php endif; ?>
                <div class="ann-date"><?= udate('M j, Y', strtotime((string)$a['published_at'])) ?></div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Events -->
<div class="col">
    <div class="col-header">
        <span class="col-icon">📅</span>
        <span class="col-label">Upcoming Events</span>
        <?php if ($events): ?>
            <span class="col-count"><?= count($events) ?></span>
        <?php endif; ?>
    </div>
    <div class="scroll-viewport" id="vp-evt">
        <div class="scroll-track" id="tr-evt">
        <?php if (!$events): ?>
            <div class="empty">No upcoming events.</div>
        <?php else: foreach ($events as $ev):
            $ts    = strtotime((string)$ev['starts_at']);
            $endTs = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
        ?>
            <div class="card">
                <div class="evt-item">
                    <div class="evt-cal">
                        <div class="m"><?= udate('M', $ts) ?></div>
                        <div class="d"><?= udate('j', $ts) ?></div>
                    </div>
                    <div class="evt-info">
                        <div class="evt-title"><?= e((string)$ev['title']) ?></div>
                        <div class="evt-meta">
                            <?= udate('g:i A', $ts) ?>
                            <?php if ($endTs): ?> – <?= udate($endTs - $ts < 86400 ? 'g:i A' : 'M j, g:i A', $endTs) ?><?php endif; ?>
                        </div>
                        <?php if (!empty($ev['location'])): ?>
                            <div class="evt-meta evt-loc"><?= e((string)$ev['location']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Marketplace -->
<div class="col">
    <div class="col-header">
        <span class="col-icon">🏷️</span>
        <span class="col-label">Marketplace</span>
        <?php if ($listings): ?>
            <span class="col-count"><?= count($listings) ?></span>
        <?php endif; ?>
    </div>
    <div class="scroll-viewport" id="vp-mkt">
        <div class="scroll-track" id="tr-mkt">
        <?php if (!$listings): ?>
            <div class="empty">No active listings.</div>
        <?php else: foreach ($listings as $item):
            $isFree  = ($item['price_cents'] === null || (int)$item['price_cents'] === 0);
            $price   = $isFree ? 'FREE' : '$' . number_format((int)$item['price_cents'] / 100, 2);
            $condLbl = $CONDITION_LABELS[$item['condition_label']] ?? $item['condition_label'];
            $catLbl  = ucfirst((string)$item['category']);
        ?>
            <div class="card">
                <div class="mkt-head">
                    <div class="mkt-title"><?= e((string)$item['title']) ?></div>
                    <div class="mkt-price <?= $isFree ? 'mkt-price--free' : '' ?>"><?= $price ?></div>
                </div>
                <?php $desc = trim((string)$item['description']); if ($desc): ?>
                    <div class="mkt-desc"><?= e($desc) ?></div>
                <?php endif; ?>
                <div class="mkt-tags">
                    <span class="mkt-tag"><?= e($catLbl) ?></span>
                    <span class="mkt-tag"><?= e($condLbl) ?></span>
                    <?php if (!empty($item['first_name'])): ?>
                        <span class="mkt-tag">From <?= e((string)$item['first_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<footer>
    <div class="footer-note">Powered by BadassHOA</div>
    <div class="footer-note">Auto-refreshes every <?= $refreshSec ?> seconds</div>
</footer>

<script>
// ── Clock ──────────────────────────────────────────────────────────────────
function tick() {
    var now = new Date();
    var h = now.getHours(), m = now.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('clock').textContent =
        h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    var days    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months  = ['January','February','March','April','May','June',
                   'July','August','September','October','November','December'];
    document.getElementById('dateline').textContent =
        days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
}
tick();
setInterval(tick, 1000);

// ── Auto-scroll each column independently ─────────────────────────────────
// Speed: pixels per second. Pause (ms) at top and bottom before resuming.
var SPEED      = 55;   // px/sec — comfortable reading speed
var PAUSE_TOP  = 3000; // ms to wait at the top before starting
var PAUSE_BOT  = 2500; // ms to wait at the bottom before resetting

function autoScroll(vpId, trId) {
    var vp = document.getElementById(vpId);
    var tr = document.getElementById(trId);
    if (!vp || !tr) return;

    var pos       = 0;
    var direction = 1; // 1 = down, -1 = up (we reset, not reverse)
    var lastTime  = null;
    var pausing   = true;
    var pauseEnd  = Date.now() + PAUSE_TOP;
    var maxScroll = 0;

    function measure() {
        maxScroll = tr.scrollHeight - vp.clientHeight;
    }
    measure();

    // No overflow — nothing to do
    if (maxScroll <= 20) return;

    function frame(ts) {
        if (lastTime === null) lastTime = ts;
        var dt = ts - lastTime;
        lastTime = ts;

        if (pausing) {
            if (Date.now() >= pauseEnd) {
                pausing = false;
            }
        } else {
            pos += (SPEED * dt) / 1000;
            measure();
            if (pos >= maxScroll) {
                pos = maxScroll;
                tr.style.transform = 'translateY(-' + pos + 'px)';
                pausing  = true;
                pauseEnd = Date.now() + PAUSE_BOT;
                // after bottom pause, reset to top
                setTimeout(function () {
                    pos = 0;
                    tr.style.transform = 'translateY(0)';
                    tr.style.transition = 'none';
                    pausing  = true;
                    pauseEnd = Date.now() + PAUSE_TOP;
                    lastTime = null;
                }, PAUSE_BOT);
                requestAnimationFrame(frame);
                return;
            }
        }

        tr.style.transition = 'none';
        tr.style.transform  = 'translateY(-' + Math.round(pos) + 'px)';
        requestAnimationFrame(frame);
    }

    requestAnimationFrame(frame);
}

// Stagger start times so all three columns don't scroll in lockstep
setTimeout(function () { autoScroll('vp-ann', 'tr-ann'); }, 0);
setTimeout(function () { autoScroll('vp-evt', 'tr-evt'); }, 800);
setTimeout(function () { autoScroll('vp-mkt', 'tr-mkt'); }, 1600);
</script>
</body>
</html>

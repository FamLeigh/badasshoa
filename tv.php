<?php
// Lobby TV / digital signage — no login required, token-authenticated.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') { http_response_code(404); die('Not found.'); }

$stmt = db()->prepare('SELECT * FROM associations WHERE tv_token = ? AND status = "active" LIMIT 1');
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
      LIMIT 8"
);
$anns->execute([$assocId]);
$announcements = $anns->fetchAll();

// Upcoming events (next 14 days, all-audience)
$evts = db()->prepare(
    "SELECT title, starts_at, ends_at, location FROM events
      WHERE association_id = ? AND audience = 'all'
        AND starts_at >= NOW() AND starts_at <= NOW() + INTERVAL 14 DAY
      ORDER BY starts_at LIMIT 6"
);
$evts->execute([$assocId]);
$events = $evts->fetchAll();

$hasLogo   = !empty($assoc['logo_path']);
$primary   = preg_match('/^#[0-9a-f]{6}$/i', (string)$assoc['primary_color']) ? $assoc['primary_color'] : '#0f1f3d';

// Refresh every 60 seconds
$refreshSec = 60;
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
    --bg: #0a0f1e;
    --card: #131929;
    --border: rgba(255,255,255,.08);
    --text: #fff;
    --muted: rgba(255,255,255,.55);
    --orange: #f05a28;
    --r: 16px;
}
html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; overflow: hidden; }
body { display: grid; grid-template-rows: auto 1fr; grid-template-columns: 1fr 1fr; gap: 24px; padding: 32px; }

/* header spans full width */
header { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; }
.brand { display: flex; align-items: center; gap: 20px; }
.brand img { max-height: 56px; }
.brand h1 { font-size: clamp(1.4rem, 2.5vw, 2.2rem); font-weight: 800; letter-spacing: -0.02em; }
.clock { font-size: clamp(2rem, 5vw, 4rem); font-weight: 900; font-variant-numeric: tabular-nums; letter-spacing: -0.03em; color: var(--primary); }
.date-line { font-size: clamp(.75rem, 1.2vw, 1rem); color: var(--muted); text-align: right; margin-top: 2px; }

/* columns */
.col { display: flex; flex-direction: column; gap: 20px; overflow: hidden; }
.col-label { font-size: .7rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }

/* cards */
.card { background: var(--card); border-radius: var(--r); border: 1px solid var(--border); padding: 20px 24px; overflow: hidden; }
.card--emergency { border-color: #ef4444; background: rgba(239,68,68,.12); }

.ann-type { display: inline-block; font-size: .65rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; padding: 3px 8px; border-radius: 6px; margin-bottom: 8px; background: var(--border); }
.ann-type--emergency { background: #ef4444; color: #fff; }
.ann-type--maintenance { background: #f59e0b; color: #000; }
.ann-type--info, .ann-type--general { background: var(--primary); color: #fff; }

.ann-title { font-size: clamp(.95rem, 1.5vw, 1.2rem); font-weight: 700; line-height: 1.25; margin-bottom: 6px; }
.ann-body  { font-size: clamp(.75rem, 1.1vw, .95rem); color: var(--muted); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.ann-date  { font-size: .7rem; color: var(--muted); margin-top: 8px; }

.evt-item { display: flex; gap: 16px; align-items: flex-start; }
.evt-cal { flex-shrink: 0; width: 52px; text-align: center; background: var(--primary); border-radius: 10px; padding: 6px 4px; }
.evt-cal .m { font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.8); }
.evt-cal .d { font-size: 1.6rem; font-weight: 900; line-height: 1; color: #fff; }
.evt-info h3 { font-size: clamp(.9rem, 1.3vw, 1.1rem); font-weight: 700; }
.evt-info .meta { font-size: .75rem; color: var(--muted); margin-top: 4px; }
.evt-divider { border: none; border-top: 1px solid var(--border); margin: 12px 0; }

.empty { color: var(--muted); font-size: .9rem; font-style: italic; text-align: center; padding: 20px 0; }

.scroll-announcements { overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px; }
.scroll-announcements::-webkit-scrollbar { width: 4px; }
.scroll-announcements::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* Ticker at bottom */
footer { grid-column: 1 / -1; border-top: 1px solid var(--border); padding-top: 12px; display: flex; align-items: center; justify-content: space-between; }
.powered { font-size: .65rem; color: rgba(255,255,255,.25); }
.last-updated { font-size: .65rem; color: rgba(255,255,255,.25); }
</style>
</head>
<body>

<header>
    <div class="brand">
        <?php if ($hasLogo): ?>
            <img src="/branding.php?id=<?= $assocId ?>" alt="<?= e((string)$assoc['name']) ?>">
        <?php else: ?>
            <h1><?= e((string)$assoc['name']) ?></h1>
        <?php endif; ?>
        <span style="color: var(--muted); font-size: 1rem; font-weight: 600;">Community Board</span>
    </div>
    <div style="text-align:right;">
        <div class="clock" id="clock">--:--</div>
        <div class="date-line" id="dateline"></div>
    </div>
</header>

<div class="col">
    <div class="col-label">Announcements</div>
    <div class="scroll-announcements">
    <?php if (empty($announcements)): ?>
        <div class="empty">No active announcements.</div>
    <?php else: foreach ($announcements as $a):
        $isEmergency = $a['type'] === 'emergency';
    ?>
        <div class="card <?= $isEmergency ? 'card--emergency' : '' ?>">
            <span class="ann-type ann-type--<?= e((string)$a['type']) ?>"><?= e((string)$a['type']) ?></span>
            <div class="ann-title"><?= e((string)$a['title']) ?></div>
            <div class="ann-body"><?= e(strip_tags((string)$a['body'])) ?></div>
            <div class="ann-date"><?= udate('M j, Y', strtotime((string)$a['published_at'])) ?></div>
        </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<div class="col">
    <div class="col-label">Upcoming Events</div>
    <?php if (empty($events)): ?>
        <div class="empty">No upcoming events.</div>
    <?php else: foreach ($events as $i => $ev):
        $ts = strtotime((string)$ev['starts_at']);
        $endTs = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
        if ($i > 0): ?><hr class="evt-divider"><?php endif; ?>
        <div class="evt-item">
            <div class="evt-cal">
                <div class="m"><?= udate('M', $ts) ?></div>
                <div class="d"><?= udate('j', $ts) ?></div>
            </div>
            <div class="evt-info">
                <h3><?= e((string)$ev['title']) ?></h3>
                <div class="meta">
                    <?= udate('g:i A', $ts) ?>
                    <?php if ($endTs): ?> – <?= udate($endTs - $ts < 86400 ? 'g:i A' : 'M j, g:i A', $endTs) ?><?php endif; ?>
                    <?php if (!empty($ev['location'])): ?> &middot; <?= e((string)$ev['location']) ?><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<footer>
    <div class="powered">Powered by BadassHOA</div>
    <div class="last-updated">Auto-refreshes every <?= $refreshSec ?> seconds</div>
</footer>

<script>
function tick() {
    var now = new Date();
    var h = now.getHours(), m = now.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('clock').textContent = h + ':' + String(m).padStart(2,'0') + ' ' + ampm;
    var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('dateline').textContent = days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
}
tick();
setInterval(tick, 1000);
</script>
</body>
</html>

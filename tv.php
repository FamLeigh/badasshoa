<?php
// Lobby TV / digital signage — no login required, token-authenticated.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Supports three auth modes:
//   ?slug=X&pin=Y  — kiosk PIN (bookmarkable, preferred)
//   ?token=<hex>   — legacy 48-char token (still works)
//   POST slug+pin  — login form submit → redirect to bookmarkable URL
//   (no params)    — show PIN entry form

$slug      = trim((string)($_GET['slug']  ?? ''));
$pin       = trim((string)($_GET['pin']   ?? ''));
$token     = trim((string)($_GET['token'] ?? ''));
$loginError = null;
$postSlug  = '';

// URL-overridable display params (carried through login → redirect → display).
// style: 'columns' | 'ticker'   — overrides association's saved tv_mode
// dark:  '1' | '0'              — 1=dark (default), 0=light/white theme
$urlStyle = in_array((string)($_GET['style'] ?? ''), ['columns','ticker']) ? (string)$_GET['style'] : '';
$urlDark  = isset($_GET['dark']) ? ((string)$_GET['dark'] === '0' ? '0' : '1') : '';

// Build the extra query string to carry through redirects
function tv_extra_qs(string $style, string $dark): string {
    $parts = [];
    if ($style !== '') $parts[] = 'style=' . urlencode($style);
    if ($dark  !== '') $parts[] = 'dark='  . urlencode($dark);
    return $parts ? '&' . implode('&', $parts) : '';
}

$clientIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$clientIp = trim(explode(',', $clientIp)[0]); // take first IP if comma-list

// Rate-limit PIN attempts: 10 failures per IP per 15 minutes.
function tv_pin_is_locked(string $ip): bool {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE kind = ? AND ip_address = ? AND succeeded = 0
            AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $stmt->execute(['tv_pin', $ip]);
    return (int)$stmt->fetchColumn() >= 10;
}
function tv_pin_record(string $ip, bool $ok): void {
    db()->prepare(
        'INSERT INTO login_attempts (kind, ip_address, succeeded) VALUES (?, ?, ?)'
    )->execute(['tv_pin', $ip, $ok ? 1 : 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postSlug  = trim((string)($_POST['slug']  ?? ''));
    $postPin   = trim((string)($_POST['pin']   ?? ''));
    $postStyle = in_array((string)($_POST['style'] ?? ''), ['columns','ticker']) ? (string)$_POST['style'] : '';
    $postDark  = isset($_POST['dark']) ? ((string)$_POST['dark'] === '0' ? '0' : '1') : '';
    if ($postStyle !== '') $urlStyle = $postStyle;
    if ($postDark  !== '') $urlDark  = $postDark;
    if (tv_pin_is_locked($clientIp)) {
        $loginError = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif ($postSlug !== '' && $postPin !== '') {
        $chk = db()->prepare(
            'SELECT id FROM associations WHERE subdomain = ? AND tv_pin = ? AND status IN ("active","trial","gifted") LIMIT 1'
        );
        $chk->execute([$postSlug, $postPin]);
        if ($chk->fetch()) {
            tv_pin_record($clientIp, true);
            header('Location: /tv?slug=' . urlencode($postSlug) . '&pin=' . urlencode($postPin) . tv_extra_qs($urlStyle, $urlDark));
            exit;
        }
        tv_pin_record($clientIp, false);
        $loginError = 'Community not found or PIN incorrect. Check with your board administrator.';
    } else {
        $loginError = 'Enter both a community ID and PIN.';
    }
}

$assoc = null;
if ($slug !== '' && $pin !== '') {
    // Bookmarked URL: also rate-limited so crawlers can't enumerate PINs.
    if (tv_pin_is_locked($clientIp)) {
        http_response_code(429);
        die('Too many failed attempts. Try again in 15 minutes.');
    }
    $stmt = db()->prepare(
        'SELECT * FROM associations WHERE subdomain = ? AND tv_pin = ? AND status IN ("active","trial","gifted") LIMIT 1'
    );
    $stmt->execute([$slug, $pin]);
    $assoc = $stmt->fetch() ?: null;
    if (!$assoc) {
        tv_pin_record($clientIp, false);
    }
} elseif ($token !== '') {
    $stmt = db()->prepare(
        'SELECT * FROM associations WHERE tv_token = ? AND status IN ("active","trial","gifted") LIMIT 1'
    );
    $stmt->execute([$token]);
    $assoc = $stmt->fetch() ?: null;
}

if (!$assoc) {
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BadassHOA — Community TV</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    width: 100%; min-height: 100vh;
    background: #08111f; color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
    display: flex; align-items: center; justify-content: center;
}
.box {
    width: min(520px, 90vw);
    background: #111c2e;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 18px;
    padding: clamp(32px,6vw,60px) clamp(28px,5vw,52px);
    text-align: center;
}
.logo { font-size: clamp(1.2rem,2.5vw,1.6rem); font-weight:900; color:#f05a28; margin-bottom:8px; }
h1 { font-size: clamp(1.8rem,4.5vw,2.8rem); font-weight:900; margin-bottom:10px; letter-spacing:-.03em; }
.sub { font-size:clamp(.9rem,1.8vw,1.2rem); color:rgba(255,255,255,.5); margin-bottom:clamp(28px,4vw,44px); line-height:1.55; }
label { display:block; text-align:left; font-size:clamp(.8rem,1.4vw,.95rem); font-weight:700; color:rgba(255,255,255,.55); letter-spacing:.07em; text-transform:uppercase; margin-bottom:8px; }
input {
    display:block; width:100%;
    background:#182438; border:2px solid rgba(255,255,255,.1); border-radius:10px;
    color:#fff; font-family:inherit; font-size:clamp(1.4rem,3.5vw,2rem); font-weight:700;
    padding:16px 20px; margin-bottom:clamp(16px,3vw,26px); outline:none;
    text-align:center; letter-spacing:.05em; transition:border-color .15s;
}
input:focus { border-color:#f05a28; }
button {
    width:100%; background:#f05a28; color:#fff; border:none; border-radius:10px;
    font-family:inherit; font-size:clamp(1.1rem,2.5vw,1.5rem); font-weight:800;
    padding:18px; cursor:pointer; transition:opacity .15s;
}
button:hover { opacity:.88; }
.error {
    background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.4);
    color:#fca5a5; border-radius:10px; padding:14px 18px;
    font-size:clamp(.85rem,1.5vw,1.05rem); font-weight:600; margin-bottom:24px;
}
.hint { font-size:clamp(.75rem,1.3vw,.9rem); color:rgba(255,255,255,.3); margin-top:24px; line-height:1.6; }
</style>
</head>
<body>
<div class="box">
    <div class="logo">BadassHOA</div>
    <h1>Community TV</h1>
    <p class="sub">Enter your community ID and PIN to access the lobby display. Bookmark the page after signing in.</p>
    <?php if ($loginError): ?>
        <div class="error"><?= e($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" action="/tv" autocomplete="off">
        <label for="f-slug">Community ID</label>
        <input id="f-slug" name="slug" type="text" placeholder="bellair"
               autocomplete="off" autocapitalize="none" spellcheck="false"
               value="<?= e($postSlug) ?>">
        <label for="f-pin">PIN</label>
        <input id="f-pin" name="pin" type="text" inputmode="numeric"
               placeholder="000000" autocomplete="off" maxlength="8">
    <div class="pickers">
        <div class="picker-group">
            <div class="picker-label">Layout</div>
            <div class="picker-row">
                <label class="pick-opt">
                    <input type="radio" name="style" value="columns" checked>
                    <span>
                        <svg width="32" height="22" viewBox="0 0 32 22" fill="none">
                            <rect x="1" y="1" width="9" height="20" rx="2" fill="currentColor" opacity=".3"/>
                            <rect x="12" y="1" width="9" height="20" rx="2" fill="currentColor" opacity=".3"/>
                            <rect x="23" y="1" width="8" height="20" rx="2" fill="currentColor" opacity=".3"/>
                        </svg>
                        3 Columns
                    </span>
                </label>
                <label class="pick-opt">
                    <input type="radio" name="style" value="ticker">
                    <span>
                        <svg width="32" height="22" viewBox="0 0 32 22" fill="none">
                            <rect x="1" y="7" width="8" height="8" rx="2" fill="currentColor" opacity=".3"/>
                            <rect x="11" y="7" width="8" height="8" rx="2" fill="currentColor" opacity=".3"/>
                            <rect x="21" y="7" width="8" height="8" rx="2" fill="currentColor" opacity=".3"/>
                            <polyline points="29,11 32,11" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Ticker
                    </span>
                </label>
            </div>
        </div>
        <div class="picker-group">
            <div class="picker-label">Theme</div>
            <div class="picker-row">
                <label class="pick-opt">
                    <input type="radio" name="dark" value="1" checked>
                    <span>🌙 Dark</span>
                </label>
                <label class="pick-opt">
                    <input type="radio" name="dark" value="0">
                    <span>☀️ Light</span>
                </label>
            </div>
        </div>
    </div>
        <button type="submit">Sign in →</button>
    </form>
    <p class="hint">Ask your board administrator for the community ID and PIN.</p>
</div>
<style>
.pickers { margin: var(--sp,24px) 0 0; display: flex; flex-direction: column; gap: 16px; }
.picker-group {}
.picker-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.4); margin-bottom: 8px; text-align: left; }
.picker-row { display: flex; gap: 10px; flex-wrap: wrap; }
.pick-opt { flex: 1; min-width: 100px; cursor: pointer; }
.pick-opt input { position: absolute; opacity: 0; width: 0; height: 0; }
.pick-opt span {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 12px 10px; border-radius: 10px;
    background: #182438; border: 2px solid rgba(255,255,255,.1);
    font-size: .8rem; font-weight: 700; color: rgba(255,255,255,.6);
    transition: border-color .15s, color .15s;
}
.pick-opt input:checked + span { border-color: #f05a28; color: #fff; }
.pick-opt span svg { color: rgba(255,255,255,.5); }
.pick-opt input:checked + span svg { color: #f05a28; }
</style>
<script>document.getElementById('f-slug').value ? document.getElementById('f-pin').focus() : document.getElementById('f-slug').focus();</script>
</body>
</html>
<?php
    exit;
}

// Ensure $token is always set for internal image URLs (even when authed via PIN).
$token = (string)$assoc['tv_token'];

$assocId = (int)$assoc['id'];

// Active announcements (all-audience, non-expired)
$anns = db()->prepare(
    "SELECT id, title, body, type, published_at, image_path FROM announcements
      WHERE association_id = ? AND audience = 'all'
        AND published_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())
      ORDER BY type = 'emergency' DESC, image_path IS NULL, published_at DESC
      LIMIT 20"
);
$anns->execute([$assocId]);
$announcements = $anns->fetchAll();

// Upcoming events (next 30 days, all-audience) — expand recurrences in PHP.
$evts = db()->prepare(
    "SELECT id, title, description, starts_at, ends_at, location, image_path, recurrence_type, recurrence_until
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
                'id'          => $ev['id'],
                'title'       => $ev['title'],
                'description' => $ev['description'],
                'location'    => $ev['location'],
                'image_path'  => $ev['image_path'],
                'starts_at'   => $start->format('Y-m-d H:i:s'),
                'ends_at'     => $end ? $end->format('Y-m-d H:i:s') : null,
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
                'id'          => $ev['id'],
                'title'       => $ev['title'],
                'description' => $ev['description'],
                'location'    => $ev['location'],
                'image_path'  => $ev['image_path'],
                'starts_at'   => $cur->format('Y-m-d H:i:s'),
                'ends_at'     => $duration > 0 ? $cur->modify("+{$duration} seconds")->format('Y-m-d H:i:s') : null,
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
    "SELECT m.id, m.title, m.description, m.price_cents, m.category, m.condition_label,
            m.photo_path, u.first_name, u.unit_number, u.phone
       FROM marketplace_listings m
       JOIN users u ON u.id = m.seller_user_id
      WHERE m.association_id = ? AND m.status = 'active'
      ORDER BY m.photo_path IS NULL, m.created_at DESC
      LIMIT 20"
);
$mkt->execute([$assocId]);
$listings = $mkt->fetchAll();

$hasLogo    = !empty($assoc['logo_path']);
$primary    = preg_match('/^#[0-9a-f]{6}$/i', (string)$assoc['primary_color']) ? $assoc['primary_color'] : '#0f1f3d';
$hasWeather = !empty($assoc['latitude']) && !empty($assoc['longitude']);
$tvAnnColors = ann_type_colors($assocId);
$lat = $hasWeather ? (float)$assoc['latitude'] : null;
$lon = $hasWeather ? (float)$assoc['longitude'] : null;
$refreshSec = 600;
// URL param overrides saved DB mode; DB mode is the default.
$dbMode = in_array((string)($assoc['tv_mode'] ?? 'columns'), ['columns','ticker']) ? (string)$assoc['tv_mode'] : 'columns';
$tvMode = $urlStyle !== '' ? $urlStyle : $dbMode;

// Dark (default) vs light theme. URL param overrides. Dark = 1, Light = 0.
$dbDark = isset($assoc['tv_dark']) ? (bool)(int)$assoc['tv_dark'] : true;
$tvDark = $urlDark !== '' ? ($urlDark === '1') : $dbDark;

// Build flat ticker items (announcements + events + marketplace) sorted newest first.
if ($tvMode === 'ticker') {
    $tickerItems = [];
    foreach ($announcements as $a) {
        $tickerItems[] = [
            'kind'       => 'announcement',
            'id'         => (int)$a['id'],
            'ann_type'   => (string)$a['type'],
            'title'      => (string)$a['title'],
            'body'       => trim(strip_tags((string)$a['body'])),
            'image_path' => $a['image_path'],
            'date'       => $a['published_at'],
            'sort_ts'    => strtotime((string)$a['published_at']),
        ];
    }
    foreach ($events as $ev) {
        $tickerItems[] = [
            'kind'        => 'event',
            'id'          => (int)$ev['id'],
            'title'       => (string)$ev['title'],
            'description' => trim(strip_tags((string)($ev['description'] ?? ''))),
            'starts_at'   => (string)$ev['starts_at'],
            'location'    => (string)($ev['location'] ?? ''),
            'image_path'  => $ev['image_path'],
            'sort_ts'     => strtotime((string)$ev['starts_at']),
        ];
    }
    foreach ($listings as $l) {
        $tickerItems[] = [
            'kind'        => 'marketplace',
            'title'       => (string)$l['title'],
            'price_cents' => $l['price_cents'],
            'category'    => (string)$l['category'],
            'seller'      => (string)($l['first_name'] ?? ''),
            'unit'        => (string)($l['unit_number'] ?? ''),
            'photo_path'  => $l['photo_path'],
            'sort_ts'     => 0,
        ];
    }
    // Newest/soonest first; marketplace (no timestamp) floats to end.
    usort($tickerItems, fn($a, $b) => $b['sort_ts'] - $a['sort_ts']);
}

// Fetch weather server-side — TV browser makes no external requests,
// and the page auto-refreshes every 10 min so data stays current.
$tvWeather = null;
if ($hasWeather) {
    static $WMO_ICONS = [
        0=>'☀️',1=>'🌤️',2=>'⛅',3=>'🌥️',45=>'🌫️',48=>'🌫️',
        51=>'🌦️',53=>'🌦️',55=>'🌧️',61=>'🌧️',63=>'🌧️',65=>'🌧️',
        71=>'🌨️',73=>'🌨️',75=>'❄️',77=>'🌨️',80=>'🌦️',81=>'🌧️',82=>'⛈️',
        95=>'⛈️',96=>'⛈️',99=>'⛈️',
    ];
    static $WMO_LABELS = [
        0=>'Clear',1=>'Mostly clear',2=>'Partly cloudy',3=>'Overcast',
        45=>'Fog',48=>'Icy fog',51=>'Light drizzle',53=>'Drizzle',55=>'Heavy drizzle',
        61=>'Light rain',63=>'Rain',65=>'Heavy rain',71=>'Light snow',73=>'Snow',
        75=>'Heavy snow',77=>'Snow grains',80=>'Showers',81=>'Heavy showers',82=>'Violent showers',
        95=>'Thunderstorm',96=>'Thunderstorm + hail',99=>'Thunderstorm + hail',
    ];
    $wUrl = 'https://api.open-meteo.com/v1/forecast'
          . '?latitude=' . $lat . '&longitude=' . $lon
          . '&current=temperature_2m,weather_code,wind_speed_10m'
          . '&hourly=precipitation_probability'
          . '&temperature_unit=fahrenheit&wind_speed_unit=mph&forecast_days=1';
    $wCtx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $wRaw = @file_get_contents($wUrl, false, $wCtx);
    if ($wRaw) {
        $wData = json_decode($wRaw, true);
        if (!empty($wData['current'])) {
            $wCode   = (int)$wData['current']['weather_code'];
            $wTemp   = (int)round((float)$wData['current']['temperature_2m']);
            $wWind   = (int)round((float)$wData['current']['wind_speed_10m']);
            $wHour   = (int)gmdate('G');
            $wPrecip = isset($wData['hourly']['precipitation_probability'][$wHour])
                       ? (int)$wData['hourly']['precipitation_probability'][$wHour] : null;
            $tvWeather = [
                'icon'   => $WMO_ICONS[$wCode]  ?? '🌡️',
                'temp'   => $wTemp,
                'label'  => $WMO_LABELS[$wCode] ?? '',
                'wind'   => $wWind,
                'precip' => $wPrecip,
            ];
        }
    }
}

// Association local timezone for displaying event times correctly.
$assocTzName = (string)($assoc['timezone'] ?? 'UTC');
if (!@timezone_open($assocTzName)) $assocTzName = 'UTC';
$assocTz = new DateTimeZone($assocTzName);

// Format a UTC unix timestamp in the association's local timezone.
function tv_time(string $fmt, int $ts, DateTimeZone $tz): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($fmt);
}

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
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
<?php if ($tvDark): ?>
:root {
    --primary:     <?= e($primary) ?>;
    --bg:          #08111f;
    --panel:       #111c2e;
    --card:        #182438;
    --border:      rgba(255,255,255,.09);
    --text:        #fff;
    --muted:       rgba(255,255,255,.5);
    --muted-dim:   rgba(255,255,255,.3);
    --muted-faint: rgba(255,255,255,.2);
    --orange:      #f05a28;
    --green:       #22c55e;
    --red:         #ef4444;
    --amber:       #f59e0b;
    --r:           14px;
}
<?php else: ?>
:root {
    --primary:     <?= e($primary) ?>;
    --bg:          #f4f6fa;
    --panel:       #ffffff;
    --card:        #edf0f7;
    --border:      rgba(15,31,61,.1);
    --text:        #0f1f3d;
    --muted:       rgba(15,31,61,.5);
    --muted-dim:   rgba(15,31,61,.4);
    --muted-faint: rgba(15,31,61,.25);
    --orange:      #f05a28;
    --green:       #16a34a;
    --red:         #dc2626;
    --amber:       #d97706;
    --r:           14px;
}
<?php endif; ?>

html, body {
    width: 100%; height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
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
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    border-bottom: 1px solid var(--border);
    padding-bottom: 16px;
    gap: 24px;
}
.brand { display: flex; align-items: center; gap: 18px; }
.brand img { max-height: 110px; object-fit: contain; }
.brand-name { font-size: 2rem; font-size: clamp(1.6rem, 2.4vw, 2.4rem); font-weight: 900; letter-spacing: -0.03em; }
.brand-tag {
    font-size: .85rem;
    font-size: clamp(.75rem, 1.1vw, 1rem);
    font-weight: 700;
    color: var(--muted);
    letter-spacing: .08em;
    text-transform: uppercase;
    padding-left: 2px;
}

/* ── Weather (center of header) ───────────────────────────── */
.weather-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.weather-main {
    display: flex;
    align-items: center;
    gap: 10px;
}
.weather-icon { font-size: 2.4rem; font-size: clamp(2rem, 4vw, 3.6rem); line-height: 1; }
.weather-temp {
    font-size: 3rem;
    font-size: clamp(2.4rem, 4.5vw, 4.2rem);
    font-weight: 900;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.03em;
    color: var(--text);
    line-height: 1;
}
.weather-cond {
    font-size: 1rem;
    font-size: clamp(.85rem, 1.3vw, 1.2rem);
    color: var(--muted);
    font-weight: 600;
    text-align: center;
    margin-top: 2px;
}
.weather-refresh {
    font-size: .7rem;
    font-size: clamp(.6rem, .85vw, .75rem);
    color: var(--muted-faint);
    font-weight: 600;
    letter-spacing: .04em;
    text-align: center;
    margin-top: 3px;
}

.clock-block { text-align: right; }
.clock {
    font-size: 3.5rem;
    font-size: clamp(2.8rem, 5.5vw, 5rem);
    font-weight: 900;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.04em;
    color: var(--orange);
    line-height: 1;
}
.dateline {
    font-size: 1rem;
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
    font-size: .95rem;
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
    font-size: .7rem;
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
.ann-badge--beautification { background: #2e7d32; }
.ann-badge--birth_notice   { background: #7c3aed; }
.ann-badge--death_notice   { background: #4b5563; }

.ann-title {
    font-size: 1.2rem;
    font-size: clamp(1.1rem, 1.8vw, 1.6rem);
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 8px;
}
.ann-body {
    font-size: 1rem;
    font-size: clamp(.9rem, 1.3vw, 1.2rem);
    color: var(--muted);
    line-height: 1.55;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ann-date {
    font-size: .8rem;
    font-size: clamp(.7rem, 1vw, .9rem);
    color: var(--muted-dim);
    margin-top: 10px;
    font-weight: 600;
}

/* ── Event card ───────────────────────────────────────────── */
.evt-item { display: flex; gap: 18px; align-items: flex-start; }
.evt-cal {
    flex-shrink: 0;
    width: 60px;
    width: clamp(52px, 7vw, 72px);
    text-align: center;
    background: var(--primary);
    border-radius: 10px;
    padding: 8px 6px;
}
.evt-cal .m {
    font-size: .7rem;
    font-size: clamp(.6rem, .9vw, .8rem);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: rgba(255,255,255,.75);
}
.evt-cal .d {
    font-size: 2rem;
    font-size: clamp(1.7rem, 3vw, 2.6rem);
    font-weight: 900;
    line-height: 1;
    color: #fff;
}
.evt-info { flex: 1; min-width: 0; }
.evt-title {
    font-size: 1.2rem;
    font-size: clamp(1.05rem, 1.7vw, 1.5rem);
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 6px;
}
.evt-meta {
    font-size: .9rem;
    font-size: clamp(.8rem, 1.15vw, 1.05rem);
    color: var(--muted);
    line-height: 1.4;
}
.evt-loc { margin-top: 3px; color: var(--muted-dim); }
.ann-thumb { width: 100%; height: 72px; height: clamp(60px, 7vw, 90px); object-fit: cover; border-radius: 6px; margin-bottom: 10px; display: block; }
.evt-thumb { width: 100%; height: 72px; height: clamp(60px, 7vw, 90px); object-fit: cover; border-radius: 6px; margin-bottom: 10px; display: block; }

/* ── Marketplace card ─────────────────────────────────────── */
.mkt-thumb { width: 100%; height: 80px; height: clamp(70px, 8vw, 100px); object-fit: cover; border-radius: 6px; margin-bottom: 10px; display: block; }
.mkt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.mkt-title {
    font-size: 1.2rem;
    font-size: clamp(1.05rem, 1.7vw, 1.5rem);
    font-weight: 800;
    line-height: 1.2;
    flex: 1;
}
.mkt-price {
    font-size: 1.1rem;
    font-size: clamp(1rem, 1.6vw, 1.4rem);
    font-weight: 900;
    color: var(--green);
    white-space: nowrap;
    flex-shrink: 0;
}
.mkt-price--free { color: var(--orange); }
.mkt-desc {
    font-size: 1rem;
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
    font-size: .7rem;
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
    font-size: 1.1rem;
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
    gap: 24px;
}
.footer-note {
    font-size: .7rem;
    font-size: clamp(.6rem, .9vw, .8rem);
    color: var(--muted);
    font-weight: 600;
    letter-spacing: .04em;
}
.footer-cta {
    font-size: 1rem;
    font-size: clamp(.85rem, 1.3vw, 1.15rem);
    font-weight: 800;
    color: var(--orange);
    letter-spacing: .02em;
    text-align: center;
    flex: 1;
}
</style>
<?php
$tvStoredCustom = [];
if (!empty($assoc['ann_type_colors'])) {
    $decoded = json_decode((string)$assoc['ann_type_colors'], true);
    if (is_array($decoded)) $tvStoredCustom = $decoded;
}
if ($tvStoredCustom):
?>
<style>
<?php foreach ($tvStoredCustom as $type => $hex):
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) continue;
    $safeType = preg_replace('/[^a-z]/', '', strtolower($type)); ?>
.ann-badge--<?= $safeType ?> { background: <?= $hex ?>; color: #fff; }
<?php endforeach; ?>
</style>
<?php endif; ?>
<?php if ($tvMode === 'ticker'): ?>
<style>
/* ── Ticker mode overrides ────────────────────────────────── */
body {
    grid-template-rows: auto 1fr auto !important;
    grid-template-columns: 1fr !important;
}
.ticker-wrap {
    grid-column: 1 / -1;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0;
    min-height: 0;
}
/* Strip running across the bottom holding the scrolling band */
.ticker-band {
    flex: 1;
    overflow: hidden;
    position: relative;
    min-height: 0;
}
.ticker-track {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    gap: 20px;
    padding: 20px 24px;
    position: absolute;
    top: 0; left: 0;
    height: 100%;
    white-space: nowrap;
}
.ticker-card {
    display: inline-flex;
    flex-direction: column;
    justify-content: flex-start;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px 28px;
    min-width: clamp(300px, 30vw, 500px);
    max-width: clamp(300px, 32vw, 520px);
    flex-shrink: 0;
    white-space: normal;
    height: 100%;
    box-sizing: border-box;
    overflow: hidden;
}
.ticker-card--emergency { border-color: var(--red); background: rgba(239,68,68,.12); }
.ticker-kind {
    font-size: clamp(.65rem, .9vw, .85rem);
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
}
.ticker-badge {
    display: inline-block;
    font-size: clamp(.65rem, .9vw, .85rem);
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: 4px 12px;
    border-radius: 6px;
    margin-bottom: 12px;
    background: var(--border);
    color: var(--text);
}
.ticker-badge--emergency   { background: var(--red); }
.ticker-badge--maintenance { background: var(--amber); color: #000; }
.ticker-badge--info,
.ticker-badge--general     { background: var(--primary); }
.ticker-badge--event       { background: #7c3aed; }
.ticker-badge--beautification { background: #2e7d32; }
.ticker-badge--birth_notice   { background: #7c3aed; }
.ticker-badge--death_notice   { background: #4b5563; }
.ticker-title {
    font-size: clamp(1.3rem, 2.2vw, 2.2rem);
    font-weight: 900;
    line-height: 1.2;
    margin-bottom: 10px;
    color: var(--text);
}
.ticker-body {
    font-size: clamp(1rem, 1.5vw, 1.5rem);
    color: var(--muted);
    line-height: 1.5;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 5;
    -webkit-box-orient: vertical;
}
.ticker-meta {
    font-size: clamp(.85rem, 1.2vw, 1.1rem);
    color: var(--muted-dim);
    margin-top: 10px;
    font-weight: 600;
}
.ticker-price {
    font-size: clamp(1.1rem, 1.8vw, 1.8rem);
    font-weight: 900;
    color: var(--green);
    margin-top: 8px;
}
.ticker-price--free { color: var(--orange); }
.ticker-photo {
    width: 100%;
    height: clamp(90px, 10vw, 140px);
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 12px;
    display: block;
}
.ticker-evt-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.ticker-evt-block {
    background: var(--primary);
    border-radius: 10px;
    padding: 8px 14px;
    text-align: center;
    flex-shrink: 0;
}
.ticker-evt-block .m {
    font-size: clamp(.6rem, .9vw, .8rem);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: rgba(255,255,255,.75);
}
.ticker-evt-block .d {
    font-size: clamp(1.6rem, 2.8vw, 2.8rem);
    font-weight: 900;
    line-height: 1;
    color: #fff;
}
/* Hide column grid in ticker mode */
.col { display: none !important; }
</style>
<?php endif; ?>
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

    <?php if ($hasWeather && $tvWeather): ?>
    <div class="weather-block">
        <div class="weather-main">
            <span class="weather-icon"><?= e($tvWeather['icon']) ?></span>
            <span class="weather-temp"><?= $tvWeather['temp'] ?>°F</span>
        </div>
        <div class="weather-cond">
            <?= e($tvWeather['label']) ?><?php if ($tvWeather['wind']): ?>&nbsp;&nbsp;💨 <?= $tvWeather['wind'] ?> mph<?php endif; ?><?php if ($tvWeather['precip'] !== null): ?>&nbsp;&nbsp;🌧 <?= $tvWeather['precip'] ?>%<?php endif; ?>
        </div>
        <div class="weather-refresh">Auto-refreshes every 10 minutes</div>
    </div>
    <?php elseif ($hasWeather): ?>
    <div class="weather-block">
        <div class="weather-main">
            <span class="weather-icon">🌡️</span>
            <span class="weather-temp">—°</span>
        </div>
    </div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>

    <div class="clock-block">
        <div class="clock" id="clock">--:--</div>
        <div class="dateline" id="dateline"></div>
    </div>
</header>

<?php if ($tvMode === 'ticker'): ?>

<!-- ══ TICKER MODE ══════════════════════════════════════════════════════════ -->
<div class="ticker-wrap">
    <div class="ticker-band">
        <div class="ticker-track" id="ticker-track">
        <?php if (!$tickerItems): ?>
            <div class="ticker-card" style="min-width:clamp(400px,50vw,700px); align-items:center; justify-content:center;">
                <div style="font-size:clamp(1.2rem,2vw,1.8rem); color:var(--muted); text-align:center;">No content to display yet.</div>
            </div>
        <?php else: foreach ($tickerItems as $item):
            if ($item['kind'] === 'announcement'):
                $isEmergency = $item['ann_type'] === 'emergency';
        ?>
            <div class="ticker-card <?= $isEmergency ? 'ticker-card--emergency' : '' ?>">
                <span class="ticker-badge ticker-badge--<?= e($item['ann_type']) ?>"><?= e(ann_type_label($item['ann_type'])) ?></span>
                <?php if (!empty($item['image_path'])): ?>
                    <img class="ticker-photo" src="/announcement-image.php?id=<?= (int)$item['id'] ?>" alt="">
                <?php endif; ?>
                <div class="ticker-title"><?= e($item['title']) ?></div>
                <?php if ($item['body'] !== ''): ?>
                    <div class="ticker-body"><?= e($item['body']) ?></div>
                <?php endif; ?>
                <div class="ticker-meta"><?= tv_time('M j, Y', strtotime((string)$item['date']), $assocTz) ?></div>
            </div>
        <?php elseif ($item['kind'] === 'event'):
            $ts    = strtotime((string)$item['starts_at']);
            $endTs = !empty($item['ends_at']) ? strtotime((string)$item['ends_at']) : null;
        ?>
            <div class="ticker-card">
                <div class="ticker-evt-header">
                    <div class="ticker-evt-block">
                        <div class="m"><?= tv_time('M', $ts, $assocTz) ?></div>
                        <div class="d"><?= tv_time('j', $ts, $assocTz) ?></div>
                    </div>
                    <div class="ticker-kind" style="margin-bottom:0;">📅 Upcoming Event</div>
                </div>
                <?php if (!empty($item['image_path'])): ?>
                    <img class="ticker-photo" src="/event-image.php?id=<?= (int)$item['id'] ?>" alt="">
                <?php endif; ?>
                <div class="ticker-title"><?= e($item['title']) ?></div>
                <div class="ticker-meta"><?= tv_time('g:i A', $ts, $assocTz) ?><?= $item['location'] !== '' ? ' · ' . e($item['location']) : '' ?></div>
                <?php if ($item['description'] !== ''): ?>
                    <div class="ticker-body" style="margin-top:8px;"><?= e($item['description']) ?></div>
                <?php endif; ?>
            </div>
        <?php elseif ($item['kind'] === 'marketplace'):
            $isFree = ($item['price_cents'] === null || (int)$item['price_cents'] === 0);
            $price  = $isFree ? 'FREE' : '$' . number_format((int)$item['price_cents'] / 100, 2);
        ?>
            <div class="ticker-card">
                <?php if (!empty($item['photo_path'])): ?>
                    <img class="ticker-photo" src="/tv-image.php?token=<?= urlencode($token) ?>&id=<?= (int)$item['id'] ?>" alt="">
                <?php endif; ?>
                <div class="ticker-kind">🏷️ Marketplace</div>
                <div class="ticker-title"><?= e($item['title']) ?></div>
                <div class="ticker-price <?= $isFree ? 'ticker-price--free' : '' ?>"><?= $price ?></div>
                <?php if ($item['seller'] !== ''): ?>
                    <div class="ticker-meta"><?= e($item['seller']) ?><?= $item['unit'] !== '' ? ' · Unit ' . e($item['unit']) : '' ?></div>
                <?php endif; ?>
            </div>
        <?php endif; endforeach; endif; ?>
        </div>
    </div>
</div>

<?php else: ?>

<!-- ══ COLUMNS MODE ═════════════════════════════════════════════════════════ -->

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
                <?php if (!empty($a['image_path'])): ?>
                    <img class="ann-thumb" src="/announcement-image.php?id=<?= (int)$a['id'] ?>" alt="">
                <?php endif; ?>
                <span class="ann-badge ann-badge--<?= e((string)$a['type']) ?>"><?= e(ann_type_label((string)$a['type'])) ?></span>
                <div class="ann-title"><?= e((string)$a['title']) ?></div>
                <?php $body = trim(strip_tags((string)$a['body'])); if ($body): ?>
                    <div class="ann-body"><?= e($body) ?></div>
                <?php endif; ?>
                <div class="ann-date"><?= tv_time('M j, Y', strtotime((string)$a['published_at']), $assocTz) ?></div>
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
                <?php if (!empty($ev['image_path'])): ?>
                    <img class="evt-thumb" src="/event-image.php?id=<?= (int)$ev['id'] ?>" alt="">
                <?php endif; ?>
                <div class="evt-item">
                    <div class="evt-cal">
                        <div class="m"><?= tv_time('M', $ts, $assocTz) ?></div>
                        <div class="d"><?= tv_time('j', $ts, $assocTz) ?></div>
                    </div>
                    <div class="evt-info">
                        <div class="evt-title"><?= e((string)$ev['title']) ?></div>
                        <div class="evt-meta">
                            <?= tv_time('g:i A', $ts, $assocTz) ?>
                            <?php if ($endTs): ?> – <?= tv_time($endTs - $ts < 86400 ? 'g:i A' : 'M j, g:i A', $endTs, $assocTz) ?><?php endif; ?>
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
                <?php if (!empty($item['photo_path'])): ?>
                    <img class="mkt-thumb" src="/tv-image.php?token=<?= urlencode($token) ?>&id=<?= (int)$item['id'] ?>" alt="">
                <?php endif; ?>
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
                        <span class="mkt-tag">From <?= e((string)$item['first_name']) ?><?= !empty($item['unit_number']) ? ' · Unit ' . e((string)$item['unit_number']) : '' ?></span>
                    <?php endif; ?>
                    <?php if (!empty($item['phone'])): ?>
                        <span class="mkt-tag">📞 <?= e((string)$item['phone']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php endif; // end columns mode ?>

<footer>
    <div class="footer-note">&copy; 2026 Savvy Brain LLC and Kevin B. Leigh &middot; Powered by BadassHOA.com &middot; 386-353-4444</div>
    <div class="footer-cta">Log in for additional details &mdash; badasshoa.com/<?= e((string)$assoc['subdomain']) ?></div>
    <div class="footer-note">&nbsp;</div>
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

// ── Infinite auto-scroll — setInterval + translate3d ─────────────────────────
// rAF stalls/stops on older Tizen (pre-2019). setInterval at a fixed timestep
// is more reliable on those browsers. 30fps is smooth at lobby viewing distance.
var SPEED    = 40; // px per second
var INTERVAL = 33; // ms per tick (~30 fps)

function setupScroll(vpId, trId, startDelay) {
    var vp = document.getElementById(vpId);
    var tr = document.getElementById(trId);
    if (!vp || !tr) return;

    var origHeight = tr.scrollHeight;
    if (origHeight <= vp.clientHeight + 20) return; // fits without scrolling

    // Double the children so the list wraps seamlessly.
    Array.from(tr.children).forEach(function(c) { tr.appendChild(c.cloneNode(true)); });

    // Promote to GPU layer.
    tr.style.webkitBackfaceVisibility = 'hidden';
    tr.style.backfaceVisibility       = 'hidden';
    tr.style.webkitTransform          = 'translate3d(0,0,0)';
    tr.style.transform                = 'translate3d(0,0,0)';

    var pos   = 0;
    var delta = SPEED * INTERVAL / 1000; // px per tick — fixed, no dt drift

    setTimeout(function() {
        setInterval(function() {
            pos += delta;
            if (pos >= origHeight) pos -= origHeight;
            var y = -Math.round(pos);
            tr.style.webkitTransform = 'translate3d(0,' + y + 'px,0)';
            tr.style.transform       = 'translate3d(0,' + y + 'px,0)';
        }, INTERVAL);
    }, startDelay || 0);
}

window.addEventListener('load', function() {
    <?php if ($tvMode === 'ticker'): ?>
    // Horizontal ticker scroll — same setInterval approach for Tizen compat.
    var tr = document.getElementById('ticker-track');
    if (tr && tr.children.length) {
        var origWidth = tr.scrollWidth;
        Array.from(tr.children).forEach(function(c) { tr.appendChild(c.cloneNode(true)); });
        tr.style.webkitBackfaceVisibility = 'hidden';
        tr.style.backfaceVisibility       = 'hidden';
        tr.style.webkitTransform          = 'translate3d(0,0,0)';
        tr.style.transform                = 'translate3d(0,0,0)';
        var HSPEED = 50; // px/s rightward scroll
        var pos = 0;
        var delta = HSPEED * INTERVAL / 1000;
        setTimeout(function() {
            setInterval(function() {
                pos += delta;
                if (pos >= origWidth) pos -= origWidth;
                var x = -Math.round(pos);
                tr.style.webkitTransform = 'translate3d(' + x + 'px,0,0)';
                tr.style.transform       = 'translate3d(' + x + 'px,0,0)';
            }, INTERVAL);
        }, 1000);
    }
    <?php else: ?>
    setupScroll('vp-ann', 'tr-ann', 0);
    setupScroll('vp-evt', 'tr-evt', 800);
    setupScroll('vp-mkt', 'tr-mkt', 1600);
    <?php endif; ?>
});
</script>
</body>
</html>

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

$ownerCount = db()->prepare("SELECT COUNT(*) FROM users WHERE association_id = ? AND status <> 'inactive' AND role IN ('owner','board_member','board_admin')");
$ownerCount->execute([$assocId]);
$stats['members_owners'] = (int)$ownerCount->fetchColumn();

$renterCount = db()->prepare("SELECT COUNT(*) FROM users WHERE association_id = ? AND status <> 'inactive' AND role = 'renter'");
$renterCount->execute([$assocId]);
$stats['members_renters'] = (int)$renterCount->fetchColumn();

$staffCount = db()->prepare("SELECT COUNT(*) FROM users WHERE association_id = ? AND status <> 'inactive' AND role IN ('staff','property_manager')");
$staffCount->execute([$assocId]);
$stats['members_staff'] = (int)$staffCount->fetchColumn();

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

$contsStmt = db()->prepare('SELECT COUNT(*) FROM association_contacts WHERE association_id = ?');
$contsStmt->execute([$assocId]);
$stats['contacts'] = (int)$contsStmt->fetchColumn();

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

$mkCountStmt = db()->prepare("SELECT COUNT(*) FROM marketplace_listings WHERE association_id = ? AND status = 'active'");
$mkCountStmt->execute([$assocId]);
$stats['marketplace'] = (int)$mkCountStmt->fetchColumn();

$mkRecentStmt = db()->prepare(
    "SELECT ml.id, ml.title, ml.price_cents, ml.category, ml.created_at, ml.photo_path,
            CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS seller
       FROM marketplace_listings ml
       JOIN users u ON u.id = ml.seller_user_id
      WHERE ml.association_id = ? AND ml.status = 'active'
      ORDER BY ml.created_at DESC LIMIT 5"
);
$mkRecentStmt->execute([$assocId]);
$mkListings = $mkRecentStmt->fetchAll();

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

// Storage usage (shown at top for managers only — residents don't need this).
$canManage = role_can_manage(viewing_role());
$storageUsed = $storageQuota = 0; $storagePct = 0;
if ($canManage) {
    $storageUsed  = association_storage_used_bytes($assocId);
    $storageQuota = association_storage_quota_bytes($association);
    $storagePct   = $storageQuota > 0 ? min(100, ($storageUsed / $storageQuota) * 100) : 0;
}

$user = current_user();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: var(--sp-8); padding-bottom: var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6); align-items: flex-start; gap: var(--sp-4); flex-wrap: wrap;">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-1);"><span data-greet><?= e($greet) ?></span>, <?= e($user['first_name'] ?: 'there') ?>.</h1>
            <p class="muted" style="margin: 0;">Here&rsquo;s what&rsquo;s happening at <?= e($association['name']) ?> today.</p>
        </div>
        <div style="text-align: right;">
            <div style="display:flex; align-items:baseline; justify-content:flex-end; gap: var(--sp-4);">
                <div data-now-time style="font-size: var(--fs-2xl); font-weight: 700; color: var(--color-navy); line-height: 1.1; font-variant-numeric: tabular-nums;">—</div>
                <?php if (!empty($association['latitude']) && !empty($association['longitude'])): ?>
                <button id="weather-btn"
                        data-lat="<?= e((string)$association['latitude']) ?>"
                        data-lon="<?= e((string)$association['longitude']) ?>"
                        title="Get current weather"
                        style="background:none; border:none; cursor:pointer; font-size: var(--fs-xl); font-weight:700; color:var(--color-navy); padding:0; line-height:1.1; display:flex; align-items:center; gap:6px;">
                    <span style="font-size:1.4em; line-height:1;">🌤️</span>
                    <span id="weather-val" style="font-variant-numeric:tabular-nums;">—°</span>
                </button>
                <?php endif; ?>
            </div>
            <div data-now-date class="muted" style="font-size: var(--fs-sm); margin-top: 3px;">—</div>
            <div id="weather-desc" style="font-size: var(--fs-sm); color: var(--color-text-soft); margin-top: 2px; min-height: 1.3em;"></div>
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

        // Weather widget — auto-loads on page open, persists in localStorage,
        // auto-refreshes every 30 min. Clicking when loaded opens NWS forecast.
        (function () {
            var btn  = document.getElementById('weather-btn');
            var val  = document.getElementById('weather-val');
            var desc = document.getElementById('weather-desc');
            if (!btn || !val) return;

            var STORE = 'bhoa_weather', TTL = 30 * 60 * 1000;
            var loaded = false;
            var lat = btn.dataset.lat, lon = btn.dataset.lon;

            var WMO_ICON = {
                0:'☀️', 1:'🌤️', 2:'⛅', 3:'🌥️',
                45:'🌫️', 48:'🌫️',
                51:'🌦️', 53:'🌦️', 55:'🌧️',
                61:'🌧️', 63:'🌧️', 65:'🌧️',
                71:'🌨️', 73:'🌨️', 75:'❄️', 77:'🌨️',
                80:'🌦️', 81:'🌧️', 82:'⛈️',
                95:'⛈️', 96:'⛈️', 99:'⛈️'
            };
            var WMO_LABEL = {
                0:'Clear', 1:'Mostly clear', 2:'Partly cloudy', 3:'Overcast',
                45:'Fog', 48:'Icy fog',
                51:'Light drizzle', 53:'Drizzle', 55:'Heavy drizzle',
                61:'Light rain', 63:'Rain', 65:'Heavy rain',
                71:'Light snow', 73:'Snow', 75:'Heavy snow', 77:'Snow grains',
                80:'Showers', 81:'Heavy showers', 82:'Violent showers',
                95:'Thunderstorm', 96:'Thunderstorm + hail', 99:'Thunderstorm + hail'
            };

            function render(d) {
                var cur  = d.current;
                var code = cur.weather_code;
                var ico  = WMO_ICON[code]  || '🌡️';
                var lbl  = WMO_LABEL[code] || 'Unknown';
                btn.querySelector('span').textContent = ico;
                val.textContent = Math.round(cur.temperature_2m) + '°F';
                if (desc) desc.textContent = lbl;
                btn.title = lbl + ' — click for full forecast';
                loaded = true;
            }

            function fetchWeather() {
                fetch('https://api.open-meteo.com/v1/forecast?latitude=' + encodeURIComponent(lat)
                    + '&longitude=' + encodeURIComponent(lon)
                    + '&current=temperature_2m,weather_code&temperature_unit=fahrenheit&forecast_days=1')
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        render(d);
                        try { localStorage.setItem(STORE, JSON.stringify({ ts: Date.now(), data: d })); } catch (_) {}
                    })
                    .catch(function () { if (!loaded) val.textContent = '—°'; });
            }

            // On load: render from localStorage if fresh, else fetch immediately.
            var fromCache = false;
            try {
                var hit = JSON.parse(localStorage.getItem(STORE) || 'null');
                if (hit && (Date.now() - hit.ts) < TTL) { render(hit.data); fromCache = true; }
            } catch (_) {}
            if (!fromCache) fetchWeather();

            // Auto-refresh every 30 min regardless of user interaction.
            setInterval(fetchWeather, TTL);

            // Click: if loaded open forecast; if not yet loaded trigger fetch.
            btn.addEventListener('click', function () {
                if (loaded) {
                    window.open('https://forecast.weather.gov/MapClick.php?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon), '_blank', 'noopener');
                } else {
                    val.textContent = '…';
                    fetchWeather();
                }
            });
        })();
    </script>

    <style>
        /* 6-up tile grid that steps down gracefully on narrower viewports. */
        .dashboard-stats { display:grid; grid-template-columns: repeat(6, 1fr); gap: var(--sp-2); margin-bottom: var(--sp-8); }
        @media (max-width: 1100px) { .dashboard-stats { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 680px)  { .dashboard-stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 420px)  { .dashboard-stats { grid-template-columns: repeat(2, 1fr); } }
        /* Tile internals — icon left, label + value right. Hover gets a subtle lift. */
        .stat { position: relative; display:flex; align-items:center; gap: var(--sp-2); padding: var(--sp-2) var(--sp-3); transition: transform 120ms ease, box-shadow 120ms ease; }
        .stat:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(15,31,61,0.10); }
        .stat__icon { font-size: 20px; line-height: 1; flex: 0 0 24px; }
        .stat__body { display:flex; flex-direction: column; min-width: 0; }
        .stat__label { font-size: var(--fs-xs); color: var(--color-text-soft); text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stat__value { font-size: var(--fs-xl); font-weight: 800; color: var(--color-navy); line-height: 1.1; font-variant-numeric: tabular-nums; }
        .stat__hint  { font-size: var(--fs-xs); color: var(--color-text-soft); margin-top: 2px; }
        .stat--alert { border-left: 3px solid var(--color-orange); }
    </style>
    <div class="dashboard-stats">
        <a class="stat" href="/dashboard/directory.php">
            <div class="stat__icon">👥</div>
            <div class="stat__body">
                <div class="stat__label">Owners / Renters</div>
                <div style="display:flex; gap:var(--sp-3); align-items:baseline; flex-wrap:wrap;">
                    <div><span class="stat__value"><?= (int)$stats['members_owners'] ?></span> <span style="font-size:var(--fs-xs);color:var(--color-text-soft);">owners</span></div>
                    <span style="color:var(--color-text-soft);">/</span>
                    <div><span class="stat__value"><?= (int)$stats['members_renters'] ?></span> <span style="font-size:var(--fs-xs);color:var(--color-text-soft);">renters</span></div>
                </div>
            </div>
        </a>
        <?php if ($canManage): ?>
        <a class="stat" href="/dashboard/units.php">
            <div class="stat__icon">🏠</div>
            <div class="stat__body">
                <div class="stat__label">Units</div>
                <div class="stat__value"><?= (int)$stats['units'] ?></div>
            </div>
        </a>
        <?php endif; ?>
        <?php if (can_do('read_contacts')): ?>
        <a class="stat" href="/dashboard/contacts.php">
        <?php else: ?>
        <div class="stat">
        <?php endif; ?>
            <div class="stat__icon">🎩</div>
            <div class="stat__body">
                <div class="stat__label">Board &amp; mgmt</div>
                <div class="stat__value"><?= (int)$stats['board'] ?></div>
            </div>
        <?php echo can_do('read_contacts') ? '</a>' : '</div>'; ?>
        <a class="stat<?= ($canManage && $stats['rule_changes_pending'] > 0) ? ' stat--alert' : '' ?>" href="/dashboard/search.php">
            <div class="stat__icon">📜</div>
            <div class="stat__body">
                <div class="stat__label">Rules &amp; bylaws <?php if ($canManage && $stats['rule_changes_pending'] > 0): ?><span class="badge badge--warning" style="font-size: 10px; vertical-align: middle; margin-left: 4px;"><?= (int)$stats['rule_changes_pending'] ?> pending</span><?php endif; ?></div>
                <div class="stat__value"><?= (int)$stats['rules'] ?></div>
            </div>
        </a>
        <?php if (can_do('read_documents')): ?>
        <a class="stat" href="/dashboard/documents.php">
            <div class="stat__icon">📁</div>
            <div class="stat__body">
                <div class="stat__label">Docs &amp; media</div>
                <div class="stat__value"><?= (int)$stats['documents'] ?> <span style="font-size: var(--fs-sm); font-weight: 500; color: var(--color-text-soft);">/ <?= (int)$stats['photos'] ?></span></div>
                <div class="stat__hint">docs / photos</div>
            </div>
        </a>
        <?php endif; ?>
        <a class="stat" href="/dashboard/communications.php">
            <div class="stat__icon">📣</div>
            <div class="stat__body">
                <div class="stat__label">Announcements</div>
                <div class="stat__value"><?= (int)$stats['announcements'] ?></div>
            </div>
        </a>
        <a class="stat" href="/dashboard/marketplace.php">
            <div class="stat__icon">🛒</div>
            <div class="stat__body">
                <div class="stat__label">Marketplace</div>
                <div class="stat__value"><?= (int)$stats['marketplace'] ?></div>
                <div class="stat__hint">active listings</div>
            </div>
        </a>
        <a class="stat" href="/dashboard/committees.php">
            <div class="stat__icon">🤝</div>
            <div class="stat__body">
                <div class="stat__label">Committees</div>
                <div class="stat__value"><?= (int)$stats['committees'] ?></div>
            </div>
        </a>
        <?php if (can_do('read_contacts')): ?>
        <a class="stat" href="/dashboard/contacts.php">
            <div class="stat__icon">📞</div>
            <div class="stat__body">
                <div class="stat__label">Contacts</div>
                <div class="stat__value"><?= (int)$stats['contacts'] ?></div>
            </div>
        </a>
        <?php else: ?>
        <div class="stat">
            <div class="stat__icon">📞</div>
            <div class="stat__body">
                <div class="stat__label">Contacts</div>
                <div class="stat__value"><?= (int)$stats['contacts'] ?></div>
            </div>
        </div>
        <?php endif; ?>
        <a class="stat" href="/dashboard/events.php">
            <div class="stat__icon">📅</div>
            <div class="stat__body">
                <div class="stat__label">Upcoming events</div>
                <div class="stat__value"><?= (int)$stats['events'] ?></div>
            </div>
        </a>
        <a class="stat<?= $stats['concerns_open'] > 0 ? ' stat--alert' : '' ?>" href="/dashboard/concerns.php">
            <div class="stat__icon">💬</div>
            <div class="stat__body">
                <div class="stat__label">Feedback pending</div>
                <div class="stat__value">
                    <?= (int)$stats['concerns_open'] ?>
                    <?php if ($stats['concerns'] > $stats['concerns_open']): ?>
                        <span style="font-size: var(--fs-sm); font-weight: 400; color: var(--color-text-soft); margin-left: 4px;">/ <?= (int)$stats['concerns'] ?> total</span>
                    <?php endif; ?>
                </div>
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

    <div class="dash-split" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--sp-5); align-items: start;">

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Recent announcements</h2>
                <a href="/dashboard/communications.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
            </div>
            <?php if (!$announcements): ?>
                <p class="muted">No announcements yet. <a href="/dashboard/communications.php?action=new">Post the first one</a>.</p>
            <?php else: ?>
                <div class="dash-list">
                <?php
                $dashAnnColors = ann_type_colors($assocId);
                foreach ($announcements as $a):
                    $aTs = strtotime((string)$a['published_at']);
                    $typeBadgeStyle = ann_badge_style((string)$a['type'], $dashAnnColors);
                ?>
                    <a class="dash-row" href="/dashboard/communications.php?id=<?= (int)$a['id'] ?>">
                        <div class="dash-date">
                            <div class="m"><?= e(udate('M', $aTs)) ?></div>
                            <div class="d"><?= e(udate('j', $aTs)) ?></div>
                        </div>
                        <div class="dash-body">
                            <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                                <span class="badge" style="font-size: var(--fs-xs); <?= $typeBadgeStyle ?>"><?= e(ann_type_label((string)$a['type'])) ?></span>
                                <span class="muted" style="font-size: var(--fs-xs);"><?= e(udate('g:i A', $aTs)) ?> · <?= e(trim($a['author']) ?: 'Unknown') ?></span>
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
                <h2 class="card__title">Marketplace</h2>
                <a href="/dashboard/marketplace.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
            </div>
            <?php if (!$mkListings): ?>
                <p class="muted">No active listings yet. <a href="/dashboard/marketplace.php?post=1">Post the first one</a>.</p>
            <?php else: ?>
                <div class="dash-list">
                <?php foreach ($mkListings as $mk):
                    $mkTs = strtotime((string)$mk['created_at']);
                    $price = $mk['price_cents'] === null ? 'Free' : '$' . number_format($mk['price_cents'] / 100, 0);
                ?>
                    <a class="dash-row" href="/dashboard/marketplace.php?id=<?= (int)$mk['id'] ?>">
                        <?php if (!empty($mk['photo_path'])): ?>
                        <div class="dash-thumb">
                            <img src="/marketplace-image.php?id=<?= (int)$mk['id'] ?>" alt="" loading="lazy">
                        </div>
                        <?php else: ?>
                        <div class="dash-date">
                            <div class="m"><?= e(udate('M', $mkTs)) ?></div>
                            <div class="d"><?= e(udate('j', $mkTs)) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="dash-body">
                            <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                                <span class="badge badge--success" style="font-size: var(--fs-xs);"><?= e($price) ?></span>
                                <span class="muted" style="font-size: var(--fs-xs);"><?= e(trim($mk['seller'])) ?></span>
                            </div>
                            <strong><?= e($mk['title']) ?></strong>
                            <p class="muted" style="margin: 2px 0 0; font-size: var(--fs-sm);"><?= e(ucfirst(str_replace('_',' ',(string)$mk['category']))) ?></p>
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
                        <?php if (!empty($ev['image_path'])): ?>
                        <div class="dash-thumb">
                            <img src="/event-image.php?id=<?= (int)$ev['id'] ?>" alt="" loading="lazy">
                        </div>
                        <?php else: ?>
                        <div class="dash-date">
                            <div class="m"><?= e(udate('M', $startTs)) ?></div>
                            <div class="d"><?= e(udate('j', $startTs)) ?></div>
                            <div class="dow"><?= e(udate('D', $startTs)) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="dash-body">
                            <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                                <span class="badge <?= $audClass ?>" style="font-size: var(--fs-xs);"><?= e((string)$ev['audience']) ?></span>
                                <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                                    <span class="badge" style="background: var(--color-surface); color: var(--color-text-soft); font-size: var(--fs-xs);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                                <?php endif; ?>
                                <span class="muted" style="font-size: var(--fs-xs);">
                                    <?= e(udate('g:i A', $startTs)) ?>
                                    <?php if ($endTs): ?> – <?= e(udate($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?><?php endif; ?>
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

    <?php if ($canManage):
        $barColor = $storagePct < 75 ? 'var(--color-success)' : ($storagePct < 95 ? 'var(--color-warning)' : 'var(--color-error)');
        $over     = $storageUsed > $storageQuota;
    ?>
    <a href="/dashboard/storage.php" class="card card--padded" style="margin-top: var(--sp-6); display:flex; gap: var(--sp-4); align-items:center; flex-wrap: wrap; padding: var(--sp-3) var(--sp-4); text-decoration: none; color: inherit;" title="See where your storage is being used">
        <div style="font-size: 22px; line-height: 1;">💾</div>
        <div style="flex: 1; min-width: 200px;">
            <div class="row" style="justify-content: space-between; gap: var(--sp-3); align-items: baseline; flex-wrap: wrap;">
                <strong style="font-size: var(--fs-sm);">
                    Storage:
                    <?= e(format_bytes($storageUsed)) ?> of <?= e(format_bytes($storageQuota)) ?>
                    <span class="muted" style="font-weight: normal;">(<?= number_format($storagePct, 1) ?>%)</span>
                </strong>
                <span class="muted" style="font-size: var(--fs-xs);">
                    <?php if ((int)($association['storage_paid_extra_gb'] ?? 0) > 0): ?>
                        Includes <?= (int)$association['storage_paid_extra_gb'] ?> GB paid add-on ·
                    <?php endif; ?>
                    Breakdown →
                </span>
            </div>
            <div style="margin-top: 4px; height: 8px; background: var(--color-surface); border-radius: 999px; overflow: hidden;">
                <div style="height: 100%; width: <?= number_format($storagePct, 2) ?>%; background: <?= $barColor ?>; transition: width 200ms ease;"></div>
            </div>
            <?php if ($over): ?>
                <div class="muted" style="font-size: var(--fs-xs); color: var(--color-error); margin-top: 4px;">⚠ Over quota — new uploads will be blocked until you delete or upgrade.</div>
            <?php elseif ($storagePct >= 90): ?>
                <div class="muted" style="font-size: var(--fs-xs); color: var(--color-warning); margin-top: 4px;">Approaching your limit. New uploads will start failing soon.</div>
            <?php endif; ?>
        </div>
    </a>
    <?php endif; ?>

</div>

<style>
    @media (max-width: 1050px) { .dash-split { grid-template-columns: 1fr 1fr !important; } }
    @media (max-width: 700px) { .dash-split { grid-template-columns: 1fr !important; } }
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
    .dash-thumb {
        flex: 0 0 56px; width: 56px; height: 56px; border-radius: 6px;
        overflow: hidden; background: var(--color-border);
    }
    .dash-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
</style>

<?php require __DIR__ . '/../includes/footer.php'; ?>

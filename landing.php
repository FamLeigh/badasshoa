<?php
// Per-association public landing page at /{slug}/  (rewritten via .htaccess).
// Renders the association's branding + a sign-in CTA. Public — no auth.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));

$assoc = null;
if ($slug !== '') {
    $stmt = db()->prepare(
        'SELECT * FROM associations WHERE subdomain = ? LIMIT 1'
    );
    $stmt->execute([$slug]);
    $assoc = $stmt->fetch() ?: null;
}

// Not found, inactive, or landing disabled → bounce to /login.php
if (!$assoc || $assoc['status'] === 'inactive' || (int)$assoc['public_landing_enabled'] !== 1) {
    if ($assoc && (int)$assoc['public_landing_enabled'] !== 1) {
        flash('info', "{$assoc['name']} keeps their portal private. Sign in to access it.");
    }
    redirect('/login.php');
}

$primary  = preg_match('/^#[0-9a-f]{6}$/i', (string)$assoc['primary_color']) ? $assoc['primary_color'] : '#0f1f3d';
$hasLogo  = !empty($assoc['logo_path']);
$cityLine = trim(
    ($assoc['city'] ?? '')
    . (($assoc['city'] && $assoc['state_region']) ? ', ' : '')
    . ($assoc['state_region'] ?? '')
    . ' ' . ($assoc['postal_code'] ?? '')
);

// Public announcements (audience='all') for the landing feed
$annStmt = db()->prepare(
    "SELECT a.id, a.title, a.body, a.type, a.published_at,
            CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS author
     FROM announcements a
     LEFT JOIN users u ON u.id = a.author_id
     WHERE a.association_id = ? AND a.audience = 'all'
     ORDER BY a.published_at DESC LIMIT 8"
);
$annStmt->execute([(int)$assoc['id']]);
$publicAnnouncements = $annStmt->fetchAll();

// Public photo gallery (media WHERE visibility='public')
$mediaStmt = db()->prepare(
    "SELECT id, file_name, caption, category
     FROM media
     WHERE association_id = ? AND visibility = 'public'
     ORDER BY created_at DESC LIMIT 18"
);
$mediaStmt->execute([(int)$assoc['id']]);
$publicMedia = $mediaStmt->fetchAll();

// Amenities (one per line in $assoc['amenities_text'])
$amenities = [];
if (!empty($assoc['amenities_text'])) {
    foreach (preg_split('/\r?\n/', (string)$assoc['amenities_text']) as $line) {
        $line = trim($line);
        if ($line !== '') $amenities[] = $line;
    }
}

// FAQ entries
$faqStmt = db()->prepare('SELECT question, answer FROM faqs WHERE association_id = ? ORDER BY sort_order, id');
$faqStmt->execute([(int)$assoc['id']]);
$faqs = $faqStmt->fetchAll();

// Active board members who've opted in to the public listing
$boardStmt = db()->prepare(
    "SELECT first_name, last_name, role, unit_number
     FROM users
     WHERE association_id = ?
       AND status = 'active'
       AND show_on_public_landing = 1
       AND role IN ('board_admin','board_member','property_manager')
     ORDER BY FIELD(role, 'board_admin', 'property_manager', 'board_member'),
              last_name, first_name"
);
$boardStmt->execute([(int)$assoc['id']]);
$boardMembers = $boardStmt->fetchAll();

// Public documents
$docStmt = db()->prepare(
    "SELECT id, title, description, category, file_type, version, created_at
     FROM documents
     WHERE association_id = ? AND access_level = 'public'
     ORDER BY category, title"
);
$docStmt->execute([(int)$assoc['id']]);
$publicDocs = $docStmt->fetchAll();

// Build a clean address string for the 'View on map' link
$mapAddress = trim(
    ($assoc['address']     ? $assoc['address']     . ', ' : '')
    . ($assoc['city']        ? $assoc['city']        . ', ' : '')
    . ($assoc['state_region']? $assoc['state_region']. ' '  : '')
    . ($assoc['postal_code'] ? $assoc['postal_code']        : '')
);
$mapAddress = trim($mapAddress, ', ');

// Upcoming public events. Pull both single events with starts_at in the
// future AND recurring series whose end isn't past — expand_events() then
// turns the series into individual occurrences within the next 90 days.
$evStmt = db()->prepare(
    "SELECT id, title, description, location, starts_at, ends_at, recurrence_type, recurrence_until
     FROM events
     WHERE association_id = ?
       AND audience = 'all'
       AND ((recurrence_type = 'none' AND starts_at >= NOW())
            OR (recurrence_type <> 'none' AND (recurrence_until IS NULL OR recurrence_until >= CURDATE())))
     LIMIT 50"
);
$evStmt->execute([(int)$assoc['id']]);
$publicEvents = expand_events($evStmt->fetchAll(), false, 90);
if (count($publicEvents) > 6) $publicEvents = array_slice($publicEvents, 0, 6);

$hasAbout    = trim(strip_tags((string)($assoc['about_text'] ?? ''))) !== '';
$hasContact  = !empty($assoc['contact_email']) || !empty($assoc['contact_phone']);
$hasHeroImg  = !empty($assoc['hero_image_path']);
$hasMap      = $assoc['latitude'] !== null && $assoc['longitude'] !== null;
$contacted   = isset($_GET['contacted']);

$page_title  = e($assoc['name']);
$page_layout = 'public_landing'; // Avoids the public marketing nav; landing has its own chrome
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($assoc['name']) ?> — Community Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <?php $cssDir = __DIR__ . '/assets/css'; ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e((string)(@filemtime("$cssDir/tokens.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/base.css?v=<?= e((string)(@filemtime("$cssDir/base.css") ?: '')) ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= e((string)(@filemtime("$cssDir/app.css") ?: '')) ?>">
    <style>
        :root { --assoc-color: <?= e($primary) ?>; }
    </style>
</head>
<body class="page-public">

<section class="landing-hero <?= $hasHeroImg ? 'landing-hero--banner' : '' ?>">
    <?php if ($hasHeroImg): ?>
        <img class="landing-hero__banner" src="/branding.php?id=<?= (int)$assoc['id'] ?>&kind=hero" alt="" aria-hidden="true">
        <div class="landing-hero__overlay" aria-hidden="true"></div>
    <?php else: ?>
        <div class="landing-hero__bg" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container landing-hero__inner">
        <?php if ($hasLogo): ?>
            <img class="landing-hero__logo" src="/branding.php?id=<?= (int)$assoc['id'] ?>&kind=logo" alt="<?= e($assoc['name']) ?>">
        <?php endif; ?>

        <span class="landing-hero__eyebrow">Community portal</span>
        <h1 class="landing-hero__title"><?= e($assoc['name']) ?></h1>

        <?php if ($assoc['address'] || $cityLine): ?>
            <p class="landing-hero__address">
                <?php if ($assoc['address']): ?><?= e((string)$assoc['address']) ?><?php endif; ?>
                <?php if ($assoc['address'] && $cityLine !== ''): ?> &middot; <?php endif; ?>
                <?php if ($cityLine !== ''): ?><?= e($cityLine) ?><?php endif; ?>
                <?php if ($mapAddress !== ''): ?>
                    <a class="landing-hero__map-link" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($mapAddress) ?>" target="_blank" rel="noopener">View on map &rarr;</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="landing-hero__ctas">
            <a class="btn btn--primary btn--lg" href="/login.php">Sign in to your portal &rarr;</a>
            <a class="btn btn--ghost btn--lg" href="/forgot.php">Forgot password?</a>
        </div>

        <p class="landing-hero__hint muted">
            New to <?= e($assoc['name']) ?>? Ask your board for an invitation.
        </p>
    </div>
</section>

<?php if ($hasAbout): ?>
<section class="landing-about">
    <div class="container container--narrow">
        <h2 class="landing-section__heading">About <?= e($assoc['name']) ?></h2>
        <div class="landing-about__body">
            <?= $assoc['about_text'] /* HTML from Quill — board-trusted content */ ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($amenities): ?>
<section class="landing-amenities">
    <div class="container container--narrow">
        <h2 class="landing-section__heading">Amenities</h2>
        <ul class="landing-amenities__list">
            <?php foreach ($amenities as $am): ?>
                <li><?= e($am) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if ($publicMedia): ?>
<section class="landing-gallery">
    <div class="container">
        <h2 class="landing-section__heading center">Community photos</h2>
        <div class="landing-gallery__grid">
            <?php foreach ($publicMedia as $m): ?>
                <a class="landing-gallery__item" href="/public-media.php?id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                    <img src="/public-media.php?id=<?= (int)$m['id'] ?>" alt="<?= e((string)$m['caption']) ?>" loading="lazy">
                    <?php if (!empty($m['caption'])): ?>
                        <span class="landing-gallery__caption"><?= e((string)$m['caption']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($boardMembers): ?>
<section class="landing-board">
    <div class="container">
        <h2 class="landing-section__heading center">Meet your board</h2>
        <p class="muted center" style="margin-bottom: var(--sp-8);">The volunteers who run the day-to-day.</p>
        <div class="landing-board__grid">
            <?php foreach ($boardMembers as $bm):
                $first = trim((string)$bm['first_name']);
                $last  = trim((string)$bm['last_name']);
                $displayName = $first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '');
                $initial = strtoupper(mb_substr($displayName ?: '?', 0, 1));
                $roleLabel = match ($bm['role']) {
                    'board_admin'      => 'Board Admin',
                    'property_manager' => 'Property Manager',
                    default            => 'Board Member',
                };
                $roleClass = match ($bm['role']) {
                    'board_admin'      => 'badge--orange',
                    'property_manager' => 'badge--info',
                    default            => 'badge--navy',
                };
            ?>
            <div class="landing-board__card">
                <span class="landing-board__avatar"><?= e($initial) ?></span>
                <div class="landing-board__name"><?= e($displayName ?: 'Board Member') ?></div>
                <span class="badge <?= $roleClass ?>"><?= e($roleLabel) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($publicDocs): ?>
<section class="landing-docs">
    <div class="container container--narrow">
        <h2 class="landing-section__heading center">Documents &amp; forms</h2>
        <p class="muted center" style="margin-bottom: var(--sp-8);">Public documents you can download without signing in.</p>
        <div class="landing-docs__list">
            <?php foreach ($publicDocs as $d):
                $ext = strtoupper(pathinfo((string)($d['file_type'] ?? ''), PATHINFO_EXTENSION) ?: '');
                if ($ext === '') {
                    // Fallback: derive from MIME
                    $mime = (string)($d['file_type'] ?? '');
                    $ext = match (true) {
                        str_contains($mime, 'pdf')         => 'PDF',
                        str_contains($mime, 'msword'),
                        str_contains($mime, 'wordprocess') => 'DOC',
                        str_contains($mime, 'spreadsheet'),
                        str_contains($mime, 'excel')       => 'XLS',
                        str_contains($mime, 'image')       => 'IMG',
                        str_contains($mime, 'csv')         => 'CSV',
                        default                            => 'FILE',
                    };
                }
            ?>
            <a class="landing-docs__item" href="/public-document.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
                <span class="landing-docs__ext"><?= e($ext) ?></span>
                <div class="landing-docs__meta">
                    <strong><?= e((string)$d['title']) ?></strong>
                    <?php if (!empty($d['description'])): ?>
                        <span class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$d['description'], 0, 90, '…')) ?></span>
                    <?php endif; ?>
                    <span class="muted" style="font-size: var(--fs-xs);">
                        <?= !empty($d['category']) ? e((string)$d['category']) . ' &middot; ' : '' ?>v<?= e((string)$d['version']) ?>
                    </span>
                </div>
                <span class="landing-docs__download" aria-hidden="true">⇣</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($publicEvents): ?>
<section class="landing-events">
    <div class="container container--narrow">
        <h2 class="landing-section__heading center">Upcoming events</h2>
        <p class="muted center" style="margin-bottom: var(--sp-8);">Mark your calendar.</p>
        <div class="landing-events__list">
            <?php foreach ($publicEvents as $ev):
                $startTs = strtotime((string)$ev['starts_at']);
                $endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
                $sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
            ?>
            <article class="landing-events__item">
                <div class="landing-events__date">
                    <span class="landing-events__month"><?= e(date('M', $startTs)) ?></span>
                    <span class="landing-events__day"><?= e(date('j', $startTs)) ?></span>
                    <span class="landing-events__weekday"><?= e(date('D', $startTs)) ?></span>
                </div>
                <div class="landing-events__body">
                    <h3 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-1);"><?= e((string)$ev['title']) ?></h3>
                    <div class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-2);">
                        <?= e(date('g:i A', $startTs)) ?>
                        <?php if ($endTs): ?>
                            – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?>
                        <?php endif; ?>
                        <?php if (!empty($ev['location'])): ?>
                            &middot; <?= e((string)$ev['location']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($ev['description'])): ?>
                        <p style="margin: 0; color: var(--color-text-soft); white-space: pre-wrap;"><?= e(mb_strimwidth((string)$ev['description'], 0, 280, '…')) ?></p>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($hasMap): ?>
<section class="landing-map">
    <div class="container">
        <h2 class="landing-section__heading center">Find us</h2>
        <p class="muted center" style="margin-bottom: var(--sp-6);">
            <?= $assoc['address'] ? e((string)$assoc['address']) . ' &middot; ' : '' ?>
            <?= $cityLine !== '' ? e($cityLine) : '' ?>
        </p>
        <?php
        $lat = (float)$assoc['latitude'];
        $lon = (float)$assoc['longitude'];
        // ~0.005° in each direction = roughly a half-mile zoom
        $bbox = sprintf('%.6f,%.6f,%.6f,%.6f', $lon - 0.005, $lat - 0.005, $lon + 0.005, $lat + 0.005);
        $marker = sprintf('%.6f,%.6f', $lat, $lon);
        ?>
        <div class="landing-map__frame">
            <iframe
                src="https://www.openstreetmap.org/export/embed.html?bbox=<?= e($bbox) ?>&amp;layer=mapnik&amp;marker=<?= e($marker) ?>"
                title="Map of <?= e($assoc['name']) ?>"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        <p class="muted center" style="margin-top: var(--sp-3); font-size: var(--fs-xs);">
            <a href="https://www.openstreetmap.org/?mlat=<?= e((string)$lat) ?>&amp;mlon=<?= e((string)$lon) ?>#map=17/<?= e((string)$lat) ?>/<?= e((string)$lon) ?>" target="_blank" rel="noopener">
                Open larger map &rarr;
            </a>
        </p>
    </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<section class="landing-faq">
    <div class="container container--narrow">
        <h2 class="landing-section__heading center">Frequently asked questions</h2>
        <div class="landing-faq__list">
            <?php foreach ($faqs as $f): ?>
            <details class="landing-faq__item">
                <summary><?= e((string)$f['question']) ?></summary>
                <div class="landing-faq__answer"><?= nl2br(e((string)$f['answer'])) ?></div>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($hasContact): ?>
<section class="landing-contact" id="contact">
    <div class="container container--narrow">
        <h2 class="landing-section__heading center">Get in touch</h2>
        <p class="muted center" style="margin-bottom: var(--sp-6);">
            Questions about the community? Reach out — we'll get back to you.
        </p>

        <div class="landing-contact__row" style="margin-bottom: var(--sp-8);">
            <?php if (!empty($assoc['contact_email'])): ?>
                <a class="landing-contact__item" href="mailto:<?= e((string)$assoc['contact_email']) ?>">
                    <strong>Email</strong>
                    <span><?= e((string)$assoc['contact_email']) ?></span>
                </a>
            <?php endif; ?>
            <?php if (!empty($assoc['contact_phone'])): ?>
                <a class="landing-contact__item" href="tel:<?= e((string)$assoc['contact_phone']) ?>">
                    <strong>Phone</strong>
                    <span><?= e((string)$assoc['contact_phone']) ?></span>
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($assoc['contact_email'])): ?>
        <div class="landing-contact__form-wrap">
            <h3 style="font-size: var(--fs-lg); text-align: center; margin: 0 0 var(--sp-5);">Or send a message</h3>

            <?php if ($contacted): ?>
                <div class="flash flash--success" style="margin-bottom: var(--sp-4);">
                    Thanks — your message was sent. We'll be in touch soon.
                </div>
            <?php endif; ?>
            <?php
            $_flashes = flash_take();
            foreach ($_flashes as $f):
                if ($f['type'] === 'success' && $contacted) continue; // already shown above
            ?>
                <div class="flash flash--<?= e($f['type']) ?>" style="margin-bottom: var(--sp-4);"><?= e($f['message']) ?></div>
            <?php endforeach; ?>

            <form method="post" action="/contact.php" class="form landing-contact__form">
                <?= csrf_field() ?>
                <input type="hidden" name="slug" value="<?= e((string)$assoc['subdomain']) ?>">
                <!-- Honeypot: real users won't fill this; bots will -->
                <input type="text" name="website" tabindex="-1" autocomplete="off" style="position: absolute; left: -9999px; width: 1px; height: 1px;" aria-hidden="true">

                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="cname">Your name</label>
                        <input class="input" type="text" id="cname" name="name" required maxlength="200" autocomplete="name">
                    </div>
                    <div class="field">
                        <label class="field__label" for="cemail">Your email</label>
                        <input class="input" type="email" id="cemail" name="email" required maxlength="255" autocomplete="email">
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="cmsg">Message</label>
                    <textarea class="textarea" id="cmsg" name="message" rows="5" required minlength="10" maxlength="4000" placeholder="What would you like to know?"></textarea>
                </div>
                <div class="row" style="justify-content: flex-end;">
                    <button class="btn btn--primary btn--lg" type="submit">Send message</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($publicAnnouncements): ?>
<section class="landing-news">
    <div class="container container--narrow">
        <h2 class="landing-news__heading">From the board</h2>
        <p class="muted landing-news__sub">Latest community announcements. Sign in to see board-only updates.</p>
        <div class="stack-lg" style="margin-top: var(--sp-8);">
            <?php foreach ($publicAnnouncements as $a):
                $typeClass = $a['type'] === 'emergency' ? 'badge--error'
                           : ($a['type'] === 'event' ? 'badge--info'
                           : ($a['type'] === 'maintenance' ? 'badge--warning' : 'badge--orange'));
            ?>
            <article class="landing-news__item">
                <div class="row" style="gap: var(--sp-3); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <span class="badge <?= $typeClass ?>"><?= e((string)$a['type']) ?></span>
                    <span class="muted" style="font-size: var(--fs-xs);">
                        <?= e(date('M j, Y', strtotime((string)$a['published_at']))) ?>
                        <?php if (trim((string)$a['author']) !== ''): ?>
                            &middot; posted by <?= e(trim((string)$a['author'])) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <h3 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-2);"><?= e((string)$a['title']) ?></h3>
                <p style="margin: 0; white-space: pre-wrap; color: var(--color-text-soft);">
                    <?= e(mb_strimwidth(strip_tags((string)$a['body']), 0, 360, '…')) ?>
                </p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$socialLinks = [
    'website'   => ['url' => $assoc['website_url']   ?? null, 'label' => 'Website',   'icon' => 'globe'],
    'facebook'  => ['url' => $assoc['facebook_url']  ?? null, 'label' => 'Facebook',  'icon' => 'facebook'],
    'instagram' => ['url' => $assoc['instagram_url'] ?? null, 'label' => 'Instagram', 'icon' => 'instagram'],
    'twitter'   => ['url' => $assoc['twitter_url']   ?? null, 'label' => 'X / Twitter', 'icon' => 'twitter'],
    'nextdoor'  => ['url' => $assoc['nextdoor_url']  ?? null, 'label' => 'Nextdoor',  'icon' => 'nextdoor'],
];
$socialLinks = array_filter($socialLinks, fn ($l) => !empty($l['url']));

function landing_social_icon(string $name): string {
    $svg = 'width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    return match ($name) {
        'globe'     => "<svg $svg><circle cx='12' cy='12' r='10'/><line x1='2' y1='12' x2='22' y2='12'/><path d='M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z'/></svg>",
        'facebook'  => "<svg $svg><path d='M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z'/></svg>",
        'instagram' => "<svg $svg><rect x='2' y='2' width='20' height='20' rx='5' ry='5'/><path d='M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z'/><line x1='17.5' y1='6.5' x2='17.51' y2='6.5'/></svg>",
        'twitter'   => "<svg $svg><path d='M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z'/></svg>",
        'nextdoor'  => "<svg $svg><path d='M3 21V10l9-7 9 7v11h-6v-7h-6v7H3z'/></svg>",
        default     => '',
    };
}
?>
<footer class="landing-foot">
    <div class="container landing-foot__inner">
        <?php if ($socialLinks): ?>
            <div class="landing-foot__social">
                <?php foreach ($socialLinks as $key => $link): ?>
                    <a href="<?= e((string)$link['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($link['label']) ?>" title="<?= e($link['label']) ?>">
                        <?= landing_social_icon($link['icon']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="muted">
            &copy; <?= (int)date('Y') ?> <?= e($assoc['name']) ?>. Established <?= e(date('Y', strtotime((string)$assoc['created_at']))) ?>.
        </div>
        <div class="muted" style="font-size: var(--fs-xs);">
            Powered by <a href="/" style="color: var(--color-orange);">BadassHOA</a>
        </div>
    </div>
</footer>

<script src="/assets/js/app.js?v=<?= e((string)(@filemtime(__DIR__ . '/assets/js/app.js') ?: '')) ?>" defer></script>
</body>
</html>

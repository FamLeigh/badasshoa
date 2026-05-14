<?php
// One-page committee invite flyer. Pulls the committee's name, description,
// chair, and current member count. Minimal HTML so the browser print dialog
// produces a clean letter-size flyer suitable for pinning to a board or
// emailing as a PDF. Auto-opens the print dialog on load.
require __DIR__ . '/_bootstrap.php';

$cid = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM committees WHERE id = ? AND association_id = ?');
$stmt->execute([$cid, $assocId]);
$committee = $stmt->fetch();
if (!$committee) { http_response_code(404); die('Committee not found'); }

$memStmt = db()->prepare(
    "SELECT u.first_name, u.last_name, cm.role
       FROM committee_members cm
       JOIN users u ON u.id = cm.user_id
      WHERE cm.committee_id = ? AND u.status = 'active'
      ORDER BY (cm.role = 'chair') DESC, u.last_name, u.first_name"
);
$memStmt->execute([$cid]);
$mems  = $memStmt->fetchAll();
$chair = null;
foreach ($mems as $m) if ($m['role'] === 'chair') { $chair = $m; break; }

$assocName  = (string)$association['name'];
$assocSlug  = (string)($association['subdomain'] ?? '');
$loginUrl   = 'https://badasshoa.com/login.php';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Join the <?= e((string)$committee['name']) ?> — <?= e($assocName) ?></title>
<style>
    @page { size: letter; margin: 0.6in; }
    body {
        font-family: Inter, system-ui, sans-serif;
        color: #0f1f3d; margin: 0.6in;
        line-height: 1.5; max-width: 7.3in;
    }
    .eyebrow { color: #f05a28; text-transform: uppercase; letter-spacing: 0.12em; font-size: 11pt; font-weight: 700; margin-bottom: 0.4em; }
    h1 { font-size: 32pt; margin: 0.2em 0 0.4em; letter-spacing: -0.01em; }
    .lead { font-size: 14pt; color: #4a5060; margin-bottom: 1.5em; }
    .desc { font-size: 12pt; margin-bottom: 1.5em; }
    .desc p { margin: 0 0 0.6em; }
    .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1em 2em; margin: 1.5em 0; padding: 1em 1.25em; background: #f8f5ec; border-radius: 10px; }
    .meta-grid .label { font-size: 9pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; margin-bottom: 0.2em; }
    .meta-grid .val { font-size: 12pt; color: #0f1f3d; font-weight: 600; }
    .cta {
        margin-top: 1.5em;
        padding: 1em 1.25em;
        background: #0f1f3d; color: #fff;
        border-radius: 10px;
        font-size: 11.5pt;
    }
    .cta strong { color: #fff; }
    .cta .url { font-family: ui-monospace, "SF Mono", Consolas, monospace; background: rgba(255,255,255,0.12); padding: 4px 8px; border-radius: 4px; }
    .foot { margin-top: 2em; padding-top: 1em; border-top: 1px solid #d9d3c5; color: #6b7280; font-size: 9pt; }
    @media print {
        a { color: inherit; text-decoration: none; }
        body { margin: 0; }
    }
</style>
</head><body>

<?= print_header_html($association) ?>

<div class="eyebrow">Get involved</div>
<h1>Join the<br><?= e((string)$committee['name']) ?></h1>

<?php if (!empty($committee['description'])): ?>
<div class="desc"><?= (string)$committee['description'] /* HTML from Quill — board-trusted */ ?></div>
<?php else: ?>
<p class="lead">Help shape decisions in your community. We meet, listen, and act — and we'd love your voice.</p>
<?php endif; ?>

<div class="meta-grid">
    <div>
        <div class="label">Chair</div>
        <div class="val"><?= $chair ? e(trim((string)$chair['first_name'] . ' ' . (string)$chair['last_name'])) : 'Open seat — could be you' ?></div>
    </div>
    <div>
        <div class="label">Members</div>
        <div class="val"><?= count($mems) ?> <?= count($mems) === 1 ? 'person serving' : 'people serving' ?></div>
    </div>
</div>

<div class="cta">
    <strong>Ready to join?</strong> Sign in at
    <span class="url"><?= e($loginUrl) ?></span>
    , click <strong>Committees</strong>, find <strong><?= e((string)$committee['name']) ?></strong>, and hit <strong>Join</strong>. That's it.
</div>

<?= print_footer_html('Posted ' . udate('M j, Y')) ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body></html>

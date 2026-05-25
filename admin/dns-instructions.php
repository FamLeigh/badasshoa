<?php
declare(strict_types=1);

// DNS instructions preview + send page. Generates a board-admin-friendly
// email walking them through pointing their custom domain at the BadassHOA
// server, with step-by-step instructions for GoDaddy, Squarespace, Hover,
// and Hostinger. Super-admin only.
//
// Usage: /admin/dns-instructions.php?id=<association_id>
//
// The association must already have associations.custom_domain set (via
// /admin/associations.php?action=edit). The page lists every board_admin
// of that association as a default recipient; the operator can uncheck
// any of them and add additional emails before sending.

require __DIR__ . '/_bootstrap.php';

// Public IP of the BadassHOA web server (Hostinger shared). Customers
// point their A record at this. Override with ?ip=... if you ever need
// to test with a different value.
const BADASSHOA_SERVER_IP = '77.37.59.82';

$assocId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$assocId) { http_response_code(400); die('Missing association id.'); }

$stmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
$stmt->execute([$assocId]);
$assoc = $stmt->fetch();
if (!$assoc) { http_response_code(404); die('Association not found.'); }

$customDomain = trim((string)($assoc['custom_domain'] ?? ''));
if ($customDomain === '') {
    http_response_code(400);
    die('Association #' . (int)$assocId . ' has no custom domain set. Set one via the edit page first.');
}

$serverIP = (string)($_GET['ip'] ?? BADASSHOA_SERVER_IP);

// ----- Build the email body ------------------------------------------------

function dns_email_subject(string $domain, array $assoc): string
{
    return sprintf('DNS setup for %s — %s on BadassHOA', $domain, $assoc['name']);
}

function dns_email_html(string $domain, string $serverIp, array $assoc): string
{
    $bare = htmlspecialchars($domain, ENT_QUOTES, 'UTF-8');
    $ip   = htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)$assoc['name'], ENT_QUOTES, 'UTF-8');
    $slug = htmlspecialchars((string)$assoc['subdomain'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>DNS setup for $bare</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1a1f36; line-height: 1.55; max-width: 640px; margin: 0 auto; padding: 24px;">

<h1 style="font-size: 22px; margin: 0 0 8px; color: #0f1f3d;">DNS setup for $bare</h1>
<p style="color: #555;">Once these DNS records are in place, $name will be reachable at <strong>https://$bare</strong> instead of (or in addition to) <code>https://badasshoa.com/$slug/</code>.</p>

<h2 style="font-size: 17px; margin-top: 28px; color: #0f1f3d;">The records you need to add</h2>
<p>At your domain registrar, open the DNS settings for <strong>$bare</strong> and add these two records:</p>
<table cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; font-size: 14px; margin: 12px 0; border: 1px solid #ddd;">
  <tr style="background: #f5f5f7;"><th style="text-align:left; border: 1px solid #ddd;">Type</th><th style="text-align:left; border: 1px solid #ddd;">Name / Host</th><th style="text-align:left; border: 1px solid #ddd;">Value / Points to</th><th style="text-align:left; border: 1px solid #ddd;">TTL</th></tr>
  <tr><td style="border: 1px solid #ddd;"><strong>A</strong></td><td style="border: 1px solid #ddd;"><code>@</code> (or <code>$bare</code>)</td><td style="border: 1px solid #ddd;"><code>$ip</code></td><td style="border: 1px solid #ddd;">1 hour (or default)</td></tr>
  <tr><td style="border: 1px solid #ddd;"><strong>A</strong></td><td style="border: 1px solid #ddd;"><code>www</code></td><td style="border: 1px solid #ddd;"><code>$ip</code></td><td style="border: 1px solid #ddd;">1 hour (or default)</td></tr>
</table>
<p style="font-size: 13px; color: #555;">If your registrar uses different terminology — "Host" instead of "Name", or "Answer" instead of "Value" — the meaning is the same. The "@" character means "the bare domain", e.g. <code>$bare</code>.</p>

<h2 style="font-size: 17px; margin-top: 32px; color: #0f1f3d;">Step-by-step by registrar</h2>

<h3 style="font-size: 15px; margin-top: 20px; color: #0f1f3d;">GoDaddy</h3>
<ol style="font-size: 14px; padding-left: 20px;">
  <li>Sign in at <a href="https://account.godaddy.com">account.godaddy.com</a>.</li>
  <li>Open <strong>My Products</strong> → find <strong>$bare</strong> → click <strong>DNS</strong>.</li>
  <li>Under <strong>DNS Records</strong>, click <strong>Add New Record</strong>.</li>
  <li>Type: <strong>A</strong>. Name: <strong>@</strong>. Value: <strong>$ip</strong>. TTL: <em>1 hour</em>. Save.</li>
  <li>Add a second record. Type: <strong>A</strong>. Name: <strong>www</strong>. Value: <strong>$ip</strong>. TTL: <em>1 hour</em>. Save.</li>
  <li>If GoDaddy already has an A record for <code>@</code> pointing at a parking page, edit it instead of adding a new one (you can only have one A record per name).</li>
</ol>

<h3 style="font-size: 15px; margin-top: 20px; color: #0f1f3d;">Squarespace Domains</h3>
<ol style="font-size: 14px; padding-left: 20px;">
  <li>Sign in at <a href="https://account.squarespace.com">account.squarespace.com</a>.</li>
  <li>Open <strong>Domains</strong> → click <strong>$bare</strong>.</li>
  <li>Click <strong>DNS</strong> in the side panel, then <strong>Custom Records</strong>.</li>
  <li>Add a record: Host: <strong>@</strong>. Type: <strong>A</strong>. Data: <strong>$ip</strong>. Save.</li>
  <li>Add a second record: Host: <strong>www</strong>. Type: <strong>A</strong>. Data: <strong>$ip</strong>. Save.</li>
  <li>If Squarespace shows existing A records for <code>@</code> pointing at their own servers (198.x.x.x), delete those first so the new ones take effect.</li>
</ol>

<h3 style="font-size: 15px; margin-top: 20px; color: #0f1f3d;">Hover</h3>
<ol style="font-size: 14px; padding-left: 20px;">
  <li>Sign in at <a href="https://www.hover.com/signin">hover.com/signin</a>.</li>
  <li>Open <strong>Domains</strong> → click <strong>$bare</strong>.</li>
  <li>Click the <strong>DNS</strong> tab.</li>
  <li>Click <strong>Add a Record</strong>. Hostname: <strong>@</strong>. Type: <strong>A</strong>. Target host: <strong>$ip</strong>. TTL: <em>15 min</em>. Add.</li>
  <li>Click <strong>Add a Record</strong> again. Hostname: <strong>www</strong>. Type: <strong>A</strong>. Target host: <strong>$ip</strong>. TTL: <em>15 min</em>. Add.</li>
  <li>Delete any existing A records on <code>@</code> or <code>www</code> that point somewhere else.</li>
</ol>

<h3 style="font-size: 15px; margin-top: 20px; color: #0f1f3d;">Hostinger</h3>
<ol style="font-size: 14px; padding-left: 20px;">
  <li>Sign in at <a href="https://hpanel.hostinger.com">hpanel.hostinger.com</a>.</li>
  <li>Open <strong>Domains</strong> → <strong>$bare</strong> → <strong>DNS / Nameservers</strong>.</li>
  <li>Under <strong>DNS Records</strong>, find or add the A record for <strong>@</strong>. Set its value to <strong>$ip</strong>. Save.</li>
  <li>Add another A record for <strong>www</strong> pointing to <strong>$ip</strong>. Save.</li>
  <li>If Hostinger asks you to switch from external nameservers to Hostinger's own, you'll want to do that <em>only</em> if you also want to host email or other DNS on Hostinger. Either way works for the A records.</li>
</ol>

<h2 style="font-size: 17px; margin-top: 32px; color: #0f1f3d;">After you save</h2>
<ol style="font-size: 14px; padding-left: 20px;">
  <li>Reply to this email or send a note to <a href="mailto:success@badasshoa.com">success@badasshoa.com</a> so we can verify the records and finish enabling <strong>$bare</strong> on our side.</li>
  <li>DNS changes typically resolve within 15 minutes, but some networks can take up to 24 hours. You can check propagation at <a href="https://dnschecker.org/#A/$bare">dnschecker.org</a>.</li>
  <li>Once we confirm, <strong>https://$bare</strong> will load the same BadassHOA portal you currently access at <code>https://badasshoa.com/$slug/</code>. Both URLs continue to work.</li>
</ol>

<p style="font-size: 14px; margin-top: 32px;">Questions? Just reply to this email.</p>
<p style="font-size: 14px;">— The BadassHOA team</p>

</body></html>
HTML;
}

function dns_email_text(string $domain, string $serverIp, array $assoc): string
{
    return "DNS setup for $domain
{$assoc['name']} — BadassHOA portal

THE RECORDS YOU NEED TO ADD
At your domain registrar, open the DNS settings for $domain and add:

  A   @     → $serverIp
  A   www   → $serverIp

(Both records, both pointing to $serverIp. \"@\" means the bare domain.)

STEP-BY-STEP

GoDaddy
  1. Sign in at account.godaddy.com.
  2. My Products → $domain → DNS.
  3. Add New Record. Type A. Name @. Value $serverIp.
  4. Add New Record. Type A. Name www. Value $serverIp.
  5. If an existing @ record points at a GoDaddy parking page, edit it
     instead of adding a new one.

Squarespace Domains
  1. Sign in at account.squarespace.com.
  2. Domains → $domain → DNS → Custom Records.
  3. Add record: Host @. Type A. Data $serverIp.
  4. Add record: Host www. Type A. Data $serverIp.
  5. Delete any existing @ A records pointing at Squarespace servers.

Hover
  1. Sign in at hover.com/signin.
  2. Domains → $domain → DNS.
  3. Add a Record. Hostname @. Type A. Target $serverIp.
  4. Add a Record. Hostname www. Type A. Target $serverIp.
  5. Delete existing @ or www A records pointing elsewhere.

Hostinger
  1. Sign in at hpanel.hostinger.com.
  2. Domains → $domain → DNS / Nameservers.
  3. Set the A record for @ to $serverIp.
  4. Add an A record for www pointing to $serverIp.

AFTER YOU SAVE
  1. Reply or email success@badasshoa.com so we can verify and finish
     enabling $domain on our side.
  2. DNS usually resolves in 15 minutes but can take 24 hours.
     Check at dnschecker.org.
  3. Once confirmed, https://$domain will load the same portal you
     currently access at https://badasshoa.com/{$assoc['subdomain']}/.

Questions? Just reply.

— The BadassHOA team
";
}

$emailSubject = dns_email_subject($customDomain, $assoc);
$emailHtml    = dns_email_html($customDomain, $serverIP, $assoc);
$emailText    = dns_email_text($customDomain, $serverIP, $assoc);

// ----- Recipients --------------------------------------------------------

$baStmt = db()->prepare(
    "SELECT id, first_name, last_name, email
       FROM users
      WHERE association_id = ?
        AND role = 'board_admin'
        AND status = 'active'
        AND email NOT LIKE 'demo+%@badasshoa.com'
        AND email NOT LIKE 'staff+%@badasshoa.com'
      ORDER BY last_name, first_name"
);
$baStmt->execute([$assocId]);
$boardAdmins = $baStmt->fetchAll();

// ----- POST: send --------------------------------------------------------

$flashError   = null;
$flashSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selectedIds = array_filter(array_map('intval', (array)($_POST['recipients'] ?? [])));
    $extraEmails = trim((string)($_POST['extra_emails'] ?? ''));
    $extraList   = $extraEmails === ''
        ? []
        : array_filter(array_map('trim', preg_split('/[,\s;]+/', $extraEmails)));

    // Validate extras
    foreach ($extraList as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $flashError = "Invalid email in the extra recipients list: $e";
            break;
        }
    }

    $targets = [];
    foreach ($boardAdmins as $ba) {
        if (in_array((int)$ba['id'], $selectedIds, true)) {
            $targets[] = ['email' => (string)$ba['email'], 'name' => trim($ba['first_name'] . ' ' . $ba['last_name'])];
        }
    }
    foreach ($extraList as $e) {
        $targets[] = ['email' => (string)$e, 'name' => ''];
    }

    if (!$flashError && !$targets) {
        $flashError = 'Pick at least one recipient (or add an extra email).';
    }

    if (!$flashError) {
        $sent = 0;
        foreach ($targets as $t) {
            try {
                send_mail($t['email'], $emailSubject, $emailText, $emailHtml);
                $sent++;
            } catch (Throwable $e) {
                // Continue with other recipients; surface at end.
                $flashError = ($flashError ? $flashError . ' ' : '') . "Failed sending to {$t['email']}: " . $e->getMessage();
            }
        }
        if ($sent > 0) {
            audit('dns.instructions_sent', [
                'association_id' => $assocId,
                'custom_domain'  => $customDomain,
                'recipient_count'=> $sent,
                'recipients'     => array_column($targets, 'email'),
            ], $assocId, 'association');
            $flashSuccess = "Sent DNS instructions to $sent recipient" . ($sent === 1 ? '' : 's') . '.';
        }
    }
}

$page_title = 'DNS instructions — ' . $customDomain;
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 980px;">

    <div class="row row--between" style="align-items: flex-start; flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">DNS instructions for <code><?= e($customDomain) ?></code></h1>
            <p class="muted">
                Preview the email below and send it to the board admins of <strong><?= e((string)$assoc['name']) ?></strong>
                <code class="muted" style="font-size: var(--fs-sm); font-weight: 400;">#<?= (int)$assoc['id'] ?></code>.
            </p>
        </div>
        <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php?action=edit&id=<?= (int)$assoc['id'] ?>">← Back to association</a>
    </div>

    <?php if ($flashError): ?>
        <div class="flash flash--error"><?= e($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="flash flash--success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--sp-4); font-size: var(--fs-sm);">
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Custom domain</div>
                <div style="font-weight: 700; margin-top: var(--sp-1);"><?= e($customDomain) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Server IP (A record value)</div>
                <div style="font-weight: 700; margin-top: var(--sp-1);"><code><?= e($serverIP) ?></code></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Current portal URL</div>
                <div style="margin-top: var(--sp-1);"><a href="/<?= e((string)$assoc['subdomain']) ?>/" target="_blank" rel="noopener">/<?= e((string)$assoc['subdomain']) ?>/</a></div>
            </div>
        </div>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">📧 Email preview</h2>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">
            Subject: <strong><?= e($emailSubject) ?></strong>
        </p>
        <div style="border: 1px solid var(--color-border); border-radius: var(--r-md); background: #fff; padding: 0; max-height: 600px; overflow: auto;">
            <iframe srcdoc="<?= e($emailHtml) ?>" style="width: 100%; height: 600px; border: 0; display: block;" sandbox="allow-same-origin"></iframe>
        </div>
        <details style="margin-top: var(--sp-3);">
            <summary class="muted" style="cursor: pointer; font-size: var(--fs-sm);">Show plain-text version</summary>
            <pre style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-sm); padding: var(--sp-3); margin-top: var(--sp-2); font-size: var(--fs-xs); white-space: pre-wrap; max-height: 400px; overflow: auto;"><?= e($emailText) ?></pre>
        </details>
    </div>

    <form method="post" class="card card--padded">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$assocId ?>">

        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">Send to</h2>

        <?php if (!$boardAdmins): ?>
            <p class="muted">No active board_admin users found for this association. Add one via the Users page, or use the "Additional emails" field below to send to a different address.</p>
        <?php else: ?>
            <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-3);">All board admins are checked by default. Uncheck anyone you don't want to email.</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-2) var(--sp-4); margin-bottom: var(--sp-4);">
                <?php foreach ($boardAdmins as $ba):
                    $nm = trim($ba['first_name'] . ' ' . $ba['last_name']) ?: $ba['email'];
                ?>
                    <label style="display: flex; align-items: center; gap: var(--sp-2); padding: var(--sp-2) var(--sp-3); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--r-sm); cursor: pointer;">
                        <input type="checkbox" name="recipients[]" value="<?= (int)$ba['id'] ?>" checked>
                        <span>
                            <strong style="font-size: var(--fs-sm);"><?= e($nm) ?></strong>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$ba['email']) ?></div>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="extra_emails">Additional emails <span class="muted" style="font-weight:400;">(optional)</span></label>
            <input class="input" id="extra_emails" name="extra_emails" type="text"
                   placeholder="contact@example.com, board@example.com"
                   value="<?= e((string)($_POST['extra_emails'] ?? '')) ?>">
            <div class="field__hint">Comma- or space-separated. Use this to also send to someone outside the board_admin list.</div>
        </div>

        <div class="row" style="justify-content: flex-end; gap: var(--sp-3); margin-top: var(--sp-4);">
            <a class="btn btn--ghost" href="/admin/associations.php?action=edit&id=<?= (int)$assoc['id'] ?>">Cancel</a>
            <button class="btn btn--primary" type="submit"
                    onclick="return confirm('Send DNS instructions email now?');">
                📧 Send DNS instructions
            </button>
        </div>
    </form>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

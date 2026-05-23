<?php
// POST-only handler: generate and email an invite link for a not-yet-logged-in member.
// Called from activity.php and directory.php edit forms.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();
if (!role_can_manage(viewing_role())) { http_response_code(403); die('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('Method not allowed'); }
csrf_check();
$user = current_user();

$userId   = (int)($_POST['user_id'] ?? 0);
$redirect = (string)($_POST['redirect'] ?? '/dashboard/activity.php');

// Whitelist redirect targets to prevent open redirect.
$redirectBase = strtok($redirect, '?');
$allowed = ['/dashboard/activity.php', '/dashboard/directory.php'];
if (!in_array($redirectBase, $allowed, true)) {
    $redirect = '/dashboard/activity.php';
}

if (!$userId) { flash('error', 'Invalid member.'); redirect($redirect); }

$stmt = db()->prepare(
    'SELECT id, first_name, last_name, email, unit_number, last_login_at, invite_sent_at
       FROM users
      WHERE id = ? AND association_id = ? AND status = "active"'
);
$stmt->execute([$userId, $assocId]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'Member not found.');
    redirect($redirect);
}
if (is_placeholder_email((string)$target['email'])) {
    flash('error', trim($target['first_name'] . ' ' . $target['last_name']) . ' has no email address on file — add one first.');
    redirect($redirect);
}
if (!empty($target['last_login_at'])) {
    flash('error', trim($target['first_name'] . ' ' . $target['last_name']) . ' has already logged in — no invite needed.');
    redirect($redirect);
}

$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', strtotime('+10 days'));

db()->prepare(
    'UPDATE users SET invite_token = ?, invite_sent_at = NOW(), invite_expires_at = ? WHERE id = ? AND association_id = ?'
)->execute([$tokenHash, $expiresAt, $userId, $assocId]);

$scheme    = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'badasshoa.com';
$inviteUrl = "$scheme://$host/invite.php?token=$rawToken";
$firstName = $target['first_name'] ?: 'Neighbor';
$resend    = !empty($target['invite_sent_at']);

$slug      = (string)($association['subdomain'] ?? '');
$portalUrl = $slug ? "$scheme://$host/$slug/" : "$scheme://$host/";

$assocName   = (string)$association['name'];
$senderName  = trim($user['first_name'] . ' ' . $user['last_name']);
$senderEmail = (string)($user['email'] ?? '');
$senderPhone = (string)($user['phone'] ?? '');
$intro       = $resend
    ? "We're resending your invitation to the {$assocName} community portal."
    : "Congratulations, you've been invited to join the {$assocName} community portal.";

// --- Plain text ---
$plainText =
    "Hi $firstName,\n\n"
    . "$intro\n\n"
    . "Click the link below to set your password and get started. The link expires in 10 days.\n\n"
    . $inviteUrl . "\n\n"
    . "You'll be asked to confirm your unit number and choose a password — that's all it takes.\n\n"
    . "Once you're set up, bookmark your community portal:\n$portalUrl\n\n"
    . "---\n\n"
    . "WHY CREATE AN ACCOUNT?\n\n"
    . "$assocName community portal is a single place to stay informed, connect with neighbors, and get things done.\n\n"
    . "WHAT YOU CAN DO WHEN LOGGED IN\n\n"
    . "1. See upcoming events for $assocName.\n"
    . "2. Read community announcements from your board and management.\n"
    . "3. Browse and post in the $assocName marketplace to sell, swap, or give away items to neighbors.\n"
    . "4. Search your rules and bylaws any time, from any device.\n"
    . "5. Share comments, compliments, or suggestions for the board to review.\n"
    . "6. Join or request to join community committees.\n"
    . "7. Read minutes from past board and committee meetings.\n\n"
    . "More features are coming soon to make life in $assocName even easier.\n\n"
    . "---\n\n"
    . "Questions? Reach out directly:\n\n"
    . $senderName . "\n"
    . ($senderEmail ? $senderEmail . "\n" : '')
    . ($senderPhone ? $senderPhone . "\n" : '')
    . "\n$assocName  $portalUrl\nBadassHOA  https://badasshoa.com";

// Logo URL — branding.php is a public endpoint, safe to embed in email.
$logoUrl   = $slug ? "{$scheme}://{$host}/branding.php?slug=" . urlencode($slug) : '';
$hasLogo   = $slug && !empty($association['logo_path']);
$logoBlock = $hasLogo
    ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . '" style="max-height:70px;max-width:260px;display:block;margin:0 0 12px;">'
    : '';

// --- HTML ---
$htmlEmail = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 0;">
  <tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;">
    <!-- Header -->
    <tr><td style="background:#0f1f3d;padding:28px 40px;">
      <p style="margin:0 0 12px;color:#aab4c8;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;">A Message From</p>'
      . $logoBlock
      . '<p style="margin:0;color:#ffffff;font-size:18px;font-weight:bold;">' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . '</p>
    </td></tr>
    <!-- Body -->
    <tr><td style="padding:36px 40px;color:#222222;font-size:15px;line-height:1.6;">
      <p style="margin:0 0 16px;">Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',</p>
      <p style="margin:0 0 24px;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>
      <p style="margin:0 0 8px;">Click below to set your password and get started. The link expires in 10 days.</p>
      <p style="margin:0 0 28px;text-align:center;">
        <a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#f05a28;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 32px;border-radius:6px;font-size:16px;">Set my password &rarr;</a>
      </p>
      <p style="margin:0 0 24px;font-size:13px;color:#666;">You\'ll be asked to confirm your unit number and choose a password &mdash; that\'s all it takes.</p>
      <p style="margin:0 0 8px;">Once you\'re set up, bookmark your community portal:</p>
      <p style="margin:0 0 28px;">
        <a href="' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0f1f3d;font-weight:bold;">' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '</a>
      </p>
      <hr style="border:none;border-top:1px solid #eeeeee;margin:28px 0;">
      <h3 style="margin:0 0 8px;font-size:16px;color:#0f1f3d;">Why create an account?</h3>
      <p style="margin:0 0 20px;">' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . ' community portal is a single place to stay informed, connect with neighbors, and get things done.</p>
      <h3 style="margin:0 0 12px;font-size:16px;color:#0f1f3d;">What you can do when logged in</h3>
      <ol style="margin:0 0 20px;padding-left:20px;line-height:1.8;">
        <li>See upcoming events for ' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . '.</li>
        <li>Read community announcements from your board and management.</li>
        <li>Browse and post in the ' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . ' marketplace to sell, swap, or give away items to neighbors.</li>
        <li>Search your rules and bylaws any time, from any device.</li>
        <li>Share comments, compliments, or suggestions for the board to review.</li>
        <li>Join or request to join community committees.</li>
        <li>Read minutes from past board and committee meetings.</li>
      </ol>
      <p style="margin:0 0 28px;color:#555;">More features are coming soon to make life in ' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . ' even easier.</p>
      <hr style="border:none;border-top:1px solid #eeeeee;margin:28px 0;">
      <p style="margin:0 0 4px;font-size:14px;"><strong>Questions? Reach out directly:</strong></p>
      <p style="margin:0 0 2px;font-size:14px;">' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') . '</p>'
      . ($senderEmail ? '<p style="margin:0 0 2px;font-size:14px;"><a href="mailto:' . htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8') . '" style="color:#0f1f3d;">' . htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8') . '</a></p>' : '')
      . ($senderPhone ? '<p style="margin:0 0 2px;font-size:14px;">' . htmlspecialchars($senderPhone, ENT_QUOTES, 'UTF-8') . '</p>' : '') . '
    </td></tr>
    <!-- Footer -->
    <tr><td style="background:#f8f7f4;padding:20px 40px;border-top:1px solid #eeeeee;text-align:center;font-size:13px;color:#888888;">
      <a href="' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0f1f3d;font-weight:bold;text-decoration:none;">' . htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8') . '</a>
      &nbsp;via&nbsp;
      <a href="https://badasshoa.com" style="color:#f05a28;font-weight:bold;text-decoration:none;">BadassHOA</a>
    </td></tr>
  </table>
  </td></tr>
</table>
</body></html>';

send_mail(
    (string)$target['email'],
    "You're invited to join {$assocName} on BadassHOA",
    $plainText,
    $htmlEmail
);

$name = trim($target['first_name'] . ' ' . $target['last_name']) ?: (string)$target['email'];
audit('user.invite_sent', ['email' => $target['email'], 'resend' => $resend], $userId, 'user');
flash('success', ($resend ? 'Invite resent' : 'Invite sent') . " to $name.");
redirect($redirect);

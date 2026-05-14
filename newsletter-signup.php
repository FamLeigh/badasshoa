<?php
// Processes newsletter signup from the public community landing page.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();

// Honeypot — bots fill it, humans don't.
if (!empty($_POST['website'])) { http_response_code(200); exit; }

$assocId = (int)($_POST['association_id'] ?? 0);
$email   = trim(strtolower((string)($_POST['email'] ?? '')));
$name    = trim((string)($_POST['name'] ?? ''));
$back    = (string)($_POST['back_url'] ?? '/');

if (!$assocId || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please enter a valid email address.');
    redirect($back);
}

// Verify the association exists.
$a = db()->prepare('SELECT id FROM associations WHERE id=?');
$a->execute([$assocId]);
if (!$a->fetch()) { http_response_code(404); exit; }

// Check existing subscription.
$chk = db()->prepare('SELECT id, status FROM newsletter_subscribers WHERE association_id=? AND email=?');
$chk->execute([$assocId, $email]);
$existing = $chk->fetch();

if ($existing) {
    if ($existing['status'] === 'active') {
        flash('info', 'You\'re already subscribed.');
    } else {
        // Re-subscribe.
        db()->prepare("UPDATE newsletter_subscribers SET status='active', unsubscribed_at=NULL, name=?, subscribed_at=NOW() WHERE id=?")
            ->execute([$name ?: null, (int)$existing['id']]);
        flash('success', 'Welcome back! You\'re subscribed.');
    }
} else {
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO newsletter_subscribers (association_id, email, name, token) VALUES (?,?,?,?)'
    )->execute([$assocId, $email, $name ?: null, $token]);
    flash('success', 'Thanks! You\'re subscribed to community updates.');
}

redirect($back);

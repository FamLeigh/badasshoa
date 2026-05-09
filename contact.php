<?php
// Public contact-form endpoint. POSTed from the per-association landing page.
// CSRF-protected, honeypot-protected, validates inputs, sends email to the
// association's contact_email (or admin_email fallback).
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}

csrf_check();

// Honeypot — bots will fill this; real users won't see it
if (!empty($_POST['website'] ?? '')) {
    // Pretend we sent it; quietly drop
    redirect('/' . preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['slug'] ?? ''))) . '/?contacted=1');
}

$slug    = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_POST['slug'] ?? ''))));
$name    = trim((string)($_POST['name']    ?? ''));
$email   = trim((string)($_POST['email']   ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

// Look up the association so we know where to send + that the slug is real
$assoc = null;
if ($slug !== '') {
    $stmt = db()->prepare('SELECT id, name, contact_email FROM associations WHERE subdomain = ? AND public_landing_enabled = 1');
    $stmt->execute([$slug]);
    $assoc = $stmt->fetch() ?: null;
}
if (!$assoc) {
    flash('error', 'Could not find that community.');
    redirect('/');
}

// Validate
$errors = [];
if ($name === '')                                            $errors[] = 'Your name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))              $errors[] = 'A valid email';
if (mb_strlen($message) < 10)                                $errors[] = 'A message (at least 10 characters)';
if ($errors) {
    flash('error', 'Please provide: ' . implode(', ', $errors) . '.');
    redirect('/' . $slug . '/#contact');
}

// Recipient: association's public contact_email, fallback to platform admin_email
$recipient = $assoc['contact_email'] ?: (config()['app']['admin_email'] ?? 'admin@badasshoa.com');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

send_mail(
    $recipient,
    "New message via {$assoc['name']} community page",
    "From:  $name <$email>\n"
    . "Sent via: " . (config()['app']['base_url'] ?? '') . "/$slug/\n"
    . "IP:    $ipAddress\n"
    . "\n"
    . "Message:\n"
    . "--------\n"
    . $message
    . "\n--------\n"
);

audit('landing.contact_submitted', [
    'association_id' => (int)$assoc['id'],
    'name' => $name,
    'email' => $email,
    'message_len' => mb_strlen($message),
], (int)$assoc['id'], 'association');

flash('success', "Thanks $name — we'll be in touch.");
redirect('/' . $slug . '/?contacted=1#contact');

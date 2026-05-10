<?php
// Public endpoint that serves a user's avatar from /storage/ (which isn't
// web-accessible). Mirrors /branding.php for association logos. No auth gate:
// avatars are addressable by user id, and the URL is only embedded where the
// user appears in the UI. Cached for a day.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || empty($row['avatar_path'])) { http_response_code(404); die('No avatar'); }

$abs = storage_path((string)$row['avatar_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

$mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'image/png') : 'image/png';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($abs);

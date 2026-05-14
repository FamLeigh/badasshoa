<?php
// Public endpoint that serves an event image from /storage/.
// No auth required — same pattern as user-avatar.php. URLs are unguessable
// (UUID-based filenames), and event images are non-sensitive.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare('SELECT image_path FROM events WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || empty($row['image_path'])) { http_response_code(404); die('No image'); }

$abs = storage_path((string)$row['image_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

$mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'image/jpeg') : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);

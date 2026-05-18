<?php
// Public endpoint for announcement images — no auth required.
// URLs are unguessable (UUID-based filenames). Same pattern as event-image.php.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare('SELECT image_path FROM announcements WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || empty($row['image_path'])) { http_response_code(404); die('No image'); }

$abs = storage_path((string)$row['image_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

$mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'image/jpeg') : 'image/jpeg';
$etag = '"' . md5_file($abs) . '"';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, no-cache');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}
readfile($abs);

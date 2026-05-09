<?php
// Public media endpoint — serves a media row only if visibility='public'.
// /dashboard/file.php requires auth; this is the unauthenticated cousin
// for things like the community landing page's photo gallery.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare("SELECT file_path, file_type, file_name FROM media WHERE id = ? AND visibility = 'public'");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); die('Not found'); }

$abs = storage_path((string)$row['file_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

header('Content-Type: ' . ($row['file_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
$fname = $row['file_name'] ?: basename($abs);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $fname) . '"');
readfile($abs);

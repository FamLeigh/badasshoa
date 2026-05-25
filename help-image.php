<?php
// Serve help topic images from storage/uploads/help/.
// Requires any authenticated session (dashboard or admin).
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$filename = basename((string)($_GET['f'] ?? ''));
if (!$filename || !preg_match('/^[0-9a-f]+\.(png|jpg|jpeg|gif|webp)$/i', $filename)) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/storage/uploads/help/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$types = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);

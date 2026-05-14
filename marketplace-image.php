<?php
// Serves marketplace listing photos — auth + tenant check.
require_once __DIR__ . '/includes/auth.php';
require_login();

$assocId = (int)($_SESSION['association_id'] ?? 0);
$lid     = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT association_id, photo_path FROM marketplace_listings WHERE id=?');
$stmt->execute([$lid]);
$row = $stmt->fetch();

if (!$row || (int)$row['association_id'] !== $assocId || empty($row['photo_path'])) {
    http_response_code(404); exit;
}

$path = __DIR__ . '/storage/uploads/' . $assocId . '/' . $row['photo_path'];
if (!is_file($path)) { http_response_code(404); exit; }

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg','jpeg' => 'image/jpeg',
    'png'        => 'image/png',
    'gif'        => 'image/gif',
    'webp'       => 'image/webp',
    default      => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
readfile($path);

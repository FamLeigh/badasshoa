<?php
// Serves marketplace listing photos for the lobby TV — token-authenticated, no session needed.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

$token = trim((string)($_GET['token'] ?? ''));
$lid   = (int)($_GET['id'] ?? 0);

if ($token === '' || $lid === 0) { http_response_code(404); exit; }

// Validate token → association
$stmt = db()->prepare('SELECT id FROM associations WHERE tv_token = ? AND status IN ("active","trial") LIMIT 1');
$stmt->execute([$token]);
$assoc = $stmt->fetch();
if (!$assoc) { http_response_code(404); exit; }

$assocId = (int)$assoc['id'];

// Fetch listing — must belong to this association and have a photo
$stmt = db()->prepare('SELECT photo_path FROM marketplace_listings WHERE id = ? AND association_id = ? AND status = "active"');
$stmt->execute([$lid, $assocId]);
$row = $stmt->fetch();
if (!$row || empty($row['photo_path'])) { http_response_code(404); exit; }

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
header('Cache-Control: public, max-age=3600');
readfile($path);

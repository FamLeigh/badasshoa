<?php
// Public listing photo — no auth, gated by association_id param.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id  = (int)($_GET['id']  ?? 0);
$aid = (int)($_GET['aid'] ?? 0);
if (!$id || !$aid) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare("SELECT photo_path FROM property_listings WHERE id=? AND association_id=? AND status='active'");
$stmt->execute([$id, $aid]);
$row = $stmt->fetch();
if (!$row || empty($row['photo_path'])) { http_response_code(404); die('Not found'); }

$rel = 'listings/' . basename((string)$row['photo_path']);
$abs = storage_path($rel);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) { 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', default => 'image/jpeg' };

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);

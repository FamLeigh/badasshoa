<?php
// Public endpoint that serves an association's logo from /storage/.
// Storage isn't web-accessible (.htaccess denies it) so we proxy through PHP.
// No auth required — branding assets are public by design.
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id   = (int)($_GET['id'] ?? 0);
$slug = trim((string)($_GET['slug'] ?? ''));
$kind = ($_GET['kind'] ?? '') === 'hero' ? 'hero' : 'logo';
$col  = $kind === 'hero' ? 'hero_image_path' : 'logo_path';

$assoc = null;
if ($id) {
    $stmt = db()->prepare("SELECT id, $col AS path FROM associations WHERE id = ?");
    $stmt->execute([$id]);
    $assoc = $stmt->fetch() ?: null;
} elseif ($slug !== '') {
    $stmt = db()->prepare("SELECT id, $col AS path FROM associations WHERE subdomain = ?");
    $stmt->execute([$slug]);
    $assoc = $stmt->fetch() ?: null;
}

if (!$assoc || empty($assoc['path'])) {
    http_response_code(404);
    die('No image');
}

$abs = storage_path((string)$assoc['path']);
if (!is_file($abs)) {
    http_response_code(404);
    die('File missing');
}

$mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'image/png') : 'image/png';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=86400'); // 1 day; bust by changing logo file
header('X-Content-Type-Options: nosniff');
readfile($abs);

<?php
// Image upload endpoint for the help topic WYSIWYG editor (admin only).
// Stores files in storage/uploads/help/ and returns { ok, url } where the URL
// is served by help-image.php at the project root.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php'; // enforces super_admin

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Accept CSRF token from either form field or X-CSRF header (JS fetch sends the header).
$token = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
if (!hash_equals($_SESSION['_csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No file or upload error']);
    exit;
}

if ($_FILES['file']['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Max image size is 8 MB']);
    exit;
}

$allowed = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!isset($allowed[$ext])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image must be PNG, JPG, GIF, or WEBP']);
    exit;
}

$dir  = __DIR__ . '/../storage/uploads/help';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$filename = bin2hex(random_bytes(12)) . '.' . $ext;
$dest     = $dir . '/' . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save image']);
    exit;
}

echo json_encode([
    'ok'  => true,
    'url' => '/help-image.php?f=' . urlencode($filename),
]);

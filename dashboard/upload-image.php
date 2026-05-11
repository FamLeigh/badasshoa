<?php
// Image upload endpoint for the rule editor (and any other in-page WYSIWYG that needs it).
// Stores the file to /storage/uploads/{association_id}/media/ and creates a media row,
// then returns { ok, id, url } where the URL is served by the existing file gatekeeper.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json');

$user      = current_user();
$canManage = role_can_manage(viewing_role());

if (!$canManage) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// CSRF — accept either form field or X-CSRF header (so JS fetch can send it).
$token = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
if (!hash_equals($_SESSION['_csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}
if ($_FILES['file']['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Max image size is 8 MB']);
    exit;
}
if (storage_over_quota_by($association, (int)$_FILES['file']['size'])) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Storage quota exceeded — delete something or upgrade.']);
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

$newName = uuid_filename($_FILES['file']['name']);
$relDir  = "uploads/$assocId/media";
$absDir  = storage_path($relDir);
ensure_dir($absDir);
$relPath = "$relDir/$newName";
$absPath = "$absDir/$newName";

if (!move_uploaded_file($_FILES['file']['tmp_name'], $absPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save image']);
    exit;
}

$stmt = db()->prepare(
    'INSERT INTO media (association_id, uploaded_by, file_path, file_name, file_type, caption, category, visibility, linked_type)
     VALUES (?, ?, ?, ?, ?, NULL, ?, "private", "general")'
);
$stmt->execute([
    $assocId, (int)$user['id'], $relPath,
    $_FILES['file']['name'], $allowed[$ext], 'Rule images',
]);
$mediaId = (int)db()->lastInsertId();

audit('media.uploaded.editor', ['source' => 'rule_editor'], $mediaId, 'media');

echo json_encode([
    'ok'  => true,
    'id'  => $mediaId,
    'url' => '/dashboard/file.php?type=media&id=' . $mediaId,
]);

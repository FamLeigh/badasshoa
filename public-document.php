<?php
// Public document endpoint — serves a document only if access_level='public'.
// Counterpart to /dashboard/file.php (which requires login).
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare(
    "SELECT file_path, file_type, title, association_id
     FROM documents
     WHERE id = ? AND access_level = 'public'"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); die('Not found'); }

// Optional: only serve if the association's landing is enabled
$assocStmt = db()->prepare('SELECT public_landing_enabled FROM associations WHERE id = ?');
$assocStmt->execute([$row['association_id']]);
if ((int)$assocStmt->fetchColumn() !== 1) {
    http_response_code(404);
    die('Not available');
}

$abs = storage_path((string)$row['file_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

audit('public_document.served', ['document_id' => $id], $id, 'document');

header('Content-Type: ' . ($row['file_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$row['title']) . '"');
readfile($abs);

<?php
// Attachment gatekeeper — serves images and PDFs attached to work orders (type=wo)
// or ARC requests (type=arc). Requires login + same association as the record.
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !in_array($type, ['wo', 'arc'], true)) { http_response_code(400); die('Bad request.'); }

$assocId = (int)($_SESSION['association_id'] ?? 0);

if ($type === 'wo') {
    $stmt = db()->prepare(
        'SELECT a.file_path, a.file_name, a.file_type
           FROM work_order_attachments a
           JOIN work_orders w ON w.id = a.work_order_id
          WHERE a.id = ? AND w.association_id = ?'
    );
} else {
    $stmt = db()->prepare(
        'SELECT a.file_path, a.file_name, a.file_type
           FROM arc_request_attachments a
           JOIN arc_requests r ON r.id = a.request_id
          WHERE a.id = ? AND r.association_id = ?'
    );
}
$stmt->execute([$id, $assocId]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); die('Not found.'); }

$fullPath = __DIR__ . '/storage/uploads/' . ltrim((string)$att['file_path'], '/');
if (!is_file($fullPath)) { http_response_code(404); die('File not found.'); }

$mime = (string)($att['file_type'] ?: mime_content_type($fullPath) ?: 'application/octet-stream');
$name = (string)($att['file_name'] ?: basename($fullPath));

// Inline for images; attachment prompt for PDFs.
$disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
readfile($fullPath);
exit;

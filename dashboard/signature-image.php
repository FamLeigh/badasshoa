<?php
// Gatekeeper for stored signature images. Stored outside the web root —
// served only to the submitter, occupants of the related unit, or managers.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Not found'); }

$stmt = db()->prepare(
    'SELECT id, submitter_user_id, unit_id, signature_image_path
       FROM form_submissions
      WHERE id = ? AND association_id = ?'
);
$stmt->execute([$id, $assocId]);
$row = $stmt->fetch();
if (!$row || empty($row['signature_image_path'])) { http_response_code(404); die('Not found'); }

$user      = current_user();
$canManage = role_can_manage(viewing_role());

// Authorize: signed-in viewer must be the submitter, an occupant of the
// related unit, or a manager. Signatures aren't quite as sensitive as
// medical data but they're not public — same gate as the rest of the form.
if (!$canManage && (int)$row['submitter_user_id'] !== (int)$user['id']) {
    $allowed = false;
    if (!empty($row['unit_id'])) {
        $c = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
        $c->execute([(int)$row['unit_id'], (int)$user['id']]);
        $allowed = (bool)$c->fetchColumn();
    }
    if (!$allowed) { http_response_code(403); die('Forbidden'); }
}

$abs = storage_path((string)$row['signature_image_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

// Detect content type (handles PNG / JPEG / WEBP)
$mime = 'image/png';
$head = file_get_contents($abs, false, null, 0, 12);
if ($head !== false) {
    if (str_starts_with($head, "\xFF\xD8\xFF"))                  $mime = 'image/jpeg';
    elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n"))         $mime = 'image/png';
    elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') $mime = 'image/webp';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($abs);

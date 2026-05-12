<?php
// Gatekeeper for stored signature images. Stored outside the web root —
// served only to the submitter, occupants of the related unit, or managers.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id      = (int)($_GET['id'] ?? 0);
$savedId = (int)($_GET['saved_id'] ?? 0);
if (!$id && !$savedId) { http_response_code(404); die('Not found'); }

$user      = current_user();
$canManage = role_can_manage(viewing_role());

$path = null;
if ($savedId > 0) {
    // Saved signature from the user's private library — STRICTLY owner-only.
    // Managers don't get to peek; super_admins could pull from disk if they
    // really had to but no UI surfaces it.
    $stmt = db()->prepare('SELECT user_id, image_path FROM user_signatures WHERE id = ?');
    $stmt->execute([$savedId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['image_path'])) { http_response_code(404); die('Not found'); }
    if ((int)$row['user_id'] !== (int)$user['id']) { http_response_code(403); die('Forbidden'); }
    $path = (string)$row['image_path'];
} else {
    $stmt = db()->prepare(
        'SELECT id, submitter_user_id, unit_id, signature_image_path
           FROM form_submissions
          WHERE id = ? AND association_id = ?'
    );
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['signature_image_path'])) { http_response_code(404); die('Not found'); }

    // Authorize: submitter, unit occupant, or manager.
    if (!$canManage && (int)$row['submitter_user_id'] !== (int)$user['id']) {
        $allowed = false;
        if (!empty($row['unit_id'])) {
            $c = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
            $c->execute([(int)$row['unit_id'], (int)$user['id']]);
            $allowed = (bool)$c->fetchColumn();
        }
        if (!$allowed) { http_response_code(403); die('Forbidden'); }
    }
    $path = (string)$row['signature_image_path'];
}

$abs = storage_path($path);
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

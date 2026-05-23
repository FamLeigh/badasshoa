<?php
// Gatekeeper for signed PDF copies. Access: the signer themselves or any manager.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();

$id        = (int)($_GET['id'] ?? 0);
$canManage = role_can_manage(viewing_role());
$user      = current_user();

if (!$id) { http_response_code(400); die('id required'); }

$stmt = db()->prepare(
    'SELECT s.*, d.title, d.access_level, d.unit_id
       FROM document_signatures s
       JOIN documents d ON d.id = s.document_id
      WHERE s.id = ? AND s.association_id = ?'
);
$stmt->execute([$id, $assocId]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); die('Not found'); }

// Authorize: respect the original document's access level.
// Managers always pass. Others follow the same rules as file.php.
if (!$canManage) {
    $access = (string)($row['access_level'] ?? 'members_only');
    if ($access === 'board_only') {
        http_response_code(403); die('Access denied');
    }
    if ($access === 'unit_only') {
        if (empty($row['unit_id'])) { http_response_code(403); die('Access denied'); }
        $uc = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
        $uc->execute([(int)$row['unit_id'], (int)$user['id']]);
        if (!$uc->fetchColumn()) { http_response_code(403); die('Access denied'); }
    }
    // public + members_only: any authenticated user passes.
}

$abs = storage_path((string)$row['signed_file_path']);
if (!is_file($abs)) { http_response_code(404); die('File missing'); }

$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$row['title']) . '_signed.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);

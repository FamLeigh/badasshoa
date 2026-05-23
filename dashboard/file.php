<?php
// Gatekeeper for serving files stored outside the web root.
// /dashboard/file.php?type=document&id=42
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Not found'); }

if ($type === 'document') {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Not found'); }

    // Access-level enforcement. Uses viewing_role() so view-as-homeowner
    // properly blocks restricted docs from being downloaded — exit view-as
    // mode if you actually need the file.
    if ($row['access_level'] === 'board_only' && !role_can_manage(viewing_role())) {
        http_response_code(403); die('Forbidden');
    }
    // unit_only: managers always allowed; otherwise the user must be on
    // unit_occupants for this document's unit.
    if ($row['access_level'] === 'unit_only' && !role_can_manage(viewing_role())) {
        $allowed = false;
        if (!empty($row['unit_id'])) {
            $check = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
            $check->execute([(int)$row['unit_id'], (int)$_SESSION['user_id']]);
            $allowed = (bool)$check->fetchColumn();
        }
        if (!$allowed) { http_response_code(403); die('Forbidden'); }
    }
    $relative = $row['file_path'];
    $filename = $row['title'];
    $type_h   = $row['file_type'];
} elseif ($type === 'unit_media') {
    $stmt = db()->prepare('SELECT * FROM unit_media WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Not found'); }
    // Occupants of the unit and managers can view; everyone else is blocked.
    if (!role_can_manage(viewing_role())) {
        $check = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
        $check->execute([(int)$row['unit_id'], (int)$_SESSION['user_id']]);
        if (!$check->fetchColumn()) { http_response_code(403); die('Forbidden'); }
    }
    $relative = $row['file_path'];
    $filename = $row['title'] ?: basename($relative);
    $type_h   = $row['mime_type'];
} elseif ($type === 'media') {
    $stmt = db()->prepare('SELECT * FROM media WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Not found'); }
    if ($row['visibility'] === 'private') {
        if (!role_can_manage(viewing_role())) { http_response_code(403); die('Forbidden'); }
    }
    $relative = $row['file_path'];
    $filename = $row['file_name'] ?: basename($relative);
    $type_h   = $row['file_type'];
    // Serve thumbnail when requested — fall back to original if not generated yet
    if (!empty($_GET['thumb'])) {
        $thumbName = pathinfo(basename($relative), PATHINFO_FILENAME) . '.jpg';
        $thumbRel  = dirname($relative) . '/thumbs/' . $thumbName;
        $thumbAbs  = storage_path($thumbRel);
        if (is_file($thumbAbs)) {
            $relative = $thumbRel;
            $type_h   = 'image/jpeg';
            $filename = $thumbName;
        }
        // If thumb doesn't exist yet, falls through and serves the original
    }
} elseif ($type === 'minutes_signin') {
    if (!role_can_manage(viewing_role())) { http_response_code(403); die('Forbidden'); }
    $stmt = db()->prepare('SELECT * FROM meeting_minutes WHERE id = ? AND association_id = ?');
    $stmt->execute([$id, $assocId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['signin_sheet_path'])) { http_response_code(404); die('Not found'); }
    $relative = $row['signin_sheet_path'];
    $filename = 'signin-sheet-' . $id . '.' . pathinfo($relative, PATHINFO_EXTENSION);
    $type_h   = $row['signin_sheet_type'] ?: 'application/octet-stream';
} else {
    http_response_code(400); die('Bad request');
}

$absPath = storage_path($relative);
if (!is_file($absPath)) { http_response_code(404); die('File missing'); }

audit('file.served', ['type' => $type, 'id' => $id], $id, $type);

header('Content-Type: ' . ($type_h ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($absPath);

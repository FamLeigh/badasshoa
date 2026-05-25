<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();

if (!can_do('book_amenity')) {
    http_response_code(403);
    die('Access denied.');
}

$canManage  = role_can_manage(viewing_role());
$myUserId   = (int)$_SESSION['user_id'];
$errors     = [];

/**
 * Insert a community-events row reflecting an approved booking and return the
 * new event id. Open bookings are titled with the purpose so neighbors know
 * what they're invited to; private bookings only reveal the reserver's
 * last name so the calendar shows the space is in use without leaking the
 * occasion. Both use audience='members' (logged-in members only — never the
 * public landing page) per the design call locked in 2026-05-25.
 */
function create_booking_event(
    int $assocId,
    string $amenityName,
    string $amenityLocation,
    string $bookingDate,
    string $startTime,
    string $endTime,
    string $eventKind,
    ?string $purpose,
    string $bookerFullName,
    int $createdBy
): int {
    $startsAt = $bookingDate . ' ' . substr($startTime, 0, 8);
    $endsAt   = $bookingDate . ' ' . substr($endTime,   0, 8);
    $purpose  = $purpose !== null ? trim($purpose) : '';

    // "Last Name" from the full name; fall back to the whole string if it's a
    // single token (initials-only members, etc.).
    $parts    = preg_split('/\s+/', trim($bookerFullName)) ?: [];
    $lastName = count($parts) > 1 ? end($parts) : ($bookerFullName !== '' ? $bookerFullName : 'A member');

    if ($eventKind === 'open') {
        $title = $amenityName . ' — ' . ($purpose !== '' ? $purpose : 'open gathering');
        $desc  = "Open to all members. Hosted by {$lastName}."
               . ($purpose !== '' ? "\n\nAbout: {$purpose}" : '');
    } else {
        $title = $amenityName . ' — private event (reserved by ' . $lastName . ')';
        $desc  = 'Space reserved for a private event. Not open to other members.';
    }

    $stmt = db()->prepare(
        'INSERT INTO events
            (association_id, title, description, location, starts_at, ends_at, audience, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $assocId,
        mb_substr($title, 0, 255),
        $desc,
        $amenityLocation !== '' ? mb_substr($amenityLocation, 0, 255) : null,
        $startsAt,
        $endsAt,
        'members',
        $createdBy,
    ]);
    return (int)db()->lastInsertId();
}

$tab           = $_GET['tab']  ?? 'amenities';
$editId        = (int)($_GET['edit']  ?? 0);   // 0=list, -1=new, >0=edit
$bookAmenityId = (int)($_GET['book']  ?? 0);
$bFilter       = $_GET['bstatus'] ?? 'all';

// ── POST handlers ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // ── save_amenity (board only) ───────────────────────────────────────
    if ($action === 'save_amenity') {
        if (!$canManage) { http_response_code(403); die('Forbidden.'); }

        $saveId      = (int)($_POST['id'] ?? 0);
        $name        = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000) ?: null;
        $location    = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 200) ?: null;
        $capRaw      = (string)($_POST['capacity'] ?? '');
        $capacity    = $capRaw !== '' ? max(1, (int)$capRaw) : null;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;
        $maxAdvance  = max(1, min(365, (int)($_POST['max_advance_days'] ?? 90)));
        $maxDuration = max(1, min(24,  (int)($_POST['max_duration_hours'] ?? 4)));
        $reqApproval = isset($_POST['requires_approval']) ? 1 : 0;
        $depositRaw  = (string)($_POST['deposit'] ?? '');
        $depositCents = $depositRaw !== '' ? (int)round((float)preg_replace('/[^0-9.]/', '', $depositRaw) * 100) : null;
        if ($depositCents !== null && $depositCents <= 0) $depositCents = null;
        $bookingInstructions = mb_substr(trim((string)($_POST['booking_instructions'] ?? '')), 0, 2000) ?: null;
        $sortOrder   = max(0, min(255, (int)($_POST['sort_order'] ?? 0)));

        if ($name === '') $errors[] = 'Name is required.';

        $newPhotoPath = null;
        $removePhoto  = !empty($_POST['remove_photo']);

        if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $origName = (string)($_FILES['photo']['name'] ?? '');
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $ext      = preg_replace('/[^a-z0-9]/', '', $ext);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $errors[] = 'Photo must be JPG, PNG, GIF, or WebP.';
            } elseif ((int)($_FILES['photo']['size'] ?? 0) > 10 * 1024 * 1024) {
                $errors[] = 'Photo must be under 10 MB.';
            } elseif (empty($errors)) {
                $dir = storage_path('uploads/' . $assocId . '/amenities');
                ensure_dir($dir);
                $fname = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file((string)$_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
                    $newPhotoPath = 'amenities/' . $fname;
                } else {
                    $errors[] = 'Photo upload failed — try again.';
                }
            }
        }

        if (empty($errors)) {
            if ($saveId > 0) {
                $existing = db()->prepare('SELECT id, photo_path FROM amenities WHERE id = ? AND association_id = ?');
                $existing->execute([$saveId, $assocId]);
                $existingRow = $existing->fetch();

                if ($existingRow) {
                    $oldPhoto = (string)($existingRow['photo_path'] ?? '');

                    if ($newPhotoPath !== null) {
                        if ($oldPhoto !== '') {
                            $oldAbs = storage_path('uploads/' . $assocId . '/' . $oldPhoto);
                            if (is_file($oldAbs)) @unlink($oldAbs);
                        }
                        $photoSql  = ', photo_path = ?';
                        $photoArgs = [$newPhotoPath];
                    } elseif ($removePhoto && $oldPhoto !== '') {
                        $oldAbs = storage_path('uploads/' . $assocId . '/' . $oldPhoto);
                        if (is_file($oldAbs)) @unlink($oldAbs);
                        $photoSql  = ', photo_path = NULL';
                        $photoArgs = [];
                    } else {
                        $photoSql  = '';
                        $photoArgs = [];
                    }

                    $stmt = db()->prepare(
                        'UPDATE amenities
                            SET name = ?, description = ?, location = ?, capacity = ?,
                                is_active = ?, max_advance_days = ?, max_duration_hours = ?,
                                requires_approval = ?, deposit_cents = ?,
                                booking_instructions = ?, sort_order = ?'
                        . $photoSql .
                        ' WHERE id = ? AND association_id = ?'
                    );
                    $stmt->execute(array_merge(
                        [
                            $name, $description, $location, $capacity,
                            $isActive, $maxAdvance, $maxDuration,
                            $reqApproval, $depositCents,
                            $bookingInstructions, $sortOrder,
                        ],
                        $photoArgs,
                        [$saveId, $assocId]
                    ));
                    audit('amenity.updated', ['name' => $name], $saveId, 'amenity');
                    flash('success', 'Amenity updated.');
                    redirect('/dashboard/amenities.php');
                }
            } else {
                $ins = db()->prepare(
                    'INSERT INTO amenities
                        (association_id, name, description, location, capacity,
                         is_active, max_advance_days, max_duration_hours,
                         requires_approval, deposit_cents, booking_instructions,
                         sort_order, photo_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $assocId, $name, $description, $location, $capacity,
                    $isActive, $maxAdvance, $maxDuration,
                    $reqApproval, $depositCents, $bookingInstructions,
                    $sortOrder, $newPhotoPath,
                ]);
                $newId = (int)db()->lastInsertId();
                audit('amenity.created', ['name' => $name], $newId, 'amenity');
                flash('success', 'Amenity added.');
                redirect('/dashboard/amenities.php');
            }
        }
    }

    // ── delete_amenity (board only) ─────────────────────────────────────
    if ($action === 'delete_amenity') {
        if (!$canManage) { http_response_code(403); die('Forbidden.'); }

        $delId = (int)($_POST['id'] ?? 0);
        $row   = db()->prepare('SELECT photo_path FROM amenities WHERE id = ? AND association_id = ?');
        $row->execute([$delId, $assocId]);
        $delRow = $row->fetch();
        if ($delRow) {
            // Clear any community events this amenity's bookings created — bookings
            // get wiped by ON DELETE CASCADE on the amenity, but events would be left
            // behind on the calendar otherwise.
            $evStmt = db()->prepare(
                'SELECT event_id FROM amenity_bookings
                  WHERE amenity_id = ? AND association_id = ? AND event_id IS NOT NULL'
            );
            $evStmt->execute([$delId, $assocId]);
            $eventIds = array_filter(array_map(fn ($r) => (int)$r['event_id'], $evStmt->fetchAll()));
            if ($eventIds) {
                $ph = implode(',', array_fill(0, count($eventIds), '?'));
                $delEv = db()->prepare("DELETE FROM events WHERE association_id = ? AND id IN ($ph)");
                $delEv->execute(array_merge([$assocId], $eventIds));
            }

            if (!empty($delRow['photo_path'])) {
                $f = storage_path('uploads/' . $assocId . '/' . $delRow['photo_path']);
                if (is_file($f)) @unlink($f);
            }
            db()->prepare('DELETE FROM amenities WHERE id = ? AND association_id = ?')
                ->execute([$delId, $assocId]);
            audit('amenity.deleted', [], $delId, 'amenity');
            flash('success', 'Amenity deleted.');
        }
        redirect('/dashboard/amenities.php');
    }

    // ── submit_booking ──────────────────────────────────────────────────
    if ($action === 'submit_booking') {
        $amenityId    = (int)($_POST['amenity_id'] ?? 0);
        $bookingDate  = (string)($_POST['booking_date'] ?? '');
        $startTime    = (string)($_POST['start_time']   ?? '');
        $endTime      = (string)($_POST['end_time']     ?? '');
        $purpose      = mb_substr(trim((string)($_POST['purpose'] ?? '')), 0, 500) ?: null;
        $attendeeRaw  = (string)($_POST['attendee_count'] ?? '');
        $attendeeCount = $attendeeRaw !== '' ? max(1, (int)$attendeeRaw) : null;
        $eventKind    = ($_POST['event_kind'] ?? 'private') === 'open' ? 'open' : 'private';

        $amenityStmt = db()->prepare(
            'SELECT * FROM amenities WHERE id = ? AND association_id = ? AND is_active = 1'
        );
        $amenityStmt->execute([$amenityId, $assocId]);
        $amenityRow = $amenityStmt->fetch();

        if (!$amenityRow) {
            $errors[] = 'That amenity is not available.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
            $errors[] = 'Please select a valid date.';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            $errors[] = 'Please enter a valid start time.';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            $errors[] = 'Please enter a valid end time.';
        } else {
            $todayUtc     = gmdate('Y-m-d');
            $maxDateUtc   = gmdate('Y-m-d', strtotime('+' . (int)$amenityRow['max_advance_days'] . ' days'));

            if ($bookingDate < $todayUtc) {
                $errors[] = 'Booking date must be today or in the future.';
            } elseif ($bookingDate > $maxDateUtc) {
                $errors[] = 'You can only book up to ' . (int)$amenityRow['max_advance_days'] . ' days in advance.';
            } elseif ($startTime >= $endTime) {
                $errors[] = 'End time must be after start time.';
            } else {
                $startSecs = strtotime('1970-01-01 ' . $startTime . ' UTC');
                $endSecs   = strtotime('1970-01-01 ' . $endTime   . ' UTC');
                $durationH = ($endSecs - $startSecs) / 3600;
                if ($durationH > (int)$amenityRow['max_duration_hours']) {
                    $errors[] = 'Maximum booking duration is ' . (int)$amenityRow['max_duration_hours'] . ' hour(s).';
                }

                if ($attendeeCount !== null && $amenityRow['capacity'] !== null) {
                    if ($attendeeCount > (int)$amenityRow['capacity']) {
                        $errors[] = 'Attendee count exceeds this amenity\'s capacity of ' . (int)$amenityRow['capacity'] . '.';
                    }
                }

                if (empty($errors)) {
                    $overlapStmt = db()->prepare(
                        "SELECT COUNT(*) FROM amenity_bookings
                          WHERE amenity_id = ?
                            AND booking_date = ?
                            AND status IN ('pending','approved')
                            AND start_time < ?
                            AND end_time   > ?"
                    );
                    $overlapStmt->execute([$amenityId, $bookingDate, $endTime, $startTime]);
                    if ((int)$overlapStmt->fetchColumn() > 0) {
                        $errors[] = 'That time slot is already booked or pending. Please choose a different time.';
                    }
                }
            }
        }

        if (empty($errors) && $amenityRow) {
            $status = ((int)$amenityRow['requires_approval'] === 0) ? 'approved' : 'pending';
            $ins = db()->prepare(
                'INSERT INTO amenity_bookings
                    (association_id, amenity_id, user_id, booking_date, start_time, end_time,
                     purpose, attendee_count, event_kind, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $assocId, $amenityId, $myUserId,
                $bookingDate, $startTime, $endTime,
                $purpose, $attendeeCount, $eventKind, $status,
            ]);
            $newBookingId = (int)db()->lastInsertId();
            audit('booking.submitted', ['amenity_id' => $amenityId, 'date' => $bookingDate, 'status' => $status, 'kind' => $eventKind], $newBookingId, 'amenity_booking');

            // Auto-approved bookings get their event created immediately so the
            // calendar reflects the reservation without waiting on board review.
            if ($status === 'approved') {
                $eventId = create_booking_event(
                    $assocId,
                    (string)$amenityRow['name'],
                    (string)($amenityRow['location'] ?? ''),
                    $bookingDate, $startTime, $endTime,
                    $eventKind, $purpose,
                    (string)($_SESSION['name'] ?? ''),
                    $myUserId
                );
                if ($eventId > 0) {
                    db()->prepare('UPDATE amenity_bookings SET event_id = ? WHERE id = ?')
                        ->execute([$eventId, $newBookingId]);
                }
            }

            if ($status === 'pending') {
                $boardStmt = db()->prepare(
                    "SELECT email, CONCAT(first_name,' ',last_name) AS full_name
                       FROM users
                      WHERE association_id = ?
                        AND status = 'active'
                        AND role IN ('board_member','board_admin','property_manager')
                        AND email IS NOT NULL"
                );
                $boardStmt->execute([$assocId]);
                $memberName = trim((string)($_SESSION['name'] ?? 'A member'));
                $assocName  = (string)($association['name'] ?? '');
                foreach ($boardStmt->fetchAll() as $bUser) {
                    if (is_placeholder_email((string)$bUser['email'])) continue;
                    $subject = "{$assocName}: Amenity booking request for " . (string)$amenityRow['name'];
                    $body    = "Hi {$bUser['full_name']},\n\n"
                             . "{$memberName} has submitted a booking request:\n\n"
                             . "  Amenity: " . (string)$amenityRow['name'] . "\n"
                             . "  Date:    {$bookingDate}\n"
                             . "  Time:    {$startTime} – {$endTime}\n"
                             . ($purpose ? "  Purpose: {$purpose}\n" : '')
                             . "\nReview it at: https://badasshoa.com/dashboard/amenities.php?tab=bookings\n\n"
                             . "— BadassHOA";
                    send_mail((string)$bUser['email'], $subject, $body);
                }
                flash('success', 'Booking request submitted. The board will review it shortly.');
            } else {
                flash('success', 'Booking confirmed for ' . date('M j, Y', strtotime($bookingDate)) . '.');
            }
            redirect('/dashboard/amenities.php');
        }
    }

    // ── review_booking (board only) ─────────────────────────────────────
    if ($action === 'review_booking') {
        if (!$canManage) { http_response_code(403); die('Forbidden.'); }

        $bookingId  = (int)($_POST['booking_id'] ?? 0);
        $newStatus  = (string)($_POST['status'] ?? '');
        $boardNotes = mb_substr(trim((string)($_POST['board_notes'] ?? '')), 0, 2000) ?: null;

        if (!in_array($newStatus, ['approved', 'denied', 'cancelled'], true)) {
            flash('error', 'Invalid status.');
            redirect('/dashboard/amenities.php?tab=bookings');
        }

        $bkStmt = db()->prepare(
            'SELECT ab.*, u.email, u.first_name, u.last_name, a.name AS amenity_name, a.location AS amenity_location
               FROM amenity_bookings ab
               JOIN users u      ON u.id = ab.user_id
               JOIN amenities a  ON a.id = ab.amenity_id
              WHERE ab.id = ? AND ab.association_id = ?'
        );
        $bkStmt->execute([$bookingId, $assocId]);
        $bk = $bkStmt->fetch();

        if ($bk) {
            db()->prepare(
                'UPDATE amenity_bookings
                    SET status = ?, board_notes = ?, updated_at = NOW()
                  WHERE id = ? AND association_id = ?'
            )->execute([$newStatus, $boardNotes, $bookingId, $assocId]);

            // Going to approved → create the community event if one doesn't
            // already exist. Anything else (denied, cancelled) → kill the event
            // so it stops cluttering the calendar.
            if ($newStatus === 'approved' && empty($bk['event_id'])) {
                $bookerName = trim((string)$bk['first_name'] . ' ' . (string)$bk['last_name']);
                $eventId = create_booking_event(
                    $assocId,
                    (string)$bk['amenity_name'],
                    (string)($bk['amenity_location'] ?? ''),
                    (string)$bk['booking_date'],
                    (string)$bk['start_time'],
                    (string)$bk['end_time'],
                    (string)($bk['event_kind'] ?? 'private'),
                    (string)($bk['purpose'] ?? ''),
                    $bookerName,
                    (int)$bk['user_id']
                );
                if ($eventId > 0) {
                    db()->prepare('UPDATE amenity_bookings SET event_id = ? WHERE id = ?')
                        ->execute([$eventId, $bookingId]);
                }
            } elseif ($newStatus !== 'approved' && !empty($bk['event_id'])) {
                db()->prepare('DELETE FROM events WHERE id = ? AND association_id = ?')
                    ->execute([(int)$bk['event_id'], $assocId]);
                db()->prepare('UPDATE amenity_bookings SET event_id = NULL WHERE id = ?')
                    ->execute([$bookingId]);
            }

            audit('booking.reviewed', ['status' => $newStatus, 'booking_id' => $bookingId], $bookingId, 'amenity_booking');

            $memberEmail = (string)($bk['email'] ?? '');
            if ($memberEmail !== '' && !is_placeholder_email($memberEmail)) {
                $mName      = trim((string)$bk['first_name'] . ' ' . (string)$bk['last_name']) ?: 'there';
                $assocName  = (string)($association['name'] ?? 'Your HOA');
                $amenName   = (string)($bk['amenity_name'] ?? 'the amenity');
                $dateLabel  = date('M j, Y', strtotime((string)$bk['booking_date']));
                $timeLabel  = substr((string)$bk['start_time'], 0, 5) . ' – ' . substr((string)$bk['end_time'], 0, 5);

                $statusLabel = match ($newStatus) {
                    'approved'  => 'approved',
                    'denied'    => 'denied',
                    'cancelled' => 'cancelled',
                    default     => $newStatus,
                };

                $subject = "{$assocName}: Your booking for {$amenName} has been {$statusLabel}";
                $body    = "Hi {$mName},\n\n"
                         . "Your booking request for {$amenName} on {$dateLabel} ({$timeLabel}) has been {$statusLabel}.\n"
                         . ($boardNotes ? "\nNote from the board:\n{$boardNotes}\n" : '')
                         . "\n— {$assocName}";
                send_mail($memberEmail, $subject, $body);
            }

            flash('success', 'Booking ' . $newStatus . '.');
        }
        redirect('/dashboard/amenities.php?tab=bookings');
    }

    // ── cancel_booking (member cancels own pending booking) ─────────────
    if ($action === 'cancel_booking') {
        $cancelId = (int)($_POST['booking_id'] ?? 0);
        $exStmt = db()->prepare(
            "SELECT event_id FROM amenity_bookings
              WHERE id = ? AND association_id = ? AND user_id = ? AND status = 'pending'"
        );
        $exStmt->execute([$cancelId, $assocId, $myUserId]);
        $exRow = $exStmt->fetch();
        if ($exRow) {
            db()->prepare(
                "UPDATE amenity_bookings
                    SET status = 'cancelled', event_id = NULL, updated_at = NOW()
                  WHERE id = ? AND association_id = ? AND user_id = ?"
            )->execute([$cancelId, $assocId, $myUserId]);
            if (!empty($exRow['event_id'])) {
                db()->prepare('DELETE FROM events WHERE id = ? AND association_id = ?')
                    ->execute([(int)$exRow['event_id'], $assocId]);
            }
            audit('booking.cancelled_by_member', [], $cancelId, 'amenity_booking');
        }
        flash('success', 'Booking cancelled.');
        redirect('/dashboard/amenities.php');
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────

// Edit row for board
$editRow = null;
if ($canManage && $editId > 0) {
    $stmt = db()->prepare('SELECT * FROM amenities WHERE id = ? AND association_id = ?');
    $stmt->execute([$editId, $assocId]);
    $editRow = $stmt->fetch() ?: null;
}

// All amenities (board sees active+inactive, members only active)
if ($canManage) {
    $amenStmt = db()->prepare(
        'SELECT * FROM amenities WHERE association_id = ? ORDER BY sort_order, name'
    );
    $amenStmt->execute([$assocId]);
} else {
    $amenStmt = db()->prepare(
        'SELECT * FROM amenities WHERE association_id = ? AND is_active = 1 ORDER BY sort_order, name'
    );
    $amenStmt->execute([$assocId]);
}
$amenities = $amenStmt->fetchAll();

// Pending count for tab badge
$pendingCountStmt = db()->prepare(
    "SELECT COUNT(*) FROM amenity_bookings WHERE association_id = ? AND status = 'pending'"
);
$pendingCountStmt->execute([$assocId]);
$pendingCount = (int)$pendingCountStmt->fetchColumn();

// Bookings for board tab
$allBookings = [];
$bookingCounts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'cancelled' => 0];
if ($canManage) {
    $allBkStmt = db()->prepare(
        'SELECT ab.*, u.first_name, u.last_name, u.unit_number, a.name AS amenity_name
           FROM amenity_bookings ab
           JOIN users u     ON u.id = ab.user_id
           JOIN amenities a ON a.id = ab.amenity_id
          WHERE ab.association_id = ?
          ORDER BY ab.booking_date DESC, ab.start_time DESC'
    );
    $allBkStmt->execute([$assocId]);
    $allBookings = $allBkStmt->fetchAll();
    foreach ($allBookings as $bk) {
        $bookingCounts['all']++;
        if (isset($bookingCounts[$bk['status']])) $bookingCounts[$bk['status']]++;
    }
}

// My bookings for member view
$myBookings = [];
if (!$canManage) {
    $myBkStmt = db()->prepare(
        'SELECT ab.*, a.name AS amenity_name
           FROM amenity_bookings ab
           JOIN amenities a ON a.id = ab.amenity_id
          WHERE ab.association_id = ? AND ab.user_id = ?
          ORDER BY ab.booking_date DESC, ab.start_time DESC'
    );
    $myBkStmt->execute([$assocId, $myUserId]);
    $myBookings = $myBkStmt->fetchAll();
}

// Amenity being booked
$bookAmenity = null;
if ($bookAmenityId > 0) {
    foreach ($amenities as $a) {
        if ((int)$a['id'] === $bookAmenityId) { $bookAmenity = $a; break; }
    }
}

$active     = 'amenities';
$page_title = 'Amenity Bookings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1280px;">

<div style="margin-bottom: var(--sp-6); display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: var(--sp-4);">
    <div>
        <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);">Amenity Bookings</h1>
        <p class="muted" style="margin: 0;">Reserve shared spaces for your community.</p>
    </div>
    <?php if ($canManage): ?>
    <a class="btn btn--primary" href="/dashboard/amenities.php?edit=-1">+ Add Amenity</a>
    <?php endif; ?>
</div>

<?php foreach (flash_take() as $f): ?>
    <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<?php if ($errors): ?>
    <div class="flash flash--error">
        <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ─────────────── BOARD VIEW ───────────────────────────────────────
if ($canManage): ?>

<!-- Tab bar -->
<div style="display: flex; gap: 0; border-bottom: 2px solid var(--color-border); margin-bottom: var(--sp-6);">
    <a href="/dashboard/amenities.php?tab=amenities"
       style="padding: var(--sp-3) var(--sp-5); font-size: var(--fs-sm); font-weight: 600; text-decoration: none; color: <?= $tab === 'amenities' ? 'var(--color-navy)' : 'var(--color-text-muted)' ?>; border-bottom: 2px solid <?= $tab === 'amenities' ? 'var(--color-navy)' : 'transparent' ?>; margin-bottom: -2px;">
        Amenities
    </a>
    <a href="/dashboard/amenities.php?tab=bookings"
       style="padding: var(--sp-3) var(--sp-5); font-size: var(--fs-sm); font-weight: 600; text-decoration: none; color: <?= $tab === 'bookings' ? 'var(--color-navy)' : 'var(--color-text-muted)' ?>; border-bottom: 2px solid <?= $tab === 'bookings' ? 'var(--color-navy)' : 'transparent' ?>; margin-bottom: -2px;">
        Bookings<?php if ($pendingCount > 0): ?> <span class="badge badge--orange" style="font-size: 11px;"><?= $pendingCount ?> pending</span><?php endif; ?>
    </a>
</div>

<?php if ($tab === 'amenities'): ?>

<?php // ── Edit / Add form ────────────────────────────────────────────────
if ($editId !== 0): ?>
<div class="card card--padded" style="margin-bottom: var(--sp-6);">
    <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-5);">
        <?= ($editId === -1 || $editRow === null) ? 'New Amenity' : 'Edit Amenity' ?>
    </h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_amenity">
        <input type="hidden" name="id" value="<?= max(0, $editId) ?>">

        <div class="form-grid form-grid--2" style="gap: var(--sp-4);">
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="am-name">Name <span style="color:var(--color-orange)">*</span></label>
                <input class="input" type="text" id="am-name" name="name" required maxlength="200"
                       value="<?= e((string)($editRow['name'] ?? $_POST['name'] ?? '')) ?>"
                       placeholder="e.g. Clubhouse, Pool Deck, BBQ Pavilion">
            </div>

            <div class="field">
                <label class="field__label" for="am-location">Location</label>
                <input class="input" type="text" id="am-location" name="location" maxlength="200"
                       value="<?= e((string)($editRow['location'] ?? $_POST['location'] ?? '')) ?>"
                       placeholder="e.g. Building A, 3rd floor">
            </div>

            <div class="field">
                <label class="field__label" for="am-capacity">Capacity (max people)</label>
                <input class="input" type="number" id="am-capacity" name="capacity" min="1"
                       value="<?= e((string)($editRow['capacity'] ?? $_POST['capacity'] ?? '')) ?>"
                       placeholder="Leave blank if unlimited">
            </div>

            <div class="field">
                <label class="field__label" for="am-advance">Max advance booking (days)</label>
                <input class="input" type="number" id="am-advance" name="max_advance_days"
                       min="1" max="365"
                       value="<?= (int)($editRow['max_advance_days'] ?? $_POST['max_advance_days'] ?? 90) ?>">
                <div class="field__hint">How many days ahead members can book. 1–365.</div>
            </div>

            <div class="field">
                <label class="field__label" for="am-duration">Max duration (hours)</label>
                <input class="input" type="number" id="am-duration" name="max_duration_hours"
                       min="1" max="24"
                       value="<?= (int)($editRow['max_duration_hours'] ?? $_POST['max_duration_hours'] ?? 4) ?>">
                <div class="field__hint">Maximum length of a single booking. 1–24.</div>
            </div>

            <div class="field">
                <label class="field__label" for="am-deposit">Deposit amount (dollars)</label>
                <input class="input" type="text" id="am-deposit" name="deposit"
                       value="<?= e(($editRow['deposit_cents'] ?? '') !== '' && $editRow['deposit_cents'] !== null ? number_format((int)$editRow['deposit_cents'] / 100, 2) : ($_POST['deposit'] ?? '')) ?>"
                       placeholder="e.g. 50.00 — leave blank if none">
            </div>

            <div class="field">
                <label class="field__label" for="am-sort">Sort order</label>
                <input class="input" type="number" id="am-sort" name="sort_order" min="0" max="255"
                       value="<?= (int)($editRow['sort_order'] ?? $_POST['sort_order'] ?? 0) ?>">
                <div class="field__hint">Lower = appears first.</div>
            </div>

            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="am-desc">Description</label>
                <textarea class="textarea" id="am-desc" name="description" rows="3" maxlength="5000"
                          placeholder="Brief description of this space…"><?= e((string)($editRow['description'] ?? $_POST['description'] ?? '')) ?></textarea>
            </div>

            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="am-instructions">Booking instructions</label>
                <textarea class="textarea" id="am-instructions" name="booking_instructions" rows="3" maxlength="2000"
                          placeholder="Rules, access codes, cleanup requirements…"><?= e((string)($editRow['booking_instructions'] ?? $_POST['booking_instructions'] ?? '')) ?></textarea>
            </div>

            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="am-photo">Photo</label>
                <?php if (!empty($editRow['photo_path'])): ?>
                <div style="display: flex; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-3);
                            padding: var(--sp-3); background: var(--color-surface-2); border-radius: var(--r-md);">
                    <img src="/dashboard/file.php?type=amenity_photo&id=<?= (int)$editRow['id'] ?>" alt=""
                         style="width: 80px; height: 80px; object-fit: cover; border-radius: var(--r-sm); flex-shrink: 0;">
                    <div>
                        <div style="font-size: var(--fs-sm); font-weight: 600; margin-bottom: 2px;">Current photo</div>
                        <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer; margin-top: var(--sp-2); font-size: var(--fs-sm);">
                            <input type="checkbox" name="remove_photo" value="1">
                            Remove photo
                        </label>
                    </div>
                </div>
                <?php endif; ?>
                <input class="input" type="file" id="am-photo" name="photo" accept="image/*">
                <div class="field__hint">JPG, PNG, GIF, or WebP. Max 10 MB.</div>
            </div>

            <div class="field" style="grid-column: 1 / -1; display: flex; gap: var(--sp-6);">
                <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ((int)($editRow['is_active'] ?? 1)) ? 'checked' : '' ?>>
                    <span>Active (visible to members)</span>
                </label>
                <label style="display: flex; align-items: center; gap: var(--sp-2); cursor: pointer;">
                    <input type="checkbox" name="requires_approval" value="1"
                           <?= ((int)($editRow['requires_approval'] ?? 1)) ? 'checked' : '' ?>>
                    <span>Requires board approval</span>
                </label>
            </div>
        </div>

        <div style="display: flex; gap: var(--sp-3); margin-top: var(--sp-5);">
            <button class="btn btn--primary" type="submit">
                <?= ($editId === -1 || $editRow === null) ? 'Add amenity' : 'Save changes' ?>
            </button>
            <a class="btn" href="/dashboard/amenities.php">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php // ── Amenity card grid ──────────────────────────────────────────────
if ($amenities): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--sp-5);">
    <?php foreach ($amenities as $am): ?>
    <div class="card" style="display: flex; flex-direction: column; overflow: hidden;">
        <?php if (!empty($am['photo_path'])): ?>
        <div style="height: 160px; overflow: hidden; flex-shrink: 0;">
            <img src="/dashboard/file.php?type=amenity_photo&id=<?= (int)$am['id'] ?>" alt=""
                 style="width: 100%; height: 100%; object-fit: cover; display: block;">
        </div>
        <?php else: ?>
        <div style="height: 100px; background: var(--color-surface-2); display: flex; align-items: center;
                    justify-content: center; font-size: 2.5rem; flex-shrink: 0;">🏛️</div>
        <?php endif; ?>

        <div style="padding: var(--sp-4); flex: 1; display: flex; flex-direction: column; gap: var(--sp-2);">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sp-2);">
                <h3 style="font-size: var(--fs-md); font-weight: 700; margin: 0;"><?= e((string)$am['name']) ?></h3>
                <span class="badge <?= $am['is_active'] ? 'badge--green' : 'badge--muted' ?>">
                    <?= $am['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </div>

            <div style="display: flex; gap: var(--sp-2); flex-wrap: wrap;">
                <?php if (!empty($am['location'])): ?>
                <span class="badge badge--info"><?= e((string)$am['location']) ?></span>
                <?php endif; ?>
                <?php if ($am['capacity'] !== null): ?>
                <span class="badge badge--muted">Up to <?= (int)$am['capacity'] ?> people</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($am['description'])): ?>
            <p class="muted" style="font-size: var(--fs-sm); margin: 0;">
                <?= e(mb_strimwidth((string)$am['description'], 0, 100, '…')) ?>
            </p>
            <?php endif; ?>

            <div style="margin-top: auto; padding-top: var(--sp-3); display: flex; gap: var(--sp-2); flex-wrap: wrap;">
                <a class="btn btn--sm" href="/dashboard/amenities.php?edit=<?= (int)$am['id'] ?>">Edit</a>
                <form method="post" style="display: inline;"
                      onsubmit="return confirm('Delete this amenity and all its bookings? This cannot be undone.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_amenity">
                    <input type="hidden" name="id" value="<?= (int)$am['id'] ?>">
                    <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card card--padded" style="text-align: center; padding: var(--sp-12);">
    <div style="font-size: 3rem; margin-bottom: var(--sp-3);">🏛️</div>
    <h3 style="margin: 0 0 var(--sp-2);">No amenities yet</h3>
    <p class="muted" style="margin: 0 0 var(--sp-4);">Add your clubhouse, pool, BBQ area, or any other bookable space.</p>
    <a class="btn btn--primary" href="/dashboard/amenities.php?edit=-1">+ Add first amenity</a>
</div>
<?php endif; ?>

<?php // ── Bookings tab ───────────────────────────────────────────────────
elseif ($tab === 'bookings'): ?>

<!-- Status filter links -->
<div style="display: flex; gap: var(--sp-2); flex-wrap: wrap; margin-bottom: var(--sp-4);">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied', 'cancelled' => 'Cancelled'] as $bsKey => $bsLabel): ?>
    <a href="/dashboard/amenities.php?tab=bookings&bstatus=<?= e($bsKey) ?>"
       style="padding: var(--sp-1) var(--sp-3); border-radius: var(--radius, 6px); font-size: var(--fs-sm);
              font-weight: 600; text-decoration: none;
              <?= $bFilter === $bsKey
                ? 'background: var(--color-navy); color: #fff;'
                : 'background: var(--color-surface-2); color: var(--color-text-muted);' ?>">
        <?= e($bsLabel) ?> (<?= $bookingCounts[$bsKey] ?>)
    </a>
    <?php endforeach; ?>
</div>

<?php
$visibleBookings = $bFilter === 'all'
    ? $allBookings
    : array_filter($allBookings, fn($b) => $b['status'] === $bFilter);
?>

<?php if ($visibleBookings): ?>
<div class="card">
    <table class="table" style="min-width: 700px;">
        <thead>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Amenity</th>
                <th>Member</th>
                <th>Purpose</th>
                <th>Attendees</th>
                <th>Status</th>
                <th style="width: 200px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($visibleBookings as $bk): ?>
        <?php
        $bkStatusBadge = match ($bk['status']) {
            'pending'   => 'badge--orange',
            'approved'  => 'badge--green',
            default     => 'badge--muted',
        };
        $memberDisplay = trim((string)$bk['first_name'] . ' ' . (string)$bk['last_name']);
        if (!empty($bk['unit_number'])) $memberDisplay .= ' (' . $bk['unit_number'] . ')';
        ?>
        <?php $kindIsOpen = ($bk['event_kind'] ?? 'private') === 'open'; ?>
        <tr>
            <td style="white-space: nowrap;"><?= e(date('M j, Y', strtotime((string)$bk['booking_date']))) ?></td>
            <td style="white-space: nowrap; font-size: var(--fs-sm);">
                <?= e(substr((string)$bk['start_time'], 0, 5)) ?> – <?= e(substr((string)$bk['end_time'], 0, 5)) ?>
            </td>
            <td><?= e((string)$bk['amenity_name']) ?></td>
            <td><?= e($memberDisplay) ?></td>
            <td style="max-width: 160px;">
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span class="badge <?= $kindIsOpen ? 'badge--info' : 'badge--muted' ?>" style="font-size:11px; align-self:flex-start;">
                        <?= $kindIsOpen ? 'Open' : 'Private' ?>
                    </span>
                    <?php if (!empty($bk['purpose'])): ?>
                        <span style="font-size: var(--fs-sm);" title="<?= e((string)$bk['purpose']) ?>">
                            <?= e(mb_strimwidth((string)$bk['purpose'], 0, 50, '…')) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </td>
            <td style="text-align: center;">
                <?= $bk['attendee_count'] !== null ? (int)$bk['attendee_count'] : '—' ?>
            </td>
            <td><span class="badge <?= $bkStatusBadge ?>"><?= e(ucfirst((string)$bk['status'])) ?></span></td>
            <td>
                <?php if ($bk['status'] === 'pending'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="review_booking">
                    <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                    <input type="hidden" name="status" value="approved" id="bk-st-<?= (int)$bk['id'] ?>">
                    <textarea name="board_notes" id="bk-note-<?= (int)$bk['id'] ?>"
                              style="display:none; width:100%; margin-bottom:var(--sp-2);
                                     font-size:var(--fs-sm); padding:var(--sp-2); resize:vertical;
                                     border:1px solid var(--color-border); border-radius:var(--r-sm);"
                              rows="2" placeholder="Optional note to member…"></textarea>
                    <div style="display:flex; gap:var(--sp-2); flex-wrap:wrap;">
                        <button class="btn btn--sm btn--primary" type="submit"
                                onclick="document.getElementById('bk-st-<?= (int)$bk['id'] ?>').value='approved'">Approve</button>
                        <button class="btn btn--sm btn--danger" type="submit"
                                onclick="document.getElementById('bk-st-<?= (int)$bk['id'] ?>').value='denied'">Deny</button>
                        <button type="button" class="btn btn--sm" style="font-size:var(--fs-xs);"
                                onclick="var n=document.getElementById('bk-note-<?= (int)$bk['id'] ?>');n.style.display='block';this.style.display='none';">Add note</button>
                    </div>
                </form>
                <?php elseif ($bk['status'] === 'approved'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="review_booking">
                    <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                    <input type="hidden" name="status" value="cancelled">
                    <button class="btn btn--sm" type="submit"
                            onclick="return confirm('Cancel this approved booking?')">Cancel</button>
                </form>
                <?php else: ?>
                <?php if (!empty($bk['board_notes'])): ?>
                    <span class="muted" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$bk['board_notes'], 0, 60, '…')) ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="card card--padded" style="text-align: center; padding: var(--sp-10);">
    <p class="muted" style="margin: 0;">No <?= $bFilter !== 'all' ? e($bFilter) . ' ' : '' ?>bookings found.</p>
</div>
<?php endif; ?>

<?php endif; // tab === bookings ?>

<?php // ─────────────── MEMBER VIEW ──────────────────────────────────────
else: ?>

<?php if ($amenities): ?>
<!-- Amenity grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--sp-5); margin-bottom: var(--sp-8);">
    <?php foreach ($amenities as $am): ?>
    <?php $isBeingBooked = ((int)$am['id'] === $bookAmenityId); ?>
    <div class="card" id="amenity-<?= (int)$am['id'] ?>"
         style="display: flex; flex-direction: column; overflow: hidden;
                <?= $isBeingBooked ? 'outline: 2px solid var(--color-orange); outline-offset: 2px;' : '' ?>">

        <?php if (!empty($am['photo_path'])): ?>
        <div style="height: 200px; overflow: hidden; flex-shrink: 0;">
            <img src="/dashboard/file.php?type=amenity_photo&id=<?= (int)$am['id'] ?>" alt=""
                 style="width: 100%; height: 100%; object-fit: cover; display: block;">
        </div>
        <?php else: ?>
        <div style="height: 120px; background: var(--color-surface-2); display: flex; align-items: center;
                    justify-content: center; font-size: 3rem; flex-shrink: 0;">🏛️</div>
        <?php endif; ?>

        <div style="padding: var(--sp-4); flex: 1; display: flex; flex-direction: column; gap: var(--sp-2);">
            <h3 style="font-size: var(--fs-md); font-weight: 700; margin: 0;"><?= e((string)$am['name']) ?></h3>

            <div style="display: flex; gap: var(--sp-2); flex-wrap: wrap;">
                <?php if (!empty($am['location'])): ?>
                <span class="badge badge--info"><?= e((string)$am['location']) ?></span>
                <?php endif; ?>
                <?php if ($am['capacity'] !== null): ?>
                <span class="badge badge--muted">Up to <?= (int)$am['capacity'] ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($am['description'])): ?>
            <p style="font-size: var(--fs-sm); color: var(--color-text); margin: 0;">
                <?= e((string)$am['description']) ?>
            </p>
            <?php endif; ?>

            <div style="font-size: var(--fs-sm); color: var(--color-text-muted); display: flex; flex-direction: column; gap: 2px; margin-top: var(--sp-1);">
                <span>Up to <?= (int)$am['max_duration_hours'] ?> hour<?= (int)$am['max_duration_hours'] !== 1 ? 's' : '' ?> per booking
                    &middot; Book up to <?= (int)$am['max_advance_days'] ?> days ahead</span>
                <?php if ($am['deposit_cents'] !== null && (int)$am['deposit_cents'] > 0): ?>
                <span>Deposit: $<?= number_format((int)$am['deposit_cents'] / 100, 2) ?></span>
                <?php endif; ?>
                <?php if (!empty($am['booking_instructions'])): ?>
                <span><?= e((string)$am['booking_instructions']) ?></span>
                <?php endif; ?>
            </div>

            <div style="margin-top: auto; padding-top: var(--sp-3);">
                <?php if ($isBeingBooked): ?>
                <a class="btn btn--sm" href="/dashboard/amenities.php">Cancel</a>
                <?php else: ?>
                <a class="btn btn--sm btn--primary"
                   href="/dashboard/amenities.php?book=<?= (int)$am['id'] ?>#amenity-<?= (int)$am['id'] ?>">
                    Book this space
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php // ── Inline booking form ─────────────────────────────────────────────
if ($bookAmenity !== null): ?>
<div class="card card--padded" id="booking-form" style="margin-bottom: var(--sp-8);
     border-top: 3px solid var(--color-orange);">
    <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-4);">
        Book: <?= e((string)$bookAmenity['name']) ?>
    </h2>
    <?php
    $todayDate    = gmdate('Y-m-d');
    $maxDateDate  = gmdate('Y-m-d', strtotime('+' . (int)$bookAmenity['max_advance_days'] . ' days'));
    $maxDurH      = (int)$bookAmenity['max_duration_hours'];
    $capacity     = $bookAmenity['capacity'] !== null ? (int)$bookAmenity['capacity'] : null;
    $reqApproval  = (int)$bookAmenity['requires_approval'];
    ?>
    <form method="post" id="booking-submit-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_booking">
        <input type="hidden" name="amenity_id" value="<?= (int)$bookAmenity['id'] ?>">

        <div class="form-grid form-grid--2" style="gap: var(--sp-4);">
            <div class="field">
                <label class="field__label" for="bk-date">Date <span style="color:var(--color-orange)">*</span></label>
                <input class="input" type="date" id="bk-date" name="booking_date" required
                       min="<?= e($todayDate) ?>" max="<?= e($maxDateDate) ?>"
                       value="<?= e((string)($_POST['booking_date'] ?? '')) ?>">
            </div>

            <div class="field" style="align-self: end;">
                <div style="font-size: var(--fs-xs); color: var(--color-text-muted);">
                    Available today through <?= e(date('M j, Y', strtotime($maxDateDate))) ?>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="bk-start">Start time <span style="color:var(--color-orange)">*</span></label>
                <input class="input" type="time" id="bk-start" name="start_time" required step="900"
                       value="<?= e((string)($_POST['start_time'] ?? '')) ?>"
                       oninput="updateBookingHint()">
            </div>

            <div class="field">
                <label class="field__label" for="bk-end">End time <span style="color:var(--color-orange)">*</span></label>
                <input class="input" type="time" id="bk-end" name="end_time" required step="900"
                       value="<?= e((string)($_POST['end_time'] ?? '')) ?>"
                       oninput="updateBookingHint()">
                <div class="field__hint" id="bk-duration-hint">
                    Max duration: <?= $maxDurH ?> hour<?= $maxDurH !== 1 ? 's' : '' ?>
                </div>
            </div>

            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label" for="bk-purpose">Purpose (optional)</label>
                <input class="input" type="text" id="bk-purpose" name="purpose" maxlength="500"
                       value="<?= e((string)($_POST['purpose'] ?? '')) ?>"
                       placeholder="What's the occasion?">
            </div>

            <div class="field">
                <label class="field__label" for="bk-attendees">
                    Number of attendees (optional)<?= $capacity !== null ? ' — max ' . $capacity : '' ?>
                </label>
                <input class="input" type="number" id="bk-attendees" name="attendee_count"
                       min="1" <?= $capacity !== null ? 'max="' . $capacity . '"' : '' ?>
                       value="<?= e((string)($_POST['attendee_count'] ?? '')) ?>"
                       placeholder="How many people?">
            </div>

            <div class="field" style="grid-column: 1 / -1;">
                <?php $postedKind = (string)($_POST['event_kind'] ?? 'private'); ?>
                <label class="field__label">Event type <span style="color:var(--color-orange)">*</span></label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3);">
                    <label style="display:flex; gap:var(--sp-3); padding:var(--sp-3) var(--sp-4); border:2px solid <?= $postedKind === 'open' ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius:var(--r-md); cursor:pointer; align-items:flex-start;">
                        <input type="radio" name="event_kind" value="open" <?= $postedKind === 'open' ? 'checked' : '' ?> style="margin-top:3px;">
                        <span>
                            <strong style="display:block; margin-bottom:2px;">Open to members</strong>
                            <span class="muted" style="font-size:var(--fs-sm);">Posted on the community calendar so neighbors can join. Use for community gatherings, watch parties, classes.</span>
                        </span>
                    </label>
                    <label style="display:flex; gap:var(--sp-3); padding:var(--sp-3) var(--sp-4); border:2px solid <?= $postedKind === 'private' ? 'var(--color-orange)' : 'var(--color-border)' ?>; border-radius:var(--r-md); cursor:pointer; align-items:flex-start;">
                        <input type="radio" name="event_kind" value="private" <?= $postedKind !== 'open' ? 'checked' : '' ?> style="margin-top:3px;">
                        <span>
                            <strong style="display:block; margin-bottom:2px;">Private event / party</strong>
                            <span class="muted" style="font-size:var(--fs-sm);">Shown on the calendar as "reserved" so the space isn't double-booked, but the occasion stays between you and your guests.</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: var(--sp-3); margin-top: var(--sp-5); align-items: center; flex-wrap: wrap;">
            <button class="btn btn--primary" type="submit">
                <?= $reqApproval ? 'Request Booking' : 'Book Now' ?>
            </button>
            <a class="btn" href="/dashboard/amenities.php">Cancel</a>
            <?php if ($reqApproval): ?>
            <span class="muted" style="font-size: var(--fs-sm);">The board will review your request.</span>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; // bookAmenity form ?>

<?php else: ?>
<div class="card card--padded" style="text-align: center; padding: var(--sp-12);">
    <div style="font-size: 3rem; margin-bottom: var(--sp-3);">🏛️</div>
    <h3 style="margin: 0 0 var(--sp-2);">No amenities yet</h3>
    <p class="muted" style="margin: 0;">Your board hasn't added any bookable spaces yet. Check back soon.</p>
</div>
<?php endif; // amenities ?>

<!-- My Bookings -->
<div style="margin-top: var(--sp-8);">
    <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-4);">My Bookings</h2>

    <?php if ($myBookings): ?>
    <div class="card">
        <table class="table" style="min-width: 500px;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Amenity</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($myBookings as $mbk): ?>
            <?php
            $myBkBadge = match ($mbk['status']) {
                'pending'   => 'badge--orange',
                'approved'  => 'badge--green',
                default     => 'badge--muted',
            };
            ?>
            <?php $myKindIsOpen = ($mbk['event_kind'] ?? 'private') === 'open'; ?>
            <tr>
                <td style="white-space: nowrap;"><?= e(date('M j, Y', strtotime((string)$mbk['booking_date']))) ?></td>
                <td style="white-space: nowrap; font-size: var(--fs-sm);">
                    <?= e(substr((string)$mbk['start_time'], 0, 5)) ?> – <?= e(substr((string)$mbk['end_time'], 0, 5)) ?>
                </td>
                <td>
                    <?= e((string)$mbk['amenity_name']) ?>
                    <span class="badge <?= $myKindIsOpen ? 'badge--info' : 'badge--muted' ?>" style="font-size:11px; margin-left:6px;">
                        <?= $myKindIsOpen ? 'Open' : 'Private' ?>
                    </span>
                </td>
                <td style="font-size: var(--fs-sm);">
                    <?= !empty($mbk['purpose']) ? e((string)$mbk['purpose']) : '<span class="muted">—</span>' ?>
                </td>
                <td>
                    <span class="badge <?= $myBkBadge ?>"><?= e(ucfirst((string)$mbk['status'])) ?></span>
                    <?php if (!empty($mbk['board_notes'])): ?>
                    <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;"><?= e((string)$mbk['board_notes']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($mbk['status'] === 'pending'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_booking">
                        <input type="hidden" name="booking_id" value="<?= (int)$mbk['id'] ?>">
                        <button class="btn btn--sm btn--danger" type="submit"
                                onclick="return confirm('Cancel this booking request?')">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card card--padded" style="text-align: center; padding: var(--sp-8);">
        <p class="muted" style="margin: 0;">You haven't made any bookings yet.</p>
    </div>
    <?php endif; ?>
</div>

<?php endif; // canManage else (member view) ?>

</div><?php /* /container */ ?>

<script>
(function () {
    var maxDurHours = <?= (int)($bookAmenity['max_duration_hours'] ?? 4) ?>;

    function updateBookingHint() {
        var startEl = document.getElementById('bk-start');
        var endEl   = document.getElementById('bk-end');
        var hint    = document.getElementById('bk-duration-hint');
        if (!startEl || !endEl || !hint) return;

        var sv = startEl.value;
        var ev = endEl.value;
        if (!sv || !ev) {
            hint.textContent = 'Max duration: ' + maxDurHours + ' hour' + (maxDurHours !== 1 ? 's' : '');
            hint.style.color = '';
            return;
        }

        var toMins = function (t) {
            var parts = t.split(':');
            return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
        };
        var startMins = toMins(sv);
        var endMins   = toMins(ev);
        var diffMins  = endMins - startMins;

        if (diffMins <= 0) {
            hint.textContent = 'End time must be after start time.';
            hint.style.color = 'var(--color-orange)';
            return;
        }

        var diffH = diffMins / 60;
        var hours = Math.floor(diffH);
        var mins  = diffMins % 60;
        var label = hours > 0 ? hours + 'h' : '';
        if (mins > 0) label += (label ? ' ' : '') + mins + 'm';

        if (diffH > maxDurHours) {
            hint.textContent = label + ' — exceeds max of ' + maxDurHours + ' hour' + (maxDurHours !== 1 ? 's' : '');
            hint.style.color = 'var(--color-orange)';
        } else {
            hint.textContent = label + ' — max ' + maxDurHours + ' hour' + (maxDurHours !== 1 ? 's' : '');
            hint.style.color = 'var(--color-green, #2e7d32)';
        }
    }

    window.updateBookingHint = updateBookingHint;
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

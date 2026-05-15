<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// --- output escaping ----------------------------------------------------
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- string utils -------------------------------------------------------
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

function uuid_filename(string $original): string
{
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $bytes = bin2hex(random_bytes(16));
    return $ext ? "$bytes.$ext" : $bytes;
}

// --- paths --------------------------------------------------------------
function storage_path(string $rel = ''): string
{
    return __DIR__ . '/../storage/' . ltrim($rel, '/');
}

function ensure_dir(string $absPath): void
{
    if (!is_dir($absPath)) {
        @mkdir($absPath, 0755, true);
    }
}

// --- form submissions ---------------------------------------------------
// Form types Bellair has now — extend the ENUM in a future migration to add
// more. Each pair: machine key → ['label' => human, 'icon' => emoji,
// 'submit_url' => starting URL for the submit form].
function form_types(): array
{
    return [
        'guest_registration'   => ['label' => 'Guest registration',     'icon' => '👋', 'submit_url' => '/dashboard/forms.php?action=new&type=guest_registration'],
        'parking_pass'         => ['label' => 'Temp parking pass',      'icon' => '🅿️', 'submit_url' => '/dashboard/forms.php?action=new&type=parking_pass'],
        'maintenance_request'  => ['label' => 'Maintenance request',    'icon' => '🔧', 'submit_url' => '/dashboard/forms.php?action=new&type=maintenance_request'],
        'pet_registration'     => ['label' => 'Pet registration',       'icon' => '🐕', 'submit_url' => '/dashboard/forms.php?action=new&type=pet_registration'],
        'vehicle_registration' => ['label' => 'Resident vehicle',       'icon' => '🚗', 'submit_url' => '/dashboard/forms.php?action=new&type=vehicle_registration'],
        'contractor_notice'    => ['label' => 'Contractor / work notice','icon'=> '🔨', 'submit_url' => '/dashboard/forms.php?action=new&type=contractor_notice'],
        'amenity_reservation'  => ['label' => 'Amenity reservation',    'icon' => '🎉', 'submit_url' => '/dashboard/forms.php?action=new&type=amenity_reservation'],
        'hurricane_checklist'  => ['label' => 'Hurricane prep',         'icon' => '🌪️', 'submit_url' => '/dashboard/forms.php?action=new&type=hurricane_checklist'],
        'emergency_contact'    => ['label' => 'Emergency contact',      'icon' => '☎️', 'submit_url' => '/dashboard/forms.php?action=new&type=emergency_contact'],
        'move_in'              => ['label' => 'Move-in notice',         'icon' => '📦', 'submit_url' => '/dashboard/forms.php?action=new&type=move_in'],
        'move_out'             => ['label' => 'Move-out notice',        'icon' => '📤', 'submit_url' => '/dashboard/forms.php?action=new&type=move_out'],
        'key_request'          => ['label' => 'Key / fob request',      'icon' => '🔑', 'submit_url' => '/dashboard/forms.php?action=new&type=key_request'],
        'estoppel_request'     => ['label' => 'Estoppel / sale',        'icon' => '📨', 'submit_url' => '/dashboard/forms.php?action=new&type=estoppel_request'],
        'service_animal'       => ['label' => 'Service / assist animal','icon' => '🦮', 'submit_url' => '/dashboard/forms.php?action=new&type=service_animal'],
        'other'                => ['label' => 'Other',                  'icon' => '📝', 'submit_url' => '/dashboard/forms.php?action=new&type=other'],
    ];
}

function form_type_label(string $type): string
{
    return form_types()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
}

// Which form types carry a legal weight that warrants an electronic signature.
// Driven by code (not DB) so we can tweak intentionally rather than per-tenant.
// Forms NOT in this list still capture an audit trail when signed, but the
// signature is optional.
function forms_requiring_signature(): array
{
    return [
        'guest_registration'  => true,  // rules acknowledgement
        'amenity_reservation' => true,  // deposit acknowledgement
        'estoppel_request'    => true,  // title-company-driven; legal weight
        'hurricane_checklist' => true,  // attestation owner did the prep
        'service_animal'      => true,  // FHA accommodation request; board needs signed record
    ];
}

function form_requires_signature(string $type): bool
{
    return !empty(forms_requiring_signature()[$type] ?? false);
}

// Canonical hash of a payload for tamper-detection. Sorted keys so order
// doesn't change the hash; null fields are treated as absent. Run on the
// payload AT signing time and store; re-compute on display to flag tampering.
function payload_hash(array $payload): string
{
    $clean = [];
    foreach ($payload as $k => $v) {
        if ($v === null || $v === '') continue;
        $clean[$k] = $v;
    }
    ksort($clean);
    return hash('sha256', json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// User's reusable signature library — private to them. Used to populate the
// "Saved" tab on the form signature pad. Sorted by most-recently-used so
// the one they reach for most often is on top.
function user_saved_signatures(int $userId): array
{
    $s = db()->prepare(
        'SELECT * FROM user_signatures
          WHERE user_id = ?
          ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC'
    );
    $s->execute([$userId]);
    return $s->fetchAll();
}

// Mark a saved signature as just-used (refreshes its sort position).
function touch_user_signature(int $userId, int $sigId): void
{
    db()->prepare('UPDATE user_signatures SET last_used_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$sigId, $userId]);
}

// Save a drawn-signature data URL (data:image/png;base64,…) to disk and
// return the storage path. Returns null on failure.
function save_signature_data_url(string $dataUrl, int $assocId): ?string
{
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,(.+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 50 || strlen($bin) > 3 * 1024 * 1024) return null;
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $relDir = "uploads/$assocId/signatures";
    ensure_dir(storage_path($relDir));
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $rel  = "$relDir/$name";
    if (file_put_contents(storage_path($rel), $bin) === false) return null;
    return $rel;
}

// Generate a friendly confirmation code — 8 chars, unambiguous alphabet.
// Format: XXXX-XXXX. Pairs nicely with on-screen display + verbal sharing.
function generate_form_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
    $out = '';
    $bytes = random_bytes(8);
    for ($i = 0; $i < 8; $i++) {
        $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        if ($i === 3) $out .= '-';
    }
    return $out;
}

// --- storage quota ------------------------------------------------------
// Each association gets storage_quota_bytes (default 1 GiB) plus
// storage_paid_extra_gb additional GiB (settable by super admin).
// Usage = du-style scan of the association's uploads dir. Cached per-request
// in a static so multiple checks during one render don't re-scan.
function association_storage_quota_bytes(array $assoc): int
{
    $base  = (int)($assoc['storage_quota_bytes']    ?? 1073741824);
    $extra = (int)($assoc['storage_paid_extra_gb']  ?? 0);
    return $base + ($extra * 1073741824);
}

function association_storage_used_bytes(int $assocId, bool $fresh = false): int
{
    static $cache = [];
    if (!$fresh && isset($cache[$assocId])) return $cache[$assocId];

    $dir = storage_path('uploads/' . $assocId);
    if (!is_dir($dir)) return $cache[$assocId] = 0;

    $total = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $total += $f->getSize();
    }
    return $cache[$assocId] = $total;
}

// Breakdown of an association's storage by subdir (documents, media,
// avatars, branding, other). Returns an array like:
//   [ 'documents' => ['bytes' => 12345, 'count' => 3, 'files' => [...]],
//     'media'     => ['bytes' => ...,  'count' => ..., 'files' => [...]], ... ]
// Each 'files' entry is ['rel' => relative path under the assoc dir,
//                         'size' => int, 'mtime' => int].
function association_storage_breakdown(int $assocId): array
{
    $base = storage_path('uploads/' . $assocId);
    $cats = [
        'documents' => ['label' => 'Documents',        'bytes' => 0, 'count' => 0, 'files' => []],
        'media'     => ['label' => 'Photos & inline images', 'bytes' => 0, 'count' => 0, 'files' => []],
        'avatars'   => ['label' => 'Member headshots', 'bytes' => 0, 'count' => 0, 'files' => []],
        'branding'  => ['label' => 'Logo & branding',  'bytes' => 0, 'count' => 0, 'files' => []],
        'other'     => ['label' => 'Other',            'bytes' => 0, 'count' => 0, 'files' => []],
    ];
    if (!is_dir($base)) return $cats;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $abs  = $f->getPathname();
        $size = $f->getSize();
        $rel  = ltrim(str_replace($base, '', $abs), '/');
        // First path segment is the category.
        $seg  = explode('/', $rel, 2)[0] ?? '';
        $key  = array_key_exists($seg, $cats) ? $seg : 'other';
        $cats[$key]['bytes'] += $size;
        $cats[$key]['count']++;
        $cats[$key]['files'][] = ['rel' => $rel, 'size' => $size, 'mtime' => $f->getMTime()];
    }
    // Sort each category's file list largest-first (capped to 50 to keep things sane).
    foreach ($cats as &$c) {
        usort($c['files'], fn($a, $b) => $b['size'] <=> $a['size']);
        if (count($c['files']) > 50) $c['files'] = array_slice($c['files'], 0, 50);
    }
    return $cats;
}

function format_bytes(int $b): string
{
    if ($b >= 1073741824) return number_format($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}

// True when adding $addBytes would exceed the quota. Returns the over-quota
// amount in bytes (positive number); 0 if it would fit. Used by upload
// handlers to short-circuit before move_uploaded_file().
function storage_over_quota_by(array $assoc, int $addBytes): int
{
    $used  = association_storage_used_bytes((int)$assoc['id']);
    $quota = association_storage_quota_bytes($assoc);
    $after = $used + $addBytes;
    return $after > $quota ? ($after - $quota) : 0;
}

// --- document categories (per-association picklist) --------------------
// Bootstraps a sensible default set of document categories for an association
// the first time they hit the documents page. Idempotent — does nothing once
// the association has at least one category. Defaults come from the spec
// Kevin set on 2026-05-10: Bylaws, Minutes, Insurance, Forms, Renters, General.
function ensure_default_document_categories(int $assocId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM document_categories WHERE association_id = ?');
    $stmt->execute([$assocId]);
    if ((int)$stmt->fetchColumn() > 0) return;

    $defaults = [
        ['Bylaws',    10],
        ['Minutes',   20],
        ['Insurance', 30],
        ['Forms',     40],
        ['Renters',   50],
        ['General',   60],
    ];
    $ins = db()->prepare(
        'INSERT IGNORE INTO document_categories (association_id, name, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($defaults as [$name, $order]) {
        $ins->execute([$assocId, $name, $order]);
    }
}

// --- event recurrence ---------------------------------------------------
// Expands a single events row into one or more occurrences within the given
// window. For non-recurring events (recurrence_type='none' or unset) it
// returns a one-element array with the original row. For recurring events
// it generates occurrences forward from starts_at, stopping at the earlier
// of recurrence_until or the window end.
//
// Each occurrence has the same row data with starts_at/ends_at shifted to
// the occurrence date. The flag _is_recurring_occurrence=true is set on
// repeated occurrences (the original starts_at row is also marked).
//
// $windowDays: how far forward to expand. Default 90 — generous enough
// for "what's coming up" listings without exploding for endless series.
function expand_event(array $event, int $windowDays = 90): array
{
    $type = (string)($event['recurrence_type'] ?? 'none');
    if ($type === '' || $type === 'none') return [$event];

    $startTs = strtotime((string)$event['starts_at']);
    if ($startTs === false || $startTs === 0) return [$event];

    $endTs    = !empty($event['ends_at']) ? strtotime((string)$event['ends_at']) : null;
    $duration = $endTs ? ($endTs - $startTs) : 0;

    $windowEnd = time() + ($windowDays * 86400);
    $until = !empty($event['recurrence_until'])
        ? strtotime((string)$event['recurrence_until'] . ' 23:59:59')
        : $windowEnd;
    $cap = min($windowEnd, $until ?: $windowEnd);

    $step = match ($type) {
        'daily'    => '+1 day',
        'weekly'   => '+1 week',
        'biweekly' => '+2 weeks',
        'monthly'  => '+1 month',
        default    => null,
    };
    if ($step === null) return [$event];

    $occurrences = [];
    $cursor = $startTs;
    $maxIters = 366; // safety cap
    $i = 0;
    while ($cursor <= $cap && $i++ < $maxIters) {
        $occ = $event;
        $occ['starts_at'] = date('Y-m-d H:i:s', $cursor);
        $occ['ends_at']   = $duration ? date('Y-m-d H:i:s', $cursor + $duration) : null;
        $occ['_is_recurring_occurrence'] = true;
        $occurrences[] = $occ;
        $cursor = strtotime($step, $cursor);
        if ($cursor === false) break;
    }
    return $occurrences ?: [$event];
}

// Expand a list of raw events into occurrences and optionally filter by
// past/upcoming. $past=null means return all occurrences (no date filter).
function expand_events(array $events, ?bool $past = false, int $windowDays = 90): array
{
    $out = [];
    $now = time();
    foreach ($events as $e) {
        foreach (expand_event($e, $windowDays) as $occ) {
            $occTs = strtotime((string)$occ['starts_at']);
            if ($occTs === false) continue;
            if ($past === true  && $occTs >= $now) continue;
            if ($past === false && $occTs < $now) continue;
            $out[] = $occ;
        }
    }
    usort($out, fn($a, $b) => strtotime((string)$a['starts_at']) <=> strtotime((string)$b['starts_at']));
    if ($past === true) $out = array_reverse($out);
    return $out;
}

// --- rule categories (per-association picklist) ------------------------
// Mirrors ensure_default_document_categories(). Bootstraps the 15 default
// rule categories Kevin set on 2026-05-10 the first time an association
// hits the Rules page with zero categories. Existing associations that
// already have categories (e.g. the demo seeded by migration 004) are
// untouched — non-destructive.
function ensure_default_rule_categories(int $assocId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM rule_categories WHERE association_id = ?');
    $stmt->execute([$assocId]);
    if ((int)$stmt->fetchColumn() > 0) return;

    $defaults = [
        'General Conduct',     'Occupancy & Guests', 'Pets',
        'Sales',               'Rentals',            'Safety & Appearance',
        'Common Areas',        'Parking & Vehicles', 'Garages',
        'Laundry',             'Trash & Recycling',  'Storage',
        'Pool & Recreation',   'Enforcement',        'Fees & Fines',
    ];
    $ins = db()->prepare(
        'INSERT IGNORE INTO rule_categories (association_id, name, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($defaults as $i => $name) {
        $ins->execute([$assocId, $name, ($i + 1) * 10]);
    }
}

// --- board office labels -------------------------------------------------
// users.board_office (added in migration 029) is a display-only label for
// board members + property managers. It does NOT affect permissions — those
// still flow from `role` (board_admin = manager, board_member = view-only).
// One office per person; NULL means "no specific office on file".
function board_offices(): array
{
    return [
        'president'           => 'President',
        'vice_president'      => 'Vice President',
        'secretary'           => 'Secretary',
        'treasurer'           => 'Treasurer',
        'secretary_treasurer' => 'Secretary-Treasurer',
        'director'            => 'Director',
    ];
}

function board_office_label(?string $office): string
{
    if ($office === null || $office === '') return '';
    return board_offices()[$office] ?? '';
}

// Canonical seniority sort. Use as the second key after FIELD() in SQL or as
// PHP-side sort tiebreaker. Lower number = higher in the listing.
function board_office_rank(?string $office): int
{
    $order = array_flip(array_keys(board_offices()));
    return isset($order[$office]) ? (int)$order[$office] : 99;
}

// --- placeholder email helpers ------------------------------------------
// Roster imports (e.g. /dashboard/directory.php's CSV importer) synthesize
// a placeholder email like noemail+<hex>@placeholder.local for residents
// whose real address isn't on file yet. These helpers let UI code render
// "(no email on file)" or hide the field rather than expose the synthetic
// address.
function is_placeholder_email(?string $email): bool
{
    return is_string($email) && str_ends_with($email, '@placeholder.local');
}

function display_email(?string $email, string $emptyLabel = '— no email on file —'): string
{
    if ($email === null || $email === '' || is_placeholder_email($email)) {
        return $emptyLabel;
    }
    return $email;
}

// --- printable headers / footers ----------------------------------------
// Used by /dashboard/rule.php, rules-print.php, document.php, committee-flyer.php
// (and any future print views) so every printed page leads with the
// association's logo + address + phone and ends with the standard copyright +
// powered-by footer. Self-contained inline styles so the helper drops into the
// minimal HTML each print page emits without depending on app.css.
function print_header_html(array $assoc): string
{
    $name  = (string)($assoc['name']         ?? '');
    $addr  = trim((string)($assoc['address'] ?? ''));
    $city  = trim((string)($assoc['city']    ?? ''));
    $state = trim((string)($assoc['state_region'] ?? ''));
    $zip   = trim((string)($assoc['postal_code']  ?? ''));
    $phone = trim((string)($assoc['contact_phone'] ?? ''));
    $email = trim((string)($assoc['contact_email'] ?? ''));
    $hasLogo = !empty($assoc['logo_path']);
    $logoUrl = $hasLogo ? '/branding.php?id=' . (int)($assoc['id'] ?? 0) : '';

    $cityLine = trim(
        ($city !== '' ? $city : '') .
        ($city !== '' && $state !== '' ? ', ' : '') .
        ($state !== '' ? $state : '') .
        ($zip !== '' ? ' ' . $zip : '')
    );

    $out  = '<div class="print-header" style="display:flex; align-items:center; gap: 16pt; margin-bottom: 18pt; padding-bottom: 12pt; border-bottom: 2px solid #0f1f3d;">';
    if ($hasLogo) {
        $out .= '<img src="' . e($logoUrl) . '" alt="" style="height: 56pt; max-width: 120pt; object-fit: contain; flex: 0 0 auto;">';
    }
    $out .= '<div style="flex: 1; line-height: 1.35;">';
    $out .= '<div style="font-size: 14pt; font-weight: 800; color: #0f1f3d;">' . e($name) . '</div>';
    if ($addr !== '' || $cityLine !== '') {
        $out .= '<div style="font-size: 9pt; color: #4a5060;">'
              . ($addr !== '' ? e($addr) : '')
              . (($addr !== '' && $cityLine !== '') ? ' · ' : '')
              . ($cityLine !== '' ? e($cityLine) : '')
              . '</div>';
    }
    if ($phone !== '' || $email !== '') {
        $out .= '<div style="font-size: 9pt; color: #4a5060;">'
              . ($phone !== '' ? e($phone) : '')
              . (($phone !== '' && $email !== '') ? ' · ' : '')
              . ($email !== '' ? e($email) : '')
              . '</div>';
    }
    $out .= '</div></div>';
    return $out;
}

function print_footer_html(string $context = ''): string
{
    $year  = (int)date('Y');
    $extra = $context !== '' ? e($context) . ' · ' : '';
    return '<div class="print-foot" style="margin-top: 24pt; padding-top: 10pt; border-top: 1px solid #d9d3c5; color: #6b7280; font-size: 8pt; text-align: center;">'
         . $extra
         . '&copy; ' . $year . ' Savvy Brain LLC and Kevin B. Leigh · Powered by BadassHOA'
         . '</div>';
}

// --- email all managers of an association --------------------------------
// Used when a member submits a rule suggestion (board needs a heads-up).
// "Manager" = any role in MANAGE_ROLES belonging to that association,
// excluding super_admins (they're cross-tenant; they get the existing
// /admin/activity feed instead).
function notify_association_managers(int $assocId, string $subject, string $body): int
{
    $stmt = db()->prepare(
        "SELECT email FROM users
          WHERE association_id = ?
            AND status = 'active'
            AND role IN ('property_manager','board_member','board_admin')
            AND email IS NOT NULL"
    );
    $stmt->execute([$assocId]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $row) {
        send_mail((string)$row['email'], $subject, $body);
        $sent++;
    }
    return $sent;
}

// --- audit log ----------------------------------------------------------
// Records actions to the audit_log table. The "actor" is the current session
// user — which during super-admin impersonation is the *impersonated* user
// (so an action shows up correctly in their association's activity feed).
// We auto-tag those rows with an `impersonated_by` metadata key so the trail
// back to the real super admin is never lost.
function audit(string $action, array $meta = [], ?int $targetId = null, ?string $targetType = null): void
{
    try {
        if (!empty($_SESSION['real_user_id']) && !isset($meta['impersonated_by'])) {
            $meta['impersonated_by'] = (int)$_SESSION['real_user_id'];
        }
        db()->prepare(
            'INSERT INTO audit_log
             (actor_user_id, association_id, action, target_type, target_id, ip_address, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['association_id'] ?? null,
            $action,
            $targetType,
            $targetId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // Audit failures must never break the user flow.
        error_log('audit() failed: ' . $e->getMessage());
    }
}

// --- mailer --------------------------------------------------------------
// Driver chosen by config()['mail']['driver']:
//   'log'   → append to storage/logs/mail.log (local dev, no SMTP available)
//   'mail'  → PHP's mail() via the host's sendmail (Hostinger ships hsendmail)
//   'msmtp' → pipe RFC822 message to msmtp -t (uses ~/.msmtprc on the server)
// On send failure we fall through to log so the message isn't silently lost.
function send_mail(string $to, string $subject, string $body): void
{
    $cfg    = config()['mail'] ?? [];
    $driver = $cfg['driver']     ?? 'log';
    $from   = $cfg['from']       ?? 'noreply@badasshoa.com';
    $bin    = $cfg['msmtp_path'] ?? '/usr/bin/msmtp';

    if ($driver === 'mail') {
        $headers  = "From: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        // mb_encode_mimeheader handles non-ASCII subjects safely.
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');

        // -f sets the envelope sender; only honored if PHP-FPM allows it.
        if (mail($to, $encodedSubject, $body, $headers, '-f ' . $from)) return;

        error_log("send_mail mail() returned false to={$to}");
        // fall through to log so the message isn't lost
    }

    if ($driver === 'msmtp') {
        $headers  = "From: {$from}\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        $message = $headers . "\r\n" . $body;

        // -t: read recipients from headers. -f: envelope sender (Return-Path).
        $cmd  = $bin . ' -t -f ' . escapeshellarg($from);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (is_resource($proc)) {
            fwrite($pipes[0], $message);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $exit = proc_close($proc);

            if ($exit === 0) return;

            error_log("send_mail msmtp failed (exit {$exit}) to={$to}: {$stderr}");
            // fall through to log so the message isn't lost
        } else {
            error_log("send_mail: could not invoke msmtp at {$bin}");
        }
    }

    ensure_dir(storage_path('logs'));
    $line = sprintf(
        "[%s] To:%s | Subj:%s\n%s\n---\n",
        date('c'), $to, $subject, $body
    );
    file_put_contents(storage_path('logs/mail.log'), $line, FILE_APPEND | LOCK_EX);
}

// --- invite + password-reset emails -------------------------------------
// Generates a one-time token (stored as SHA-256 hash, raw token in the link),
// invalidates any prior unused tokens for the user, and sends an email pointing
// at /reset.php?token=…
//
// Used by:
//   - /forgot.php (the user-driven reset flow inlines this rather than calling
//     it, for anti-enumeration reasons — see that file)
//   - super_admin tools in /admin/users.php and /admin/associations.php to
//     invite a new user (purpose='invite') or kick off a password reset on
//     someone's behalf (purpose='reset')
//
// Returns true if the email was handed to send_mail(), false if the user
// couldn't receive (not found, inactive). The caller is responsible for
// any further audit logging beyond the generic password_reset.<purpose> entry
// we add here.
function send_password_link(int $userId, string $purpose = 'reset'): bool
{
    $stmt = db()->prepare(
        'SELECT u.id, u.first_name, u.email, u.status, u.association_id, a.name AS association_name
         FROM users u LEFT JOIN associations a ON a.id = u.association_id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] === 'inactive') return false;

    db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
        ->execute([$userId]);

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    db()->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (?, ?, (NOW() + INTERVAL 1 HOUR))'
    )->execute([$userId, $tokenHash]);

    $base       = (string)(config()['app']['base_url'] ?? 'https://badasshoa.com');
    $url        = rtrim($base, '/') . '/reset.php?token=' . $rawToken;
    $name       = (string)($user['first_name'] ?: 'there');
    $assocName  = (string)($user['association_name'] ?? '');
    $hasAssoc   = $assocName !== '';

    if ($purpose === 'invite') {
        if ($hasAssoc) {
            $subject = "You've been added to {$assocName} on BadassHOA";
            $body    = "Hi {$name},\n\n"
                     . "{$assocName} has added you to their HOA portal on BadassHOA. Click the link below to set your password and get signed in (the link expires in 1 hour):\n\n"
                     . $url . "\n\n"
                     . "If you weren't expecting this, you can safely ignore this email.\n\n"
                     . "— {$assocName} (via BadassHOA)";
        } else {
            $subject = 'Welcome to BadassHOA — set up your account';
            $body    = "Hi {$name},\n\n"
                     . "You've been added to BadassHOA. Click the link below to set your password and sign in (link expires in 1 hour):\n\n"
                     . $url . "\n\n"
                     . "If you weren't expecting this, you can safely ignore this email.\n\n"
                     . "— BadassHOA";
        }
    } else {
        // Password reset is always user-initiated (or admin acting "as the user")
        // — keep the framing platform-neutral.
        $subject = 'Reset your BadassHOA password';
        $body    = "Hi {$name},\n\n"
                 . "A password reset has been initiated for your BadassHOA account. Click the link below to set a new password (link expires in 1 hour):\n\n"
                 . $url . "\n\n"
                 . "If you didn't expect this, you can ignore this email — your password is unchanged.\n\n"
                 . "— BadassHOA";
    }

    send_mail((string)$user['email'], $subject, $body);
    audit('password_reset.' . $purpose, ['email' => $user['email']], $userId, 'user');
    return true;
}

// --- flash messages -----------------------------------------------------
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['_flash'])) $_SESSION['_flash'] = [];
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// --- routing helpers ----------------------------------------------------
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function base_url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

// --- Geocode an address via Photon (free, no API key) ------------------
// Returns ['lat' => float, 'lon' => float] on success, null on failure.
// Silent fail; never throws.
function geocode_address(string $query, int $timeoutSeconds = 5): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    $url = 'https://photon.komoot.io/api/?q=' . rawurlencode($query) . '&limit=1';
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeoutSeconds, 'header' => "Accept: application/json\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['features'])) return null;
    $coords = $data['features'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) return null;
    // Photon returns [lon, lat]
    return ['lat' => (float)$coords[1], 'lon' => (float)$coords[0]];
}

// --- US states + Canadian provinces datalist (for address forms) -------
function us_ca_states_datalist(string $id = 'us-ca-states'): string
{
    $states = [
        // US states
        'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware',
        'District of Columbia','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa',
        'Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota',
        'Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey',
        'New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon',
        'Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah',
        'Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming',
        // Canadian provinces & territories
        'Alberta','British Columbia','Manitoba','New Brunswick','Newfoundland and Labrador',
        'Nova Scotia','Northwest Territories','Nunavut','Ontario','Prince Edward Island',
        'Quebec','Saskatchewan','Yukon',
    ];
    $out = '<datalist id="' . e($id) . '">';
    foreach ($states as $name) {
        $out .= '<option value="' . e($name) . '">';
    }
    $out .= '</datalist>';
    return $out;
}

// --- timezone helpers ---------------------------------------------------
function user_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz !== null) return $tz;
    $user   = current_user();
    $tzName = (!empty($user['timezone']) && @timezone_open((string)$user['timezone'])) ? (string)$user['timezone'] : 'UTC';
    $tz     = new DateTimeZone($tzName);
    return $tz;
}

// Drop-in for date() that respects the signed-in user's timezone preference.
// Timestamps stored in the DB are always UTC; this converts to the user's TZ for display.
// Do NOT use for DB/form format strings (Y-m-d H:i:s, Y-m-d\TH:i) — those stay in UTC.
function udate(string $format, int|string|null $ts = null): string
{
    if ($ts === null) $ts = time();
    if (is_string($ts)) {
        $parsed = strtotime($ts);
        $ts     = $parsed !== false ? $parsed : time();
    }
    return (new DateTime('@' . $ts))->setTimezone(user_tz())->format($format);
}

// --- attachment upload helper -------------------------------------------
// Processes a single file upload from $_FILES[$field] and saves it under
// storage/uploads/{assocId}/{subdir}/{uuid}.{ext}.
// Returns ['file_path'=>..., 'file_name'=>..., 'file_type'=>..., 'file_size'=>...]
// or null when no file was selected or the field is absent.
// Throws RuntimeException on validation or save failure.
function save_attachment(int $assocId, string $subdir, string $field = 'attachment'): ?array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error: ' . $_FILES[$field]['error']);
    }

    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
    ];
    $maxBytes = 20 * 1024 * 1024; // 20 MB

    $tmpPath  = (string)$_FILES[$field]['tmp_name'];
    $origName = basename((string)$_FILES[$field]['name']);
    $size     = (int)$_FILES[$field]['size'];

    if ($size > $maxBytes) {
        throw new RuntimeException('File is too large. Max 20 MB.');
    }

    // Use finfo for reliable MIME detection; ignore the browser-supplied value.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($tmpPath);
    if (!in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('Only images (JPEG, PNG, GIF, WebP) and PDFs are allowed.');
    }

    $ext = match ($mime) {
        'image/jpeg'     => 'jpg',
        'image/png'      => 'png',
        'image/gif'      => 'gif',
        'image/webp'     => 'webp',
        'application/pdf'=> 'pdf',
        default          => 'bin',
    };

    $dir = __DIR__ . '/../storage/uploads/' . $assocId . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $uuid     = bin2hex(random_bytes(16));
    $filename = $uuid . '.' . $ext;
    $dest     = $dir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $dest)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return [
        'file_path' => $assocId . '/' . $subdir . '/' . $filename,
        'file_name' => $origName,
        'file_type' => $mime,
        'file_size' => $size,
    ];
}

// --- pricing calc (single source of truth) ------------------------------
// 30-day free trial on every paid tier. Two pricing bands (Professional was
// dropped 2026-05-13 — superfluous):
//   1-20   units → $20/mo flat
//   21+    units → $20 base + $0.50 per unit over 20
// Enterprise is a feature differentiator (multi-property portfolios + SLA),
// not a price tier — quoted custom.
function calc_monthly_price(int $units): array
{
    $u = max(1, $units);
    if ($u <= 20) return ['tier' => 'starter', 'price' => 20.0, 'cta' => 'Start 30-day free trial'];
    return                ['tier' => 'growth',  'price' => 20.0 + 0.50 * ($u - 20), 'cta' => 'Start 30-day free trial'];
}

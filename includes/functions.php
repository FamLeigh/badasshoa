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

// --- audit log ----------------------------------------------------------
function audit(string $action, array $meta = [], ?int $targetId = null, ?string $targetType = null): void
{
    try {
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

// --- pricing calc (single source of truth) ------------------------------
// 30-day free trial on every paid tier. Three pricing bands:
//   1-10   units → $20/mo flat
//   11-100 units → $0.50/unit (no base)
//   101+   units → $0.75/unit (no base)
// Enterprise is a feature differentiator (SSO, SLA, multi-property), not a price tier.
function calc_monthly_price(int $units): array
{
    $u = max(1, $units);
    if ($u <= 10)  return ['tier' => 'starter',      'price' => 20.0,        'cta' => 'Start 30-day free trial'];
    if ($u <= 100) return ['tier' => 'growth',       'price' => 0.50 * $u,   'cta' => 'Start 30-day free trial'];
    return                ['tier' => 'professional', 'price' => 0.75 * $u,   'cta' => 'Start 30-day free trial'];
}

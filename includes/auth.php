<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('badasshoa_sess');
    session_start();
}

// Strict role hierarchy; default-deny on missing/typo'd roles.
const ROLE_RANK = [
    'renter'           => 1,
    'resident'         => 2,
    'property_manager' => 3,
    'board_member'     => 4,
    'board_admin'      => 5,
    'super_admin'      => 6,
];

// --- CSRF ---------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(419);
        die('Session expired. Please reload and try again.');
    }
}

// --- current user -------------------------------------------------------
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    static $u = null;
    if ($u !== null) return $u;
    $stmt = db()->prepare(
        'SELECT id, association_id, first_name, last_name, email, role, status, unit_number
         FROM users WHERE id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch() ?: null;
    return $u;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        flash('error', 'Please sign in to continue.');
        redirect('/login.php');
    }
}

function require_role(string $minRole): void
{
    require_login();
    $userRole = $_SESSION['role'] ?? '';
    $userRank = ROLE_RANK[$userRole] ?? 0;            // unknown role -> 0 (default deny)
    $minRank  = ROLE_RANK[$minRole]  ?? PHP_INT_MAX;  // typo'd minRole -> deny everyone
    if ($userRank === 0 || $userRank < $minRank) {
        http_response_code(403);
        die('Access denied.');
    }
}

// --- login throttling ---------------------------------------------------
function login_attempt_blocked(string $email, string $ip): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE succeeded = 0
           AND attempted_at > (NOW() - INTERVAL 15 MINUTE)
           AND (ip_address = ? OR email = ?)'
    );
    $stmt->execute([$ip, $email]);
    return ((int)$stmt->fetchColumn()) >= 5;
}

function record_login_attempt(string $email, string $ip, bool $ok): void
{
    db()->prepare('INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (?, ?, ?)')
        ->execute([$email, $ip, $ok ? 1 : 0]);
}

// --- login / logout -----------------------------------------------------
function login_user(array $user): void
{
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id']        = (int)$user['id'];
    $_SESSION['association_id'] = $user['association_id'] !== null ? (int)$user['association_id'] : null;
    $_SESSION['role']           = $user['role'];
    $_SESSION['email']          = $user['email'];
    $_SESSION['name']           = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// --- post-login redirect target by role --------------------------------
function landing_for(string $role): string
{
    return $role === 'super_admin' ? '/admin/' : '/dashboard/';
}

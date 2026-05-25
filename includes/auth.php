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
    'staff'            => 2,
    'owner'            => 3,
    'property_manager' => 4,
    'board_member'     => 5,
    'board_admin'      => 6,
    'super_admin'      => 7,
];

// --- Configurable permissions -------------------------------------------

// Default minimum role for each feature. Board admins can override per-association
// via /dashboard/permissions.php. Defaults apply when no DB row exists.
function permission_defaults(): array
{
    return [
        'read_minutes'        => 'owner',
        'read_contacts'       => 'renter',
        'read_work_orders'    => 'board_member',
        'read_violations'     => 'board_member',
        'read_insurance'      => 'board_member',
        'read_employees'      => 'board_member',
        'submit_concerns'     => 'renter',
        'submit_arc'          => 'owner',
        'read_full_directory' => 'owner',
        'read_documents'      => 'renter',
        'manage_attractions'  => 'board_member',
        'submit_listing'      => 'owner',
    ];
}

// Check whether the current user's role meets the minimum for $permission.
// Super admins always pass. Management actions are NOT in this table — those
// stay hardcoded in require_management() so a misconfiguration can't expose them.
function can_do(string $permission): bool
{
    $role = viewing_role();
    if ($role === 'super_admin') return true;

    $aid      = (int)($_SESSION['association_id'] ?? 0);
    $defaults = permission_defaults();

    static $cache = [];
    if (!isset($cache[$aid])) {
        $cache[$aid] = [];
        if ($aid > 0) {
            try {
                $stmt = db()->prepare(
                    'SELECT permission_key, min_role FROM association_permissions WHERE association_id = ?'
                );
                $stmt->execute([$aid]);
                foreach ($stmt->fetchAll() as $r) {
                    $cache[$aid][$r['permission_key']] = $r['min_role'];
                }
            } catch (Throwable) {
                // Table may not exist in dev env yet — fall back to defaults silently.
            }
        }
    }

    $minRole  = $cache[$aid][$permission] ?? $defaults[$permission] ?? 'board_member';
    $userRank = ROLE_RANK[$role]    ?? 0;
    $minRank  = ROLE_RANK[$minRole] ?? PHP_INT_MAX;
    return $userRank >= $minRank;
}

// Label helper used in the permissions UI.
function role_label(string $role): string
{
    return match ($role) {
        'renter'           => 'Renter',
        'staff'            => 'Staff',
        'owner'            => 'Owner',
        'property_manager' => 'Property manager',
        'board_member'     => 'Board member',
        'board_admin'      => 'Board admin',
        'super_admin'      => 'Super admin',
        default            => ucfirst(str_replace('_', ' ', $role)),
    };
}

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
        'SELECT id, association_id, first_name, last_name, email, phone, role, status, unit_number, avatar_path, timezone, password_hash
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

// Roles allowed to add/edit/delete content within their association.
// Property managers count even though their rank is below board_member —
// the management privilege is a discrete capability, not a strict hierarchy.
const MANAGE_ROLES = ['property_manager', 'board_member', 'board_admin', 'super_admin'];

function role_can_manage(string $role): bool
{
    return in_array($role, MANAGE_ROLES, true);
}

// Server-side gate for "must be able to manage". Always uses the REAL session
// role — never the view_as role — because view-as is a UI lens, not an actual
// privilege drop.
function require_management(): void
{
    require_login();
    if (!role_can_manage((string)($_SESSION['role'] ?? ''))) {
        http_response_code(403);
        die('Access denied.');
    }
}

// "View as" lens: a manager can temporarily render the dashboard as if they
// were a homeowner or renter, to preview what those users see. It's a UI
// override, not a privilege change. UI/permission gates that affect
// rendering should call viewing_role() instead of reading $_SESSION['role'].
// Server-side action handlers and access gates should still use the real
// session role for safety.
function viewing_role(): string
{
    return (string)($_SESSION['view_as_role'] ?? $_SESSION['role'] ?? '');
}

function is_viewing_as(): bool
{
    return !empty($_SESSION['view_as_role']);
}

// --- login throttling ---------------------------------------------------
function login_attempt_blocked(string $email, string $ip): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
          WHERE kind = 'login' AND succeeded = 0
            AND attempted_at > (NOW() - INTERVAL 15 MINUTE)
            AND (ip_address = ? OR email = ?)"
    );
    $stmt->execute([$ip, $email]);
    return ((int)$stmt->fetchColumn()) >= 5;
}

function record_login_attempt(string $email, string $ip, bool $ok): void
{
    db()->prepare("INSERT INTO login_attempts (email, kind, ip_address, succeeded) VALUES (?, 'login', ?, ?)")
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
    // Board members are homeowners first — default them to member view on login.
    // They can switch to board view via the topbar toggle.
    if (in_array($user['role'], ['board_admin', 'board_member'], true)) {
        $_SESSION['view_as_role'] = 'owner';
    }
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

<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // needed by attempt_remember_login() at the bottom of this file

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/** Admin-configurable idle-session timeout (minutes) per role. 0 = no idle
 *  timeout (session only ends via PHP's native cookie/GC lifetime). */
function get_session_timeout_minutes(string $role): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
        $stmt->execute(["session_timeout_{$role}"]);
        $val = $stmt->fetchColumn();
        return $val !== false ? max(0, (int)$val) : 120;
    } catch (Throwable $e) {
        return 120;
    }
}

/** Root-relative path of the current request, safe to bounce back to after
 *  login (REQUEST_URI is already site-rooted, e.g. "/Akuapemconnect/x.php"). */
function current_request_path(): string {
    return $_SERVER['REQUEST_URI'] ?? '/';
}

/** Only ever return a same-site, root-relative path — never an absolute URL
 *  or protocol-relative one — so a "redirect" param can't be used for an
 *  open redirect. Returns '' if $target isn't safe to use. */
function safe_redirect_target(?string $target): string {
    $target = (string)($target ?? '');
    if ($target === '' || $target[0] !== '/') return '';
    if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) return '';
    if (str_contains($target, '://')) return '';
    return $target;
}

function require_login() {
    if (!current_user()) {
        // Absolute path required: this is called from admin/*.php too, where a
        // relative "login.php" redirect resolves to /admin/login.php (404),
        // which is what breaks the AJAX loader in admin/index.php on session expiry.
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php?redirect=' . urlencode(current_request_path()));
        exit;
    }

    // Idle-session timeout — admin-configurable per role (Admin → Monetize →
    // Session Settings). Sliding window: any authenticated request resets it.
    $timeoutMinutes = get_session_timeout_minutes(current_user()['role']);
    $now = time();
    if ($timeoutMinutes > 0 && isset($_SESSION['last_activity'])
        && ($now - $_SESSION['last_activity']) > $timeoutMinutes * 60) {
        logout_user();
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php?msg=session_expired&redirect=' . urlencode(current_request_path()));
        exit;
    }
    $_SESSION['last_activity'] = $now;

    // Invalidate sessions created before a password change (checked every 5 min per session)
    if (isset($_SESSION['session_started_at'])) {
        $now       = time();
        $lastCheck = $_SESSION['_pw_check_at'] ?? 0;
        if ($now - $lastCheck > 300) {
            $_SESSION['_pw_check_at'] = $now;
            global $pdo;
            $user = current_user();
            $stmt = $pdo->prepare('SELECT password_changed_at FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['password_changed_at'])
                && strtotime($row['password_changed_at']) > $_SESSION['session_started_at']) {
                logout_user();
                header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php?msg=password_changed&redirect=' . urlencode(current_request_path()));
                exit;
            }
        }
    }
}

function require_role($role) {
    $user = current_user();
    if (!$user || $user['role'] !== $role) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/jobs.php');
        exit;
    }
}

/** Blocks access to a module's pages when an admin has switched it off.
 *  $key matches module_enabled()'s prefix (e.g. 'mp', 'jobs', 'events'). */
function require_module_enabled(string $key, string $label): void {
    if (!module_enabled($key)) {
        flash("{$label} is currently unavailable.", 'error');
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/index.php');
        exit;
    }
}

function login_user($user) {
    // Regenerate session ID on login to prevent session fixation attacks
    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['user']             = $user;
    $_SESSION['session_started_at'] = time();
}

function logout_user() {
    clear_remember_cookie();
    $_SESSION = [];
    // Explicitly delete the session cookie so the browser discards it immediately
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── "Stay logged in" persistent tokens ──────────────────────────────────────
// Selector/validator pattern: the selector is a plain lookup key, the
// validator is only ever stored as a SHA-256 hash — a DB leak alone can't
// forge a valid cookie. Rotated on every successful auto-login.

const REMEMBER_COOKIE = 'remember_me';

function create_remember_token(int $userId): void {
    global $pdo;
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $days      = max(1, (int)get_platform_setting('remember_me_days', '30'));
    $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

    $pdo->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, $selector, hash('sha256', $validator), $expiresAt]);

    set_remember_cookie($selector, $validator, $days);
}

function set_remember_cookie(string $selector, string $validator, int $days): void {
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void {
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (!empty($parts[0])) {
        global $pdo;
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$parts[0]]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 86400, 'path' => '/']);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * Silently re-establishes a session from a "stay logged in" cookie, if valid.
 * Called automatically at the bottom of this file on every request, so any
 * page that already requires auth.php gets this for free.
 */
function attempt_remember_login(): void {
    if (current_user() || empty($_COOKIE[REMEMBER_COOKIE])) return;

    global $pdo;
    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) { clear_remember_cookie(); return; }
    [$selector, $validator] = $parts;

    $row = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector=?');
    $row->execute([$selector]);
    $token = $row->fetch();

    if (!$token || strtotime($token['expires_at']) < time()
        || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        clear_remember_cookie();
        return;
    }

    $userStmt = $pdo->prepare(
        'SELECT id, name, username, email, email_verified, role, phone, town_id,
                latitude, longitude, profile_photo, email_notifications_enabled, banned
         FROM users WHERE id = ?'
    );
    $userStmt->execute([$token['user_id']]);
    $user = $userStmt->fetch();

    // Delete the used token either way — it's single-use regardless of outcome.
    $pdo->prepare('DELETE FROM remember_tokens WHERE id=?')->execute([$token['id']]);

    if (!$user || $user['banned']) {
        clear_remember_cookie();
        return;
    }

    login_user($user);
    create_remember_token((int)$user['id']); // rotate
}

// ── CSRF helpers ─────────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_check(): void {
    // GET fallback exists for read-only, link-triggered actions (e.g. CSV export
    // links built with csrf_token() in the query string) — POST body still wins
    // whenever both are present.
    $submitted = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        exit('Security check failed. Please go back and try again.');
    }
}

function is_email_verified(): bool
{
    $user = current_user();
    if (!$user) {
        return true;
    }
    // Sessions created before the migration won't have this key — treat as verified
    return !isset($user['email_verified']) || (bool) $user['email_verified'];
}

function is_admin() {
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function is_worker() {
    $user = current_user();
    return $user && $user['role'] === 'worker';
}

function is_customer() {
    $user = current_user();
    return $user && $user['role'] === 'customer';
}

function is_manager() {
    $user = current_user();
    return $user && $user['role'] === 'manager';
}

function is_admin_or_manager() {
    $user = current_user();
    return $user && ($user['role'] === 'admin' || $user['role'] === 'manager');
}

// Runs on every request that loads auth.php (virtually every page) — silently
// restores a session from a valid "stay logged in" cookie before any
// page-specific require_login()/current_user() check happens.
attempt_remember_login();

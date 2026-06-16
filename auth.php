<?php
require_once __DIR__ . '/db.php';

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
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
                header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php?msg=password_changed');
                exit;
            }
        }
    }
}

function require_role($role) {
    $user = current_user();
    if (!$user || $user['role'] !== $role) {
        header('Location: jobs.php');
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
    $_SESSION = [];
    // Explicitly delete the session cookie so the browser discards it immediately
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
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
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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

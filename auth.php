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
}

function require_role($role) {
    $user = current_user();
    if (!$user || $user['role'] !== $role) {
        header('Location: dashboard.php');
        exit;
    }
}

function login_user($user) {
    unset($user['password_hash']);
    $_SESSION['user'] = $user;
}

function logout_user() {
    session_unset();
    session_destroy();
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

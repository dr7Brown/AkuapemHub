<?php
/**
 * Entry point for the "Sign in with Google" button on login.php/register.php.
 * Builds the Google OAuth URL and redirects — the actual token exchange
 * happens in google_callback.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: community.php');
    exit;
}

$google = google_oauth_settings();
if ($google['client_id'] === '') {
    flash('Sign in with Google is not configured yet.', 'error');
    header('Location: login.php');
    exit;
}

// Single-use CSRF/session-fixation guard, checked once in google_callback.php.
$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;

// Set when the app shell opens this in the system browser (see
// assets/js/google-auth-bridge.js) — Google blocks OAuth inside an embedded
// WebView, so the app hands off here instead. google_callback.php reads
// this back (same browser, same session cookie) to know whether to finish
// with a normal same-site redirect or hand off to the app via a deep link.
$_SESSION['google_oauth_mobile'] = !empty($_GET['mobile']);

// Preserve the same "redirect back to where I was" behavior login.php uses.
$redirectTarget = safe_redirect_target($_GET['redirect'] ?? null);
if ($redirectTarget !== '') {
    $_SESSION['google_oauth_redirect'] = $redirectTarget;
} else {
    unset($_SESSION['google_oauth_redirect']);
}

$params = http_build_query([
    'client_id'     => $google['client_id'],
    'redirect_uri'  => $google['redirect_uri'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;

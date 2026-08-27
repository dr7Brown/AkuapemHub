<?php
/**
 * OAuth redirect target for "Sign in with Google". Exchanges the auth code
 * for an access token, fetches the profile from Google's userinfo endpoint,
 * then hands off to google_find_or_create_user() (functions.php) for the
 * match/link/create decision. Raw cURL + json_decode, no SDK — same style as
 * paystack_request() in paystack.php, since this project has no Composer.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

function google_bounce(string $message): never {
    flash($message, 'error');
    header('Location: login.php');
    exit;
}

if (current_user()) {
    header('Location: community.php');
    exit;
}

$state       = $_GET['state'] ?? '';
$storedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']); // single-use regardless of outcome

if (!$storedState || !hash_equals($storedState, $state)) {
    google_bounce('Your Google sign-in session expired. Please try again.');
}

if (!empty($_GET['error'])) {
    // User cancelled on Google's consent screen, or Google itself errored —
    // either way this isn't a bug, just no code to exchange.
    google_bounce('Google sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    google_bounce('Google did not return an authorization code.');
}

$google = google_oauth_settings();
if ($google['client_id'] === '' || $google['client_secret'] === '') {
    google_bounce('Sign in with Google is not configured yet.');
}

// ── Exchange the code for an access token ───────────────────────────────────
$tokenCh = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($tokenCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $google['client_id'],
        'client_secret' => $google['client_secret'],
        'redirect_uri'  => $google['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$tokenResponse = curl_exec($tokenCh);
$tokenCurlError = curl_error($tokenCh);
curl_close($tokenCh);

if ($tokenResponse === false) {
    error_log('Google OAuth token exchange failed: ' . $tokenCurlError);
    google_bounce('Could not reach Google right now. Please try again.');
}
$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;
if (!$accessToken) {
    error_log('Google OAuth token exchange returned no access_token: ' . $tokenResponse);
    google_bounce('Google sign-in failed. Please try again.');
}

// ── Fetch the profile — a direct authenticated call to Google's own server,
// which is what makes this trustworthy without hand-rolling ID-token JWT
// signature verification. ──────────────────────────────────────────────────
$infoCh = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($infoCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 15,
]);
$infoResponse = curl_exec($infoCh);
curl_close($infoCh);

$profile = $infoResponse ? json_decode($infoResponse, true) : null;
if (!$profile || empty($profile['sub'])) {
    error_log('Google OAuth userinfo request failed: ' . $infoResponse);
    google_bounce('Google sign-in failed. Please try again.');
}

$result = google_find_or_create_user($profile);
if (!$result['ok']) {
    google_bounce($result['error'] ?? 'Google sign-in failed.');
}

login_user($result['user']);

if ($result['is_new']) {
    header('Location: complete_profile.php');
    exit;
}

$redirectTarget = $_SESSION['google_oauth_redirect'] ?? '';
unset($_SESSION['google_oauth_redirect']);
header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : 'community.php'));
exit;

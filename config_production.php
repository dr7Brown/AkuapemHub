<?php
// Application configuration — PRODUCTION.

// ── Error display: hidden from visitors in production, shown on local XAMPP ──
$__isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
ini_set('display_errors', $__isLocal ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
unset($__isLocal);

// Pin PHP to UTC regardless of the server's ambient php.ini/system timezone —
// Ghana is GMT/UTC+0 year-round (no DST), so this is also correct local time.
// Without this, PHP's time_ago()/date() calculations silently drift out of
// sync with whatever timezone MySQL's NOW() actually writes in (a common
// shared-hosting mismatch — e.g. MySQL on US Eastern vs PHP on UTC produces
// exactly a 4-hour "time ago" error on freshly-created rows).
date_default_timezone_set('UTC');

// ── Database configuration (PRODUCTION) ──────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'royayfxh_akuapemconnectx');
define('DB_USER', 'royayfxh_akuapemconnect_admin');
define('DB_PASS', 'jx-EhmH,s]=1IRmZ');
define('APP_NAME', 'AkuapemConnect');
define('BASE_URL', 'https://akuapemconnect.com');

define('ADMIN_EMAIL', 'admin@akuapemconnect.com');
define('MAIL_FROM', 'info@expresslabgh.com');

// ── WhatsApp OTP provider ────────────────────────────────────────────────────
// Set WHATSAPP_PROVIDER to 'meta' or 'twilio'. Leave blank to disable sending
// (messages are only written to error_log — useful during development).
//
// Meta Cloud API:  set WHATSAPP_PHONE_NUMBER_ID + WHATSAPP_PROVIDER_TOKEN (bearer token)
// Twilio:          set WHATSAPP_TWILIO_ACCOUNT_SID + WHATSAPP_PROVIDER_TOKEN (auth token) + WHATSAPP_TWILIO_FROM
define('WHATSAPP_PROVIDER',           '');      // 'meta' | 'twilio' | ''
define('WHATSAPP_PROVIDER_TOKEN',     '');      // Meta: Bearer token  |  Twilio: Auth token
define('WHATSAPP_PHONE_NUMBER_ID',    '');      // Meta Cloud API phone number ID
define('WHATSAPP_TWILIO_ACCOUNT_SID', '');      // Twilio account SID
define('WHATSAPP_TWILIO_FROM',        '');      // Twilio from number e.g. whatsapp:+14155238886
define('WHATSAPP_PROVIDER_URL',       '');      // Legacy — unused by WhatsAppService
define('SMS_PROVIDER_URL',            '');
define('SMS_PROVIDER_TOKEN',          '');

define('ADMIN_ROLE', 'admin');

define('DEFAULT_COMMISSION', 10);

// ── Secure session configuration ────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 7200); // 2-hour idle timeout
// Only set Secure when actually on HTTPS (not localhost HTTP)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

session_start();

// ── Security headers (sent with every response) ─────────────────────────────
// Works even on shared hosting where php.ini's expose_php can't be changed.
header_remove('X-Powered-By');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
// CSP: unsafe-inline required for this app's extensive inline JS/CSS blocks.
// Remove 'unsafe-inline' from script-src and migrate to nonces in a future hardening pass.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' js.paystack.co; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: *; connect-src 'self' api.paystack.co; frame-src js.paystack.co; font-src 'self' data:; object-src 'none'; base-uri 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

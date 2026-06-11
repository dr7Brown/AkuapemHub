<?php
// Application configuration for XAMPP / local environment.
// Copy this file to config.php and update values if needed.

// define('DB_HOST', '127.0.0.1');
define('DB_HOST', 'localhost');
define('DB_NAME', 'akuapemhub');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'AkuapemHub');
define('BASE_URL', 'http://localhost:8080/AkuapemHub');

define('ADMIN_EMAIL', 'admin@example.com');
define('MAIL_FROM', 'noreply@example.com');

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

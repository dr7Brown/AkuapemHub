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

// SMS / WhatsApp Business messaging provider (e.g. Africa's Talking, Twilio, Meta Cloud API).
// Leave the URL blank to keep messaging disabled — outgoing messages are then only logged, not sent.
define('WHATSAPP_PROVIDER_URL', '');
define('WHATSAPP_PROVIDER_TOKEN', '');
define('SMS_PROVIDER_URL', '');
define('SMS_PROVIDER_TOKEN', '');

define('ADMIN_ROLE', 'admin');

define('DEFAULT_COMMISSION', 10);

session_start();

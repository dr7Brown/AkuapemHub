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

define('ADMIN_ROLE', 'admin');

define('DEFAULT_COMMISSION', 10);

session_start();

<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
header('Location: ' . ($user ? 'dashboard.php' : 'community.php'));
exit;

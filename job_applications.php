<?php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
$dest = ($user['role'] === 'worker')
    ? 'worker_history.php'
    : ('manage_applicants.php' . (!empty($_GET['id']) ? '?id=' . intval($_GET['id']) : ''));
header('Location: ' . $dest);
exit;

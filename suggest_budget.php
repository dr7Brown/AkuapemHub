<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

header('Content-Type: application/json');

$categoryId = intval($_GET['category_id'] ?? 0);
$location = trim($_GET['location'] ?? '');

if ($categoryId <= 0) {
    echo json_encode(['suggestion' => null]);
    exit;
}

echo json_encode(['suggestion' => get_suggested_budget_range($categoryId, $location)]);

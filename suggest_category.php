<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

header('Content-Type: application/json');

$title = trim($_GET['title'] ?? '');
$description = trim($_GET['description'] ?? '');
$categories = get_categories();

echo json_encode(['suggestion' => guess_category_for_text($title, $description, $categories)]);

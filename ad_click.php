<?php
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: news.php'); exit; }

$stmt = $pdo->prepare("SELECT destination_url FROM advertisements WHERE id=? AND status='active' LIMIT 1");
$stmt->execute([$id]);
$ad = $stmt->fetch();

if ($ad && $ad['destination_url']) {
    $pdo->prepare("UPDATE advertisements SET click_count=click_count+1 WHERE id=?")->execute([$id]);
    $url = $ad['destination_url'];
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    header('Location: ' . $url);
} else {
    header('Location: news.php');
}
exit;

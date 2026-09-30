<?php
/**
 * Quick Services — gated result document download. The file itself lives in
 * private_uploads/quick_service_results/ (denied by its own .htaccess, so a
 * guessed/leaked direct URL can't reach it) — this script is the ONLY path
 * to it, and only after confirming the requester is the owning customer, an
 * assigned manager, or an admin.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/quick_service_functions.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

$reference = trim($_GET['ref'] ?? '');
$tx = $reference !== '' ? qs_get_transaction_by_reference($reference) : null;

$isOwner   = $tx && (int)$tx['user_id'] === (int)$user['id'];
$canManage = $tx && user_can_manage_quick_service($user['id'], (int)$tx['service_id']);
if (!$tx || (!$isOwner && !$canManage) || !$tx['result_file_path']) {
    http_response_code(404);
    exit('Not found.');
}

$path = __DIR__ . '/private_uploads/quick_service_results/' . basename($tx['result_file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

log_audit_action($user['id'], 'qs_result_download', "Downloaded result document for {$tx['reference']}");

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $tx['reference']) . '.' . $ext . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

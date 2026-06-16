<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

require_login();
$user = current_user();

$theirId = intval($_GET['user_id'] ?? 0);
$jobId   = intval($_GET['job_id']  ?? 0) ?: null;

if (!$theirId) {
    flash('Invalid user.', 'error');
    header('Location: jobs.php');
    exit;
}

$check = can_chat_with($user['id'], $theirId, $jobId);
if (!$check['allowed']) {
    flash($check['reason'], 'error');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'jobs.php'));
    exit;
}

$convId = get_or_create_conversation($user['id'], $theirId, $check['type'], $check['job_id'] ?? null);
header('Location: chat.php?id=' . $convId);
exit;

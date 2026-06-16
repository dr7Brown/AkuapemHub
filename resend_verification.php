<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/EmailService.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: jobs.php');
    exit;
}
csrf_check();

$user = current_user();

// Already verified
if (!empty($user['email_verified'])) {
    flash('Your email is already verified.', 'info');
    header('Location: jobs.php');
    exit;
}

// Rate limit: 1 resend per 5 minutes
$stmt = $pdo->prepare('SELECT email_verification_sent_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && !empty($row['email_verification_sent_at'])) {
    $sentAt = strtotime($row['email_verification_sent_at']);
    if (time() - $sentAt < 300) {
        $waitSec = 300 - (time() - $sentAt);
        flash('Please wait ' . ceil($waitSec / 60) . ' minute(s) before requesting another verification email.', 'error');
        header('Location: jobs.php');
        exit;
    }
}

$token   = bin2hex(random_bytes(32));
$sentAt  = date('Y-m-d H:i:s');
$pdo->prepare(
    'UPDATE users SET email_verification_token = ?, email_verification_sent_at = ? WHERE id = ?'
)->execute([$token, $sentAt, $user['id']]);

EmailService::sendVerificationEmail($user['email'], $user['name'], $token);
log_security_event($user['id'], 'email_verification_resent', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', '');

flash('Verification email sent to ' . $user['email'] . '. Please check your inbox.', 'success');
header('Location: jobs.php');
exit;

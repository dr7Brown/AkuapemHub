<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('worker');
$user = current_user();

$stmt = $pdo->prepare('SELECT subscription_status FROM worker_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

if (!$profile) {
    flash('Worker profile not found.', 'error');
    header('Location: worker_profile.php');
    exit;
}

// Upgrading (free -> premium) costs money once the admin requires it —
// send them to the real payment flow instead of flipping the flag for free.
// Downgrading (premium -> free) stays instant and free either way; there's
// no reason to charge someone to cancel.
if ($profile['subscription_status'] !== 'premium' && is_feature_paid('enable_paid_worker_premium')) {
    header('Location: pay_worker_premium.php');
    exit;
}

$newStatus = $profile['subscription_status'] === 'premium' ? 'free' : 'premium';
$pdo->prepare('UPDATE worker_profiles SET subscription_status = ?, updated_at = NOW() WHERE user_id = ?')->execute([$newStatus, $user['id']]);

notify_user($user['id'], 'Subscription updated', "Your worker subscription has been updated to {$newStatus}.", 'success');
flash('Subscription updated to ' . ucfirst($newStatus) . '.');
header('Location: worker_profile.php');
exit;

<?php
/**
 * POST-only "Report Listing" handler — no fully-generic report table exists
 * elsewhere in the codebase to reuse (disputes is hard-wired to
 * service_requests), so this writes to the new lightweight accommodation_reports
 * table and notifies moderators the same way every other admin queue does.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: accommodation.php'); exit; }
csrf_check();

$user      = current_user();
$listingId = (int)($_POST['listing_id'] ?? 0);
$reason    = $_POST['reason'] ?? '';
$details   = trim($_POST['details'] ?? '');
$validReasons = ['fake', 'wrong_info', 'already_rented', 'scam', 'inappropriate', 'other'];

$listing = get_accommodation_listing($listingId);
if (!$listing || !in_array($reason, $validReasons, true)) {
    flash('Could not submit that report.', 'error');
    header('Location: accommodation.php');
    exit;
}

$pdo->prepare('INSERT INTO accommodation_reports (listing_id, reporter_id, reason, details) VALUES (?,?,?,?)')
    ->execute([$listingId, $user['id'], $reason, $details ?: null]);

notify_moderators('manage_accommodation', 'Accommodation Listing Reported',
    $user['name'] . ' reported "' . $listing['title'] . '" — reason: ' . str_replace('_', ' ', $reason) . '. Check Admin → Accommodation → Reports.');

flash('Thanks — your report has been submitted to our team.', 'success');
header('Location: accommodation_detail.php?id=' . $listingId);
exit;

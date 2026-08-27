<?php
/**
 * POST-only handler for the three enquiry buttons on accommodation_detail.php
 * (Contact Owner / Request Viewing / Send Booking Enquiry). Reuses the
 * existing chat system rather than building new messaging — see
 * get_or_create_conversation_ex() in chat_functions.php. No job-relationship
 * gate applies here (can_chat_with() isn't used); enquiring about someone's
 * own listing is inherently a valid reason to open a conversation with them.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: accommodation.php'); exit; }
csrf_check();

$user       = current_user();
$listingId  = (int)($_POST['listing_id'] ?? 0);
$enquiryType = in_array($_POST['enquiry_type'] ?? '', ['contact', 'viewing', 'booking'], true) ? $_POST['enquiry_type'] : 'contact';

$listing = get_accommodation_listing($listingId);
if (!$listing || $listing['status'] !== 'approved') {
    flash('That listing is not available.', 'error');
    header('Location: accommodation.php');
    exit;
}

$ownerId = (int)$listing['user_id'];
if ($ownerId === (int)$user['id']) {
    flash('You cannot enquire about your own listing.', 'error');
    header('Location: accommodation_detail.php?id=' . $listingId);
    exit;
}

if (chat_is_disabled()) {
    flash('Messaging is currently disabled on this platform.', 'error');
    header('Location: accommodation_detail.php?id=' . $listingId);
    exit;
}
$myStatus = get_user_chat_status((int)$user['id']);
if (!$myStatus['can_send']) {
    flash($myStatus['banned'] ? 'Your messaging is suspended until ' . date('M j, Y', strtotime($myStatus['ban_until'])) . '.' : 'Your messaging privileges have been disabled.', 'error');
    header('Location: accommodation_detail.php?id=' . $listingId);
    exit;
}
$ownerStatus = get_user_chat_status($ownerId);
if (!$ownerStatus['can_receive']) {
    flash('This owner cannot receive messages at this time.', 'error');
    header('Location: accommodation_detail.php?id=' . $listingId);
    exit;
}

$result = get_or_create_conversation_ex((int)$user['id'], $ownerId, 'accommodation_enquiry', null, $listingId);

if ($result['created']) {
    $notifTitles = [
        'contact' => 'New accommodation enquiry',
        'viewing' => 'New viewing request',
        'booking' => 'New booking enquiry',
    ];
    $notifBodies = [
        'contact' => $user['name'] . ' is interested in "' . $listing['title'] . '" and wants to get in touch.',
        'viewing' => $user['name'] . ' would like to request a viewing for "' . $listing['title'] . '".',
        'booking' => $user['name'] . ' sent a booking enquiry for "' . $listing['title'] . '".',
    ];
    notify_user($ownerId, '🏠 ' . $notifTitles[$enquiryType], $notifBodies[$enquiryType], 'info', 'chat.php?id=' . $result['id']);
}

header('Location: chat.php?id=' . $result['id']);
exit;

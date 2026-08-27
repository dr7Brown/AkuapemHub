<?php
/**
 * Moderator quick-action handler.
 * All approve/reject actions in one place with notification links.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
csrf_check();

$mod    = current_user();
$action = $_POST['action']  ?? '';
$itemId = (int)($_POST['item_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$back   = $_POST['back'] ?? 'index.php';

if (!preg_match('/^[a-zA-Z0-9_\-\.?=&%#+\/]+$/', $back)) $back = 'index.php';

function mod_flash_redirect(string $msg, string $type, string $back): never {
    flash($msg, $type);
    header('Location: ' . $back);
    exit;
}

if (!$itemId) mod_flash_redirect('Invalid item.', 'error', $back);

// ── Conflict of Interest check ────────────────────────────────────────────────
// Map action → record type for COI detection
$coiTypeMap = [
    'approve_job'              => 'job',
    'reject_job'               => 'job',
    'approve_product'          => 'product',
    'reject_product'           => 'product',
    'approve_event'            => 'event',
    'reject_event'             => 'event',
    'approve_funeral'          => 'funeral',
    'reject_funeral'           => 'funeral',
    'approve_news'             => 'news',
    'reject_news'              => 'news',
    'approve_delivery_request' => 'delivery_request',
    'reject_delivery_request'  => 'delivery_request',
    'approve_delivery_agent'   => 'delivery_agent',
    'reject_delivery_agent'    => 'delivery_agent',
    'approve_listing'          => 'accommodation_listing',
    'reject_listing'           => 'accommodation_listing',
    'approve_delete_event'     => 'event',
    'reject_delete_event'      => 'event',
    'approve_delete_news'      => 'news',
    'reject_delete_news'       => 'news',
];
if (isset($coiTypeMap[$action]) && check_mod_coi($coiTypeMap[$action], $itemId, (int)$mod['id'])) {
    log_coi_violation((int)$mod['id'], $coiTypeMap[$action], $itemId, $action);
    mod_flash_redirect(
        '⚠️ Conflict of Interest: You cannot moderate this item because you created it. Another moderator or admin must review it.',
        'error', $back
    );
}

// ── approve_job ───────────────────────────────────────────────────────────────
if ($action === 'approve_job') {
    require_mod_permission('approve_jobs', $back);
    $row = $pdo->prepare('SELECT sr.*, u.email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id=u.id WHERE sr.id=? AND sr.status=?');
    $row->execute([$itemId, 'pending']);
    $job = $row->fetch();
    if (!$job) mod_flash_redirect('Job not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE service_requests SET status='open', approved_by=?, updated_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    notify_user((int)$job['customer_id'], 'Job Approved ✅',
        '"' . $job['title'] . '" is now live and visible to workers.',
        'success', 'request_detail.php?id=' . $itemId);
    send_email_notification($job['email'], 'Your job post is live — ' . APP_NAME,
        'Hi ' . $job['customer_name'] . ",\n\nYour job post \"" . $job['title'] . "\" has been approved and is now live.\n\n" . BASE_URL . '/request_detail.php?id=' . $itemId,
        (int)$job['customer_id']);
    log_audit_action($mod['id'], 'job_approve_quick', 'Approved job #' . $itemId . ': ' . $job['title']);
    log_mod_activity($mod['id'], 'jobs', 'approve_job', $itemId, $job['title']);
    notify_workers_of_matching_job($job);
    mod_flash_redirect('"' . $job['title'] . '" approved and posted.', 'success', $back);
}

// ── reject_job ────────────────────────────────────────────────────────────────
if ($action === 'reject_job') {
    require_mod_permission('approve_jobs', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT sr.*, u.email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id=u.id WHERE sr.id=? AND sr.status=?');
    $row->execute([$itemId, 'pending']);
    $job = $row->fetch();
    if (!$job) mod_flash_redirect('Job not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE service_requests SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$job['customer_id'], 'Job Post Rejected',
        '"' . $job['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nYou can edit and resubmit.",
        'error', 'request_detail.php?id=' . $itemId);
    log_audit_action($mod['id'], 'job_reject_quick', 'Rejected job #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'jobs', 'reject_job', $itemId, $job['title']);
    mod_flash_redirect('"' . $job['title'] . '" rejected.', 'info', $back);
}

// ── approve_product ───────────────────────────────────────────────────────────
if ($action === 'approve_product') {
    require_mod_permission('approve_products', $back);
    $row = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id, ms.shop_name FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=? AND mp.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $prod = $row->fetch();
    if (!$prod) mod_flash_redirect('Product not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE mp_products SET status='approved', approved_by=?, updated_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    notify_user((int)$prod['owner_id'], 'Product Approved ✅',
        '"' . $prod['name'] . '" is now live on the marketplace.',
        'success', 'product.php?id=' . $itemId);
    log_audit_action($mod['id'], 'mp_product_approve_quick', 'Approved product #' . $itemId . ': ' . $prod['name']);
    log_mod_activity($mod['id'], 'marketplace', 'approve_product', $itemId, $prod['name']);
    mod_flash_redirect('"' . $prod['name'] . '" approved.', 'success', $back);
}

// ── reject_product ────────────────────────────────────────────────────────────
if ($action === 'reject_product') {
    require_mod_permission('approve_products', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=? AND mp.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $prod = $row->fetch();
    if (!$prod) mod_flash_redirect('Product not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE mp_products SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$prod['owner_id'], 'Product Rejected',
        '"' . $prod['name'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nEdit and resubmit from your seller dashboard.",
        'error', 'seller_dashboard.php?tab=products');
    log_audit_action($mod['id'], 'mp_product_reject_quick', 'Rejected product #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'marketplace', 'reject_product', $itemId, $prod['name']);
    mod_flash_redirect('"' . $prod['name'] . '" rejected.', 'info', $back);
}

// ── approve_event ─────────────────────────────────────────────────────────────
if ($action === 'approve_event') {
    require_mod_permission('approve_events', $back);
    $row = $pdo->prepare('SELECT e.*, u.id AS owner_id, u.email, u.name AS owner_name FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=?');
    $row->execute([$itemId]);
    $ev = $row->fetch();
    if (!$ev) mod_flash_redirect('Event not found.', 'error', $back);

    $pdo->prepare("UPDATE events SET status='published', approved_by=?, published_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    require_once __DIR__ . '/../modules/referrals/service.php';
    award_points((int)$ev['owner_id'], 'event_approved', $itemId);
    notify_user((int)$ev['owner_id'], 'Event Published ✅',
        '"' . $ev['title'] . '" is now live. Share it with your community!',
        'success', 'event.php?slug=' . urlencode($ev['slug']));
    log_audit_action($mod['id'], 'event_approve_quick', 'Approved event #' . $itemId . ': ' . $ev['title']);
    log_mod_activity($mod['id'], 'events', 'approve_event', $itemId, $ev['title']);
    mod_flash_redirect('"' . $ev['title'] . '" published.', 'success', $back);
}

// ── reject_event ──────────────────────────────────────────────────────────────
if ($action === 'reject_event') {
    require_mod_permission('approve_events', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT e.*, u.id AS owner_id FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=?');
    $row->execute([$itemId]);
    $ev = $row->fetch();
    if (!$ev) mod_flash_redirect('Event not found.', 'error', $back);

    $pdo->prepare("UPDATE events SET status='rejected', rejection_reason=? WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$ev['owner_id'], '❌ Event Rejected — Action Required',
        '"' . $ev['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nClick to edit and resubmit.",
        'error', 'my_events.php?edit=' . $itemId);
    log_audit_action($mod['id'], 'event_reject_quick', 'Rejected event #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'events', 'reject_event', $itemId, $ev['title']);
    mod_flash_redirect('"' . $ev['title'] . '" rejected.', 'info', $back);
}

// ── approve_funeral ───────────────────────────────────────────────────────────
if ($action === 'approve_funeral') {
    require_mod_permission('approve_funerals', $back);
    $row = $pdo->prepare('SELECT fa.*, u.id AS owner_id FROM funeral_announcements fa JOIN users u ON fa.user_id=u.id WHERE fa.id=? AND fa.status=?');
    $row->execute([$itemId, 'pending']);
    $fa = $row->fetch();
    if (!$fa) mod_flash_redirect('Announcement not found.', 'error', $back);

    $pdo->prepare("UPDATE funeral_announcements SET status='approved', approved_by=? WHERE id=?")->execute([$mod['id'], $itemId]);
    notify_user((int)$fa['owner_id'], 'Funeral Announcement Approved ✅',
        'The announcement for ' . $fa['deceased_name'] . ' is now published.',
        'success', 'funeral.php?slug=' . urlencode($fa['slug']));
    log_audit_action($mod['id'], 'funeral_approve_quick', 'Approved funeral #' . $itemId);
    log_mod_activity($mod['id'], 'funerals', 'approve_funeral', $itemId, $fa['deceased_name']);
    mod_flash_redirect('Funeral announcement approved.', 'success', $back);
}

// ── reject_funeral ────────────────────────────────────────────────────────────
if ($action === 'reject_funeral') {
    require_mod_permission('approve_funerals', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT fa.*, u.id AS owner_id FROM funeral_announcements fa JOIN users u ON fa.user_id=u.id WHERE fa.id=? AND fa.status=?');
    $row->execute([$itemId, 'pending']);
    $fa = $row->fetch();
    if (!$fa) mod_flash_redirect('Announcement not found.', 'error', $back);

    $pdo->prepare("UPDATE funeral_announcements SET status='rejected', rejection_reason=? WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$fa['owner_id'], '❌ Announcement Rejected — Action Required',
        'The announcement for "' . $fa['deceased_name'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nClick to edit and resubmit.",
        'error', 'my_funerals.php?edit=' . $itemId);
    log_audit_action($mod['id'], 'funeral_reject_quick', 'Rejected funeral #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'funerals', 'reject_funeral', $itemId, $fa['deceased_name']);
    mod_flash_redirect('Funeral announcement rejected.', 'info', $back);
}

// ── approve_news ──────────────────────────────────────────────────────────────
if ($action === 'approve_news') {
    require_mod_permission('approve_news', $back);
    $row = $pdo->prepare('SELECT n.*, u.id AS owner_id FROM news n JOIN users u ON n.user_id=u.id WHERE n.id=? AND n.status=?');
    $row->execute([$itemId, 'draft']);
    $ns = $row->fetch();
    if (!$ns) mod_flash_redirect('Article not found.', 'error', $back);

    $pdo->prepare("UPDATE news SET status='published', approved_by=?, published_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    require_once __DIR__ . '/../modules/referrals/service.php';
    award_points((int)$ns['owner_id'], 'news_approved', $itemId);
    notify_user((int)$ns['owner_id'], 'Article Published ✅',
        '"' . $ns['title'] . '" is now live.',
        'success', 'news_article.php?slug=' . urlencode($ns['slug']));
    log_audit_action($mod['id'], 'news_approve_quick', 'Approved news #' . $itemId . ': ' . $ns['title']);
    log_mod_activity($mod['id'], 'news', 'approve_news', $itemId, $ns['title']);
    mod_flash_redirect('"' . $ns['title'] . '" published.', 'success', $back);
}

// ── reject_news ───────────────────────────────────────────────────────────────
if ($action === 'reject_news') {
    require_mod_permission('approve_news', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT n.*, u.id AS owner_id FROM news n JOIN users u ON n.user_id=u.id WHERE n.id=? AND n.status=?');
    $row->execute([$itemId, 'draft']);
    $ns = $row->fetch();
    if (!$ns) mod_flash_redirect('Article not found.', 'error', $back);

    $pdo->prepare("UPDATE news SET status='rejected', rejection_reason=? WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$ns['owner_id'], '❌ Article Rejected — Action Required',
        '"' . $ns['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nClick to edit and resubmit.",
        'error', 'my_news.php?edit=' . $itemId);
    log_audit_action($mod['id'], 'news_reject_quick', 'Rejected news #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'news', 'reject_news', $itemId, $ns['title']);
    mod_flash_redirect('"' . $ns['title'] . '" rejected.', 'info', $back);
}

// ── approve_delete_event: honours a user's deletion request ───────────────────
if ($action === 'approve_delete_event') {
    require_mod_permission('approve_events', $back);
    $row = $pdo->prepare('SELECT e.*, u.id AS owner_id FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=? AND e.deletion_requested=1');
    $row->execute([$itemId]);
    $ev = $row->fetch();
    if (!$ev) mod_flash_redirect('Deletion request not found.', 'error', $back);

    $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$itemId]);
    notify_user((int)$ev['owner_id'], 'Event Deleted', 'Your deletion request for "' . $ev['title'] . '" was approved — the event has been removed.', 'info');
    log_audit_action($mod['id'], 'event_delete_approve', 'Approved deletion of event #' . $itemId . ': ' . $ev['title']);
    log_mod_activity($mod['id'], 'events', 'approve_delete_event', $itemId, $ev['title']);
    mod_flash_redirect('"' . $ev['title'] . '" deleted.', 'success', $back);
}

// ── reject_delete_event: declines the deletion request, restores the event ────
if ($action === 'reject_delete_event') {
    require_mod_permission('approve_events', $back);
    if (!$reason) mod_flash_redirect('A reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT e.*, u.id AS owner_id FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=? AND e.deletion_requested=1');
    $row->execute([$itemId]);
    $ev = $row->fetch();
    if (!$ev) mod_flash_redirect('Deletion request not found.', 'error', $back);

    $pdo->prepare('UPDATE events SET deletion_requested=0, deletion_requested_at=NULL WHERE id=?')->execute([$itemId]);
    notify_user((int)$ev['owner_id'], 'Deletion Request Declined',
        'Your request to delete "' . $ev['title'] . '" was declined.' . "\n\nReason: " . $reason,
        'info', 'my_events.php');
    log_audit_action($mod['id'], 'event_delete_reject', 'Declined deletion of event #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'events', 'reject_delete_event', $itemId, $ev['title']);
    mod_flash_redirect('Deletion request declined.', 'info', $back);
}

// ── approve_delete_news: honours a user's deletion request ────────────────────
if ($action === 'approve_delete_news') {
    require_mod_permission('approve_news', $back);
    $row = $pdo->prepare('SELECT n.*, u.id AS owner_id FROM news n JOIN users u ON n.user_id=u.id WHERE n.id=? AND n.deletion_requested=1');
    $row->execute([$itemId]);
    $ns = $row->fetch();
    if (!$ns) mod_flash_redirect('Deletion request not found.', 'error', $back);

    $pdo->prepare('DELETE FROM news_comments WHERE news_id=?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM news_likes    WHERE news_id=?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM news_saves    WHERE news_id=?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM news WHERE id=?')->execute([$itemId]);
    notify_user((int)$ns['owner_id'], 'Article Deleted', 'Your deletion request for "' . $ns['title'] . '" was approved — the article has been removed.', 'info');
    log_audit_action($mod['id'], 'news_delete_approve', 'Approved deletion of news #' . $itemId . ': ' . $ns['title']);
    log_mod_activity($mod['id'], 'news', 'approve_delete_news', $itemId, $ns['title']);
    mod_flash_redirect('"' . $ns['title'] . '" deleted.', 'success', $back);
}

// ── reject_delete_news: declines the deletion request, restores the article ───
if ($action === 'reject_delete_news') {
    require_mod_permission('approve_news', $back);
    if (!$reason) mod_flash_redirect('A reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT n.*, u.id AS owner_id FROM news n JOIN users u ON n.user_id=u.id WHERE n.id=? AND n.deletion_requested=1');
    $row->execute([$itemId]);
    $ns = $row->fetch();
    if (!$ns) mod_flash_redirect('Deletion request not found.', 'error', $back);

    $pdo->prepare('UPDATE news SET deletion_requested=0, deletion_requested_at=NULL WHERE id=?')->execute([$itemId]);
    notify_user((int)$ns['owner_id'], 'Deletion Request Declined',
        'Your request to delete "' . $ns['title'] . '" was declined.' . "\n\nReason: " . $reason,
        'info', 'my_news.php');
    log_audit_action($mod['id'], 'news_delete_reject', 'Declined deletion of news #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'news', 'reject_delete_news', $itemId, $ns['title']);
    mod_flash_redirect('Deletion request declined.', 'info', $back);
}

// ── approve_delivery_request ──────────────────────────────────────────────────
if ($action === 'approve_delivery_request') {
    require_mod_permission('approve_delivery_requests', $back);
    $row = $pdo->prepare('SELECT dr.*, u.email FROM delivery_requests dr JOIN users u ON dr.customer_id=u.id WHERE dr.id=? AND dr.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $dr = $row->fetch();
    if (!$dr) mod_flash_redirect('Delivery request not found.', 'error', $back);

    $pdo->prepare("UPDATE delivery_requests SET status='approved', approved_by=?, updated_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    notify_user((int)$dr['customer_id'], 'Delivery Request Approved ✅',
        'Your delivery request #' . $itemId . ' is now live. Riders can now apply.',
        'success', 'delivery_detail.php?id=' . $itemId);
    try {
        $riders = $pdo->query("SELECT user_id FROM delivery_agents WHERE verification_status='approved' AND availability_status IN('available','busy')")->fetchAll();
        foreach ($riders as $r) {
            notify_user((int)$r['user_id'], 'New Delivery Job Available',
                "Delivery request #$itemId is open. Apply from your agent dashboard.",
                'info', 'delivery_agent_jobs.php?tab=available');
        }
    } catch(Exception $e){}
    log_audit_action($mod['id'], 'delivery_request_approve_quick', 'Approved delivery request #' . $itemId);
    log_mod_activity($mod['id'], 'delivery', 'approve_delivery_request', $itemId);
    mod_flash_redirect('Delivery request #' . $itemId . ' approved.', 'success', $back);
}

// ── reject_delivery_request ───────────────────────────────────────────────────
if ($action === 'reject_delivery_request') {
    require_mod_permission('approve_delivery_requests', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT dr.* FROM delivery_requests dr WHERE dr.id=? AND dr.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $dr = $row->fetch();
    if (!$dr) mod_flash_redirect('Delivery request not found.', 'error', $back);

    $pdo->prepare("UPDATE delivery_requests SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$dr['customer_id'], 'Delivery Request Rejected',
        "Your delivery request #$itemId was not approved." . "\n\nReason: " . $reason . "\n\nYou may submit a corrected request.",
        'error', 'delivery_detail.php?id=' . $itemId);
    log_audit_action($mod['id'], 'delivery_request_reject_quick', 'Rejected delivery request #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'delivery', 'reject_delivery_request', $itemId);
    mod_flash_redirect('Delivery request rejected.', 'info', $back);
}

// ── approve_delivery_agent ────────────────────────────────────────────────────
if ($action === 'approve_delivery_agent') {
    require_mod_permission('approve_delivery_agents', $back);
    $row = $pdo->prepare('SELECT da.*, u.email, u.name AS agent_name FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE da.id=? AND da.verification_status=?');
    $row->execute([$itemId, 'pending']);
    $ag = $row->fetch();
    if (!$ag) mod_flash_redirect('Agent not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE delivery_agents SET verification_status='approved', approved_by=?, availability_status='available', updated_at=NOW() WHERE id=?")->execute([$mod['id'], $itemId]);
    notify_user((int)$ag['user_id'], 'Agent Profile Approved ✅',
        'Your delivery agent profile has been approved. Start browsing and applying for delivery jobs!',
        'success', 'delivery_agent_jobs.php');
    send_email_notification($ag['email'], 'Delivery Agent Approved — ' . APP_NAME,
        "Hi {$ag['agent_name']},\n\nYour agent profile has been approved. Log in to start accepting jobs.\n\n" . BASE_URL . '/delivery_agent_jobs.php',
        (int)$ag['user_id']);
    log_audit_action($mod['id'], 'delivery_agent_approve_quick', 'Approved delivery agent #' . $itemId . ': ' . $ag['agent_name']);
    log_mod_activity($mod['id'], 'delivery', 'approve_delivery_agent', $itemId, $ag['agent_name']);
    mod_flash_redirect($ag['agent_name'] . ' approved as delivery agent.', 'success', $back);
}

// ── reject_delivery_agent ─────────────────────────────────────────────────────
if ($action === 'reject_delivery_agent') {
    require_mod_permission('approve_delivery_agents', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT da.*, u.email, u.name AS agent_name FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE da.id=? AND da.verification_status=?');
    $row->execute([$itemId, 'pending']);
    $ag = $row->fetch();
    if (!$ag) mod_flash_redirect('Agent not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE delivery_agents SET verification_status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$ag['user_id'], 'Agent Application Rejected',
        'Your delivery agent application was not approved.' . "\n\nReason: " . $reason . "\n\nCorrect the issues and reapply.",
        'error', 'become_delivery_agent.php');
    log_audit_action($mod['id'], 'delivery_agent_reject_quick', 'Rejected delivery agent #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'delivery', 'reject_delivery_agent', $itemId, $ag['agent_name']);
    mod_flash_redirect($ag['agent_name'] . '\'s agent application rejected.', 'info', $back);
}

// ── approve_listing (accommodation) ────────────────────────────────────────
if ($action === 'approve_listing') {
    require_mod_permission('manage_accommodation', $back);
    $row = $pdo->prepare('SELECT al.*, u.id AS owner_id FROM accommodation_listings al JOIN users u ON al.user_id=u.id WHERE al.id=? AND al.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $listing = $row->fetch();
    if (!$listing) mod_flash_redirect('Listing not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE accommodation_listings SET status='approved', rejection_reason=NULL, updated_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$listing['owner_id'], '✅ Listing Approved', '"' . $listing['title'] . '" is now live on Accommodation.',
        'success', '../accommodation_detail.php?id=' . $itemId);
    log_audit_action($mod['id'], 'accommodation_approve_quick', 'Approved listing #' . $itemId . ': ' . $listing['title']);
    log_mod_activity($mod['id'], 'accommodation', 'approve_listing', $itemId, $listing['title']);
    mod_flash_redirect('"' . $listing['title'] . '" approved.', 'success', $back);
}

// ── reject_listing (accommodation) ─────────────────────────────────────────
if ($action === 'reject_listing') {
    require_mod_permission('manage_accommodation', $back);
    if (!$reason) mod_flash_redirect('A rejection reason is required.', 'error', $back);
    $row = $pdo->prepare('SELECT al.*, u.id AS owner_id FROM accommodation_listings al JOIN users u ON al.user_id=u.id WHERE al.id=? AND al.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $listing = $row->fetch();
    if (!$listing) mod_flash_redirect('Listing not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE accommodation_listings SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $itemId]);
    notify_user((int)$listing['owner_id'], 'Listing Rejected', '"' . $listing['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nEdit and resubmit from My Listings.",
        'error', '../accommodation_form.php?id=' . $itemId);
    log_audit_action($mod['id'], 'accommodation_reject_quick', 'Rejected listing #' . $itemId . '. Reason: ' . $reason);
    log_mod_activity($mod['id'], 'accommodation', 'reject_listing', $itemId, $listing['title']);
    mod_flash_redirect('"' . $listing['title'] . '" rejected.', 'info', $back);
}

mod_flash_redirect('Unknown action.', 'error', $back);

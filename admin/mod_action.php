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

// ── approve_job ───────────────────────────────────────────────────────────────
if ($action === 'approve_job') {
    require_mod_permission('approve_jobs', $back);
    $row = $pdo->prepare('SELECT sr.*, u.email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id=u.id WHERE sr.id=? AND sr.status=?');
    $row->execute([$itemId, 'pending']);
    $job = $row->fetch();
    if (!$job) mod_flash_redirect('Job not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE service_requests SET status='open', updated_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$job['customer_id'], 'Job Approved ✅',
        '"' . $job['title'] . '" is now live and visible to workers.',
        'success', 'request_detail.php?id=' . $itemId);
    send_email_notification($job['email'], 'Your job post is live — ' . APP_NAME,
        'Hi ' . $job['customer_name'] . ",\n\nYour job post \"" . $job['title'] . "\" has been approved and is now live.\n\n" . BASE_URL . '/request_detail.php?id=' . $itemId,
        (int)$job['customer_id']);
    log_audit_action($mod['id'], 'job_approve_quick', 'Approved job #' . $itemId . ': ' . $job['title']);
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
    mod_flash_redirect('"' . $job['title'] . '" rejected.', 'info', $back);
}

// ── approve_product ───────────────────────────────────────────────────────────
if ($action === 'approve_product') {
    require_mod_permission('approve_products', $back);
    $row = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id, ms.shop_name FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=? AND mp.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $prod = $row->fetch();
    if (!$prod) mod_flash_redirect('Product not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE mp_products SET status='approved', updated_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$prod['owner_id'], 'Product Approved ✅',
        '"' . $prod['name'] . '" is now live on the marketplace.',
        'success', 'product.php?id=' . $itemId);
    log_audit_action($mod['id'], 'mp_product_approve_quick', 'Approved product #' . $itemId . ': ' . $prod['name']);
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
    mod_flash_redirect('"' . $prod['name'] . '" rejected.', 'info', $back);
}

// ── approve_event ─────────────────────────────────────────────────────────────
if ($action === 'approve_event') {
    require_mod_permission('approve_events', $back);
    $row = $pdo->prepare('SELECT e.*, u.id AS owner_id, u.email, u.name AS owner_name FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=?');
    $row->execute([$itemId]);
    $ev = $row->fetch();
    if (!$ev) mod_flash_redirect('Event not found.', 'error', $back);

    $pdo->prepare("UPDATE events SET status='published', published_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$ev['owner_id'], 'Event Published ✅',
        '"' . $ev['title'] . '" is now live. Share it with your community!',
        'success', 'event.php?slug=' . urlencode($ev['slug']));
    log_audit_action($mod['id'], 'event_approve_quick', 'Approved event #' . $itemId . ': ' . $ev['title']);
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
    notify_user((int)$ev['owner_id'], 'Event Rejected',
        '"' . $ev['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nEdit and resubmit from My Events.",
        'error', 'my_events.php');
    log_audit_action($mod['id'], 'event_reject_quick', 'Rejected event #' . $itemId . '. Reason: ' . $reason);
    mod_flash_redirect('"' . $ev['title'] . '" rejected.', 'info', $back);
}

// ── approve_funeral ───────────────────────────────────────────────────────────
if ($action === 'approve_funeral') {
    require_mod_permission('approve_funerals', $back);
    $row = $pdo->prepare('SELECT fa.*, u.id AS owner_id FROM funeral_announcements fa JOIN users u ON fa.user_id=u.id WHERE fa.id=? AND fa.status=?');
    $row->execute([$itemId, 'pending']);
    $fa = $row->fetch();
    if (!$fa) mod_flash_redirect('Announcement not found.', 'error', $back);

    $pdo->prepare("UPDATE funeral_announcements SET status='approved' WHERE id=?")->execute([$itemId]);
    notify_user((int)$fa['owner_id'], 'Funeral Announcement Approved ✅',
        'The announcement for ' . $fa['deceased_name'] . ' is now published.',
        'success', 'funeral.php?slug=' . urlencode($fa['slug']));
    log_audit_action($mod['id'], 'funeral_approve_quick', 'Approved funeral #' . $itemId);
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
    notify_user((int)$fa['owner_id'], 'Funeral Announcement Rejected',
        'The announcement for ' . $fa['deceased_name'] . ' was not approved.' . "\n\nReason: " . $reason . "\n\nEdit and resubmit from My Funerals.",
        'error', 'my_funerals.php');
    log_audit_action($mod['id'], 'funeral_reject_quick', 'Rejected funeral #' . $itemId . '. Reason: ' . $reason);
    mod_flash_redirect('Funeral announcement rejected.', 'info', $back);
}

// ── approve_news ──────────────────────────────────────────────────────────────
if ($action === 'approve_news') {
    require_mod_permission('approve_news', $back);
    $row = $pdo->prepare('SELECT n.*, u.id AS owner_id FROM news n JOIN users u ON n.user_id=u.id WHERE n.id=? AND n.status=?');
    $row->execute([$itemId, 'draft']);
    $ns = $row->fetch();
    if (!$ns) mod_flash_redirect('Article not found.', 'error', $back);

    $pdo->prepare("UPDATE news SET status='published', published_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$ns['owner_id'], 'Article Published ✅',
        '"' . $ns['title'] . '" is now live.',
        'success', 'news_article.php?slug=' . urlencode($ns['slug']));
    log_audit_action($mod['id'], 'news_approve_quick', 'Approved news #' . $itemId . ': ' . $ns['title']);
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
    notify_user((int)$ns['owner_id'], 'Article Rejected',
        '"' . $ns['title'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nEdit and resubmit from My Articles.",
        'error', 'my_news.php');
    log_audit_action($mod['id'], 'news_reject_quick', 'Rejected news #' . $itemId . '. Reason: ' . $reason);
    mod_flash_redirect('"' . $ns['title'] . '" rejected.', 'info', $back);
}

// ── approve_delivery_request ──────────────────────────────────────────────────
if ($action === 'approve_delivery_request') {
    require_mod_permission('approve_delivery_requests', $back);
    $row = $pdo->prepare('SELECT dr.*, u.email FROM delivery_requests dr JOIN users u ON dr.customer_id=u.id WHERE dr.id=? AND dr.status=?');
    $row->execute([$itemId, 'pending_approval']);
    $dr = $row->fetch();
    if (!$dr) mod_flash_redirect('Delivery request not found.', 'error', $back);

    $pdo->prepare("UPDATE delivery_requests SET status='approved', updated_at=NOW() WHERE id=?")->execute([$itemId]);
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
    mod_flash_redirect('Delivery request rejected.', 'info', $back);
}

// ── approve_delivery_agent ────────────────────────────────────────────────────
if ($action === 'approve_delivery_agent') {
    require_mod_permission('approve_delivery_agents', $back);
    $row = $pdo->prepare('SELECT da.*, u.email, u.name AS agent_name FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE da.id=? AND da.verification_status=?');
    $row->execute([$itemId, 'pending']);
    $ag = $row->fetch();
    if (!$ag) mod_flash_redirect('Agent not found or already processed.', 'error', $back);

    $pdo->prepare("UPDATE delivery_agents SET verification_status='approved', availability_status='available', updated_at=NOW() WHERE id=?")->execute([$itemId]);
    notify_user((int)$ag['user_id'], 'Agent Profile Approved ✅',
        'Your delivery agent profile has been approved. Start browsing and applying for delivery jobs!',
        'success', 'delivery_agent_jobs.php');
    send_email_notification($ag['email'], 'Delivery Agent Approved — ' . APP_NAME,
        "Hi {$ag['agent_name']},\n\nYour agent profile has been approved. Log in to start accepting jobs.\n\n" . BASE_URL . '/delivery_agent_jobs.php',
        (int)$ag['user_id']);
    log_audit_action($mod['id'], 'delivery_agent_approve_quick', 'Approved delivery agent #' . $itemId . ': ' . $ag['agent_name']);
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
    mod_flash_redirect($ag['agent_name'] . '\'s agent application rejected.', 'info', $back);
}

mod_flash_redirect('Unknown action.', 'error', $back);

<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../delivery_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }

require_mod_permission('approve_delivery_agents');
$adminUser = current_user();
$tab       = $_GET['tab'] ?? 'pending';

// ── CSV exports ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && is_admin()) {
    csrf_check();
    if ($_GET['export'] === 'agents') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="delivery_agents_' . date('Y-m-d') . '.csv"');
        $rows = $pdo->query("SELECT u.name,u.email,u.phone,da.vehicle_type,da.vehicle_registration,da.service_area,da.verification_status,da.availability_status,da.is_premium,da.is_sponsored,da.is_verified,da.rating,da.completed_deliveries,da.trust_level,da.commission_owed,da.created_at FROM delivery_agents da JOIN users u ON da.user_id=u.id ORDER BY da.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $out = fopen('php://output','w');
        fputcsv($out,['Name','Email','Phone','Vehicle','Reg','Area','Status','Availability','Premium','Sponsored','Verified','Rating','Done','Trust','Commission Owed','Registered']);
        foreach($rows as $r) {
            fputcsv($out,[csv_safe($r['name']),$r['email'],$r['phone'],$r['vehicle_type'],csv_safe($r['vehicle_registration']),csv_safe($r['service_area']),$r['verification_status'],$r['availability_status'],$r['is_premium'],$r['is_sponsored'],$r['is_verified'],$r['rating'],$r['completed_deliveries'],$r['trust_level'],$r['commission_owed'],$r['created_at']]);
        }
        fclose($out); exit;
    }
    if ($_GET['export'] === 'requests') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="delivery_requests_' . date('Y-m-d') . '.csv"');
        $rows = $pdo->query("SELECT dr.id,cu.name AS customer,au.name AS agent,dr.pickup_location,dr.dropoff_location,dr.item_category,dr.item_description,dr.delivery_fee,dr.payment_method,dr.payment_status,dr.status,dr.is_flagged,dr.auto_approved,dr.created_at FROM delivery_requests dr JOIN users cu ON dr.customer_id=cu.id LEFT JOIN delivery_agents dda ON dr.agent_id=dda.id LEFT JOIN users au ON dda.user_id=au.id ORDER BY dr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $out = fopen('php://output','w');
        fputcsv($out,['ID','Customer','Agent','Pickup','Dropoff','Category','Description','Fee','Payment','Pay Status','Status','Flagged','Auto-Approved','Created']);
        foreach($rows as $r) {
            fputcsv($out,[$r['id'],csv_safe($r['customer']),csv_safe($r['agent']),csv_safe($r['pickup_location']),csv_safe($r['dropoff_location']),$r['item_category'],csv_safe($r['item_description']),$r['delivery_fee'],$r['payment_method'],$r['payment_status'],$r['status'],$r['is_flagged'],$r['auto_approved'],$r['created_at']]);
        }
        fclose($out); exit;
    }
}

// ── POST actions ───────────────────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';

    // Forward delivery request approve/reject to delivery_ajax.php via redirect
    if (in_array($postAction, ['approve_request','reject_request'], true)) {
        // handled by delivery_ajax.php directly
        header('Location: ../delivery_ajax.php');
        exit;
    }

    // Agent approve/reject/suspend
    if (in_array($postAction, ['approve_agent','reject_agent','suspend_agent'], true) && !empty($_POST['agent_id'])) {
        $agentId  = (int)$_POST['agent_id'];
        $agentRow = $pdo->prepare('SELECT da.*,u.name,u.username,u.email,u.id AS user_id FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE da.id=?');
        $agentRow->execute([$agentId]);
        $agent = $agentRow->fetch();
        if ($agent) {
            if (in_array($postAction,['approve_agent','reject_agent'],true) && check_mod_coi('delivery_agent', $agentId, $adminUser['id'])) {
                log_coi_violation($adminUser['id'], 'delivery_agent', $agentId, $postAction);
                flash('Conflict of interest: you cannot moderate your own rider application.','error');
                header('Location: delivery.php?tab=agents'); exit;
            }
            if ($postAction === 'approve_agent') {
                $pdo->prepare("UPDATE delivery_agents SET verification_status='approved',availability_status='available',updated_at=NOW() WHERE id=?")->execute([$agentId]);
                notify_user((int)$agent['user_id'],'Agent Profile Approved ✅','Your delivery agent profile has been approved. You can now accept delivery jobs.','success');
                send_email_notification($agent['email'],'Delivery Agent Approved','Hi '.$agent['name'].",\n\nYour agent profile has been approved.\n\n".BASE_URL."delivery_agent_jobs.php",(int)$agent['user_id']);
                log_audit_action($adminUser['id'],'delivery_agent_approve','Approved agent: '.$agent['name']." (ID $agentId)");
                flash('Agent approved.','success');
            } elseif ($postAction === 'reject_agent') {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare("UPDATE delivery_agents SET verification_status='rejected',rejection_reason=?,updated_at=NOW() WHERE id=?")->execute([$reason ?: null,$agentId]);
                notify_user((int)$agent['user_id'],'Agent Application Rejected','Your delivery agent application was not approved.'.($reason?" Reason: $reason":''),'error');
                log_audit_action($adminUser['id'],'delivery_agent_reject','Rejected agent: '.$agent['name'].'. Reason: '.($reason?:'none'));
                flash('Agent rejected.','info');
            } elseif ($postAction === 'suspend_agent') {
                $pdo->prepare("UPDATE delivery_agents SET verification_status='rejected',availability_status='offline',updated_at=NOW() WHERE id=?")->execute([$agentId]);
                notify_user((int)$agent['user_id'],'Agent Account Suspended','Your delivery agent account has been suspended. Contact support.','warning');
                log_audit_action($adminUser['id'],'delivery_agent_suspend','Suspended agent: '.$agent['name']);
                flash('Agent suspended.','warning');
            }
        }
        header('Location: delivery.php?tab=agents'); exit;
    }

    // Activate subscription
    if ($postAction === 'activate_subscription' && !empty($_POST['sub_id'])) {
        $subId = (int)$_POST['sub_id'];
        $subRow = $pdo->prepare('SELECT ds.*,da.id AS da_id,u.id AS user_id,u.name FROM delivery_subscriptions ds JOIN delivery_agents da ON ds.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE ds.id=?');
        $subRow->execute([$subId]);
        $sub = $subRow->fetch();
        if ($sub && $sub['status'] === 'pending') {
            $pdo->prepare("UPDATE delivery_subscriptions SET status='active',activated_by=?,activated_at=NOW() WHERE id=?")->execute([$adminUser['id'],$subId]);
            $pdo->prepare("UPDATE delivery_agents SET is_premium=1,premium_start=?,premium_end=?,updated_at=NOW() WHERE id=?")->execute([$sub['start_date'],$sub['end_date'],$sub['da_id']]);
            $pdo->prepare("UPDATE delivery_transactions SET status='completed' WHERE related_id=? AND transaction_type='subscription'")->execute([$subId]);
            notify_user((int)$sub['user_id'],'Premium Subscription Activated ⭐','Your '.ucfirst($sub['plan_type']).' Premium subscription is now active until '.date('d M Y',strtotime($sub['end_date'])).'.','success');
            log_audit_action($adminUser['id'],'delivery_sub_activate','Activated subscription #'.$subId.' for '.$sub['name']);
            flash('Subscription activated.','success');
        }
        header('Location: delivery.php?tab=monetization'); exit;
    }

    // Activate sponsored listing
    if ($postAction === 'activate_sponsored' && !empty($_POST['sp_id'])) {
        $spId = (int)$_POST['sp_id'];
        $spRow = $pdo->prepare('SELECT dsl.*,da.id AS da_id,u.id AS user_id,u.name FROM delivery_sponsored_listings dsl JOIN delivery_agents da ON dsl.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE dsl.id=?');
        $spRow->execute([$spId]);
        $sp = $spRow->fetch();
        if ($sp && $sp['status'] === 'pending') {
            $pdo->prepare("UPDATE delivery_sponsored_listings SET status='active',activated_by=?,activated_at=NOW() WHERE id=?")->execute([$adminUser['id'],$spId]);
            $pdo->prepare("UPDATE delivery_agents SET is_sponsored=1,sponsored_end=?,updated_at=NOW() WHERE id=?")->execute([$sp['end_date'],$sp['da_id']]);
            $pdo->prepare("UPDATE delivery_transactions SET status='completed' WHERE related_id=? AND transaction_type='sponsored'")->execute([$spId]);
            notify_user((int)$sp['user_id'],'Sponsored Listing Activated 🌟','Your '.$sp['package_days'].'-day sponsored listing is now active until '.date('d M Y',strtotime($sp['end_date'])).'.','success');
            log_audit_action($adminUser['id'],'delivery_sponsored_activate','Activated sponsored listing #'.$spId.' for '.$sp['name']);
            flash('Sponsored listing activated.','success');
        }
        header('Location: delivery.php?tab=monetization'); exit;
    }

    // Approve verification badge
    if ($postAction === 'approve_verification' && !empty($_POST['vr_id'])) {
        $vrId = (int)$_POST['vr_id'];
        $vrRow = $pdo->prepare('SELECT dv.*,da.id AS da_id,u.id AS user_id,u.name FROM delivery_verifications dv JOIN delivery_agents da ON dv.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE dv.id=?');
        $vrRow->execute([$vrId]);
        $vr = $vrRow->fetch();
        if ($vr && $vr['status'] === 'pending') {
            $pdo->prepare("UPDATE delivery_verifications SET status='approved',reviewed_at=NOW() WHERE id=?")->execute([$vrId]);
            $pdo->prepare("UPDATE delivery_agents SET is_verified=1,updated_at=NOW() WHERE id=?")->execute([$vr['da_id']]);
            notify_user((int)$vr['user_id'],'Verified Rider Badge Approved ✓','Congratulations! You now have the Verified Rider badge on your profile.','success');
            log_audit_action($adminUser['id'],'delivery_verify_approve','Approved verification for '.$vr['name']);
            flash('Verification approved.','success');
        }
        header('Location: delivery.php?tab=verifications'); exit;
    }

    // Reject verification badge
    if ($postAction === 'reject_verification' && !empty($_POST['vr_id'])) {
        $vrId = (int)$_POST['vr_id'];
        $reason = trim($_POST['rejection_reason'] ?? '');
        $vrRow = $pdo->prepare('SELECT dv.*,u.id AS user_id,u.name FROM delivery_verifications dv JOIN delivery_agents da ON dv.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE dv.id=?');
        $vrRow->execute([$vrId]);
        $vr = $vrRow->fetch();
        if ($vr) {
            $pdo->prepare("UPDATE delivery_verifications SET status='rejected',rejection_reason=?,reviewed_at=NOW() WHERE id=?")->execute([$reason ?: null,$vrId]);
            notify_user((int)$vr['user_id'],'Verification Rejected','Your Verified Rider application was not approved.'.($reason?" Reason: $reason":'').' Please resubmit.','error');
            log_audit_action($adminUser['id'],'delivery_verify_reject','Rejected verification for '.$vr['name'].'. Reason: '.($reason?:'none'));
            flash('Verification rejected.','info');
        }
        header('Location: delivery.php?tab=verifications'); exit;
    }

    // Admin cancel request
    if ($postAction === 'admin_cancel' && !empty($_POST['delivery_id'])) {
        $dlId = (int)$_POST['delivery_id'];
        $dlRow = $pdo->prepare('SELECT customer_id,agent_id FROM delivery_requests WHERE id=?');
        $dlRow->execute([$dlId]);
        $dl = $dlRow->fetch();
        $pdo->prepare("UPDATE delivery_requests SET status='cancelled',cancelled_reason='Cancelled by admin',updated_at=NOW() WHERE id=?")->execute([$dlId]);
        if ($dl) {
            notify_user((int)$dl['customer_id'],'Delivery Cancelled by Admin',"Delivery #$dlId has been cancelled by an administrator.",'warning');
            if ($dl['agent_id']) {
                $agUid = $pdo->prepare('SELECT user_id FROM delivery_agents WHERE id=?');
                $agUid->execute([$dl['agent_id']]);
                if ($uid = $agUid->fetchColumn()) notify_user((int)$uid,'Delivery Cancelled by Admin',"Delivery #$dlId has been cancelled.",'warning');
                $pdo->prepare("UPDATE delivery_agents SET availability_status='available' WHERE id=? AND availability_status='busy'")->execute([$dl['agent_id']]);
            }
        }
        log_audit_action($adminUser['id'],'delivery_admin_cancel',"Admin cancelled delivery #$dlId");
        flash("Delivery #$dlId cancelled.",'info');
        header('Location: delivery.php?tab=requests'); exit;
    }

    // Save settings
    if ($postAction === 'save_settings' && is_admin()) {
        // delivery_enabled itself is no longer set from here — it now lives
        // solely in Admin → Monetization → Settings → Module Availability,
        // since a form that doesn't render that checkbox would otherwise
        // force it off on every save.
        $settingsMap = ['delivery_require_approval','delivery_auto_approve_min_deliveries','delivery_auto_approve_min_days',
                        'delivery_enable_premium','delivery_premium_requires_payment','delivery_premium_monthly_price',
                        'delivery_premium_quarterly_price','delivery_premium_yearly_price','delivery_enable_verification_fee',
                        'delivery_verification_fee','delivery_enable_sponsored','delivery_sponsored_requires_payment',
                        'delivery_sponsored_7day_price','delivery_sponsored_30day_price','delivery_sponsored_90day_price',
                        'delivery_commission_percent','delivery_commission_block_threshold','delivery_commission_grace_days'];
        foreach ($settingsMap as $k) {
            if (isset($_POST[$k])) set_platform_setting($k, trim($_POST[$k]));
        }
        // Checkboxes
        foreach (['delivery_require_approval','delivery_enable_premium','delivery_premium_requires_payment',
                  'delivery_enable_verification_fee','delivery_enable_sponsored','delivery_sponsored_requires_payment'] as $ck) {
            set_platform_setting($ck, isset($_POST[$ck]) ? '1' : '0');
        }
        log_audit_action($adminUser['id'],'delivery_settings_save','Updated delivery monetization settings');
        flash('Settings saved.','success');
        header('Location: delivery.php?tab=settings'); exit;
    }

    // Mark a rider's commission balance as settled (paid outside the system)
    if ($postAction === 'settle_commission' && is_admin() && !empty($_POST['agent_id'])) {
        $agentId = (int)$_POST['agent_id'];
        $agentRow = $pdo->prepare('SELECT commission_owed, user_id FROM delivery_agents WHERE id=?');
        $agentRow->execute([$agentId]);
        $agentRow = $agentRow->fetch();
        if ($agentRow && (float)$agentRow['commission_owed'] > 0) {
            $owed = (float)$agentRow['commission_owed'];
            $pdo->prepare('UPDATE delivery_agents SET commission_owed = 0, commission_owed_since = NULL WHERE id=?')->execute([$agentId]);
            $pdo->prepare("INSERT INTO delivery_commission_ledger (agent_id, type, amount) VALUES (?,'settlement',?)")
                ->execute([$agentId, $owed]);
            notify_user((int)$agentRow['user_id'], 'Commission Settled ✅',
                'Your commission balance of GH₵ ' . number_format($owed, 2) . ' has been marked as settled. You can accept new jobs again.',
                'success');
            log_audit_action($adminUser['id'], 'delivery_commission_settled', "Settled GHS " . number_format($owed, 2) . " commission for agent #$agentId");
            flash('Commission marked as settled.', 'success');
        }
        $cqRedirect = !empty($_POST['cq']) ? '&cq=' . urlencode($_POST['cq']) : '';
        header('Location: delivery.php?tab=commission' . $cqRedirect); exit;
    }
}

$flash = get_flash();

// ── Stats ──────────────────────────────────────────────────────────────────
$pendingCount   = (int)$pdo->query("SELECT COUNT(*) FROM delivery_requests WHERE status='pending_approval'")->fetchColumn();
$agentsPending  = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE verification_status='pending'")->fetchColumn();
$vrPending      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_verifications WHERE status='pending'")->fetchColumn();
$subPending     = (int)$pdo->query("SELECT COUNT(*) FROM delivery_subscriptions WHERE status='pending'")->fetchColumn();
$spPending      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_sponsored_listings WHERE status='pending'")->fetchColumn();
$totalAgents    = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents")->fetchColumn();
$approvedAgents = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE verification_status='approved'")->fetchColumn();
$commissionOwedCount = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE commission_owed > 0")->fetchColumn();
$premiumAgents  = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE is_premium=1 AND premium_end>=CURDATE()")->fetchColumn();
$verifiedAgents = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE is_verified=1")->fetchColumn();
$sponsoredAgents= (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE is_sponsored=1 AND sponsored_end>=CURDATE()")->fetchColumn();
$totalRequests  = (int)$pdo->query("SELECT COUNT(*) FROM delivery_requests")->fetchColumn();
$activeReqs     = (int)$pdo->query("SELECT COUNT(*) FROM delivery_requests WHERE status IN('approved','assigned','accepted','picked_up','in_progress','in_transit')")->fetchColumn();
$delivered      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_requests WHERE status='delivered'")->fetchColumn();
$revenue        = (float)$pdo->query("SELECT COALESCE(SUM(delivery_fee),0) FROM delivery_requests WHERE status='delivered'")->fetchColumn();
$subRevenue     = (float)$pdo->query("SELECT COALESCE(SUM(price_paid),0) FROM delivery_subscriptions WHERE status='active'")->fetchColumn();
$spRevenue      = (float)$pdo->query("SELECT COALESCE(SUM(price_paid),0) FROM delivery_sponsored_listings WHERE status='active'")->fetchColumn();
$vrRevenue      = (float)$pdo->query("SELECT COALESCE(SUM(fee_paid),0) FROM delivery_verifications WHERE status='approved'")->fetchColumn();

// ── Data for current tab ───────────────────────────────────────────────────
$pendingRequests = $agents = $requests = $verifications = $subscriptions = $sponsoredListings = [];

$delPage    = max(1, (int)($_GET['page'] ?? 1));
$delPerPage = 30;
$delOffset  = ($delPage - 1) * $delPerPage;
$delTotal      = 0;
$delTotalPages = 1;

if ($tab === 'pending') {
    $delTotal      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_requests WHERE status='pending_approval'")->fetchColumn();
    $delTotalPages = max(1, (int)ceil($delTotal / $delPerPage));
    $pendingRequests = $pdo->query("SELECT dr.*,cu.name AS customer_name,cu.username AS customer_username FROM delivery_requests dr JOIN users cu ON dr.customer_id=cu.id WHERE dr.status='pending_approval' ORDER BY dr.is_flagged DESC,dr.created_at ASC LIMIT $delPerPage OFFSET $delOffset")->fetchAll();
}
if ($tab === 'agents') {
    $af = $_GET['agent_status'] ?? 'pending';
    $aw = match($af) { 'pending'=>"AND da.verification_status='pending'", 'approved'=>"AND da.verification_status='approved'", 'rejected'=>"AND da.verification_status='rejected'", default=>'' };
    $delTotal      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents da WHERE 1=1 $aw")->fetchColumn();
    $delTotalPages = max(1, (int)ceil($delTotal / $delPerPage));
    $agents = $pdo->query("SELECT da.*,u.name,u.username,u.email,u.phone,u.profile_photo FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE 1=1 $aw ORDER BY (da.verification_status='pending') DESC,da.created_at DESC LIMIT $delPerPage OFFSET $delOffset")->fetchAll();
}
if ($tab === 'requests') {
    $rf = $_GET['req_status'] ?? 'all'; $rw = '';
    $validRs = ['pending_approval','approved','assigned','accepted','picked_up','in_progress','in_transit','delivered','cancelled','failed','rejected'];
    if ($rf !== 'all' && in_array($rf,$validRs,true)) $rw = "AND dr.status=".$pdo->quote($rf);
    $qs = trim($_GET['q']??''); $qw=''; $qp=[];
    if ($qs!=='') { $qw="AND (cu.name LIKE ? OR dr.item_description LIKE ?)"; $like='%'.$qs.'%'; $qp=[$like,$like]; }
    $reqSort = $_GET['rsort'] ?? 'newest';
    $reqOrderBy = match($reqSort) {
        'oldest'    => 'dr.created_at ASC',
        'fee_high'  => 'dr.delivery_fee DESC',
        'fee_low'   => 'dr.delivery_fee ASC',
        default     => 'dr.created_at DESC',
    };
    $rCountStmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_requests dr JOIN users cu ON dr.customer_id=cu.id WHERE 1=1 $rw $qw");
    $rCountStmt->execute($qp);
    $delTotal      = (int)$rCountStmt->fetchColumn();
    $delTotalPages = max(1, (int)ceil($delTotal / $delPerPage));
    $rs = $pdo->prepare("SELECT dr.*,cu.name AS customer_name,cu.username AS customer_username,au.name AS agent_name,da2.vehicle_type FROM delivery_requests dr JOIN users cu ON dr.customer_id=cu.id LEFT JOIN delivery_agents da2 ON dr.agent_id=da2.id LEFT JOIN users au ON da2.user_id=au.id WHERE 1=1 $rw $qw ORDER BY $reqOrderBy LIMIT $delPerPage OFFSET $delOffset");
    $rs->execute($qp); $requests = $rs->fetchAll();
}
if ($tab === 'verifications') {
    $delTotal      = (int)$pdo->query("SELECT COUNT(*) FROM delivery_verifications WHERE status='pending'")->fetchColumn();
    $delTotalPages = max(1, (int)ceil($delTotal / $delPerPage));
    $verifications = $pdo->query("SELECT dv.*,u.name,u.username,u.profile_photo,da.vehicle_type,da.service_area FROM delivery_verifications dv JOIN delivery_agents da ON dv.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE dv.status='pending' ORDER BY dv.submitted_at ASC LIMIT $delPerPage OFFSET $delOffset")->fetchAll();
}
if ($tab === 'monetization') {
    $subscriptions     = $pdo->query("SELECT ds.*,u.name,u.username FROM delivery_subscriptions ds JOIN delivery_agents da ON ds.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE ds.status='pending' ORDER BY ds.created_at ASC")->fetchAll();
    $sponsoredListings = $pdo->query("SELECT dsl.*,u.name,u.username FROM delivery_sponsored_listings dsl JOIN delivery_agents da ON dsl.agent_id=da.id JOIN users u ON da.user_id=u.id WHERE dsl.status='pending' ORDER BY dsl.created_at ASC")->fetchAll();
}
if ($tab === 'commission') {
    $cq = trim($_GET['cq'] ?? '');
    $cWhere  = 'WHERE da.commission_owed > 0';
    $cParams = [];
    if ($cq !== '') {
        $cWhere .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . $cq . '%';
        $cParams = [$like, $like];
    }
    $ccStmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_agents da JOIN users u ON da.user_id=u.id $cWhere");
    $ccStmt->execute($cParams);
    $delTotal      = (int)$ccStmt->fetchColumn();
    $delTotalPages = max(1, (int)ceil($delTotal / $delPerPage));
    $caStmt = $pdo->prepare(
        "SELECT da.id, da.commission_owed, da.commission_owed_since, u.name, u.username, u.email
         FROM delivery_agents da JOIN users u ON da.user_id=u.id
         $cWhere ORDER BY da.commission_owed DESC LIMIT $delPerPage OFFSET $delOffset"
    );
    $caStmt->execute($cParams);
    $commissionAgents = $caStmt->fetchAll();
    $totalOwed  = (float)$pdo->query("SELECT COALESCE(SUM(commission_owed),0) FROM delivery_agents")->fetchColumn();
    $cBlockDays = (int)get_platform_setting('delivery_commission_grace_days', '0');

    // Period-filtered ledger analytics
    $cPeriod = $_GET['cperiod'] ?? 'month';
    $cDateFilter = match($cPeriod) {
        'today' => 'AND DATE(created_at) = CURDATE()',
        'week'  => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => 'AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())',
        'year'  => 'AND YEAR(created_at)=YEAR(NOW())',
        default => '',
    };
    $cAccrued   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM delivery_commission_ledger WHERE type='commission_owed' $cDateFilter")->fetchColumn();
    $cSettled   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM delivery_commission_ledger WHERE type='settlement' $cDateFilter")->fetchColumn();
    $cReversed  = (float)$pdo->query("SELECT COALESCE(ABS(SUM(amount)),0) FROM delivery_commission_ledger WHERE type='reversal' $cDateFilter")->fetchColumn();
    $cBlockThreshold = (float)get_platform_setting('delivery_commission_block_threshold', '50');
    $cBlockedCount = 0;
    if ($cBlockThreshold > 0) {
        $cBlockedStmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_agents WHERE commission_owed >= ?');
        $cBlockedStmt->execute([$cBlockThreshold]);
        $cBlockedCount = (int)$cBlockedStmt->fetchColumn();
    }

    $cDaily = $pdo->query(
        "SELECT DATE(created_at) AS d, SUM(amount) AS amt
         FROM delivery_commission_ledger
         WHERE type='commission_owed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY DATE(created_at)"
    )->fetchAll();
    $cDailyMap = array_column($cDaily, 'amt', 'd');
    $cDailyLabels = $cDailyAmounts = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $cDailyLabels[] = date('d M', strtotime($d));
        $cDailyAmounts[] = (float)($cDailyMap[$d] ?? 0);
    }
}

// Settings values
$cfg = [];
if ($tab === 'settings') {
    $keys = ['delivery_require_approval','delivery_auto_approve_min_deliveries','delivery_auto_approve_min_days','delivery_enable_premium','delivery_premium_requires_payment','delivery_premium_monthly_price','delivery_premium_quarterly_price','delivery_premium_yearly_price','delivery_enable_verification_fee','delivery_verification_fee','delivery_enable_sponsored','delivery_sponsored_requires_payment','delivery_sponsored_7day_price','delivery_sponsored_30day_price','delivery_sponsored_90day_price','delivery_enabled','delivery_commission_percent','delivery_commission_block_threshold','delivery_commission_grace_days'];
    foreach ($keys as $k) $cfg[$k] = get_platform_setting($k,'');
    if ($cfg['delivery_commission_percent'] === '') $cfg['delivery_commission_percent'] = '10';
    if ($cfg['delivery_commission_block_threshold'] === '') $cfg['delivery_commission_block_threshold'] = '50';
    if ($cfg['delivery_commission_grace_days'] === '') $cfg['delivery_commission_grace_days'] = '0';
}

function del_qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'agent_status', 'req_status', 'q', 'rsort', 'cq', 'cperiod', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'delivery.php?' . http_build_query($merged);
}

function del_render_pagination(int $page, int $totalPages, int $total): void {
    if ($totalPages <= 1) return;
    echo '<div class="pagination">';
    if ($page > 1) echo '<a href="' . sanitize(del_qstr(['page' => $page - 1])) . '">‹ Prev</a>';
    $pStart = max(1, $page - 3);
    $pEnd   = min($totalPages, $page + 3);
    if ($pStart > 1) echo '<span>…</span>';
    for ($p = $pStart; $p <= $pEnd; $p++) {
        echo $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . sanitize(del_qstr(['page' => $p])) . '">' . $p . '</a>';
    }
    if ($pEnd < $totalPages) echo '<span>…</span>';
    if ($page < $totalPages) echo '<a href="' . sanitize(del_qstr(['page' => $page + 1])) . '">Next ›</a>';
    echo '<span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page ' . $page . ' of ' . $totalPages . ' (' . $total . ' total)</span>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .adm-shell { max-width:1060px; margin:0 auto; padding:18px 16px 60px; }
        .adm-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:10px; margin-bottom:20px; }
        .adm-stat  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; }
        .adm-stat strong { display:block; font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .adm-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }
        .adm-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:16px; }
        .adm-tab  { padding:7px 14px; border-radius:8px; font-size:.8rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .adm-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .adm-filter { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
        .adm-filter a { padding:4px 11px; border-radius:20px; font-size:.73rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .adm-filter a.active { background:var(--primary-soft); border-color:var(--primary); color:var(--primary); }
        .adm-row { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 16px; margin-bottom:8px; }
        .adm-row-head { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:8px; flex-wrap:wrap; }
        .dl-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .adm-av { width:36px; height:36px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; border:2px solid var(--border); }
        .adm-av img { width:100%; height:100%; object-fit:cover; }
        .adm-set-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .adm-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .form-hint { font-size:.73rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .adm-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px) { .adm-stats { grid-template-columns:repeat(3,1fr); } .adm-grid2 { grid-template-columns:1fr; } }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🚚 Delivery Management</h1>
    <?php if (is_admin()): ?>
    <div style="display:flex;gap:6px;">
        <a href="?export=agents&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="button button-secondary button-small">&#8595; Agents CSV</a>
        <a href="?export=requests&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="button button-secondary button-small">&#8595; Requests CSV</a>
    </div>
    <?php endif; ?>
</header>

<main class="adm-shell">

    <?php if (is_admin()): ?>
    <div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:14px;">
        <a href="?export=agents&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="button button-secondary button-small">&#8595; Agents CSV</a>
        <a href="?export=requests&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="button button-secondary button-small">&#8595; Requests CSV</a>
    </div>
    <?php endif; ?>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="adm-stats">
        <div class="adm-stat"><strong style="color:#f59e0b;"><?php echo $pendingCount; ?></strong><span>Pending</span></div>
        <div class="adm-stat"><strong><?php echo $totalAgents; ?></strong><span>Agents</span></div>
        <div class="adm-stat"><strong><?php echo $approvedAgents; ?></strong><span>Active</span></div>
        <div class="adm-stat"><strong style="color:#8b5cf6;"><?php echo $premiumAgents; ?></strong><span>Premium</span></div>
        <div class="adm-stat"><strong style="color:#10b981;"><?php echo $verifiedAgents; ?></strong><span>Verified</span></div>
        <div class="adm-stat"><strong style="color:#f59e0b;"><?php echo $sponsoredAgents; ?></strong><span>Sponsored</span></div>
        <div class="adm-stat"><strong><?php echo $totalRequests; ?></strong><span>Requests</span></div>
        <div class="adm-stat"><strong style="color:#10b981;"><?php echo $delivered; ?></strong><span>Delivered</span></div>
        <div class="adm-stat"><strong>GHS <?php echo number_format($revenue,2); ?></strong><span>Revenue</span></div>
        <div class="adm-stat"><strong>GHS <?php echo number_format($subRevenue+$spRevenue+$vrRevenue,2); ?></strong><span>Subscr.</span></div>
    </div>

    <!-- Tabs -->
    <div class="adm-tabs">
        <a href="?tab=pending" class="adm-tab <?php echo $tab==='pending'?'active':''; ?>">
            Pending Approval <?php if ($pendingCount): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingCount; ?></span><?php endif; ?>
        </a>
        <a href="?tab=agents" class="adm-tab <?php echo $tab==='agents'?'active':''; ?>">
            Agents <?php if ($agentsPending): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $agentsPending; ?></span><?php endif; ?>
        </a>
        <a href="?tab=requests" class="adm-tab <?php echo $tab==='requests'?'active':''; ?>">All Requests</a>
        <a href="?tab=verifications" class="adm-tab <?php echo $tab==='verifications'?'active':''; ?>">
            Verifications <?php if ($vrPending): ?><span style="background:#10b981;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $vrPending; ?></span><?php endif; ?>
        </a>
        <a href="?tab=monetization" class="adm-tab <?php echo $tab==='monetization'?'active':''; ?>">
            Monetization <?php $mPending=$subPending+$spPending; if($mPending): ?><span style="background:#8b5cf6;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $mPending; ?></span><?php endif; ?>
        </a>
        <?php if (is_admin()): ?>
        <a href="?tab=commission" class="adm-tab <?php echo $tab==='commission'?'active':''; ?>">
            💰 Commission <?php if ($commissionOwedCount): ?><span style="background:#ef4444;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $commissionOwedCount; ?></span><?php endif; ?>
        </a>
        <a href="?tab=settings" class="adm-tab <?php echo $tab==='settings'?'active':''; ?>">&#9881; Settings</a>
        <?php endif; ?>
    </div>

    <!-- ═══════════════ PENDING APPROVAL ═══════════════ -->
    <?php if ($tab === 'pending'): ?>
    <?php if ($pendingRequests): foreach ($pendingRequests as $r): ?>
    <div class="adm-row" style="<?php echo $r['is_flagged']?'border-color:#f59e0b;':'' ; ?>">
        <div class="adm-row-head">
            <div>
                <div style="font-weight:800;font-size:.9rem;">
                    #<?php echo $r['id']; ?> — <?php echo item_category_icon($r['item_category']); ?> <?php echo sanitize(mb_substr($r['item_description'],0,60)); ?>
                    <?php if ($r['is_flagged']): ?><span style="background:#fef3c7;color:#b45309;font-size:.68rem;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:6px;">&#9888; FLAGGED</span><?php endif; ?>
                    <?php if ($r['auto_approved']): ?><span style="background:#d1fae5;color:#065f46;font-size:.68rem;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:4px;">AUTO</span><?php endif; ?>
                </div>
                <div style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                    By <?php echo sanitize(display_name(['name'=>$r['customer_name'],'username'=>$r['customer_username']])); ?>
                    · Submitted <?php echo time_ago($r['created_at']); ?>
                </div>
            </div>
            <a href="../delivery_detail.php?id=<?php echo $r['id']; ?>" target="_blank" class="button button-secondary button-small">View &#8599;</a>
        </div>
        <div style="font-size:.8rem;color:var(--text-muted,#6b7280);margin-bottom:10px;line-height:1.5;">
            &#128205; <?php echo sanitize(mb_substr($r['pickup_location'],0,55)); ?> &rarr; <?php echo sanitize(mb_substr($r['dropoff_location'],0,55)); ?>
            <?php if ($r['delivery_fee']): ?> &middot; GHS <?php echo number_format((float)$r['delivery_fee'],2); ?><?php endif; ?>
        </div>
        <?php if ($r['flag_reason']): ?>
        <div style="font-size:.78rem;background:#fef3c7;border-radius:6px;padding:5px 9px;margin-bottom:10px;">&#9888; <?php echo sanitize($r['flag_reason']); ?></div>
        <?php endif; ?>
        <?php $drCoi = !is_admin() && (int)($r['customer_id'] ?? 0) === (int)$adminUser['id']; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($drCoi): ?>
            <span style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:8px;">&#9888; Your request — cannot moderate</span>
            <?php else: ?>
            <form method="post" action="../delivery_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="approve_request">
                <input type="hidden" name="delivery_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="button button-primary button-small">&#10003; Approve</button>
            </form>
            <form method="post" action="../delivery_ajax.php" style="display:flex;gap:6px;align-items:center;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="reject_request">
                <input type="hidden" name="delivery_id" value="<?php echo $r['id']; ?>">
                <input type="text" name="rejection_reason" placeholder="Rejection reason" style="font-size:.78rem;padding:5px 10px;width:200px;" required>
                <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject this request?');">&#10007; Reject</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div class="empty-state">No pending delivery requests. &#10003; All clear!</div>
    <?php endif; ?>
    <?php del_render_pagination($delPage, $delTotalPages, $delTotal); ?>
    <?php endif; ?>

    <!-- ═══════════════ AGENTS ═══════════════ -->
    <?php if ($tab === 'agents'): ?>
    <?php $af = $_GET['agent_status'] ?? 'pending'; ?>
    <div class="adm-filter">
        <span style="font-size:.76rem;font-weight:800;color:var(--text-muted,#6b7280);">Filter:</span>
        <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected/Suspended','all'=>'All'] as $v=>$l): ?>
        <a href="?tab=agents&agent_status=<?php echo $v; ?>" class="<?php echo $af===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($agents): foreach ($agents as $ag): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <div class="adm-av">
                <?php if (!empty($ag['profile_photo'])): ?><img src="<?php echo sanitize('../'.ltrim($ag['profile_photo'],'/')); ?>" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$ag['name'],'username'=>$ag['username']]),0,1)); ?><?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$ag['name'],'username'=>$ag['username']])); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?php echo vehicle_type_icon($ag['vehicle_type']); ?> <?php echo vehicle_type_label($ag['vehicle_type']); ?> &middot; <?php echo sanitize($ag['email']); ?></div>
                <div style="margin-top:3px;"><?php echo agent_badges_html($ag); ?></div>
            </div>
            <?php $vs=$ag['verification_status']; $vsBg=['none'=>'#f3f4f6','pending'=>'#fef3c7','approved'=>'#d1fae5','rejected'=>'#fee2e2']; $vsCol=['none'=>'#6b7280','pending'=>'#b45309','approved'=>'#065f46','rejected'=>'#c0392b']; ?>
            <span class="dl-badge" style="background:<?php echo $vsBg[$vs]??'#f3f4f6'; ?>;color:<?php echo $vsCol[$vs]??'#6b7280'; ?>;"><?php echo ucfirst($vs); ?></span>
        </div>
        <div style="font-size:.77rem;color:var(--text-muted,#6b7280);margin-bottom:10px;display:flex;gap:12px;flex-wrap:wrap;">
            <span>&#128205; <?php echo sanitize($ag['service_area']?:'—'); ?></span>
            <span>&#10003; <?php echo $ag['completed_deliveries']; ?> done</span>
            <span>&#9733; <?php echo $ag['rating']>0?number_format((float)$ag['rating'],1):'—'; ?></span>
            <span>Applied <?php echo time_ago($ag['created_at']); ?></span>
        </div>
        <?php if ($ag['id_document_path']): ?>
        <a href="<?php echo sanitize('../'.ltrim($ag['id_document_path'],'/')); ?>" target="_blank" class="button button-secondary button-small" style="margin-bottom:8px;">&#128290; ID Document</a>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($ag['verification_status']!=='approved'): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_agent"><input type="hidden" name="agent_id" value="<?php echo $ag['id']; ?>"><button type="submit" class="button button-primary button-small">&#10003; Approve</button></form>
            <?php endif; ?>
            <?php if (in_array($ag['verification_status'],['pending','none'],true)): ?>
            <form method="post" style="margin:0;display:flex;gap:5px;align-items:center;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_agent"><input type="hidden" name="agent_id" value="<?php echo $ag['id']; ?>"><input type="text" name="rejection_reason" placeholder="Reason" style="font-size:.76rem;padding:4px 9px;width:160px;"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject?');">&#10007; Reject</button></form>
            <?php endif; ?>
            <?php if ($ag['verification_status']==='approved'): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="suspend_agent"><input type="hidden" name="agent_id" value="<?php echo $ag['id']; ?>"><button type="submit" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;" onclick="return confirm('Suspend?');">&#9888; Suspend</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No agents found.</div><?php endif; ?>
    <?php del_render_pagination($delPage, $delTotalPages, $delTotal); ?>
    <?php endif; ?>

    <!-- ═══════════════ ALL REQUESTS ═══════════════ -->
    <?php if ($tab === 'requests'): ?>
    <?php $rf=$_GET['req_status']??'all'; $qs=trim($_GET['q']??''); ?>
    <div class="adm-filter">
        <?php foreach (['all'=>'All','pending_approval'=>'Pending','approved'=>'Approved','accepted'=>'Accepted','picked_up'=>'Picked Up','in_transit'=>'In Transit','delivered'=>'Delivered','cancelled'=>'Cancelled','rejected'=>'Rejected'] as $v=>$l): ?>
        <a href="?tab=requests&req_status=<?php echo $v; ?><?php echo $qs?"&q=".urlencode($qs):""; ?>" class="<?php echo $rf===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="delivery.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <input type="hidden" name="tab" value="requests"><input type="hidden" name="req_status" value="<?php echo sanitize($rf); ?>">
        <input type="text" name="q" value="<?php echo sanitize($qs); ?>" placeholder="Search customer, description…" style="flex:1;max-width:340px;">
        <select name="rsort" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <option value="newest" <?php echo $reqSort==='newest'?'selected':''; ?>>Newest First</option>
            <option value="oldest" <?php echo $reqSort==='oldest'?'selected':''; ?>>Oldest First</option>
            <option value="fee_high" <?php echo $reqSort==='fee_high'?'selected':''; ?>>Highest Fee</option>
            <option value="fee_low" <?php echo $reqSort==='fee_low'?'selected':''; ?>>Lowest Fee</option>
        </select>
        <button type="submit" class="button button-primary button-small">Search</button>
        <?php if ($qs || $reqSort !== 'newest'): ?><a href="?tab=requests&req_status=<?php echo $rf; ?>" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>
    <?php if ($requests): foreach ($requests as $r): ?>
    <div class="adm-row">
        <div class="adm-row-head">
            <div>
                <div style="font-weight:800;font-size:.88rem;">#<?php echo $r['id']; ?> — <?php echo item_category_icon($r['item_category']); ?> <?php echo sanitize(mb_substr($r['item_description'],0,55)); ?><?php if($r['is_flagged']): ?> <span style="background:#fef3c7;color:#b45309;font-size:.65rem;font-weight:800;padding:1px 6px;border-radius:10px;">FLAGGED</span><?php endif; ?></div>
                <div style="font-size:.73rem;color:var(--text-muted,#6b7280);margin-top:2px;">By <?php echo sanitize(display_name(['name'=>$r['customer_name'],'username'=>$r['customer_username']])); ?> &middot; <?php echo time_ago($r['created_at']); ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <span class="dl-badge" style="background:<?php echo delivery_status_bg($r['status']); ?>;color:<?php echo delivery_status_color($r['status']); ?>;"><?php echo delivery_status_label($r['status']); ?></span>
                <?php if ($r['delivery_fee']>0): ?><div style="font-size:.8rem;font-weight:800;color:var(--primary,#0f766e);margin-top:3px;">GHS <?php echo number_format((float)$r['delivery_fee'],2); ?></div><?php endif; ?>
            </div>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted,#6b7280);margin-bottom:8px;">&#128205; <?php echo sanitize(mb_substr($r['pickup_location'],0,50)); ?> &rarr; <?php echo sanitize(mb_substr($r['dropoff_location'],0,50)); ?></div>
        <?php if ($r['agent_name']): ?><div style="font-size:.78rem;margin-bottom:8px;">Agent: <?php echo vehicle_type_icon($r['vehicle_type']??''); ?> <?php echo sanitize($r['agent_name']); ?></div><?php endif; ?>
        <div style="display:flex;gap:8px;">
            <a href="../delivery_detail.php?id=<?php echo $r['id']; ?>" target="_blank" class="button button-secondary button-small">View &#8599;</a>
            <?php if (!in_array($r['status'],['delivered','cancelled','failed','rejected'])): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="admin_cancel"><input type="hidden" name="delivery_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Cancel?');">Cancel</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No requests found.</div><?php endif; ?>
    <?php del_render_pagination($delPage, $delTotalPages, $delTotal); ?>
    <?php endif; ?>

    <!-- ═══════════════ VERIFICATIONS ═══════════════ -->
    <?php if ($tab === 'verifications'): ?>
    <?php if ($verifications): foreach ($verifications as $vr): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <div class="adm-av">
                <?php if (!empty($vr['profile_photo'])): ?><img src="<?php echo sanitize('../'.ltrim($vr['profile_photo'],'/')); ?>" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$vr['name'],'username'=>$vr['username']]),0,1)); ?><?php endif; ?>
            </div>
            <div>
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$vr['name'],'username'=>$vr['username']])); ?></div>
                <div style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo vehicle_type_icon($vr['vehicle_type']); ?> <?php echo vehicle_type_label($vr['vehicle_type']); ?> &middot; Submitted <?php echo time_ago($vr['submitted_at']); ?></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
            <?php if ($vr['selfie_path']): ?><a href="<?php echo sanitize('../'.ltrim($vr['selfie_path'],'/')); ?>" target="_blank" class="button button-secondary button-small">&#129333; Selfie</a><?php endif; ?>
            <?php if ($vr['ghana_card_path']): ?><a href="<?php echo sanitize('../'.ltrim($vr['ghana_card_path'],'/')); ?>" target="_blank" class="button button-secondary button-small">&#128290; Ghana Card</a><?php endif; ?>
            <?php if ($vr['license_path']): ?><a href="<?php echo sanitize('../'.ltrim($vr['license_path'],'/')); ?>" target="_blank" class="button button-secondary button-small">&#128663; License</a><?php endif; ?>
            <?php if ($vr['vehicle_reg_path']): ?><a href="<?php echo sanitize('../'.ltrim($vr['vehicle_reg_path'],'/')); ?>" target="_blank" class="button button-secondary button-small">&#128663; Vehicle Reg</a><?php endif; ?>
        </div>
        <?php if ($vr['fee_paid'] > 0): ?><div style="font-size:.8rem;margin-bottom:8px;">Fee paid: GHS <?php echo number_format((float)$vr['fee_paid'],2); ?></div><?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_verification"><input type="hidden" name="vr_id" value="<?php echo $vr['id']; ?>"><button type="submit" class="button button-primary button-small">&#10003; Approve Badge</button></form>
            <form method="post" style="margin:0;display:flex;gap:5px;align-items:center;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_verification"><input type="hidden" name="vr_id" value="<?php echo $vr['id']; ?>"><input type="text" name="rejection_reason" placeholder="Reason" style="font-size:.76rem;padding:4px 9px;width:180px;"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject verification?');">&#10007; Reject</button></form>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No pending verification requests.</div><?php endif; ?>
    <?php del_render_pagination($delPage, $delTotalPages, $delTotal); ?>
    <?php endif; ?>

    <!-- ═══════════════ MONETIZATION ═══════════════ -->
    <?php if ($tab === 'monetization'): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;font-size:.82rem;">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;"><strong>Subscription Revenue</strong><br>GHS <?php echo number_format($subRevenue,2); ?></div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;"><strong>Sponsored Revenue</strong><br>GHS <?php echo number_format($spRevenue,2); ?></div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;"><strong>Verification Revenue</strong><br>GHS <?php echo number_format($vrRevenue,2); ?></div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;"><strong>Total Monetization</strong><br>GHS <?php echo number_format($subRevenue+$spRevenue+$vrRevenue,2); ?></div>
    </div>

    <?php if ($subscriptions): ?>
    <p style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Pending Subscriptions (<?php echo count($subscriptions); ?>)</p>
    <?php foreach ($subscriptions as $s): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <div>
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$s['name'],'username'=>$s['username']])); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?php echo ucfirst($s['plan_type']); ?> plan &middot; GHS <?php echo number_format((float)$s['price_paid'],2); ?> &middot; <?php echo sanitize($s['payment_method']); ?><?php if ($s['mobi_number']): ?> (<?php echo sanitize($s['mobi_number']); ?>)<?php endif; ?></div>
            </div>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="activate_subscription"><input type="hidden" name="sub_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-primary button-small">Activate</button></form>
        </div>
        <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">Would run <?php echo date('d M Y',strtotime($s['start_date'])); ?> → <?php echo date('d M Y',strtotime($s['end_date'])); ?></div>
    </div>
    <?php endforeach; endif; ?>

    <?php if ($sponsoredListings): ?>
    <p style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin:16px 0 8px;">Pending Sponsored Listings (<?php echo count($sponsoredListings); ?>)</p>
    <?php foreach ($sponsoredListings as $s): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <div>
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$s['name'],'username'=>$s['username']])); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?php echo $s['package_days']; ?> days &middot; GHS <?php echo number_format((float)$s['price_paid'],2); ?> &middot; <?php echo sanitize($s['payment_method']); ?><?php if ($s['mobi_number']): ?> (<?php echo sanitize($s['mobi_number']); ?>)<?php endif; ?></div>
            </div>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="activate_sponsored"><input type="hidden" name="sp_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-primary button-small">Activate</button></form>
        </div>
        <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">Would run <?php echo date('d M Y',strtotime($s['start_date'])); ?> → <?php echo date('d M Y',strtotime($s['end_date'])); ?></div>
    </div>
    <?php endforeach; endif; ?>

    <?php if (!$subscriptions && !$sponsoredListings): ?><div class="empty-state">No pending monetization requests.</div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══════════════ COMMISSION ═══════════════ -->
    <?php if ($tab === 'commission' && is_admin()): ?>
    <p class="meta" style="margin:0 0 12px;">Riders collect delivery fees directly (cash/MoMo) — this tracks what each owes the platform in commission. Once an agent crosses the block threshold set in Settings, they can't accept new jobs until you mark them settled here.</p>

    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
        <?php foreach (['today'=>'Today','week'=>'7 Days','month'=>'This Month','year'=>'This Year','all'=>'All Time'] as $v=>$l): ?>
        <a href="?tab=commission&cperiod=<?php echo $v; ?>" class="button <?php echo $cPeriod===$v?'button-primary':'button-secondary'; ?> button-small"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="adm-stats" style="margin-bottom:16px;">
        <div class="adm-stat"><strong style="color:#ef4444;">GHS <?php echo number_format($totalOwed,2); ?></strong><span>Total Currently Owed</span></div>
        <div class="adm-stat"><strong>GHS <?php echo number_format($cAccrued,2); ?></strong><span>Accrued (period)</span></div>
        <div class="adm-stat"><strong style="color:#10b981;">GHS <?php echo number_format($cSettled,2); ?></strong><span>Settled (period)</span></div>
        <div class="adm-stat"><strong>GHS <?php echo number_format($cReversed,2); ?></strong><span>Reversed (period)</span></div>
        <div class="adm-stat"><strong style="color:<?php echo $cBlockedCount?'#ef4444':'inherit'; ?>"><?php echo $cBlockedCount; ?></strong><span>Riders Blocked</span></div>
    </div>

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px;">
        <p style="font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Commission Accrued — Last 30 Days</p>
        <div style="position:relative;height:200px;"><canvas id="dl-commission-chart"></canvas></div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        var ctx = document.getElementById('dl-commission-chart');
        if (!ctx) return;
        var style = getComputedStyle(document.documentElement);
        var primary = style.getPropertyValue('--primary').trim() || '#0f766e';
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($cDailyLabels); ?>,
                datasets: [{ label: 'Commission Accrued (GHS)', data: <?php echo json_encode($cDailyAmounts); ?>, backgroundColor: primary, borderRadius: 4, maxBarThickness: 18 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' } } }
            }
        });
    })();
    </script>

    <form method="get" action="delivery.php" style="display:flex;gap:8px;margin-bottom:12px;">
        <input type="hidden" name="tab" value="commission">
        <input type="text" name="cq" value="<?php echo sanitize($cq); ?>" placeholder="Search rider name or email…" style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
        <button type="submit" class="button button-secondary button-small">Search</button>
        <?php if ($cq !== ''): ?><a href="?tab=commission" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>

    <?php if ($commissionAgents): ?>
    <?php foreach ($commissionAgents as $ca): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div>
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$ca['name'],'username'=>$ca['username']])); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($ca['email']); ?></div>
                <?php if (!empty($ca['commission_owed_since'])): $daysOwing = (int)floor((time() - strtotime($ca['commission_owed_since'])) / 86400); ?>
                <div style="font-size:.72rem;color:<?php echo ($cBlockDays > 0 && $daysOwing > $cBlockDays) ? '#ef4444' : 'var(--text-muted,#6b7280)'; ?>;font-weight:<?php echo ($cBlockDays > 0 && $daysOwing > $cBlockDays) ? '700' : '400'; ?>;">
                    Owed since <?php echo date('d M Y', strtotime($ca['commission_owed_since'])); ?> (<?php echo $daysOwing; ?> day<?php echo $daysOwing===1?'':'s'; ?>)
                </div>
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <strong style="color:#ef4444;">GHS <?php echo number_format((float)$ca['commission_owed'],2); ?></strong>
                <form method="post" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="settle_commission">
                    <input type="hidden" name="agent_id" value="<?php echo $ca['id']; ?>">
                    <input type="hidden" name="cq" value="<?php echo sanitize($cq); ?>">
                    <button type="submit" class="button button-primary button-small" onclick="return confirm('Mark this rider\'s GH₵ <?php echo number_format((float)$ca['commission_owed'],2); ?> commission as settled? Only do this once they\'ve actually paid you.');">Mark Settled</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div class="empty-state">No riders currently owe commission.</div>
    <?php endif; ?>
    <?php del_render_pagination($delPage, $delTotalPages, $delTotal); ?>
    <?php endif; ?>

    <!-- ═══════════════ SETTINGS ═══════════════ -->
    <?php if ($tab === 'settings' && is_admin()): ?>
    <form method="post" action="delivery.php?tab=settings">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="adm-set-section">
            <p class="adm-set-title">Module &amp; Approval</p>
            <p class="meta" style="font-size:.78rem;margin-bottom:8px;">Turning the whole module on/off has moved to <a href="monetization.php?tab=settings">Admin → Monetization → Settings</a>. Premium/Verification/Sponsored pricing below is also editable from <a href="monetization.php?tab=delivery">Admin → Monetization → Delivery</a>.</p>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="delivery_require_approval" value="1" <?php echo ($cfg['delivery_require_approval']??'1')==='1'?'checked':''; ?>>
                    Require admin approval before requests are visible to riders
                </label>
            </div>
            <div class="adm-grid2">
                <div class="form-group">
                    <label>Min completed deliveries for auto-approval</label>
                    <input type="number" name="delivery_auto_approve_min_deliveries" min="0" value="<?php echo sanitize($cfg['delivery_auto_approve_min_deliveries']??'10'); ?>">
                </div>
                <div class="form-group">
                    <label>Min account age (days) for auto-approval</label>
                    <input type="number" name="delivery_auto_approve_min_days" min="0" value="<?php echo sanitize($cfg['delivery_auto_approve_min_days']??'60'); ?>">
                </div>
            </div>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Rider Commission</p>
            <p class="meta" style="margin:0 0 10px;">Riders collect delivery fees directly and can now pay what they owe in-app via Paystack — this tracks the balance and blocks new job acceptance once either limit below is crossed.</p>
            <div class="adm-grid2">
                <div class="form-group">
                    <label>Commission (% of delivery fee owed)</label>
                    <input type="number" name="delivery_commission_percent" min="0" max="100" step="0.5" value="<?php echo sanitize($cfg['delivery_commission_percent']); ?>">
                </div>
                <div class="form-group">
                    <label>Block new jobs once owed exceeds (GH₵)</label>
                    <input type="number" name="delivery_commission_block_threshold" min="0" step="1" value="<?php echo sanitize($cfg['delivery_commission_block_threshold']); ?>">
                    <p class="meta" style="margin-top:4px;">0 = never block by amount.</p>
                </div>
                <div class="form-group">
                    <label>Block new jobs after owing for (days)</label>
                    <input type="number" name="delivery_commission_grace_days" min="0" step="1" value="<?php echo sanitize($cfg['delivery_commission_grace_days']); ?>">
                    <p class="meta" style="margin-top:4px;">0 = never block by days — amount threshold still applies. Whichever limit is hit first blocks the rider.</p>
                </div>
            </div>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Premium Rider Subscriptions</p>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px;">
                    <input type="checkbox" name="delivery_enable_premium" value="1" <?php echo ($cfg['delivery_enable_premium']??'0')==='1'?'checked':''; ?>>
                    Enable premium subscriptions feature
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="delivery_premium_requires_payment" value="1" <?php echo ($cfg['delivery_premium_requires_payment']??'0')==='1'?'checked':''; ?>>
                    Require payment (if unchecked, admin grants free)
                </label>
            </div>
            <div class="adm-grid2">
                <?php foreach (['delivery_premium_monthly_price'=>'Monthly price (GHS)','delivery_premium_quarterly_price'=>'Quarterly price (GHS)','delivery_premium_yearly_price'=>'Yearly price (GHS)'] as $k=>$l): ?>
                <div class="form-group"><label><?php echo $l; ?></label><input type="number" name="<?php echo $k; ?>" min="0" step="0.01" value="<?php echo sanitize($cfg[$k]??'0.00'); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Verification Badge</p>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="delivery_enable_verification_fee" value="1" <?php echo ($cfg['delivery_enable_verification_fee']??'0')==='1'?'checked':''; ?>>
                    Charge a fee for the Verified Rider badge
                </label>
            </div>
            <div class="form-group" style="max-width:200px;">
                <label>Verification fee (GHS — 0 = free)</label>
                <input type="number" name="delivery_verification_fee" min="0" step="0.01" value="<?php echo sanitize($cfg['delivery_verification_fee']??'0.00'); ?>">
            </div>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Sponsored Listings</p>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px;">
                    <input type="checkbox" name="delivery_enable_sponsored" value="1" <?php echo ($cfg['delivery_enable_sponsored']??'0')==='1'?'checked':''; ?>>
                    Enable sponsored rider listings feature
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="delivery_sponsored_requires_payment" value="1" <?php echo ($cfg['delivery_sponsored_requires_payment']??'0')==='1'?'checked':''; ?>>
                    Require payment (if unchecked, admin grants free)
                </label>
            </div>
            <div class="adm-grid2">
                <?php foreach (['delivery_sponsored_7day_price'=>'7-day price (GHS)','delivery_sponsored_30day_price'=>'30-day price (GHS)','delivery_sponsored_90day_price'=>'90-day price (GHS)'] as $k=>$l): ?>
                <div class="form-group"><label><?php echo $l; ?></label><input type="number" name="<?php echo $k; ?>" min="0" step="0.01" value="<?php echo sanitize($cfg[$k]??'0.00'); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="button button-primary">Save All Settings</button>
    </form>
    <?php endif; ?>

</main>
</body>
</html>

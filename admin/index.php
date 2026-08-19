<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../jobs.php');
    exit;
}

$adminUser = current_user();

$totalUsers         = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalRequests      = (int)$pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
$openRequests       = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="open"')->fetchColumn();
$pendingRequests    = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="pending"')->fetchColumn();
$completedRequests  = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="completed"')->fetchColumn();
$premiumWorkers     = get_premium_worker_count();
$pendingPayments    = (int)$pdo->query("SELECT COUNT(*) FROM platform_payments WHERE status='pending'")->fetchColumn();
$pendingPostingFees = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE posting_fee_status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — AkuapemConnect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg, #f9fafb); }

        /* ── Topbar ─────────────────────────────────────────────── */
        .adm-bar {
            position: sticky; top: 0; z-index: 300;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            display: flex; align-items: stretch; min-height: 52px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .adm-brand {
            display: flex; align-items: center; gap: 8px;
            padding: 0 18px; font-weight: 800; font-size: .95rem;
            color: var(--primary, #0f766e); white-space: nowrap;
            border-right: 1px solid var(--border, #e5e7eb);
            text-decoration: none; flex-shrink: 0;
            transition: background .12s;
        }
        .adm-brand:hover { background: var(--surface-muted, #f8fafc); }
        .adm-brand-badge {
            font-size: .6rem; font-weight: 800; letter-spacing: .06em;
            background: var(--primary, #0f766e); color: #fff;
            padding: 2px 7px; border-radius: 20px; text-transform: uppercase;
        }

        /* ── Scroll buttons ──────────────────────────────────────── */
        .adm-sb {
            display: none; align-items: center; justify-content: center;
            width: 30px; flex-shrink: 0; border: none; cursor: pointer;
            background: none; font-size: 1.2rem; font-weight: 700;
            color: var(--text-muted, #9ca3af);
            transition: background .12s, color .12s;
        }
        .adm-sb:hover { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-sb.vis { display: flex; }
        #adm-sl { border-right: 1px solid var(--border, #e5e7eb); }
        #adm-sr { border-left:  1px solid var(--border, #e5e7eb); }

        /* ── Nav rail ───────────────────────────────────────────── */
        .adm-nav {
            display: flex; align-items: center; gap: 2px;
            overflow-x: auto; padding: 0 6px; flex: 1;
            scrollbar-width: none; -webkit-overflow-scrolling: touch;
        }
        .adm-nav::-webkit-scrollbar { display: none; }

        /* ── Category buttons ───────────────────────────────────── */
        .adm-cat-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 11px; border-radius: 8px; white-space: nowrap;
            font-size: .82rem; font-weight: 700;
            background: none; border: none; cursor: pointer;
            color: var(--text-muted, #6b7280);
            transition: background .12s, color .12s;
            height: 36px;
        }
        .adm-cat-btn:hover  { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-cat-btn.open   { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-cat-btn.active { background: var(--primary-soft, #d1faf4); color: var(--primary, #0f766e); }
        .adm-caret {
            font-size: .65rem; transition: transform .18s; display: inline-block; opacity: .6;
        }
        .adm-cat-btn.open .adm-caret { transform: rotate(180deg); }

        /* ── Dropdown panels — fixed to viewport below topbar ──── */
        /* Panels live in #adm-drops (direct body child), positioned fixed */
        #adm-drops { position: fixed; top: 52px; left: 0; z-index: 500; pointer-events: none; }
        .adm-drop {
            position: absolute; top: 4px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 10px; padding: 6px;
            box-shadow: 0 8px 28px rgba(0,0,0,.13);
            min-width: 168px;
            pointer-events: none; opacity: 0;
            transform: translateY(-6px);
            transition: opacity .14s, transform .14s;
        }
        .adm-drop.open {
            pointer-events: auto; opacity: 1; transform: translateY(0);
        }
        .adm-drop a {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 10px; border-radius: 6px;
            font-size: .83rem; font-weight: 600;
            text-decoration: none; color: var(--text, #111);
            transition: background .12s;
        }
        .adm-drop a:hover  { background: var(--surface-muted, #f3f4f6); }
        .adm-drop a.active {
            background: var(--primary-soft, #d1faf4);
            color: var(--primary, #0f766e); font-weight: 700;
        }

        /* ── Bar end ─────────────────────────────────────────────── */
        .adm-bar-end {
            display: flex; align-items: center; gap: 7px;
            padding: 0 12px; border-left: 1px solid var(--border, #e5e7eb); flex-shrink: 0;
        }

        /* ── Avatar button ──────────────────────────────────────── */
        .adm-avatar-btn {
            background: none; border: none; padding: 0; cursor: pointer;
            display: flex; align-items: center; border-radius: 50%;
            transition: opacity .15s;
        }
        .adm-avatar-btn:hover { opacity: .82; }
        .adm-avatar {
            width: 34px; height: 34px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--border, #e5e7eb);
            font-size: .85rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            background: var(--primary-soft, #d1faf4); color: var(--primary, #0f766e);
        }
        .adm-avatar-wrap { position: relative; }
        .adm-avatar-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 8px);
            min-width: 190px; background: #fff;
            border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); z-index: 9999; overflow: hidden;
        }
        .adm-avatar-menu.open { display: block; }
        .adm-avatar-menu-head {
            padding: 11px 14px 9px; border-bottom: 1px solid #f1f5f9;
        }
        .adm-avatar-menu-head strong { display: block; font-size: .86rem; }
        .adm-avatar-menu-head span   { font-size: .74rem; color: #6b7280; }
        .adm-avatar-menu a {
            display: flex; align-items: center; gap: 9px;
            padding: 9px 14px; color: var(--text, #111);
            text-decoration: none; font-size: .86rem; font-weight: 600;
            transition: background .1s;
        }
        .adm-avatar-menu a:hover { background: #f8fafc; }
        .adm-avatar-menu a.danger       { color: #c0392b; }
        .adm-avatar-menu a.danger:hover { background: #fff5f5; }
        .adm-avatar-menu-sep { border-top: 1px solid #f1f5f9; }

        /* ── Page body ──────────────────────────────────────────── */
        .adm-body { flex: 1; position: relative; }

        /* ── Home panel ─────────────────────────────────────────── */
        .adm-home { max-width: 1060px; margin: 0 auto; padding: 24px 16px 56px; }
        .adm-section-label {
            font-size: .75rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .07em; color: var(--text-muted, #6b7280); margin: 0 0 12px;
        }
        .adm-stats {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px; margin-bottom: 28px;
        }
        .adm-stat {
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px; padding: 16px 12px; text-align: center;
        }
        .adm-stat strong {
            display: block; font-size: 1.9rem; font-weight: 900; line-height: 1.1;
            color: var(--primary, #0f766e);
        }
        .adm-stat span { font-size: .74rem; color: var(--text-muted, #6b7280); margin-top: 2px; display: block; }
        .adm-cards {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(158px, 1fr)); gap: 12px;
        }
        .adm-card {
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px; padding: 16px 14px;
            display: flex; flex-direction: column; gap: 5px;
            text-decoration: none; color: inherit; cursor: pointer;
            transition: box-shadow .15s, transform .15s, border-color .15s;
        }
        .adm-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,.09);
            transform: translateY(-2px);
            border-color: var(--primary, #0f766e);
        }
        .adm-card-icon  { font-size: 1.4rem; }
        .adm-card-title { font-weight: 700; font-size: .9rem; }
        .adm-card-desc  { font-size: .73rem; color: var(--text-muted, #6b7280); }

        /* ── Alert ──────────────────────────────────────────────── */
        .adm-pay-alert {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: #fffbeb; border: 1px solid #f59e0b;
            border-radius: 10px; padding: 12px 16px; margin-bottom: 22px; font-size: .9rem;
        }

        /* ── AJAX content area ──────────────────────────────────── */
        #adm-ajax { display: none; }

        /* ── Loading indicator ──────────────────────────────────── */
        .adm-loading {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; padding: 80px 20px;
            color: var(--text-muted, #6b7280); font-size: .9rem;
        }
        .adm-spinner {
            width: 26px; height: 26px; flex-shrink: 0;
            border: 3px solid var(--border, #e5e7eb);
            border-top-color: var(--primary, #0f766e);
            border-radius: 50%; animation: adm-spin .65s linear infinite;
        }
        @keyframes adm-spin { to { transform: rotate(360deg); } }

        @media (max-width: 520px) {
            .adm-stats { grid-template-columns: repeat(3, 1fr); }
            .adm-cards { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ── Top navigation bar ──────────────────────────────────────── -->
<header class="adm-bar" id="adm-bar">
    <a class="adm-brand" href="index.php" id="adm-home-btn">
        🔧 <?php echo is_admin() ? 'Admin' : 'Manager'; ?>
        <span class="adm-brand-badge"><?php echo is_admin() ? 'admin' : 'mgr'; ?></span>
    </a>

    <button class="adm-sb" id="adm-sl" aria-label="Scroll nav left">‹</button>

    <nav class="adm-nav" id="adm-nav" aria-label="Admin sections">
        <?php if (is_admin() || has_mod_permission('approve_jobs') || has_mod_permission('approve_delivery_requests') || has_mod_permission('approve_delivery_agents')): ?>
        <button class="adm-cat-btn" data-cat="jobs">
            📋 Jobs <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_users') || has_mod_permission('manage_disputes') || has_mod_permission('manage_referrals')): ?>
        <button class="adm-cat-btn" data-cat="users">
            👥 Users <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('view_reports')): ?>
        <button class="adm-cat-btn" data-cat="finance">
            💳 Finance <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_news') || has_mod_permission('approve_events') || has_mod_permission('approve_funerals') || has_mod_permission('manage_ads') || has_mod_permission('approve_products') || has_mod_permission('approve_shops') || has_mod_permission('approve_delivery_requests') || has_mod_permission('approve_delivery_agents')): ?>
        <button class="adm-cat-btn" data-cat="community">
            🌍 Community <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_communication') || has_mod_permission('view_reports')): ?>
        <button class="adm-cat-btn" data-cat="platform">
            ⚙️ Platform <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
    </nav>

    <button class="adm-sb" id="adm-sr" aria-label="Scroll nav right">›</button>

    <div class="adm-bar-end">
        <div class="adm-avatar-wrap" id="adm-av-wrap">
            <button class="adm-avatar-btn" id="adm-av-btn" aria-expanded="false" aria-haspopup="true" title="Account menu">
                <?php if (!empty($adminUser['profile_photo'])): ?>
                    <img src="<?php echo sanitize('../'.ltrim($adminUser['profile_photo'],'/')); ?>" alt="Profile" class="adm-avatar" style="pointer-events:none;" />
                <?php else: ?>
                    <span class="adm-avatar" style="pointer-events:none;"><?php echo sanitize(strtoupper(substr(display_name($adminUser), 0, 1))); ?></span>
                <?php endif; ?>
            </button>
            <div class="adm-avatar-menu" id="adm-av-menu" role="menu">
                <div class="adm-avatar-menu-head">
                    <strong><?php echo sanitize(display_name($adminUser)); ?></strong>
                    <span><?php echo sanitize($adminUser['email']); ?></span>
                </div>
                <a href="../settings.php"         role="menuitem">⚙️ Settings</a>
                <a href="../jobs.php"             role="menuitem">🏠 User Dashboard</a>
                <a href="../marketplace.php"      role="menuitem">🛍️ Marketplace</a>
                <a href="../orders.php"           role="menuitem">📋 My Orders</a>
                <a href="../seller_dashboard.php" role="menuitem">🏪 My Shop</a>
                <div class="adm-avatar-menu-sep"></div>
                <a href="../logout.php" role="menuitem" class="danger">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>
<script>
(function() {
    var btn  = document.getElementById('adm-av-btn');
    var menu = document.getElementById('adm-av-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var open = menu.classList.contains('open');
        menu.classList.toggle('open', !open);
        btn.setAttribute('aria-expanded', String(!open));
    });
    document.addEventListener('click', function() {
        menu.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    });
    menu.addEventListener('click', function(e) { e.stopPropagation(); });
})();
</script>

<!-- ── Dropdown panels (outside nav to avoid overflow clipping) ── -->
<div id="adm-drops">
    <div class="adm-drop" data-cat="jobs">
        <a href="requests.php"     data-page="requests.php">📋 Requests</a>
        <a href="applications.php" data-page="applications.php">📝 Applications</a>
    </div>
    <!-- Users dropdown — gated per permission -->
    <div class="adm-drop" data-cat="users">
        <?php if (is_admin() || has_mod_permission('manage_users')): ?>
        <a href="users.php"      data-page="users.php">👥 All Users</a>
        <a href="account_deletions.php" data-page="account_deletions.php">🚪 Account Closure Requests</a>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_disputes')): ?>
        <a href="disputes.php" data-page="disputes.php">⚖️ Disputes</a>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_referrals')): ?>
        <a href="referrals.php" data-page="referrals.php">🔗 Referrals</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <a href="moderators.php"       data-page="moderators.php">🛡️ Moderators</a>
        <a href="mod_performance.php"  data-page="mod_performance.php">🏆 Performance</a>
        <?php endif; ?>
    </div>
    <!-- Finance dropdown -->
    <div class="adm-drop" data-cat="finance">
        <?php if (is_admin() || has_mod_permission('view_reports')): ?>
        <a href="payments.php"     data-page="payments.php">💳 Payments</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <a href="monetization.php" data-page="monetization.php">💰 Monetize</a>
        <a href="mp_payouts.php"   data-page="mp_payouts.php">🏪 Seller Payouts</a>
        <a href="mp_packages.php"  data-page="mp_packages.php">📦 MP Packages</a>
        <a href="complimentary_members.php" data-page="complimentary_members.php">⭐ Complimentary</a>
        <?php endif; ?>
    </div>
    <!-- Community dropdown — each item gated -->
    <div class="adm-drop" data-cat="community">
        <?php if (is_admin() || has_mod_permission('approve_news')): ?><a href="news.php"     data-page="news.php">📰 News</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_ads')): ?><a href="ads.php"      data-page="ads.php">📣 Ads</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_funerals')): ?><a href="funerals.php" data-page="funerals.php">🕊️ Funerals</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_events')): ?><a href="events.php"   data-page="events.php">📅 Events</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_sponsors')): ?><a href="sponsors.php" data-page="sponsors.php">🤝 Sponsors</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_delivery_requests') || has_mod_permission('approve_delivery_agents') || has_mod_permission('approve_verifications') || has_mod_permission('approve_boosts')): ?><a href="delivery.php"    data-page="delivery.php">🚚 Delivery</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('approve_products') || has_mod_permission('approve_shops') || has_mod_permission('approve_boosts') || has_mod_permission('manage_quote_requests')): ?><a href="marketplace.php" data-page="marketplace.php">🛍️ Marketplace</a><?php endif; ?>
    </div>
    <!-- Platform dropdown — mostly admin-only, plus a few permitted -->
    <div class="adm-drop" data-cat="platform">
        <?php if (is_admin() || has_mod_permission('view_reports')): ?><a href="analytics.php"         data-page="analytics.php">📊 Analytics</a><?php endif; ?>
        <?php if (is_admin()): ?><a href="business_messages.php" data-page="business_messages.php">💬 Messages</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_communication')): ?><a href="communication.php" data-page="communication.php">📣 Broadcast</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_media_settings')): ?><a href="media_settings.php" data-page="media_settings.php">🖼️ Image Optimization</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_towns')): ?><a href="towns.php" data-page="towns.php">📍 Towns</a><?php endif; ?>
        <?php if (is_admin() || has_mod_permission('manage_master_catalog')): ?><a href="master_catalog.php" data-page="master_catalog.php">🗂️ Master Catalog</a><?php endif; ?>
        <?php if (is_admin()): ?>
        <a href="email_settings.php"   data-page="email_settings.php">📧 Email / SMTP</a>
        <a href="contact_settings.php" data-page="contact_settings.php">📞 Contact</a>
        <a href="theme.php"            data-page="theme.php">🎨 Theme</a>
        <a href="moderators.php"       data-page="moderators.php">🛡️ Moderators</a>
        <?php endif; ?>
        <?php if (is_admin() || has_mod_permission('view_reports')): ?><a href="audit_logs.php" data-page="audit_logs.php">📜 Audit</a><?php endif; ?>
    </div>
</div>

<!-- ── Body ────────────────────────────────────────────────────── -->
<div class="adm-body">

    <!-- Home panel: stats + quick-access cards -->
    <div id="adm-home" class="adm-home">

        <?php if ($pendingPayments > 0): ?>
        <div class="adm-pay-alert">
            💳 <strong><?php echo $pendingPayments; ?> pending payment<?php echo $pendingPayments !== 1 ? 's' : ''; ?></strong>
            awaiting confirmation.
            <button class="button button-primary button-small" onclick="admLoad('payments.php')">View payments →</button>
            <?php if ($pendingPostingFees > 0): ?>
            &nbsp;·&nbsp;
            <?php echo $pendingPostingFees; ?> job<?php echo $pendingPostingFees !== 1 ? 's' : ''; ?> blocked by unpaid posting fee.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (true): // Admins and managers both see the review queue ?>
        <!-- ── Review Queue (shown to all admins & managers) ───────────────── -->
        <?php
        $modId = (int)$adminUser['id'];

        // ── Performance stats (today & this week) ─────────────────────────────
        $statStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE admin_id=? AND action LIKE '%approve%' AND DATE(created_at)=CURDATE()"); $statStmt->execute([$modId]); $todayApproved=(int)$statStmt->fetchColumn();
        $statStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE admin_id=? AND action LIKE '%reject%' AND DATE(created_at)=CURDATE()"); $statStmt->execute([$modId]); $todayRejected=(int)$statStmt->fetchColumn();
        $statStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE admin_id=? AND (action LIKE '%approve%' OR action LIKE '%reject%') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"); $statStmt->execute([$modId]); $weekTotal=(int)$statStmt->fetchColumn();
        $statStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE admin_id=? AND action LIKE '%approve%' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"); $statStmt->execute([$modId]); $weekApproved=(int)$statStmt->fetchColumn();

        // ── Build queue sections ───────────────────────────────────────────────
        $queueSections = [];

        if (has_mod_permission('approve_jobs')) {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status='pending'")->fetchColumn();
            $items = $pdo->query("SELECT sr.id, sr.title, sr.location, sr.description, sr.budget_amount, sr.budget, sr.customer_id AS owner_id, u.name AS user_name, sr.created_at FROM service_requests sr JOIN users u ON sr.customer_id=u.id WHERE sr.status='pending' ORDER BY sr.created_at ASC LIMIT 3")->fetchAll();
            foreach ($items as &$it) { $it['view_url'] = '../request_detail.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
            $queueSections[] = ['icon'=>'📋','title'=>'Job Requests','color'=>'#f59e0b','bg'=>'#fffbeb','count'=>$c,'items'=>$items,'page'=>'requests.php','approve_action'=>'approve_job','reject_action'=>'reject_job','label_key'=>'title','meta_key'=>'location'];
        }
        if (has_mod_permission('approve_products')) {
            try {
                $c=(int)$pdo->query("SELECT COUNT(*) FROM mp_products WHERE status='pending_approval'")->fetchColumn();
                $items=$pdo->query("SELECT mp.id, mp.name AS title, mp.description, mp.price, mp.condition_type, ms.shop_name AS location, ms.user_id AS owner_id, u.name AS user_name, mp.created_at FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id JOIN users u ON ms.user_id=u.id WHERE mp.status='pending_approval' ORDER BY mp.created_at ASC LIMIT 3")->fetchAll();
                foreach ($items as &$it) { $it['view_url'] = '../product.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
                $queueSections[]=['icon'=>'🛍️','title'=>'Products','color'=>'#3b82f6','bg'=>'#eff6ff','count'=>$c,'items'=>$items,'page'=>'marketplace.php?tab=products','approve_action'=>'approve_product','reject_action'=>'reject_product','label_key'=>'title','meta_key'=>'location'];
            } catch(Exception $e){}
        }
        if (has_mod_permission('approve_events')) {
            $c=(int)$pdo->query("SELECT COUNT(*) FROM events WHERE status IN('draft','pending_payment')")->fetchColumn();
            $items=$pdo->query("SELECT e.id, e.slug, e.title, e.venue AS location, e.description, e.start_date, e.user_id AS owner_id, u.name AS user_name, e.created_at FROM events e JOIN users u ON e.user_id=u.id WHERE e.status IN('draft','pending_payment') ORDER BY e.created_at ASC LIMIT 3")->fetchAll();
            foreach ($items as &$it) { $it['view_url'] = 'event_edit.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
            $queueSections[]=['icon'=>'📅','title'=>'Events','color'=>'#10b981','bg'=>'#f0fdf4','count'=>$c,'items'=>$items,'page'=>'events.php','approve_action'=>'approve_event','reject_action'=>'reject_event','label_key'=>'title','meta_key'=>'location'];
        }
        if (has_mod_permission('approve_funerals')) {
            $c=(int)$pdo->query("SELECT COUNT(*) FROM funeral_announcements WHERE status='pending'")->fetchColumn();
            $items=$pdo->query("SELECT fa.id, fa.slug, fa.deceased_name AS title, fa.venue AS location, fa.biography AS description, fa.burial_date, fa.user_id AS owner_id, u.name AS user_name, fa.created_at FROM funeral_announcements fa JOIN users u ON fa.user_id=u.id WHERE fa.status='pending' ORDER BY fa.created_at ASC LIMIT 3")->fetchAll();
            foreach ($items as &$it) { $it['view_url'] = 'funeral_edit.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
            $queueSections[]=['icon'=>'🕊️','title'=>'Funeral Announcements','color'=>'#6b7280','bg'=>'#f9fafb','count'=>$c,'items'=>$items,'page'=>'funerals.php','approve_action'=>'approve_funeral','reject_action'=>'reject_funeral','label_key'=>'title','meta_key'=>'location'];
        }
        if (has_mod_permission('approve_news')) {
            $c=(int)$pdo->query("SELECT COUNT(*) FROM news WHERE status='draft'")->fetchColumn();
            $items=$pdo->query("SELECT n.id, n.slug, n.title, n.summary AS description, 'Article' AS location, n.user_id AS owner_id, u.name AS user_name, n.created_at FROM news n JOIN users u ON n.user_id=u.id WHERE n.status='draft' ORDER BY n.created_at ASC LIMIT 3")->fetchAll();
            foreach ($items as &$it) { $it['view_url'] = 'news_edit.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
            $queueSections[]=['icon'=>'📰','title'=>'News Articles','color'=>'#059669','bg'=>'#f0fdf4','count'=>$c,'items'=>$items,'page'=>'news.php','approve_action'=>'approve_news','reject_action'=>'reject_news','label_key'=>'title','meta_key'=>'location'];
        }
        if (has_mod_permission('approve_delivery_requests')) {
            try {
                $c=(int)$pdo->query("SELECT COUNT(*) FROM delivery_requests WHERE status='pending_approval'")->fetchColumn();
                $items=$pdo->query("SELECT dr.id, dr.item_description AS title, dr.pickup_location AS location, dr.dropoff_location, dr.delivery_fee, dr.item_category, dr.customer_id AS owner_id, u.name AS user_name, dr.created_at FROM delivery_requests dr JOIN users u ON dr.customer_id=u.id WHERE dr.status='pending_approval' ORDER BY dr.created_at ASC LIMIT 3")->fetchAll();
                foreach ($items as &$it) { $it['view_url'] = '../delivery_detail.php?id=' . $it['id']; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
                $queueSections[]=['icon'=>'🚚','title'=>'Delivery Requests','color'=>'#f97316','bg'=>'#fff7ed','count'=>$c,'items'=>$items,'page'=>'delivery.php?tab=pending','approve_action'=>'approve_delivery_request','reject_action'=>'reject_delivery_request','label_key'=>'title','meta_key'=>'location'];
            } catch(Exception $e){}
        }
        if (has_mod_permission('approve_delivery_agents')) {
            try {
                $c=(int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE verification_status='pending'")->fetchColumn();
                $items=$pdo->query("SELECT da.id, u.name AS title, da.service_area AS location, da.vehicle_type, da.bio AS description, da.user_id AS owner_id, u.name AS user_name, da.created_at FROM delivery_agents da JOIN users u ON da.user_id=u.id WHERE da.verification_status='pending' ORDER BY da.created_at ASC LIMIT 3")->fetchAll();
                foreach ($items as &$it) { $it['view_url'] = 'delivery.php?tab=agents'; $it['has_coi'] = (int)$it['owner_id'] === $modId; }; unset($it);
                $queueSections[]=['icon'=>'🛵','title'=>'Rider Applications','color'=>'#8b5cf6','bg'=>'#f5f3ff','count'=>$c,'items'=>$items,'page'=>'delivery.php?tab=agents','approve_action'=>'approve_delivery_agent','reject_action'=>'reject_delivery_agent','label_key'=>'title','meta_key'=>'location'];
            } catch(Exception $e){}
        }
        if (has_mod_permission('manage_disputes')) {
            $c=(int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status='open'")->fetchColumn();
            if ($c) $queueSections[]=['icon'=>'⚖️','title'=>'Open Disputes','color'=>'#ef4444','bg'=>'#fef2f2','count'=>$c,'items'=>[],'page'=>'disputes.php','approve_action'=>null,'reject_action'=>null,'label_key'=>null,'meta_key'=>null];
        }

        $totalPending = array_sum(array_column($queueSections, 'count'));

        // ── Recent activity by this moderator ─────────────────────────────────
        $recentActions = $pdo->prepare("SELECT action, description, created_at FROM audit_logs WHERE admin_id=? ORDER BY created_at DESC LIMIT 8");
        $recentActions->execute([$modId]);
        $recentActions = $recentActions->fetchAll();

        // ── Moderator performance data ─────────────────────────────────────────
        $modPerf = get_mod_performance($modId, 'month');
        $modAllPts = (int)get_mod_points($modId, 'all');
        // Points redeemed
        try {
            $redSt = $pdo->prepare("SELECT COALESCE(SUM(points_used),0) FROM mod_rewards WHERE mod_id=? AND status IN('approved','paid')");
            $redSt->execute([$modId]); $modRedeemedPts = (int)$redSt->fetchColumn();
        } catch(Exception $e){ $modRedeemedPts = 0; }
        $modBalance = max(0, $modAllPts - $modRedeemedPts);
        $modGhsEarned = mod_points_to_ghs($modAllPts);
        // Rank among all managers
        try {
            $rankAll = $pdo->query("SELECT mod_id, SUM(points) AS pts FROM mod_activity_log GROUP BY mod_id ORDER BY pts DESC")->fetchAll();
            $modRank = 0;
            foreach ($rankAll as $ri => $rv) { if ((int)$rv['mod_id']==$modId){ $modRank=$ri+1; break; } }
        } catch(Exception $e){ $modRank = 0; }
        ?>

        <?php
        // What permissions does this manager hold?
        $myPerms = get_user_mod_permissions($modId);
        $allPermLabels = [];
        foreach (all_mod_permissions() as $group => $perms) {
            foreach ($perms as $key => $label) $allPermLabels[$key] = $label;
        }
        ?>

        <?php if (!is_admin() && empty($myPerms)): ?>
        <!-- ── No permissions assigned yet (managers only) ──────────────────── -->
        <div style="background:#fef3c7;border:2px solid #f59e0b;border-radius:14px;padding:22px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">
            <span style="font-size:2rem;flex-shrink:0;">⚠️</span>
            <div>
                <strong style="display:block;font-size:.95rem;margin-bottom:6px;">No permissions assigned to your account yet.</strong>
                <p style="margin:0 0 10px;font-size:.85rem;color:#374151;line-height:1.6;">
                    Your role is <strong>Manager</strong>, but no specific permissions have been granted yet.
                    An <strong>Admin</strong> must visit <em>Admin → Moderators → your name</em> and check the boxes for what you should be able to do.
                </p>
                <p style="margin:0;font-size:.82rem;color:#6b7280;">Until permissions are granted, you won't be able to approve or manage any content.</p>
            </div>
        </div>

        <?php elseif (!is_admin() && !empty($myPerms)): ?>
        <!-- ── Permission summary strip ───────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:14px;">
            <p style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Your Active Permissions</p>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php foreach ($myPerms as $p): ?>
                <span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:10px;">
                    <?php echo sanitize($allPermLabels[$p] ?? $p); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; /* empty permissions */ ?>

        <!-- Performance bar -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex:1;min-width:130px;">
                <span style="font-size:1.4rem;">✅</span>
                <div><strong style="display:block;font-size:1.3rem;font-weight:900;color:#10b981;line-height:1.1;"><?php echo $todayApproved; ?></strong><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">Approved today</span></div>
            </div>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex:1;min-width:130px;">
                <span style="font-size:1.4rem;">❌</span>
                <div><strong style="display:block;font-size:1.3rem;font-weight:900;color:#ef4444;line-height:1.1;"><?php echo $todayRejected; ?></strong><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">Rejected today</span></div>
            </div>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex:1;min-width:130px;">
                <span style="font-size:1.4rem;">📋</span>
                <div><strong style="display:block;font-size:1.3rem;font-weight:900;color:var(--primary,#0f766e);line-height:1.1;"><?php echo $weekTotal; ?></strong><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">Actions this week</span></div>
            </div>
            <div style="background:<?php echo $totalPending>0?'#fef3c7':'#d1fae5'; ?>;border:1px solid <?php echo $totalPending>0?'#f59e0b':'#6ee7b7'; ?>;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex:1;min-width:130px;">
                <span style="font-size:1.4rem;"><?php echo $totalPending>0?'⏳':'✓'; ?></span>
                <div><strong style="display:block;font-size:1.3rem;font-weight:900;color:<?php echo $totalPending>0?'#b45309':'#065f46'; ?>;line-height:1.1;"><?php echo $totalPending; ?></strong><span style="font-size:.72rem;color:<?php echo $totalPending>0?'#b45309':'#065f46'; ?>;">In queue now</span></div>
            </div>
        </div>

        <?php if ($queueSections): ?>
        <p class="adm-section-label" style="color:#f59e0b;">⚡ Your Queue — <?php echo $totalPending; ?> item<?php echo $totalPending!==1?'s':''; ?> pending</p>

        <?php foreach ($queueSections as $sec): ?>
        <div style="background:var(--surface);border:2px solid <?php echo $sec['color']; ?>22;border-radius:14px;margin-bottom:12px;overflow:hidden;">
            <!-- Section header -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:<?php echo $sec['bg']; ?>;border-bottom:1px solid <?php echo $sec['color']; ?>22;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:1.1rem;"><?php echo $sec['icon']; ?></span>
                    <strong style="font-size:.9rem;"><?php echo sanitize($sec['title']); ?></strong>
                    <span style="background:<?php echo $sec['color']; ?>;color:#fff;font-size:.68rem;font-weight:800;padding:2px 7px;border-radius:10px;"><?php echo $sec['count']; ?></span>
                </div>
                <a href="<?php echo sanitize($sec['page']); ?>" data-page="<?php echo sanitize($sec['page']); ?>"
                   style="font-size:.78rem;font-weight:700;color:<?php echo $sec['color']; ?>;text-decoration:none;">
                    View all <?php echo $sec['count']; ?> →
                </a>
            </div>

            <?php foreach ($sec['items'] as $item): ?>
            <!-- Item row -->
            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border,#f1f5f9);flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.86rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo sanitize(mb_substr($item[$sec['label_key']]??'(no title)',0,60)); ?>
                    </div>
                    <div style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                        by <?php echo sanitize($item['user_name']); ?>
                        <?php if (!empty($item[$sec['meta_key']])): ?> · <?php echo sanitize(mb_substr($item[$sec['meta_key']],0,40)); ?><?php endif; ?>
                        &nbsp;·&nbsp; <?php echo time_ago($item['created_at']); ?>
                    </div>
                    <?php /* Description preview for richer context */ ?>
                    <?php $preview = $item['description'] ?? null; ?>
                    <?php if ($preview): ?>
                    <div style="font-size:.76rem;color:var(--text-muted,#6b7280);margin-top:4px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.5;">
                        <?php echo sanitize(strip_tags(mb_substr($preview,0,160))); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($item['budget_amount']) || !empty($item['price'])): ?>
                    <div style="font-size:.76rem;font-weight:700;color:var(--primary,#0f766e);margin-top:3px;">
                        GHS <?php echo number_format((float)($item['budget_amount'] ?? $item['price'] ?? 0),2); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Quick actions -->
                <?php if ($sec['approve_action']): ?>
                <div style="display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap;align-items:flex-start;">
                    <?php if (!empty($item['view_url'])): ?>
                    <a href="<?php echo sanitize($item['view_url']); ?>" target="_blank" rel="noopener"
                       class="button button-small" style="background:var(--surface-muted,#f3f4f6);border-color:var(--border);color:var(--text);padding:4px 10px;font-size:.75rem;" title="Open full detail in new tab">
                        🔍 View
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($item['has_coi'])): ?>
                    <!-- COI: hide approve/reject, show conflict notice -->
                    <span style="background:#fef3c7;border:1px solid #f59e0b;color:#b45309;font-size:.72rem;font-weight:700;padding:4px 9px;border-radius:8px;display:flex;align-items:center;gap:4px;">
                        ⚠️ Your submission — cannot moderate
                    </span>
                    <?php else: ?>
                    <form method="post" action="mod_action.php" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action"  value="<?php echo $sec['approve_action']; ?>">
                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="back"    value="index.php">
                        <button type="submit" class="button button-small" style="background:#10b981;color:#fff;border-color:transparent;padding:4px 10px;font-size:.75rem;">✓ Approve</button>
                    </form>
                    <button type="button" class="button button-small"
                            style="background:#ef4444;color:#fff;border-color:transparent;padding:4px 10px;font-size:.75rem;"
                            onclick="openReject('<?php echo $sec['reject_action']; ?>',<?php echo $item['id']; ?>,'<?php echo sanitize(addslashes(mb_substr($item[$sec['label_key']]??'',0,40))); ?>')">
                        ✗ Reject
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if (empty($sec['items']) && $sec['count'] > 0): ?>
            <div style="padding:12px 14px;font-size:.84rem;color:var(--text-muted,#6b7280);">
                <?php echo $sec['count']; ?> item<?php echo $sec['count']>1?'s':''; ?> pending —
                <a href="<?php echo sanitize($sec['page']); ?>" data-page="<?php echo sanitize($sec['page']); ?>" style="color:<?php echo $sec['color']; ?>;font-weight:700;">Review them →</a>
            </div>
            <?php elseif ($sec['count'] > count($sec['items'])): ?>
            <div style="padding:8px 14px;font-size:.78rem;color:var(--text-muted,#6b7280);text-align:right;">
                + <?php echo $sec['count'] - count($sec['items']); ?> more —
                <a href="<?php echo sanitize($sec['page']); ?>" data-page="<?php echo sanitize($sec['page']); ?>" style="color:<?php echo $sec['color']; ?>;font-weight:700;">View all →</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php else: ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <span style="font-size:1.6rem;">✅</span>
            <div><strong style="font-size:.9rem;">Queue clear!</strong><br><span style="font-size:.82rem;color:#065f46;">Nothing awaiting your review right now. Check back later.</span></div>
        </div>
        <?php endif; ?>

        <!-- ── Moderator performance snapshot (managers only) ── -->
        <?php if (!is_admin()): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0;">Your Performance — This Month</p>
                <a href="mod_performance.php?tab=leaderboard&mod=<?php echo $modId; ?>" class="button button-secondary button-small" style="font-size:.76rem;">Full Scorecard →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;margin-bottom:12px;">
                <div style="text-align:center;background:var(--primary-soft,#d1fae5);border-radius:10px;padding:10px 6px;">
                    <strong style="display:block;font-size:1.2rem;font-weight:900;color:var(--primary,#0f766e);line-height:1.1;"><?php echo number_format($modPerf['points'],1); ?></strong>
                    <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Points (month)</span>
                </div>
                <div style="text-align:center;background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px 6px;">
                    <strong style="display:block;font-size:1.2rem;font-weight:900;color:#1d4ed8;line-height:1.1;"><?php echo number_format($modBalance); ?></strong>
                    <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Point Balance</span>
                </div>
                <div style="text-align:center;background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px 6px;">
                    <strong style="display:block;font-size:1.2rem;font-weight:900;color:#16a34a;line-height:1.1;">GHS <?php echo number_format($modGhsEarned,2); ?></strong>
                    <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Total Earned</span>
                </div>
                <div style="text-align:center;background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px 6px;">
                    <strong style="display:block;font-size:1.2rem;font-weight:900;color:#f59e0b;line-height:1.1;"><?php echo $modRank > 0 ? '#'.$modRank : '—'; ?></strong>
                    <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Your Rank</span>
                </div>
                <div style="text-align:center;background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px 6px;">
                    <strong style="display:block;font-size:1.2rem;font-weight:900;line-height:1.1;"><?php echo $modPerf['approval_rate']; ?>%</strong>
                    <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Approval Rate</span>
                </div>
            </div>
            <?php if ($modBalance >= 100): ?>
            <a href="mod_reward_request.php" class="button button-primary button-small">💰 Request Reward (<?php echo number_format($modBalance); ?> pts = GHS <?php echo number_format(mod_points_to_ghs($modBalance),2); ?>)</a>
            <?php else: ?>
            <p style="font-size:.78rem;color:var(--text-muted,#6b7280);margin:0;">Earn <?php echo max(0,100-$modBalance); ?> more points to unlock your first reward. Current balance: <?php echo $modBalance; ?> pts.</p>
            <?php endif; ?>
        </div>

        <?php endif; // !is_admin() — end of performance snapshot ?>

        <!-- Recent activity by this moderator -->
        <?php if (!is_admin() && $recentActions): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:20px;">
            <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 10px;">Your Recent Actions</p>
            <?php foreach ($recentActions as $log): ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:5px 0;border-bottom:1px solid var(--border,#f1f5f9);font-size:.81rem;gap:10px;">
                <div>
                    <?php
                    $logColor = str_contains($log['action'],'approve')?'#10b981':(str_contains($log['action'],'reject')?'#ef4444':'#6b7280');
                    ?>
                    <span style="background:<?php echo $logColor; ?>22;color:<?php echo $logColor; ?>;font-size:.65rem;font-weight:800;padding:1px 6px;border-radius:8px;margin-right:5px;"><?php echo sanitize($log['action']); ?></span>
                    <?php echo sanitize(mb_substr($log['description'],0,80)); ?>
                </div>
                <span style="font-size:.72rem;color:var(--text-muted,#6b7280);white-space:nowrap;flex-shrink:0;"><?php echo time_ago($log['created_at']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; // recentActions ?>

        <!-- Hidden reject modal -->
        <div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:var(--surface);border-radius:14px;padding:24px;width:100%;max-width:440px;margin:16px;">
                <h3 style="margin:0 0 6px;font-size:1rem;">Reject Item</h3>
                <p id="reject-label" style="font-size:.84rem;color:var(--text-muted,#6b7280);margin:0 0 14px;"></p>
                <form method="post" action="mod_action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"  id="reject-action">
                    <input type="hidden" name="item_id" id="reject-item-id">
                    <input type="hidden" name="back"    value="index.php">
                    <div class="form-group">
                        <label style="font-weight:700;font-size:.86rem;display:block;margin-bottom:4px;">Rejection Reason *</label>
                        <textarea name="reason" id="reject-reason" rows="3" required style="width:100%;box-sizing:border-box;"
                                  placeholder="Explain why this is being rejected so the submitter can correct and resubmit…"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="button button-secondary button-small" onclick="closeReject()">Cancel</button>
                        <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        function openReject(action, id, label) {
            document.getElementById('reject-action').value  = action;
            document.getElementById('reject-item-id').value = id;
            document.getElementById('reject-label').textContent = label;
            document.getElementById('reject-reason').value  = '';
            var m = document.getElementById('reject-modal');
            m.style.display = 'flex';
            document.getElementById('reject-reason').focus();
        }
        function closeReject() {
            document.getElementById('reject-modal').style.display = 'none';
        }
        document.getElementById('reject-modal').addEventListener('click', function(e) {
            if (e.target === this) closeReject();
        });
        </script>

        <?php endif; ?>

        <p class="adm-section-label">Platform overview</p>
        <div class="adm-stats">
            <div class="adm-stat"><strong><?php echo $totalUsers; ?></strong><span>Users</span></div>
            <div class="adm-stat"><strong><?php echo $totalRequests; ?></strong><span>Requests</span></div>
            <div class="adm-stat"><strong><?php echo $openRequests; ?></strong><span>Open</span></div>
            <div class="adm-stat"><strong><?php echo $pendingRequests; ?></strong><span>Pending</span></div>
            <div class="adm-stat"><strong><?php echo $completedRequests; ?></strong><span>Completed</span></div>
            <div class="adm-stat"><strong><?php echo $premiumWorkers; ?></strong><span>Premium</span></div>
        </div>

        <p class="adm-section-label">Quick access</p>
        <div class="adm-cards">
        <?php
        // [href, icon, title, desc, required_permission_or_null]
        // null = always visible to admin+manager; string = visible if is_admin() OR has_mod_permission($perm)
        $cards = [
            ['requests.php',         '📋', 'Requests',     'Service request queue',       'approve_jobs'],
            ['applications.php',     '📝', 'Applications', 'Job applications',            'approve_jobs'],
            ['users.php',            '👥', 'Users',        'Manage accounts & roles',     'manage_users'],
            ['disputes.php',         '⚖️', 'Disputes',     'Resolve user conflicts',      'manage_disputes'],
            ['referrals.php',        '🔗', 'Referrals',    'Referral programme',          'manage_referrals'],
            ['payments.php',         '💳', 'Payments',     'Platform payment records',    'view_reports'],
            ['monetization.php',     '💰', 'Monetize',     'Pricing, plans & fees',       null],
            ['news.php',             '📰', 'News',         'Articles & blog posts',       'approve_news'],
            ['ads.php',              '📣', 'Ads',          'Manage advertisements',       'manage_ads'],
            ['funerals.php',         '🕊️', 'Funerals',     'Funeral announcements',       'approve_funerals'],
            ['events.php',           '📅', 'Events',       'Community events',            'approve_events'],
            ['delivery.php',         '🚚', 'Delivery',     'Agents, requests & tracking', 'approve_delivery_agents'],
            ['marketplace.php',      '🛍️', 'Marketplace',  'Shops, products, orders & quote requests', ['approve_products','approve_shops','approve_boosts','manage_quote_requests']],
            ['markets.php',          '🏬', 'Nearby Markets', 'Ofie, Nkurakan & other scheduled markets', 'manage_markets'],
            ['market_orders.php',    '📝', 'Custom Orders', 'Price buyers\' market shopping lists',      'manage_market_deliveries'],
            ['market_deliveries.php','📦', 'Storehouse Deliveries', 'Manage market pickup/handoff',      'manage_market_deliveries'],
            ['market_settings.php',  '⚙️', 'Market Settings', 'Global nearby market settings',   null],
            ['quick_services.php',   '⚡', 'Quick Services', 'Manage services, fees & managers', 'manage_quick_services'],
            ['quick_service_requests.php', '📥', 'Service Requests', 'Process assigned service requests', 'manage_quick_service_requests'],
            ['promotions.php',       '🎁', 'Promotions',   'Special offers & free-access campaigns', 'manage_promotions'],
            ['moderators.php',       '🛡️', 'Moderators',   'Roles & access control',      null],
            ['mod_performance.php',  '🏆', 'Performance',  'Points, leaderboard & rewards',null],
            ['analytics.php',        '📊', 'Analytics',    'Stats & growth trends',       'view_reports'],
            ['business_messages.php','💬', 'Messages',     'Business enquiries',          null],
            ['communication.php',    '📣', 'Broadcast',    'Push notifications',          'manage_communication'],
            ['email_settings.php',   '📧', 'Email / SMTP', 'SMTP config & test',          null],
            ['contact_settings.php', '📞', 'Contact',      'Contact page information',    null],
            ['media_settings.php',   '🖼️', 'Image Optimization', 'Upload resize & quality', 'manage_media_settings'],
            ['towns.php',            '📍', 'Towns',        'Manage Akuapem towns list',   'manage_towns'],
            ['master_catalog.php',   '🗂️', 'Master Catalog', 'Shared product catalog for shops', 'manage_master_catalog'],
            ['theme.php',            '🎨', 'Theme',        'Colours & branding',          null],
            ['audit_logs.php',       '📜', 'Audit Logs',   'Admin action history',        'view_reports'],
        ];
        foreach ($cards as [$href, $icon, $title, $desc, $requiredPerm]):
            // Admin sees everything. Null-permission cards: admin-only. String: manager needs that
            // permission. Array: manager needs ANY one of those permissions.
            if (!is_admin()) {
                if ($requiredPerm === null) continue;          // admin-only card
                $requiredPerms = is_array($requiredPerm) ? $requiredPerm : [$requiredPerm];
                if (!array_filter($requiredPerms, fn($p) => has_mod_permission($p))) continue; // not granted
            }
        ?>
            <a class="adm-card" href="<?php echo $href; ?>" data-page="<?php echo $href; ?>">
                <div class="adm-card-icon"><?php echo $icon; ?></div>
                <div class="adm-card-title"><?php echo $title; ?></div>
                <div class="adm-card-desc"><?php echo $desc; ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- AJAX content injected here -->
    <div id="adm-ajax"></div>

</div><!-- .adm-body -->

<script>
(function () {
    'use strict';

    var homeEl         = document.getElementById('adm-home');
    var ajaxEl         = document.getElementById('adm-ajax');
    var navEl          = document.getElementById('adm-nav');
    var slBtn          = document.getElementById('adm-sl');
    var srBtn          = document.getElementById('adm-sr');
    var pageStyleEl    = null;
    var currentLoadUrl = null;
    var currentCat     = null;

    /* ── Scroll buttons ───────────────────────────────────────── */

    function updateScrollBtns() {
        var sl = navEl.scrollLeft;
        var max = navEl.scrollWidth - navEl.clientWidth;
        slBtn.classList.toggle('vis', sl > 4);
        srBtn.classList.toggle('vis', max > 4 && sl < max - 4);
    }

    slBtn.addEventListener('click', function () {
        navEl.scrollBy({ left: -200, behavior: 'smooth' });
    });
    srBtn.addEventListener('click', function () {
        navEl.scrollBy({ left: 200, behavior: 'smooth' });
    });
    navEl.addEventListener('scroll', updateScrollBtns, { passive: true });
    window.addEventListener('resize', updateScrollBtns);
    updateScrollBtns();

    /* ── Dropdown open / close ────────────────────────────────── */

    function openDrop(cat) {
        closeDrop(true);
        var btn  = document.querySelector('.adm-cat-btn[data-cat="' + cat + '"]');
        var drop = document.querySelector('.adm-drop[data-cat="' + cat + '"]');
        if (!btn || !drop) return;
        var rect = btn.getBoundingClientRect();
        drop.style.left = rect.left + 'px';
        btn.classList.add('open');
        drop.classList.add('open');
        currentCat = cat;
    }

    function closeDrop(silent) {
        if (!currentCat) return;
        document.querySelectorAll('.adm-cat-btn.open').forEach(function (b) { b.classList.remove('open'); });
        document.querySelectorAll('.adm-drop.open').forEach(function (d)   { d.classList.remove('open'); });
        currentCat = null;
    }

    document.querySelectorAll('.adm-cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var cat = btn.dataset.cat;
            if (currentCat === cat) { closeDrop(); } else { openDrop(cat); }
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#adm-drops') && !e.target.closest('.adm-cat-btn')) {
            closeDrop();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDrop();
    });

    /* ── Active state ─────────────────────────────────────────── */

    function setActive(absUrl) {
        document.querySelectorAll('.adm-drop a[data-page]').forEach(function (a) {
            var match = absUrl && absUrl.indexOf('/' + a.dataset.page) !== -1;
            a.classList.toggle('active', !!match);
        });
        document.querySelectorAll('.adm-cat-btn').forEach(function (btn) {
            var drop = document.querySelector('.adm-drop[data-cat="' + btn.dataset.cat + '"]');
            btn.classList.toggle('active', !!(drop && drop.querySelector('a.active')));
        });
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    function isInternalAdminPage(absUrl) {
        try {
            var u = new URL(absUrl);
            return u.hostname === window.location.hostname
                && /\/admin\/[a-z0-9_-]+\.php/.test(u.pathname)
                && !/logout|login/i.test(u.pathname);
        } catch (e) { return false; }
    }

    function ensurePageStyle() {
        if (!pageStyleEl) {
            pageStyleEl = document.createElement('style');
            pageStyleEl.id = 'adm-page-style';
            document.head.appendChild(pageStyleEl);
        }
        return pageStyleEl;
    }

    function toHashSegment(absUrl) {
        try {
            var u = new URL(absUrl);
            return u.pathname.split('/').pop() + u.search;
        } catch (e) { return ''; }
    }

    /* ── Show home dashboard ──────────────────────────────────── */

    function showHome(push) {
        homeEl.style.display = '';
        ajaxEl.style.display = 'none';
        ajaxEl.innerHTML = '';
        currentLoadUrl = null;
        setActive(null);
        document.title = 'Admin Dashboard — AkuapemConnect';
        if (push !== false) history.pushState({ adm: 'home' }, '', 'index.php');
    }

    /* ── Load a page into the AJAX panel ─────────────────────── */

    function admLoad(href, push) {
        closeDrop();
        var base   = currentLoadUrl || window.location.href;
        var absUrl = new URL(href, base).href;
        currentLoadUrl = absUrl;

        homeEl.style.display = 'none';
        ajaxEl.style.display = 'block';
        ajaxEl.innerHTML = '<div class="adm-loading"><div class="adm-spinner"></div>Loading…</div>';
        setActive(absUrl);

        if (push !== false) {
            history.pushState({ adm: 'page', url: absUrl }, '', 'index.php#' + toHashSegment(absUrl));
        }

        fetch(absUrl, { credentials: 'same-origin' })
            .then(function (r) {
                // Session expired mid-session: require_login() redirects to
                // login.php, which fetch() follows silently. Detect that via
                // the final response URL and do a real navigation instead of
                // injecting the login form into the admin dashboard layout.
                if (r.url && /\/login\.php(\?|$)/.test(r.url)) {
                    window.location.href = r.url;
                    return Promise.reject('session-expired');
                }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var doc    = parser.parseFromString(html, 'text/html');

                var t = doc.querySelector('title');
                if (t) document.title = t.textContent;

                // Extract ALL <style> blocks from the fetched page (not just head, to
                // guard against browsers that move styles around during DOMParser parsing)
                var css = '';
                doc.querySelectorAll('style').forEach(function (s) { css += s.textContent; });
                ensurePageStyle().textContent = css;

                // Load any external <script src> from the fetched page's <head>
                // (e.g. chart.js on payments.php) — only if not already loaded
                doc.querySelectorAll('head script[src]').forEach(function (old) {
                    var src = old.getAttribute('src');
                    if (src && !document.querySelector('script[src="' + src + '"]')) {
                        var s = document.createElement('script');
                        s.src = src;
                        s.async = false;
                        document.head.appendChild(s);
                    }
                });

                var main = doc.querySelector('main');
                ajaxEl.innerHTML = main ? main.outerHTML : doc.body.innerHTML;

                // Scan the full body (not just main) so scripts placed after </main>
                // but before </body> are also executed — this fixes toggleDetail,
                // tab switching, and any other page-level JS in admin pages.
                doc.querySelectorAll('body script').forEach(function (old) {
                    var s = document.createElement('script');
                    if (old.src) { s.src = old.src; s.async = false; }
                    else         { s.textContent = old.textContent; }
                    document.head.appendChild(s);
                    if (!old.src) document.head.removeChild(s);
                });

                window.scrollTo({ top: 0 });
            })
            .catch(function (err) {
                if (err === 'session-expired') return; // navigation already in progress
                ajaxEl.innerHTML =
                    '<div style="padding:30px 16px;">' +
                    '<div class="alert alert-error">Failed to load the page. ' +
                    '<a href="' + absUrl + '">Open directly →</a></div></div>';
            });
    }

    /* ── Wire dropdown links ──────────────────────────────────── */
    document.querySelectorAll('.adm-drop a[data-page]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            admLoad(a.getAttribute('href'));
        });
    });

    /* ── Wire quick-access cards ──────────────────────────────── */
    document.querySelectorAll('#adm-home .adm-card[data-page]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            admLoad(a.getAttribute('href'));
        });
    });

    /* ── Brand / home button ──────────────────────────────────── */
    document.getElementById('adm-home-btn').addEventListener('click', function (e) {
        e.preventDefault();
        showHome();
    });

    /* ── Delegate: intercept links inside AJAX content ──────── */
    ajaxEl.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        var resolved;
        try { resolved = new URL(a.getAttribute('href'), currentLoadUrl || window.location.href).href; }
        catch (ex) { return; }
        if (!isInternalAdminPage(resolved)) return;
        e.preventDefault();
        admLoad(resolved);
    });

    /* ── Delegate: intercept POST forms inside AJAX content ──── */
    // Resolves the action against currentLoadUrl so forms without an explicit
    // action attribute (which would otherwise POST to index.php) hit the
    // correct admin page (funerals.php, events.php, news.php, etc.)
    ajaxEl.addEventListener('submit', function (e) {
        var form = e.target.closest('form');
        if (!form || form.method.toLowerCase() === 'get') return;
        var actionRaw  = form.getAttribute('action') || '';
        var actionBase = currentLoadUrl || window.location.href;
        var resolved;
        try { resolved = new URL(actionRaw || actionBase, actionBase).href; }
        catch (ex) { return; }
        if (!isInternalAdminPage(resolved)) return;
        e.preventDefault();
        var fd  = new FormData(form);
        // Include the clicked submit button's name/value (e.g. "save_fee", bulk actions)
        var sub = e.submitter;
        if (sub && sub.name) fd.append(sub.name, sub.value);
        fetch(resolved, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { admLoad(r.url || resolved); })
            .catch(function ()  { admLoad(currentLoadUrl);   });
    });

    /* ── Browser back / forward ───────────────────────────────── */
    window.addEventListener('popstate', function (e) {
        if (!e.state || e.state.adm === 'home') {
            showHome(false);
        } else if (e.state.adm === 'page' && e.state.url) {
            currentLoadUrl = e.state.url;
            admLoad(e.state.url, false);
        }
    });

    /* ── On refresh: restore from hash ───────────────────────── */
    var initHash = window.location.hash.slice(1);
    if (initHash && /^[a-z0-9_-]+\.php/i.test(initHash)) {
        admLoad(initHash, false);
        history.replaceState({ adm: 'page', url: new URL(initHash, window.location.href).href }, '', window.location.href);
    } else {
        history.replaceState({ adm: 'home' }, '', window.location.href);
    }

    /* Expose for inline onclick (payments alert button) */
    window.admLoad = admLoad;
}());
</script>
<script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>

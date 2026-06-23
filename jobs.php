<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';

$user = current_user();
if (!$user) { header('Location: browse_jobs.php'); exit; }
$flash = get_flash();
sweep_expired_featured();

// Banner ad + news teaser for dashboard
$dashBannerAd  = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();
$dashLatestNews = $pdo->query("SELECT title, slug, featured_image, published_at FROM news WHERE status='published' AND (published_at IS NULL OR published_at<=NOW()) ORDER BY COALESCE(published_at,created_at) DESC LIMIT 3")->fetchAll();
$dashEvents    = $pdo->query("SELECT * FROM events WHERE status='published' AND start_date>=CURDATE() ORDER BY featured DESC, start_date ASC LIMIT 3")->fetchAll();
$dashFunerals  = $pdo->query("SELECT * FROM funeral_announcements WHERE status='approved' ORDER BY featured DESC, created_at DESC LIMIT 3")->fetchAll();
$categories = get_categories();
$notificationCount = get_unread_notifications_count((int)$user['id']);

$categoryFilter = $_GET['category'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$jobTypeFilter  = $_GET['job_type'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($categoryFilter) {
    $where[] = 'sr.category_id = ?';
    $params[] = $categoryFilter;
}
if ($locationFilter) {
    $where[] = 'sr.location LIKE ?';
    $params[] = '%' . $locationFilter . '%';
}
if ($statusFilter) {
    $where[] = 'sr.status = ?';
    $params[] = $statusFilter;
}
if ($jobTypeFilter && in_array($jobTypeFilter, ['on_site','remote','hybrid'], true)) {
    $where[] = 'sr.job_type = ?';
    $params[] = $jobTypeFilter;
}
if ($searchQuery) {
    $where[] = '(sr.title LIKE ? OR sr.description LIKE ? OR wc.name LIKE ? OR sr.location LIKE ?)';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

if (is_worker()) {
    $workerWhere = array_merge(["sr.status IN ('open','partially_staffed')", "sr.posting_fee_status != 'pending'"], $where);
    $sql = 'SELECT sr.*, u.name AS customer_name, wc.name AS category_name, w.user_id AS worker_user_id
            FROM service_requests sr
            JOIN users u ON sr.customer_id = u.id
            JOIN service_categories wc ON sr.category_id = wc.id
            LEFT JOIN worker_profiles w ON sr.assigned_worker_id = w.user_id
            WHERE ' . implode(' AND ', $workerWhere);
    $sql .= ' ORDER BY sr.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    $workerCounts = get_worker_request_counts($user['id']);
    $availableJobs = get_open_jobs_count();
    $workerEarnings = get_paid_total_by_worker($user['id']);

    $workerSkillsCsv = '';
    $workerPendingVerif = false;
    if ($profile) {
        $skillRows = $pdo->prepare('SELECT skill_name FROM worker_skills WHERE worker_profile_id = ?');
        $skillRows->execute([$profile['id']]);
        $workerSkillsCsv = implode(', ', array_column($skillRows->fetchAll(), 'skill_name'));

        $pvStmt = $pdo->prepare("SELECT id FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'pending'");
        $pvStmt->execute([$user['id']]);
        $workerPendingVerif = (bool) $pvStmt->fetch();
    }
    $matchContext = [
        'latitude' => $profile['latitude'] ?? null,
        'longitude' => $profile['longitude'] ?? null,
        'skills' => $workerSkillsCsv,
    ];

    $openJobs = [];
    $myJobs = [];
    foreach ($requests as $request) {
        if (in_array($request['status'], ['open','partially_staffed'], true)) {
            $openJobs[] = $request;
        } elseif ($request['assigned_worker_id'] === $user['id']) {
            $myJobs[] = $request;
        }
    }
    $openJobs = rank_jobs_for_worker($openJobs, $matchContext);

    $myApplicationStatuses = [];
    $appStmt = $pdo->prepare('SELECT request_id, status FROM applications WHERE worker_id = ? ORDER BY applied_at ASC');
    $appStmt->execute([$user['id']]);
    foreach ($appStmt->fetchAll() as $appRow) {
        $myApplicationStatuses[$appRow['request_id']] = $appRow['status'];
    }

    // Daily application count
    $maxDailyApps = (int)get_platform_setting('max_daily_applications', '0');
    $todayAppStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE worker_id = ? AND DATE(applied_at) = CURDATE()");
    $todayAppStmt->execute([$user['id']]);
    $todayAppCount = (int)$todayAppStmt->fetchColumn();

    // Workers can also post jobs, so load their drafts too
    $draftStmt = $pdo->prepare(
        "SELECT sr.*, sc.name AS category_name FROM service_requests sr
         JOIN service_categories sc ON sr.category_id = sc.id
         WHERE sr.customer_id = ? AND sr.status = 'draft'
         ORDER BY sr.updated_at DESC"
    );
    $draftStmt->execute([$user['id']]);
    $drafts = $draftStmt->fetchAll();

} elseif (is_customer()) {
    // Drafts — shown separately above main job list
    $draftStmt = $pdo->prepare(
        "SELECT sr.*, sc.name AS category_name FROM service_requests sr
         JOIN service_categories sc ON sr.category_id = sc.id
         WHERE sr.customer_id = ? AND sr.status = 'draft'
         ORDER BY sr.updated_at DESC"
    );
    $draftStmt->execute([$user['id']]);
    $drafts = $draftStmt->fetchAll();

    // Handle draft delete/discard
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_draft') {
        csrf_check();
        $delId = intval($_POST['draft_id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare("DELETE FROM service_requests WHERE id = ? AND status = 'draft' AND customer_id = ?")
                ->execute([$delId, $user['id']]);
            flash('Draft deleted.', 'info');
        }
        header('Location: jobs.php');
        exit;
    }

    $customerWhere = array_merge(["sr.customer_id = ?", "sr.status != 'draft'"], $where);
    $customerParams = array_merge([$user['id']], $params);
    $sql = 'SELECT sr.*, sr.posting_fee_status, wc.name AS category_name, u.name AS assigned_worker_name, r.score AS rating_score, r.comment AS rating_comment
        FROM service_requests sr
        JOIN service_categories wc ON sr.category_id = wc.id
        LEFT JOIN users u ON sr.assigned_worker_id = u.id
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.customer_id = sr.customer_id
        WHERE ' . implode(' AND ', $customerWhere) . '
        ORDER BY sr.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($customerParams);
    $requests = $stmt->fetchAll();
    $customerCounts = get_request_status_counts($user['id']);
    $customerSpent = get_customer_spending_total($user['id']);

    $browseStmt = $pdo->prepare(
        'SELECT sr.*, sc.name AS category_name FROM service_requests sr
         JOIN service_categories sc ON sr.category_id = sc.id
         WHERE sr.status IN (\'open\', \'partially_staffed\')
           AND sr.posting_fee_status != \'pending\'
           AND sr.customer_id != ?
         ORDER BY sr.featured DESC, sr.created_at DESC LIMIT 9'
    );
    $browseStmt->execute([$user['id']]);
    $browseJobs = $browseStmt->fetchAll();

} elseif ($user) {
    header('Location: admin/index.php');
    exit;
}

// Referral & points data (logged-in users only)
$dashRefEnabled = false;
$dashPtsBalance = 0;
$dashRefCode    = '';
$dashRefUrl     = '';
if ($user) {
    $dashRefEnabled = referrals_enabled();
    $dashPtsBalance = $dashRefEnabled ? get_points_balance((int)$user['id']) : 0;
    $dashRefCode    = $dashRefEnabled ? get_or_create_referral_code((int)$user['id']) : '';
    $dashRefUrl     = rtrim(BASE_URL, '/') . '/register.php?ref=' . $dashRefCode;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Home — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">

    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🏠</span> AkuapemConnect</span>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="notifications.php" class="bottom-nav-item" style="flex: none; flex-direction: row; gap: 6px; color: var(--text);">
                <span class="nav-icon<?php echo $notificationCount ? ' nav-badge' : ''; ?>" <?php echo $notificationCount ? 'data-count="' . (int)$notificationCount . '"' : ''; ?>>🔔</span>
            </a>
            <!-- Avatar with dropdown -->
            <div id="avatar-wrap" style="position:relative;">
                <button id="avatar-btn" aria-expanded="false" aria-haspopup="true"
                        style="background:none;border:none;padding:0;cursor:pointer;display:flex;align-items:center;border-radius:50%;">
                    <?php if (!empty($user['profile_photo'])): ?>
                        <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile" class="avatar" style="pointer-events:none;" />
                    <?php else: ?>
                        <span class="avatar" style="pointer-events:none;"><?php echo sanitize(strtoupper(substr(display_name($user), 0, 1))); ?></span>
                    <?php endif; ?>
                </button>
                <div id="avatar-menu" role="menu"
                     style="display:none;position:absolute;right:0;top:calc(100% + 8px);min-width:180px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);z-index:999;overflow:hidden;">
                    <div style="padding:12px 14px 10px;border-bottom:1px solid #f1f5f9;">
                        <strong style="display:block;font-size:.88rem;"><?php echo sanitize(display_name($user)); ?></strong>
                        <span style="font-size:.75rem;color:#6b7280;"><?php echo sanitize($user['email']); ?></span>
                    </div>
                    <?php if ($user['role'] === 'worker'): ?>
                    <a href="worker_profile.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>👤</span> My Worker Profile
                    </a>
                    <?php else: ?>
                    <a href="jobs.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>🏠</span> Dashboard
                    </a>
                    <?php endif; ?>
                    <a href="settings.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>⚙️</span> Settings
                    </a>
                    <div style="border-top:1px solid #f1f5f9;"></div>
                    <a href="marketplace.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>🛍️</span> Marketplace
                    </a>
                    <a href="orders.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>📋</span> My Orders
                    </a>
                    <a href="seller_dashboard.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>🏪</span> My Shop
                    </a>
                    <a href="my_saved.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span>❤️</span> Saved Products
                    </a>
                    <div style="border-top:1px solid #f1f5f9;"></div>
                    <a href="logout.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#c0392b;text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
                        <span>🚪</span> Logout
                    </a>
                </div>
            </div>
            <script>
            (function() {
                var btn  = document.getElementById('avatar-btn');
                var menu = document.getElementById('avatar-menu');
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var open = menu.style.display === 'block';
                    menu.style.display = open ? 'none' : 'block';
                    btn.setAttribute('aria-expanded', String(!open));
                });
                document.addEventListener('click', function() {
                    menu.style.display = 'none';
                    btn.setAttribute('aria-expanded', 'false');
                });
                menu.addEventListener('click', function(e) { e.stopPropagation(); });
            })();
            </script>
        </div>
    </header>

    <main class="page-shell">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
        <?php endif; ?>

        <!-- ── Logged-in user views ──────────────────────────────── -->
        <?php if (!is_email_verified()): ?>
            <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <span style="font-size:1.2rem;line-height:1;">📧</span>
                <div style="flex:1;min-width:200px;">
                    <strong>Please verify your email address</strong><br>
                    <span style="font-size:0.9rem;">
                        We sent a link to <strong><?php echo sanitize($user['email']); ?></strong>.
                        You need to verify your email before you can post or apply for jobs.
                    </span>
                </div>
                <form method="post" action="resend_verification.php" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="button button-secondary button-small"
                            style="white-space:nowrap;">Resend email</button>
                </form>
            </div>
        <?php endif; ?>

        <section class="hero-card">
            <p class="meta" style="color: rgba(255,255,255,0.85); margin-bottom: 4px;">👋 Hello, <?php echo sanitize(display_name($user)); ?></p>
            <?php if (is_worker()): ?>
                <h1>Find trusted jobs near you in Akuapem</h1>
                <div class="button-group">
                    <a href="#open-jobs" class="button button-primary">Browse open jobs</a>
                    <a href="my_applications.php" class="button button-secondary">📋 My Applications</a>
                    <a href="find_workers.php" class="button button-secondary">🔧 Find Workers</a>
                    <a href="request.php" class="button button-secondary">➕ Post Job</a>
                </div>
            <?php else: ?>
                <h1>Find trusted workers for any job in Akuapem</h1>
                <div class="button-group">
                    <a href="my_applications.php" class="button button-secondary">📋 My Applications</a>
                    <a href="find_workers.php" class="button button-secondary">🔧 Find Workers</a>
                    <a href="request.php" class="button button-primary">➕ Post Job</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- Marketplace discovery banner -->
        <div style="background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
                <p style="color:rgba(255,255,255,0.85);font-size:.72rem;margin:0 0 2px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;">Marketplace</p>
                <p style="color:#fff;font-size:.9rem;font-weight:700;margin:0;">Buy &amp; sell products in the community</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="marketplace.php" style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,.4);padding:7px 14px;border-radius:8px;font-size:.84rem;font-weight:700;text-decoration:none;white-space:nowrap;">Browse</a>
                <a href="seller_dashboard.php" style="background:#fff;color:#d97706;padding:7px 14px;border-radius:8px;font-size:.84rem;font-weight:800;text-decoration:none;white-space:nowrap;">Start Selling →</a>
            </div>
        </div>

        <?php if ($dashRefEnabled): ?>
        <div style="background:linear-gradient(135deg,var(--primary) 0%,#1d4ed8 100%);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
                <p style="color:rgba(255,255,255,0.75);font-size:0.75rem;margin:0 0 1px;letter-spacing:.03em;">YOUR POINTS</p>
                <p style="color:#fff;font-size:1.5rem;font-weight:800;margin:0;letter-spacing:-0.5px;"><?php echo number_format($dashPtsBalance); ?></p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <a href="referrals.php" style="background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.35);padding:7px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;text-decoration:none;white-space:nowrap;">View &amp; Earn</a>
                <button id="dash-share-btn" onclick="dashShare()" style="background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.3);padding:7px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;cursor:pointer;white-space:nowrap;display:none;">
                    Share Link
                </button>
                <button onclick="dashCopy()" id="dash-copy-btn" style="background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.3);padding:7px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                    Copy Invite
                </button>
            </div>
        </div>
        <script>
        var _dashRefUrl = <?php echo json_encode($dashRefUrl); ?>;
        if (navigator.share) { var sb = document.getElementById('dash-share-btn'); if(sb) sb.style.display='block'; }
        async function dashShare() {
            try { await navigator.share({ title:'Join AkuapemConnect', text:'Use my referral link:', url:_dashRefUrl }); }
            catch(e) { dashCopy(); }
        }
        async function dashCopy() {
            try { await navigator.clipboard.writeText(_dashRefUrl); }
            catch(e) { /* fallback */ }
            var btn = document.getElementById('dash-copy-btn');
            if (btn) { var orig = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = orig; }, 1800); }
        }
        </script>
        <?php endif; ?>

        <?php if (is_worker() && $profile):
            $dbFeatEnd    = $profile['featured_end_date'] ?? null;
            $dbFeatActive = !empty($profile['is_featured']) && (empty($dbFeatEnd) || $dbFeatEnd >= date('Y-m-d'));
            $dbRenewSoon  = !empty($profile['is_featured']) && !empty($dbFeatEnd) && $dbFeatEnd < date('Y-m-d', strtotime('+7 days'));
        ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;background:var(--surface);border:1px solid var(--border);margin-bottom:8px;">
                <span style="font-size:1.25rem;">🛡️</span>
                <div style="flex:1;min-width:0;">
                    <span style="font-size:0.82rem;color:var(--muted,#6b7280);display:block;margin-bottom:3px;">Profile status</span>
                    <?php if ($profile['is_verified']): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:#22a06b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.92rem;font-weight:600;"><strong style="font-size:1.05em;">✓</strong>erified</span>
                        <?php if ($profile['verification_expiry']): ?>
                            <span class="meta" style="margin-left:6px;font-size:0.8rem;">until <?php echo sanitize($profile['verification_expiry']); ?></span>
                        <?php endif; ?>
                    <?php elseif ($workerPendingVerif): ?>
                        <a href="my_payments.php" style="display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;text-decoration:none;">⏳ Verification payment pending</a>
                    <?php else: ?>
                        <span style="color:var(--muted,#6b7280);font-size:0.87rem;">Not verified</span>
                        <a href="<?php echo is_feature_paid('enable_paid_verification_badges') ? 'request_verification.php' : '#'; ?>" class="button button-secondary button-small" style="margin-left:10px;font-size:0.8rem;">Get <strong>✓</strong>erified</a>
                    <?php endif; ?>
                    <?php if ($dbFeatActive && !$dbRenewSoon): ?>
                        <span style="display:inline-flex;align-items:center;background:var(--primary);color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;margin-left:6px;">⭐ Featured<?php echo $dbFeatEnd ? ' · ' . sanitize($dbFeatEnd) : ''; ?></span>
                    <?php elseif ($dbRenewSoon): ?>
                        <a href="feature_worker.php" style="display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;text-decoration:none;margin-left:6px;">⭐ Renew feature</a>
                    <?php elseif (!$dbFeatActive): ?>
                        <a href="feature_worker.php" class="button button-secondary button-small" style="margin-left:10px;font-size:0.8rem;">⭐ Feature profile</a>
                    <?php endif; ?>
                </div>
                <a href="worker_profile.php" style="font-size:0.8rem;color:var(--primary);white-space:nowrap;">My profile →</a>
            </div>
            <?php
                $svcFeeStatus = $profile['service_fee_status'] ?? 'free';
                $svcFeeExpiry = $profile['service_fee_expiry'] ?? null;
                $listingRequired = is_feature_paid('enable_paid_worker_service');
            ?>
            <?php if ($svcFeeStatus === 'pending'): ?>
                <div class="alert alert-warning" style="margin-bottom:8px;font-size:0.9rem;">
                    💳 <strong>Service listing fee pending</strong> — your profile won't appear in Find Workers until confirmed.
                    <a href="my_payments.php" style="color:var(--primary);margin-left:4px;">Track payment →</a>
                    &nbsp;·&nbsp;<a href="pay_worker_service.php" style="color:var(--primary);">Pay now →</a>
                </div>
            <?php elseif ($svcFeeStatus === 'paid'): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;background:var(--surface);border:1px solid var(--border);margin-bottom:8px;font-size:0.9rem;">
                    <span>📋</span>
                    <div style="flex:1;">
                        <strong>Service listing</strong>: active
                        <?php if ($svcFeeExpiry): ?>
                            — expires <strong><?php echo sanitize($svcFeeExpiry); ?></strong>
                            <?php if ($svcFeeExpiry <= date('Y-m-d', strtotime('+7 days'))): ?>
                                <a href="pay_worker_service.php" style="color:var(--primary);margin-left:6px;">Renew →</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($listingRequired && $svcFeeStatus !== 'free'): ?>
                <div class="alert alert-info" style="margin-bottom:8px;font-size:0.9rem;">
                    A service listing fee is required to appear in Find Workers.
                    <a href="pay_worker_service.php" style="color:var(--primary);margin-left:4px;">Pay listing fee →</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2 style="margin: 0 0 12px;">Popular categories</h2>
        <div class="category-grid" id="category-grid">
            <?php foreach ($categories as $index => $category): ?>
                <a href="<?php echo is_worker() ? 'jobs.php?category=' . $category['id'] : 'find_workers.php?category=' . $category['id']; ?>" class="chip-pick<?php echo $index >= 5 ? ' category-extra' : ''; ?>"<?php echo $index >= 5 ? ' hidden' : ''; ?>>
                    <span class="chip-icon"><?php echo category_icon($category['name']); ?></span>
                    <span><?php echo sanitize($category['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (count($categories) > 5): ?>
            <button type="button" id="toggle-categories" class="button button-secondary button-small" style="margin-bottom: var(--space-4);" data-expanded="0">View all categories</button>
        <?php endif; ?>

        <?php if ($dashBannerAd): ?>
        <div style="text-align:center;margin:16px 0;">
            <div style="font-size:.68rem;letter-spacing:.07em;text-transform:uppercase;color:var(--muted,#6b7280);margin-bottom:5px;">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$dashBannerAd['id']; ?>" target="_blank" rel="noopener sponsored" style="display:inline-block;max-width:728px;width:100%;">
                <?php if ($dashBannerAd['image']): ?>
                    <img src="<?php echo sanitize($dashBannerAd['image']); ?>" alt="<?php echo sanitize($dashBannerAd['title']); ?>" style="width:100%;border-radius:10px;">
                <?php else: ?>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;font-weight:600;color:var(--muted,#6b7280);"><?php echo sanitize($dashBannerAd['title']); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($dashLatestNews): ?>
        <section style="margin:0 0 20px;" aria-label="Latest News">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h2 style="margin:0;font-size:1rem;font-weight:700;">📰 Latest News</h2>
                <a href="news.php" style="font-size:.82rem;color:var(--primary);font-weight:600;text-decoration:none;">View all →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
            <?php foreach ($dashLatestNews as $dn):
                $dnThumb = $dn['featured_image'] ? sanitize($dn['featured_image']) : '';
                $dnDate  = $dn['published_at'] ? date('M j, Y', strtotime($dn['published_at'])) : '';
            ?>
            <a href="news_article.php?slug=<?php echo urlencode($dn['slug']); ?>" style="display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s;" onmouseover="this.style.boxShadow='0 4px 14px rgba(0,0,0,.09)';this.style.transform='translateY(-1px)'" onmouseout="this.style.boxShadow='';this.style.transform=''">
                <?php if ($dnThumb): ?>
                    <img src="<?php echo $dnThumb; ?>" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover;">
                <?php else: ?>
                    <div style="width:100%;aspect-ratio:16/9;background:linear-gradient(135deg,var(--primary) 0%,#0d5e57 100%);display:flex;align-items:center;justify-content:center;font-size:1.4rem;">📰</div>
                <?php endif; ?>
                <div style="padding:9px 11px 11px;">
                    <p style="margin:0 0 3px;font-weight:700;font-size:.85rem;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo sanitize($dn['title']); ?></p>
                    <?php if ($dnDate): ?><p style="margin:0;font-size:.73rem;color:var(--muted,#6b7280);">📅 <?php echo $dnDate; ?></p><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($dashEvents): ?>
        <section style="margin:0 0 20px;" aria-label="Upcoming Events">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h2 style="margin:0;font-size:1rem;font-weight:700;">📅 Upcoming Events</h2>
                <a href="events.php" style="font-size:.82rem;color:#2563eb;font-weight:700;text-decoration:none;">View all →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
            <?php foreach ($dashEvents as $ev): ?>
            <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" style="display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;">
                <?php if ($ev['featured_image']): ?>
                    <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover;">
                <?php else: ?>
                    <div style="aspect-ratio:16/9;background:linear-gradient(135deg,#1e3a5f,#0f2040);display:flex;align-items:center;justify-content:center;font-size:1.8rem;">📅</div>
                <?php endif; ?>
                <div style="padding:10px 12px 12px;">
                    <p style="margin:0 0 3px;font-weight:700;font-size:.88rem;"><?php echo sanitize($ev['title']); ?></p>
                    <p style="margin:0;font-size:.75rem;color:var(--muted,#6b7280);">📅 <?php echo date('d M Y', strtotime($ev['start_date'])); ?><?php if ($ev['venue']): ?> · <?php echo sanitize(mb_substr($ev['venue'],0,30)); ?><?php endif; ?></p>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($dashFunerals): ?>
        <section style="margin:0 0 20px;" aria-label="Funeral Announcements">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h2 style="margin:0;font-size:1rem;font-weight:700;">🕊️ Funeral Announcements</h2>
                <a href="funerals.php" style="font-size:.82rem;color:var(--muted,#6b7280);font-weight:700;text-decoration:none;">View all →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
            <?php foreach ($dashFunerals as $fa): ?>
            <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" style="display:flex;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;padding:12px;align-items:center;">
                <div style="width:44px;height:44px;border-radius:10px;background:#f3f4f6;flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                    <?php if ($fa['photograph']): ?>
                        <img src="<?php echo sanitize($fa['photograph']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <span style="font-size:1rem;font-weight:900;color:#d1d5db;"><?php echo sanitize(mb_strtoupper(mb_substr($fa['deceased_name'],0,2))); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <p style="margin:0 0 3px;font-weight:700;font-size:.88rem;"><?php echo sanitize($fa['deceased_name']); ?></p>
                    <?php if ($fa['burial_date']): ?><p style="margin:0;font-size:.75rem;color:var(--muted,#6b7280);">⚰️ <?php echo date('d M Y', strtotime($fa['burial_date'])); ?></p><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (is_worker()): ?>
            <?php if (!empty($drafts)): ?>
            <section class="panel" style="margin-bottom:12px;" id="drafts-section">
                <div class="panel-header" style="margin-bottom:8px;">
                    <h2 style="font-size:1rem;margin:0;">My Job Drafts <span id="drafts-count" style="background:var(--text-muted);color:#fff;border-radius:10px;padding:1px 8px;font-size:0.75rem;font-weight:600;"><?php echo count($drafts); ?></span></h2>
                    <a href="request.php" class="button button-small button-secondary">New job</a>
                </div>
                <div class="jobs-grid" id="drafts-grid">
                    <?php foreach ($drafts as $dr): ?>
                    <div class="job-card" data-draft-id="<?php echo (int)$dr['id']; ?>" style="border-left:4px solid var(--text-muted);opacity:0.9;">
                        <div class="job-card-header">
                            <span class="category-badge"><?php echo sanitize($dr['category_name']); ?></span>
                            <span class="status" style="background:var(--text-muted);color:#fff;">DRAFT</span>
                        </div>
                        <h3 class="job-title"><?php echo sanitize($dr['title']); ?></h3>
                        <p class="job-description"><?php echo sanitize($dr['description']); ?></p>
                        <?php if ($dr['location']): ?><p class="meta">📍 <?php echo sanitize($dr['location']); ?></p><?php endif; ?>
                        <?php if ($dr['budget']): ?><p class="meta">💰 GH₵ <?php echo sanitize($dr['budget']); ?></p><?php endif; ?>
                        <p class="meta">Last saved: <?php echo date('d M Y', strtotime($dr['updated_at'])); ?></p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                            <a href="request.php?edit=<?php echo (int)$dr['id']; ?>" class="button button-primary button-small">Edit &amp; Publish</a>
                            <button type="button" class="button button-secondary button-small" onclick="deleteDraft(<?php echo (int)$dr['id']; ?>, this)">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <section class="panel" id="open-jobs">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h2><?php echo $availableJobs; ?></h2>
                        <p>Jobs available</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $workerCounts['in_progress']; ?></h2>
                        <p>Jobs in progress</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $workerCounts['completed']; ?></h2>
                        <p>Completed jobs</p>
                    </div>
                    <div class="stat-card">
                        <h2>GH₵ <?php echo number_format($workerEarnings, 2); ?></h2>
                        <p>Paid earnings</p>
                    </div>
                    <?php if ($maxDailyApps > 0): ?>
                    <div class="stat-card" style="<?php echo $todayAppCount >= $maxDailyApps ? 'border-color:#ef4444;' : ($todayAppCount >= $maxDailyApps - 1 ? 'border-color:#f59e0b;' : ''); ?>">
                        <h2 style="<?php echo $todayAppCount >= $maxDailyApps ? 'color:#ef4444;' : ''; ?>"><?php echo $todayAppCount; ?>/<?php echo $maxDailyApps; ?></h2>
                        <p>Applications today</p>
                    </div>
                    <?php else: ?>
                    <div class="stat-card">
                        <h2><?php echo $todayAppCount; ?></h2>
                        <p>Applications today</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="panel-header">
                    <h1>Jobs for you</h1>
                    <form method="get" class="filter-form">
                        <select name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="job_type">
                            <option value="">Any type</option>
                            <option value="on_site" <?php echo $jobTypeFilter === 'on_site' ? 'selected' : ''; ?>>On-site</option>
                            <option value="remote"  <?php echo $jobTypeFilter === 'remote'  ? 'selected' : ''; ?>>Remote</option>
                            <option value="hybrid"  <?php echo $jobTypeFilter === 'hybrid'  ? 'selected' : ''; ?>>Hybrid</option>
                        </select>
                        <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search jobs" />
                        <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                        <button type="submit" class="button button-primary">Filter</button>
                    </form>
                </div>
                <?php if ($openJobs): ?>
                    <p class="small-note" style="text-align: left; margin-bottom: 14px;">🎯 Open jobs are ranked by how well they match your skills, location, and recent activity.</p>
                <?php endif; ?>
                <?php $displayJobs = array_merge($openJobs, $myJobs); ?>
                <?php if (!$displayJobs): ?>
                    <div class="empty-state">No jobs match your filters.</div>
                <?php else: ?>
                    <div class="jobs-grid">
                    <?php foreach ($displayJobs as $request): ?>
                        <?php $jobDistance = $request['match_distance_km'] ?? distance_km($profile['latitude'] ?? null, $profile['longitude'] ?? null, $request['latitude'], $request['longitude']); ?>
                        <?php
                            $wJt = $request['job_type'] ?? 'on_site';
                            $wJtLabel = ['on_site' => '📍 On-site', 'remote' => '💻 Remote', 'hybrid' => '🔄 Hybrid'];
                            $wJtClass = ['on_site' => 'on-site', 'remote' => 'remote', 'hybrid' => 'hybrid'];
                        ?>
                        <article class="request-card">
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if (!empty($request['featured']) && (empty($request['featured_end_date']) || $request['featured_end_date'] >= date('Y-m-d'))): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <p class="meta">
                                        <?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?>
                                        <?php if ($jobDistance !== null): ?>
                                            • <?php echo sanitize(format_distance($jobDistance)); ?>
                                        <?php endif; ?>
                                        • <?php echo time_ago($request['created_at']); ?>
                                    </p>
                                    <?php if (isset($request['match_score'])): ?>
                                        <p class="meta match-meta">🎯 <?php echo (int)$request['match_score']; ?>% match for you<?php if (!empty($request['match_reasons'])): ?> — <?php echo sanitize(implode(' • ', $request['match_reasons'])); ?><?php endif; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                                    <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                                    <span class="job-type-badge <?php echo $wJtClass[$wJt]; ?>"><?php echo $wJtLabel[$wJt]; ?></span>
                                </div>
                            </div>
                            <p><?php echo sanitize($request['description']); ?></p>
                            <p class="meta" style="margin: 0;">GH₵ <?php echo sanitize($request['budget']); ?></p>
                            <?php $myAppStatus = $myApplicationStatuses[$request['id']] ?? null; ?>
                            <div class="card-bottom">
                                <?php if (in_array($request['status'], ['open','partially_staffed'], true)): ?>
                                    <div class="card-mid-actions">
                                        <?php if ($myAppStatus === 'pending'): ?>
                                            <span class="card-status-badge pending">⏳ Application pending review</span>
                                        <?php elseif ($myAppStatus === 'approved'): ?>
                                            <span class="card-status-badge approved">✓ Your application was approved</span>
                                        <?php elseif ($myAppStatus === 'rejected'): ?>
                                            <span class="card-status-badge rejected">✗ Application not selected</span>
                                        <?php else: ?>
                                            <form method="post" action="apply_job.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                                <button type="submit" class="button button-primary">Apply for this job</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (in_array($request['status'], ['in_progress','fully_staffed'], true) && $request['assigned_worker_id'] === $user['id']): ?>
                                    <div class="card-mid-actions">
                                        <form method="post" action="complete_job.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                            <button type="submit" class="button button-secondary">✓ Mark completed</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <div class="card-actions">
                                    <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/jobs.php'); ?>" target="_blank" class="button">Share WhatsApp</a>
                                    <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button">Details</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="panel">
                <h2>Your worker profile</h2>
                <p>Availability: <?php echo sanitize($profile['availability']); ?></p>
                <p>Location: <?php echo sanitize($profile['location'] ?: 'Not set'); ?></p>
            </section>
        <?php else: ?>
            <?php if (!empty($drafts)): ?>
            <section class="panel" style="margin-bottom:16px;" id="drafts-section">
                <div class="panel-header" style="margin-bottom:8px;">
                    <h2 style="font-size:1rem;margin:0;">Drafts <span id="drafts-count" style="background:var(--text-muted);color:#fff;border-radius:10px;padding:1px 8px;font-size:0.75rem;font-weight:600;"><?php echo count($drafts); ?></span></h2>
                    <a href="request.php" class="button button-small button-secondary">New job</a>
                </div>
                <div class="jobs-grid" id="drafts-grid">
                    <?php foreach ($drafts as $dr): ?>
                    <div class="job-card" data-draft-id="<?php echo (int)$dr['id']; ?>" style="border-left:4px solid var(--text-muted);opacity:0.9;">
                        <div class="job-card-header">
                            <span class="category-badge"><?php echo sanitize($dr['category_name']); ?></span>
                            <span class="status" style="background:var(--text-muted);color:#fff;">DRAFT</span>
                        </div>
                        <h3 class="job-title"><?php echo sanitize($dr['title']); ?></h3>
                        <p class="job-description"><?php echo sanitize($dr['description']); ?></p>
                        <?php if ($dr['location']): ?>
                        <p class="meta">📍 <?php echo sanitize($dr['location']); ?></p>
                        <?php endif; ?>
                        <?php if ($dr['budget']): ?>
                        <p class="meta">💰 GH₵ <?php echo sanitize($dr['budget']); ?></p>
                        <?php endif; ?>
                        <p class="meta">Last saved: <?php echo date('d M Y', strtotime($dr['updated_at'])); ?></p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                            <a href="request.php?edit=<?php echo (int)$dr['id']; ?>" class="button button-primary button-small">Edit &amp; Publish</a>
                            <button type="button" class="button button-secondary button-small" onclick="deleteDraft(<?php echo (int)$dr['id']; ?>, this)">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <section class="panel">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['pending']; ?></h2>
                        <p>Pending approval</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['open']; ?></h2>
                        <p>Open requests</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['in_progress']; ?></h2>
                        <p>In progress</p>
                    </div>
                    <div class="stat-card">
                        <h2>GH₵ <?php echo number_format($customerSpent, 2); ?></h2>
                        <p>Paid amount</p>
                    </div>
                </div>
                <div class="panel-header">
                    <h1>Your service requests</h1>
                    <div style="display:flex;gap:8px;">
                        <a href="manage_applicants.php" class="button button-secondary">👥 Applicants</a>
                        <a href="request.php" class="button button-primary">Create request</a>
                    </div>
                </div>
                <form method="get" class="filter-form">
                    <select name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">All statuses</option>
                        <?php foreach (['pending', 'open', 'partially_staffed', 'fully_staffed', 'in_progress', 'completed', 'cancelled'] as $statusOption): ?>
                            <option value="<?php echo $statusOption; ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>><?php echo strtoupper(str_replace('_', ' ', $statusOption)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="job_type">
                        <option value="">Any type</option>
                        <option value="on_site" <?php echo $jobTypeFilter === 'on_site' ? 'selected' : ''; ?>>On-site</option>
                        <option value="remote"  <?php echo $jobTypeFilter === 'remote'  ? 'selected' : ''; ?>>Remote</option>
                        <option value="hybrid"  <?php echo $jobTypeFilter === 'hybrid'  ? 'selected' : ''; ?>>Hybrid</option>
                    </select>
                    <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search requests" />
                    <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                    <button type="submit" class="button button-primary">Filter</button>
                </form>
                <?php if (!$requests): ?>
                    <div class="empty-state">You have no service requests yet.</div>
                <?php else: ?>
                    <div class="jobs-grid">
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $cJt = $request['job_type'] ?? 'on_site';
                            $cJtLabel = ['on_site' => '📍 On-site', 'remote' => '💻 Remote', 'hybrid' => '🔄 Hybrid'];
                            $cJtClass = ['on_site' => 'on-site', 'remote' => 'remote', 'hybrid' => 'hybrid'];
                        ?>
                        <article class="request-card">
                            <?php $feeStatus = $request['posting_fee_status'] ?? 'free'; ?>
                            <?php if ($feeStatus === 'pending'): ?>
                                <div class="alert alert-warning" style="margin-bottom:8px;font-size:0.9rem;">
                                    💳 <strong>Posting fee pending</strong> — your job is not yet visible until the fee is confirmed.
                                    <a href="pay_job_post.php?id=<?php echo $request['id']; ?>" style="color:var(--primary);margin-left:4px;">Pay now →</a>
                                </div>
                            <?php endif; ?>
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if (!empty($request['featured']) && (empty($request['featured_end_date']) || $request['featured_end_date'] >= date('Y-m-d'))): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?> · <?php echo time_ago($request['created_at']); ?></p>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                                    <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                                    <span class="job-type-badge <?php echo $cJtClass[$cJt]; ?>"><?php echo $cJtLabel[$cJt]; ?></span>
                                </div>
                            </div>
                            <p><?php echo sanitize($request['description']); ?></p>
                            <p class="meta" style="margin: 0;">
                                GH₵ <?php echo sanitize($request['budget']); ?>
                                <?php if (($request['workers_needed'] ?? 1) > 1 || ($request['workers_approved'] ?? 0) > 0): ?>
                                    · <?php echo (int)($request['workers_approved'] ?? 0); ?>/<?php echo (int)($request['workers_needed'] ?? 1); ?> hired
                                <?php elseif ($request['assigned_worker_name']): ?>
                                    · <?php echo sanitize($request['assigned_worker_name']); ?>
                                <?php endif; ?>
                                · <?php echo strtoupper($request['payment_status']); ?>
                            </p>
                            <?php
                                $cFeatEnd    = $request['featured_end_date'] ?? null;
                                $cFeatActive = !empty($request['featured']) && (empty($cFeatEnd) || $cFeatEnd >= date('Y-m-d'));
                                $cRenewSoon  = !empty($request['featured']) && !empty($cFeatEnd) && $cFeatEnd < date('Y-m-d', strtotime('+7 days'));
                                $hasActions  = !in_array($request['status'], ['pending','cancelled'], true)
                                            || (in_array($request['status'], ['pending','open'], true) && (!$cFeatActive || $cRenewSoon))
                                            || $request['status'] === 'completed';
                            ?>
                            <div class="card-bottom">
                                <?php if ($hasActions): ?>
                                <div class="card-mid-actions">
                                    <?php if (!in_array($request['status'], ['pending','cancelled'], true)): ?>
                                        <a href="manage_applicants.php?id=<?php echo $request['id']; ?>" class="button button-primary">👥 Manage Applicants</a>
                                    <?php endif; ?>
                                    <?php if (in_array($request['status'], ['pending','open'], true) && (!$cFeatActive || $cRenewSoon)): ?>
                                        <a href="feature_job.php?id=<?php echo $request['id']; ?>" class="button button-secondary"><?php echo $cRenewSoon ? '⭐ Renew feature' : '⭐ Feature job'; ?></a>
                                    <?php endif; ?>
                                    <?php if ($request['status'] === 'completed'): ?>
                                        <?php if ($request['rating_score'] === null && $request['assigned_worker_id']): ?>
                                            <a href="rate_job.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary">⭐ Rate worker</a>
                                        <?php elseif ($request['rating_score'] !== null): ?>
                                            <span class="card-status-badge approved">⭐ Rated <?php echo sanitize($request['rating_score']); ?>/5</span>
                                        <?php endif; ?>
                                        <form method="post" action="toggle_payment.php" class="inline-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                            <input type="hidden" name="current_status" value="<?php echo sanitize($request['payment_status']); ?>" />
                                            <button type="submit" class="button button-secondary">
                                                Mark as <?php echo $request['payment_status'] === 'paid' ? 'Unpaid' : 'Paid'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="card-actions">
                                    <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/jobs.php'); ?>" target="_blank" class="button">Share WhatsApp</a>
                                    <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button">Details</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php if (!empty($browseJobs)): ?>
            <section class="panel">
                <div class="panel-header">
                    <h1>Browse Available Jobs</h1>
                </div>
                <p class="meta" style="margin-bottom: 14px;">Want to earn on AkuapemConnect? <a href="become_worker.php" style="color:var(--primary);">Switch your profile to Worker</a> to apply for jobs.</p>
                <div class="jobs-grid">
                <?php foreach ($browseJobs as $job): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2><?php echo sanitize($job['title']); ?></h2>
                                <p class="meta"><?php echo sanitize($job['category_name']); ?> · <?php echo sanitize($job['location']); ?> · <?php echo time_ago($job['created_at']); ?></p>
                            </div>
                            <span class="status status-<?php echo sanitize($job['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $job['status'])); ?></span>
                        </div>
                        <p><?php echo sanitize($job['description']); ?></p>
                        <p class="meta" style="margin: 0;">GH₵ <?php echo sanitize($job['budget']); ?></p>
                        <div class="card-bottom">
                            <div class="card-mid-actions">
                                <span class="card-status-badge info">To apply for jobs, switch your profile to Worker.</span>
                                <a href="become_worker.php" class="button button-secondary" style="font-size:0.85rem;">Become a Worker →</a>
                            </div>
                            <div class="card-actions">
                                <a href="<?php echo whatsapp_share_link($job['title'], $job['location'], $job['budget'], BASE_URL . '/jobs.php'); ?>" target="_blank" class="button">Share WhatsApp</a>
                                <a href="request_detail.php?id=<?php echo $job['id']; ?>" class="button">Details</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        <?php endif; ?>


    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <script>
        var toggleCategories = document.getElementById('toggle-categories');
        if (toggleCategories) {
            toggleCategories.addEventListener('click', function () {
                var expanded = this.dataset.expanded === '1';
                document.querySelectorAll('.category-extra').forEach(function (el) {
                    el.hidden = expanded;
                });
                this.textContent = expanded ? 'View all categories' : 'Show fewer categories';
                this.dataset.expanded = expanded ? '0' : '1';
            });
        }

        function deleteDraft(id, btn) {
            if (!confirm('Delete this draft?')) return;
            btn.disabled = true;
            fetch('ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_draft&id=' + id + '&csrf_token=' + encodeURIComponent(CSRF)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { btn.disabled = false; return; }
                var card = document.querySelector('[data-draft-id="' + id + '"]');
                if (card) card.remove();
                var countEl = document.getElementById('drafts-count');
                if (countEl) {
                    var n = parseInt(countEl.textContent, 10) - 1;
                    if (n <= 0) {
                        var section = document.getElementById('drafts-section');
                        if (section) section.remove();
                    } else {
                        countEl.textContent = n;
                    }
                }
            })
            .catch(function () { btn.disabled = false; });
        }
    </script>
</body>
</html>

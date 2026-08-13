<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin() && !is_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('view_reports');

$tab  = $_GET['tab'] ?? 'overview';
$user = current_user();

// ── POST: save_charges ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'save_charges') {
        $newRate    = min(50, max(0, round((float)($_POST['escrow_commission_rate'] ?? DEFAULT_COMMISSION), 2)));
        $newRelease = min(90, max(1, (int)($_POST['escrow_auto_release_days'] ?? 7)));
        $newMin     = max(0, round((float)($_POST['escrow_min_budget'] ?? 0), 2));
        set_platform_setting('escrow_commission_rate', $newRate);
        set_platform_setting('escrow_auto_release_days', $newRelease);
        set_platform_setting('escrow_min_budget', $newMin);
        log_audit_action($user['id'], 'system_charges_updated', "Commission {$newRate}%, auto-release {$newRelease}d, min GH₵{$newMin}");
        flash('Charges updated.', 'success');
        header('Location: payments.php?tab=charges'); exit;
    }
}

// ── Charge settings ───────────────────────────────────────────────────────────
$commRate   = (float)get_platform_setting('escrow_commission_rate', DEFAULT_COMMISSION);
$autoRelease= max(1, (int)get_platform_setting('escrow_auto_release_days', 7));
$minBudget  = (float)get_platform_setting('escrow_min_budget', 0);

// ── Core stats ────────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END), 0)                                                 AS total_revenue,
    COALESCE(SUM(CASE WHEN status='paid' AND DATE(paid_at)=CURDATE() THEN amount ELSE 0 END), 0)                    AS today_revenue,
    COALESCE(SUM(CASE WHEN status='paid' AND YEAR(paid_at)=YEAR(NOW()) AND MONTH(paid_at)=MONTH(NOW()) THEN amount ELSE 0 END), 0) AS month_revenue,
    COALESCE(SUM(CASE WHEN status='paid' AND paid_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN amount ELSE 0 END), 0)    AS week_revenue,
    COUNT(CASE WHEN status='paid'      THEN 1 END) AS paid_count,
    COUNT(CASE WHEN status='pending'   THEN 1 END) AS pending_count,
    COUNT(CASE WHEN status='failed'    THEN 1 END) AS failed_count,
    COUNT(CASE WHEN status='abandoned' THEN 1 END) AS abandoned_count,
    COUNT(CASE WHEN status='refunded'  THEN 1 END) AS refunded_count,
    COUNT(CASE WHEN flagged=1          THEN 1 END) AS flagged_count,
    COUNT(*) AS total_count
FROM platform_payments")->fetch();

$escrow = $pdo->query("SELECT
    COUNT(CASE WHEN status='held'     THEN 1 END) AS held_count,
    COUNT(CASE WHEN status='released' THEN 1 END) AS released_count,
    COALESCE(SUM(CASE WHEN status IN ('held','released') THEN commission_amount ELSE 0 END), 0) AS commission_earned,
    COALESCE(SUM(CASE WHEN status='held' THEN gross_amount ELSE 0 END), 0) AS held_value
FROM escrow_payments")->fetch();

// Week-over-week trend
$prevWeek = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE status='paid' AND paid_at BETWEEN DATE_SUB(NOW(),INTERVAL 14 DAY) AND DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$thisWeek = (float)($stats['week_revenue'] ?? 0);
$weekTrend = $prevWeek > 0 ? round(($thisWeek - $prevWeek) / $prevWeek * 100, 1) : null;

// ── Charts ────────────────────────────────────────────────────────────────────
$chartRows = $pdo->query("SELECT DATE(paid_at) AS day, SUM(amount) AS revenue, COUNT(*) AS cnt
    FROM platform_payments WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(paid_at)")->fetchAll(PDO::FETCH_ASSOC);
$chartMap = [];
foreach ($chartRows as $r) $chartMap[$r['day']] = (float)$r['revenue'];
$chartLabels = []; $chartValues = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('j M', strtotime("-{$i} days"));
    $chartValues[] = $chartMap[$day] ?? 0;
}

$monthRows = $pdo->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS mo, SUM(amount) AS revenue
    FROM platform_payments WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mo ORDER BY mo")->fetchAll(PDO::FETCH_ASSOC);
$monthMap = [];
foreach ($monthRows as $r) $monthMap[$r['mo']] = (float)$r['revenue'];
$monthLabels = []; $monthValues = [];
for ($i = 11; $i >= 0; $i--) {
    $mo = date('Y-m', strtotime("-{$i} months"));
    $monthLabels[] = date('M Y', strtotime("-{$i} months"));
    $monthValues[] = $monthMap[$mo] ?? 0;
}

// ── Revenue by type ───────────────────────────────────────────────────────────
$byType = $pdo->query("SELECT payment_type, COUNT(*) AS cnt, SUM(amount) AS revenue
    FROM platform_payments WHERE status='paid'
    GROUP BY payment_type ORDER BY revenue DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// ── Top payers ────────────────────────────────────────────────────────────────
$topUsers = $pdo->query("SELECT u.id, u.name, u.email, u.role,
    COUNT(pp.id) AS tx_count, SUM(pp.amount) AS total_spent
    FROM platform_payments pp JOIN users u ON u.id=pp.user_id
    WHERE pp.status='paid' GROUP BY pp.user_id ORDER BY total_spent DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent transactions ───────────────────────────────────────────────────────
$recentTx = $pdo->query("SELECT pp.id, pp.amount, pp.status, pp.payment_type, pp.reference_code, pp.created_at, pp.paid_at,
    u.name AS user_name, u.email AS user_email
    FROM platform_payments pp JOIN users u ON u.id=pp.user_id
    ORDER BY pp.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// ── Donut data (health) ───────────────────────────────────────────────────────
$healthData = [
    (int)($stats['paid_count']     ?? 0),
    (int)($stats['pending_count']  ?? 0),
    (int)($stats['failed_count']   ?? 0) + (int)($stats['abandoned_count'] ?? 0),
    (int)($stats['refunded_count'] ?? 0),
];

// ── Conversion ────────────────────────────────────────────────────────────────
$totalUsers     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('admin')")->fetchColumn();
$convertedUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM platform_payments WHERE status='paid'")->fetchColumn();

$typeLabels = [
    'featured_job'       => 'Featured Job',
    'featured_worker'    => 'Featured Profile',
    'verification'       => 'Verification',
    'job_post'           => 'Job Posting',
    'worker_service'     => 'Worker Service',
    'escrow_payment'     => 'Escrow',
    'delivery_fee'       => 'Delivery',
    'marketplace_product'=> 'Marketplace',
    'event_fee'          => 'Event',
    'funeral_fee'        => 'Funeral',
    'news_fee'           => 'News',
    'subscription'       => 'Subscription',
    'boost'              => 'Boost',
    'sponsored'          => 'Sponsored',
    'quick_service'      => 'Quick Service',
];
$typeIcons = [
    'featured_job'=>'💼','featured_worker'=>'👤','verification'=>'✅','job_post'=>'📋',
    'worker_service'=>'🔧','escrow_payment'=>'🔒','delivery_fee'=>'🚚','marketplace_product'=>'🛍️',
    'event_fee'=>'📅','funeral_fee'=>'🕊️','news_fee'=>'📰','subscription'=>'⭐','boost'=>'🚀','sponsored'=>'📣',
    'quick_service'=>'⚡',
];
$totalRev = (float)($stats['total_revenue'] ?? 0);

$statusConfig = [
    'paid'      => ['#10b981','Paid'],
    'pending'   => ['#f59e0b','Pending'],
    'failed'    => ['#ef4444','Failed'],
    'abandoned' => ['#6b7280','Abandoned'],
    'refunded'  => ['#8b5cf6','Refunded'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments — AkuapemConnect Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── Layout ─────────────────────────────────────────────────────────── */
        .pay-wrap { max-width:1140px; margin:0 auto; padding:0 16px 64px; }

        /* ── Hero ───────────────────────────────────────────────────────────── */
        .pay-hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 20px;
            padding: 28px 28px 24px;
            margin: 20px 0 24px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .pay-hero::before {
            content:'';
            position:absolute;
            right:-60px; top:-60px;
            width:280px; height:280px;
            border-radius:50%;
            background:rgba(255,255,255,.07);
        }
        .pay-hero::after {
            content:'';
            position:absolute;
            right:60px; bottom:-80px;
            width:200px; height:200px;
            border-radius:50%;
            background:rgba(255,255,255,.05);
        }
        .pay-hero-label { font-size:.76rem; font-weight:700; opacity:.7; text-transform:uppercase; letter-spacing:.1em; margin:0 0 6px; }
        .pay-hero-amount { font-size:2.6rem; font-weight:900; line-height:1; margin:0 0 4px; letter-spacing:-.03em; }
        .pay-hero-sub { font-size:.84rem; opacity:.75; margin:0; }
        .pay-hero-kpis { display:flex; gap:20px; flex-wrap:wrap; margin-top:22px; }
        .pay-hero-kpi { min-width:100px; }
        .pay-hero-kpi strong { display:block; font-size:1.2rem; font-weight:900; }
        .pay-hero-kpi span { font-size:.72rem; opacity:.72; }
        .pay-trend { display:inline-flex; align-items:center; gap:3px; font-size:.78rem; font-weight:700;
                     padding:2px 8px; border-radius:20px; margin-left:8px; vertical-align:middle; }
        .pay-trend.up   { background:rgba(16,185,129,.22); color:#d1fae5; }
        .pay-trend.down { background:rgba(239,68,68,.22); color:#fee2e2; }
        .pay-trend.flat { background:rgba(255,255,255,.15); color:#fff; }

        /* ── Tabs ───────────────────────────────────────────────────────────── */
        .pay-tabs { display:flex; gap:4px; background:var(--surface-muted); border-radius:12px; padding:4px; margin-bottom:24px; width:fit-content; }
        .pay-tab  { padding:8px 20px; border-radius:9px; font-size:.86rem; font-weight:700; text-decoration:none;
                    color:var(--text); transition:background .15s,color .15s; white-space:nowrap; }
        .pay-tab.active { background:var(--surface); color:var(--primary); box-shadow:0 2px 8px rgba(0,0,0,.08); }

        /* ── Stat grid ──────────────────────────────────────────────────────── */
        .pay-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
        .pay-stat  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px;
                     position:relative; overflow:hidden; }
        .pay-stat-icon { position:absolute; right:12px; top:12px; font-size:1.4rem; opacity:.15; }
        .pay-stat-val  { font-size:1.4rem; font-weight:900; letter-spacing:-.02em; margin:0 0 3px; line-height:1.1; }
        .pay-stat-lbl  { font-size:.74rem; color:var(--muted); margin:0; font-weight:600; }
        .pay-stat-bar  { position:absolute; bottom:0; left:0; height:3px; background:var(--primary); border-radius:0 2px 0 14px; }

        /* ── Chart card ─────────────────────────────────────────────────────── */
        .pay-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:20px; }
        .pay-card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; gap:12px; flex-wrap:wrap; }
        .pay-card-title { font-size:.94rem; font-weight:800; margin:0; }
        .pay-card-sub   { font-size:.76rem; color:var(--muted); margin:2px 0 0; }

        /* ── Toggle pills ───────────────────────────────────────────────────── */
        .pay-toggle { display:flex; gap:4px; background:var(--surface-muted); border-radius:8px; padding:3px; }
        .pay-toggle button { padding:5px 14px; border-radius:6px; font-size:.78rem; font-weight:700;
                              border:none; cursor:pointer; background:transparent; color:var(--muted); }
        .pay-toggle button.active { background:var(--surface); color:var(--primary); box-shadow:0 1px 4px rgba(0,0,0,.1); }

        /* ── Type bars ──────────────────────────────────────────────────────── */
        .type-row { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); }
        .type-row:last-child { border-bottom:none; }
        .type-icon { font-size:1.1rem; width:28px; text-align:center; flex-shrink:0; }
        .type-name { font-size:.84rem; font-weight:700; flex:1; min-width:0; }
        .type-bar-wrap { width:120px; flex-shrink:0; height:6px; background:var(--surface-muted); border-radius:4px; overflow:hidden; }
        .type-bar  { height:100%; background:var(--primary); border-radius:4px; }
        .type-amt  { font-size:.82rem; font-weight:800; color:var(--primary); min-width:90px; text-align:right; flex-shrink:0; }
        .type-cnt  { font-size:.72rem; color:var(--muted); min-width:32px; text-align:right; flex-shrink:0; }

        /* ── Tx table ───────────────────────────────────────────────────────── */
        .tx-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .tx-table th { text-align:left; padding:9px 12px; border-bottom:2px solid var(--border);
                       font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
                       background:var(--surface-muted); }
        .tx-table th:first-child { border-radius:10px 0 0 0; }
        .tx-table th:last-child  { border-radius:0 10px 0 0; }
        .tx-table td { padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .tx-table tr:last-child td { border-bottom:none; }
        .tx-table tbody tr:hover td { background:var(--surface-muted); }

        /* ── Status pills ───────────────────────────────────────────────────── */
        .pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .pill-paid      { background:#d1fae5; color:#065f46; }
        .pill-pending   { background:#fef3c7; color:#92400e; }
        .pill-failed    { background:#fee2e2; color:#991b1b; }
        .pill-abandoned { background:#f3f4f6; color:#4b5563; }
        .pill-refunded  { background:#ede9fe; color:#5b21b6; }

        /* ── Top user row ───────────────────────────────────────────────────── */
        .user-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--border); }
        .user-row:last-child { border-bottom:none; }
        .user-av  { width:36px; height:36px; border-radius:50%; background:var(--primary-soft);
                    display:flex; align-items:center; justify-content:center; font-weight:800;
                    color:var(--primary-dark); font-size:.88rem; flex-shrink:0; }
        .user-name { font-weight:700; font-size:.86rem; }
        .user-meta { font-size:.72rem; color:var(--muted); }
        .user-amt  { margin-left:auto; font-weight:900; color:var(--primary); font-size:.9rem; white-space:nowrap; }
        .user-cnt  { font-size:.72rem; color:var(--muted); text-align:right; }

        /* ── Health donut grid ──────────────────────────────────────────────── */
        .health-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .health-item { display:flex; align-items:center; gap:8px; padding:8px; border-radius:10px; background:var(--surface-muted); }
        .health-dot  { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .health-num  { font-size:1.1rem; font-weight:900; line-height:1; }
        .health-lbl  { font-size:.68rem; color:var(--muted); font-weight:600; }

        /* ── Charges form ───────────────────────────────────────────────────── */
        .charge-field { display:flex; flex-direction:column; gap:6px; margin-bottom:20px; }
        .charge-field label { font-weight:700; font-size:.86rem; }
        .charge-field .hint { font-size:.76rem; color:var(--muted); }
        .charge-input-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .charge-input-row input[type=number] {
            width:110px; font-size:1.1rem; font-weight:700; padding:10px 14px;
            border:2px solid var(--border); border-radius:10px;
            background:var(--surface); color:var(--text);
        }
        .charge-input-row input:focus { outline:none; border-color:var(--primary); }
        .charge-preview { background:var(--primary-soft); border-radius:10px; padding:10px 14px;
                           font-size:.84rem; color:var(--primary-dark); font-weight:600; margin-top:6px; }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        .pay-two-col { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media(max-width:680px) {
            .pay-hero-amount { font-size:2rem; }
            .pay-two-col { grid-template-columns:1fr; }
            .pay-stats { grid-template-columns:repeat(2,1fr); }
            .pay-hero-kpis { gap:14px; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">Payments</h1>
    <a href="transactions.php" class="button button-small" style="background:var(--primary);color:#fff;border:none;">All Transactions →</a>
</header>

<main class="pay-wrap">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>" style="margin-top:16px;"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <?php if ((int)($stats['flagged_count'] ?? 0) > 0): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:12px;padding:10px 16px;margin-top:16px;display:flex;align-items:center;gap:10px;font-size:.86rem;">
        <span style="font-size:1.2rem;">⚑</span>
        <span><strong><?php echo (int)$stats['flagged_count']; ?> flagged transaction<?php echo $stats['flagged_count']!=1?'s':''; ?></strong> need review.</span>
        <a href="transactions.php?flagged=1" style="margin-left:auto;color:var(--primary);font-weight:700;">Review →</a>
    </div>
    <?php endif; ?>

    <!-- ── Hero ──────────────────────────────────────────────────────────────── -->
    <div class="pay-hero">
        <p class="pay-hero-label">Total Revenue</p>
        <p class="pay-hero-amount">GH₵ <?php echo number_format((float)$stats['total_revenue'], 2); ?>
            <?php if ($weekTrend !== null): ?>
            <span class="pay-trend <?php echo $weekTrend > 0 ? 'up' : ($weekTrend < 0 ? 'down' : 'flat'); ?>">
                <?php echo $weekTrend > 0 ? '↑' : ($weekTrend < 0 ? '↓' : '→'); ?> <?php echo abs($weekTrend); ?>% this week
            </span>
            <?php endif; ?>
        </p>
        <p class="pay-hero-sub">All-time platform payments &nbsp;·&nbsp; <?php echo (int)$stats['paid_count']; ?> completed transactions</p>
        <div class="pay-hero-kpis">
            <div class="pay-hero-kpi">
                <strong>GH₵ <?php echo number_format((float)$stats['today_revenue'], 2); ?></strong>
                <span>Today</span>
            </div>
            <div class="pay-hero-kpi">
                <strong>GH₵ <?php echo number_format((float)$stats['month_revenue'], 2); ?></strong>
                <span>This Month</span>
            </div>
            <div class="pay-hero-kpi">
                <strong>GH₵ <?php echo number_format((float)$stats['week_revenue'], 2); ?></strong>
                <span>This Week</span>
            </div>
            <div class="pay-hero-kpi">
                <strong>GH₵ <?php echo number_format((float)($escrow['held_value'] ?? 0), 2); ?></strong>
                <span>In Escrow</span>
            </div>
            <div class="pay-hero-kpi">
                <strong>GH₵ <?php echo number_format((float)($escrow['commission_earned'] ?? 0), 2); ?></strong>
                <span>Commission</span>
            </div>
        </div>
    </div>

    <!-- ── Tabs ──────────────────────────────────────────────────────────────── -->
    <div class="pay-tabs">
        <a href="payments.php?tab=overview" class="pay-tab <?php echo $tab==='overview'?'active':''; ?>">Overview</a>
        <a href="transactions.php"          class="pay-tab">Transactions</a>
        <a href="escrow.php"                class="pay-tab">Escrow</a>
        <a href="payments.php?tab=charges"  class="pay-tab <?php echo $tab==='charges'?'active':''; ?>">Charges</a>
        <a href="monetization.php"          class="pay-tab">Packages</a>
    </div>

    <!-- ═══════════════════════ OVERVIEW ════════════════════════════════════════ -->
    <?php if ($tab === 'overview'): ?>

    <!-- Stat row -->
    <div class="pay-stats">
        <div class="pay-stat">
            <div class="pay-stat-icon">✅</div>
            <p class="pay-stat-val" style="color:#10b981;"><?php echo (int)$stats['paid_count']; ?></p>
            <p class="pay-stat-lbl">Paid</p>
            <div class="pay-stat-bar" style="width:<?php echo $stats['total_count']>0?min(100,round($stats['paid_count']/$stats['total_count']*100)):0; ?>%;background:#10b981;"></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon">⏳</div>
            <p class="pay-stat-val" style="color:#f59e0b;"><?php echo (int)$stats['pending_count']; ?></p>
            <p class="pay-stat-lbl">Pending</p>
            <div class="pay-stat-bar" style="width:<?php echo $stats['total_count']>0?min(100,round($stats['pending_count']/$stats['total_count']*100)):0; ?>%;background:#f59e0b;"></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon">✗</div>
            <p class="pay-stat-val" style="color:#ef4444;"><?php echo (int)$stats['failed_count'] + (int)$stats['abandoned_count']; ?></p>
            <p class="pay-stat-lbl">Failed / Abandoned</p>
            <div class="pay-stat-bar" style="background:#ef4444;width:<?php echo $stats['total_count']>0?min(100,round(($stats['failed_count']+$stats['abandoned_count'])/$stats['total_count']*100)):0; ?>%;"></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon">↩</div>
            <p class="pay-stat-val" style="color:#8b5cf6;"><?php echo (int)$stats['refunded_count']; ?></p>
            <p class="pay-stat-lbl">Refunded</p>
            <div class="pay-stat-bar" style="background:#8b5cf6;"></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon">🔒</div>
            <p class="pay-stat-val" style="color:#2563eb;"><?php echo (int)($escrow['held_count'] ?? 0); ?></p>
            <p class="pay-stat-lbl">Active Escrows</p>
            <div class="pay-stat-bar" style="background:#2563eb;width:40%;"></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon">👥</div>
            <p class="pay-stat-val"><?php echo $totalUsers > 0 ? round($convertedUsers/$totalUsers*100,1) : 0; ?>%</p>
            <p class="pay-stat-lbl">Conversion Rate</p>
            <div class="pay-stat-bar" style="width:<?php echo $totalUsers > 0 ? round($convertedUsers/$totalUsers*100,1) : 0; ?>%;"></div>
        </div>
    </div>

    <!-- Revenue chart + Health donut -->
    <div class="pay-two-col">
        <div class="pay-card" style="grid-column:span 1;">
            <div class="pay-card-head">
                <div>
                    <p class="pay-card-title">Revenue</p>
                    <p class="pay-card-sub">GH₵ over time</p>
                </div>
                <div class="pay-toggle">
                    <button id="btn-d" class="active" onclick="switchChart('daily')">30 days</button>
                    <button id="btn-m" onclick="switchChart('monthly')">12 months</button>
                </div>
            </div>
            <canvas id="revenueChart" height="130"></canvas>
        </div>
        <div class="pay-card">
            <div class="pay-card-head">
                <div>
                    <p class="pay-card-title">Transaction Health</p>
                    <p class="pay-card-sub"><?php echo (int)$stats['total_count']; ?> total</p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:16px;">
                <canvas id="donutChart" width="110" height="110" style="flex-shrink:0;"></canvas>
                <div class="health-grid" style="flex:1;">
                    <div class="health-item">
                        <div class="health-dot" style="background:#10b981;"></div>
                        <div><div class="health-num"><?php echo (int)$stats['paid_count']; ?></div><div class="health-lbl">Paid</div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-dot" style="background:#f59e0b;"></div>
                        <div><div class="health-num"><?php echo (int)$stats['pending_count']; ?></div><div class="health-lbl">Pending</div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-dot" style="background:#ef4444;"></div>
                        <div><div class="health-num"><?php echo (int)$stats['failed_count']+(int)$stats['abandoned_count']; ?></div><div class="health-lbl">Failed</div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-dot" style="background:#8b5cf6;"></div>
                        <div><div class="health-num"><?php echo (int)$stats['refunded_count']; ?></div><div class="health-lbl">Refunded</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue by type + Top users -->
    <div class="pay-two-col">
        <!-- Revenue by type -->
        <div class="pay-card">
            <div class="pay-card-head">
                <div>
                    <p class="pay-card-title">Revenue by Service</p>
                    <p class="pay-card-sub">Paid transactions only</p>
                </div>
            </div>
            <?php if ($byType): ?>
            <?php foreach ($byType as $row):
                $pct = $totalRev > 0 ? round($row['revenue']/$totalRev*100,1) : 0;
                $tLabel = $typeLabels[$row['payment_type']] ?? ucwords(str_replace('_',' ',$row['payment_type']));
                $tIcon  = $typeIcons[$row['payment_type']] ?? '💳';
            ?>
            <div class="type-row">
                <div class="type-icon"><?php echo $tIcon; ?></div>
                <div class="type-name"><?php echo sanitize($tLabel); ?></div>
                <div class="type-bar-wrap"><div class="type-bar" style="width:<?php echo $pct; ?>%;"></div></div>
                <div class="type-cnt"><?php echo (int)$row['cnt']; ?></div>
                <div class="type-amt">GH₵ <?php echo number_format((float)$row['revenue'],2); ?></div>
            </div>
            <?php endforeach; ?>
            <?php else: ?><p style="color:var(--muted);font-size:.84rem;margin:0;">No paid transactions yet.</p><?php endif; ?>
        </div>

        <!-- Top paying users -->
        <div class="pay-card">
            <div class="pay-card-head">
                <div>
                    <p class="pay-card-title">Top Payers</p>
                    <p class="pay-card-sub"><?php echo $convertedUsers; ?> paying users of <?php echo $totalUsers; ?></p>
                </div>
                <a href="transactions.php" class="button button-secondary button-small" style="font-size:.74rem;">All →</a>
            </div>
            <?php if ($topUsers): ?>
            <?php foreach (array_slice($topUsers,0,7) as $i => $u): ?>
            <div class="user-row">
                <div style="font-size:.78rem;font-weight:900;color:var(--muted);width:16px;text-align:center;flex-shrink:0;"><?php echo $i+1; ?></div>
                <div class="user-av"><?php echo strtoupper(substr(trim($u['name'])?:$u['email'],0,1)); ?></div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name"><?php echo sanitize($u['name'] ?: '—'); ?></div>
                    <div class="user-meta"><?php echo sanitize($u['email']); ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="user-amt">GH₵ <?php echo number_format((float)$u['total_spent'],2); ?></div>
                    <div class="user-cnt"><?php echo (int)$u['tx_count']; ?> txns</div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?><p style="color:var(--muted);font-size:.84rem;margin:0;">No paying users yet.</p><?php endif; ?>
        </div>
    </div>

    <!-- Recent transactions -->
    <div class="pay-card">
        <div class="pay-card-head">
            <div>
                <p class="pay-card-title">Recent Transactions</p>
                <p class="pay-card-sub">Last 10 payments</p>
            </div>
            <a href="transactions.php" class="button button-secondary button-small" style="font-size:.74rem;">View all →</a>
        </div>
        <?php if ($recentTx): ?>
        <div style="overflow-x:auto;">
        <table class="tx-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentTx as $tx): ?>
            <tr>
                <td>
                    <div style="font-weight:700;font-size:.84rem;"><?php echo sanitize($tx['user_name']); ?></div>
                    <div style="font-size:.72rem;color:var(--muted);"><?php echo sanitize($tx['user_email']); ?></div>
                </td>
                <td style="font-size:.8rem;"><?php echo sanitize($typeLabels[$tx['payment_type']] ?? ucwords(str_replace('_',' ',$tx['payment_type']))); ?></td>
                <td style="font-size:.68rem;color:var(--muted);font-family:monospace;word-break:break-all;"><?php echo sanitize(strtoupper($tx['reference_code']??'—')); ?></td>
                <td style="text-align:right;font-weight:800;font-size:.88rem;">GH₵ <?php echo number_format((float)$tx['amount'],2); ?></td>
                <td><span class="pill pill-<?php echo sanitize($tx['status']); ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                <td style="font-size:.76rem;color:var(--muted);white-space:nowrap;"><?php echo date('d M y, H:i', strtotime($tx['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?><p style="color:var(--muted);font-size:.84rem;margin:0;">No transactions yet.</p><?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="transactions.php" class="button button-primary">All Transactions →</a>
        <a href="escrow.php" class="button button-secondary">Manage Escrow</a>
        <a href="transactions.php?export=csv" class="button button-secondary">Export CSV</a>
    </div>

    <!-- ═══════════════════════ CHARGES ══════════════════════════════════════════ -->
    <?php elseif ($tab === 'charges'): ?>
    <div style="max-width:660px;">
        <?php if (!is_admin()): ?>
        <div class="alert alert-warning">Only admins can modify system charges.</div>
        <?php else: ?>
        <form method="post" action="payments.php?tab=charges">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_charges">

            <div class="pay-card" style="margin-bottom:16px;">
                <p class="pay-card-title" style="margin-bottom:4px;">Escrow Commission</p>
                <p style="font-size:.78rem;color:var(--muted);margin:0 0 18px;">Percentage the platform takes from escrow-protected jobs. Applied to new jobs only.</p>

                <div class="charge-field">
                    <label>Commission Rate</label>
                    <div class="charge-input-row">
                        <input type="number" name="escrow_commission_rate" id="commRate"
                               value="<?php echo htmlspecialchars($commRate); ?>"
                               min="0" max="50" step="0.5" required oninput="updatePreview()">
                        <span style="font-size:1rem;font-weight:700;">%</span>
                        <span class="hint">of worker's quoted budget &nbsp;·&nbsp; max 50%</span>
                    </div>
                    <div class="charge-preview" id="commPreview">
                        At <?php echo $commRate; ?>%: client pays
                        <strong>GH₵ <?php echo number_format(500*(1+$commRate/100),2); ?></strong>
                        for a GH₵ 500 job. Platform earns
                        <strong>GH₵ <?php echo number_format(500*$commRate/100,2); ?></strong>.
                    </div>
                </div>
            </div>

            <div class="pay-card" style="margin-bottom:16px;">
                <p class="pay-card-title" style="margin-bottom:4px;">Auto-Release Window</p>
                <p style="font-size:.78rem;color:var(--muted);margin:0 0 18px;">If a client doesn't release escrow within this window after job completion, funds release automatically.</p>

                <div class="charge-field">
                    <label>Days after job marked complete</label>
                    <div class="charge-input-row">
                        <input type="number" name="escrow_auto_release_days"
                               value="<?php echo $autoRelease; ?>" min="1" max="90" required>
                        <span class="hint">days &nbsp;·&nbsp; range 1–90</span>
                    </div>
                </div>
            </div>

            <div class="pay-card" style="margin-bottom:24px;">
                <p class="pay-card-title" style="margin-bottom:4px;">Minimum Escrow Budget</p>
                <p style="font-size:.78rem;color:var(--muted);margin:0 0 18px;">Clients below this amount cannot post escrow-protected jobs. Set to 0 to disable.</p>

                <div class="charge-field">
                    <label>Minimum (GH₵)</label>
                    <div class="charge-input-row">
                        <span style="font-weight:700;">GH₵</span>
                        <input type="number" name="escrow_min_budget" value="<?php echo htmlspecialchars($minBudget); ?>"
                               min="0" step="0.01">
                        <span class="hint">0 = no minimum</span>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="button button-primary" style="padding:11px 28px;">Save Changes</button>
                <a href="payments.php?tab=overview" class="button button-secondary">Cancel</a>
                <a href="monetization.php" class="button button-secondary" style="margin-left:auto;">Packages & Pricing →</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<script>
// ── Charts ──────────────────────────────────────────────────────────────────
var dailyLabels = <?php echo json_encode($chartLabels); ?>;
var dailyValues = <?php echo json_encode($chartValues); ?>;
var monthLabels = <?php echo json_encode($monthLabels); ?>;
var monthValues = <?php echo json_encode($monthValues); ?>;

// Get CSS variable colour for chart
var primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#2f8f5b';

// Revenue line chart with gradient fill
var rctx = document.getElementById('revenueChart');
if (rctx) {
    var rctx2 = rctx.getContext('2d');
    var grad = rctx2.createLinearGradient(0, 0, 0, 220);
    grad.addColorStop(0, primary.replace(')', ', 0.25)').replace('rgb', 'rgba'));
    grad.addColorStop(1, primary.replace(')', ', 0.00)').replace('rgb', 'rgba'));
    // Fallback gradient if primary isn't rgb
    var gradFallback = rctx2.createLinearGradient(0, 0, 0, 220);
    gradFallback.addColorStop(0, 'rgba(47,143,91,0.28)');
    gradFallback.addColorStop(1, 'rgba(47,143,91,0.00)');

    window.revenueChart = new Chart(rctx2, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Revenue (GH₵)',
                data: dailyValues,
                borderColor: primary || '#2f8f5b',
                backgroundColor: gradFallback,
                borderWidth: 2.5,
                tension: 0.4,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 5,
            }]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return 'GH₵ ' + parseFloat(c.raw).toFixed(2); } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 6, font: { size: 11 } } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' },
                     ticks: { callback: function(v) { return 'GH₵ ' + v; }, font: { size: 11 } } }
            }
        }
    });
}

function switchChart(mode) {
    document.getElementById('btn-d').classList.toggle('active', mode==='daily');
    document.getElementById('btn-m').classList.toggle('active', mode==='monthly');
    if (!window.revenueChart) return;
    window.revenueChart.data.labels              = mode==='daily' ? dailyLabels : monthLabels;
    window.revenueChart.data.datasets[0].data    = mode==='daily' ? dailyValues : monthValues;
    window.revenueChart.data.datasets[0].label   = mode==='daily' ? 'Daily Revenue (GH₵)' : 'Monthly Revenue (GH₵)';
    window.revenueChart.update();
}

// Donut chart
var dctx = document.getElementById('donutChart');
if (dctx) {
    new Chart(dctx, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: <?php echo json_encode($healthData); ?>,
                backgroundColor: ['#10b981','#f59e0b','#ef4444','#8b5cf6'],
                borderWidth: 0,
                hoverOffset: 4,
            }]
        },
        options: {
            cutout: '72%',
            responsive: false,
            plugins: { legend: { display: false }, tooltip: {
                callbacks: { label: function(c) { return ['Paid','Pending','Failed','Refunded'][c.dataIndex] + ': ' + c.raw; } }
            }}
        }
    });
}

// ── Charges live preview ─────────────────────────────────────────────────────
function updatePreview() {
    var rate = parseFloat(document.getElementById('commRate')?.value) || 0;
    var job = 500;
    var clientPays = job * (1 + rate/100);
    var platEarns  = job * rate / 100;
    var el = document.getElementById('commPreview');
    if (el) el.innerHTML = 'At ' + rate.toFixed(1) + '%: client pays <strong>GH₵ ' + clientPays.toFixed(2) + '</strong> for a GH₵ 500 job. Platform earns <strong>GH₵ ' + platEarns.toFixed(2) + '</strong>.';
}
</script>
</body>
</html>

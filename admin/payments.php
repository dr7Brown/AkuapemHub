<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin() && !is_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

// ── Overview stats ────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END)                                      AS total_revenue,
    SUM(CASE WHEN status = 'paid' AND DATE(paid_at) = CURDATE() THEN amount ELSE 0 END)        AS today_revenue,
    SUM(CASE WHEN status = 'paid' AND YEAR(paid_at) = YEAR(NOW()) AND MONTH(paid_at) = MONTH(NOW()) THEN amount ELSE 0 END) AS month_revenue,
    COUNT(CASE WHEN status = 'paid'      THEN 1 END) AS paid_count,
    COUNT(CASE WHEN status = 'pending'   THEN 1 END) AS pending_count,
    COUNT(CASE WHEN status = 'failed'    THEN 1 END) AS failed_count,
    COUNT(CASE WHEN status = 'abandoned' THEN 1 END) AS abandoned_count,
    COUNT(CASE WHEN status = 'refunded'  THEN 1 END) AS refunded_count,
    COUNT(CASE WHEN flagged = 1          THEN 1 END) AS flagged_count,
    COUNT(*) AS total_count
FROM platform_payments")->fetch();

$escrowStats = $pdo->query("SELECT
    COUNT(CASE WHEN status = 'held'     THEN 1 END) AS held_count,
    COUNT(CASE WHEN status = 'released' THEN 1 END) AS released_count,
    SUM(CASE WHEN status IN ('held','released') THEN commission_amount ELSE 0 END) AS commission_earned,
    SUM(CASE WHEN status = 'held' THEN gross_amount ELSE 0 END) AS held_value
FROM escrow_payments")->fetch();

// ── Daily revenue chart (last 30 days) ────────────────────────────────────────
$chartRows = $pdo->query("SELECT DATE(paid_at) AS day, SUM(amount) AS revenue, COUNT(*) AS cnt
    FROM platform_payments WHERE status = 'paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(paid_at)")->fetchAll(PDO::FETCH_ASSOC);
$chartMap = [];
foreach ($chartRows as $r) $chartMap[$r['day']] = (float)$r['revenue'];

$chartLabels = [];
$chartValues = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('j M', strtotime("-{$i} days"));
    $chartValues[] = $chartMap[$day] ?? 0;
}

// ── Monthly revenue (last 12 months) ─────────────────────────────────────────
$monthRows = $pdo->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS mo, SUM(amount) AS revenue
    FROM platform_payments WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mo ORDER BY mo")->fetchAll(PDO::FETCH_ASSOC);
$monthMap = [];
foreach ($monthRows as $r) $monthMap[$r['mo']] = (float)$r['revenue'];
$monthLabels = [];
$monthValues = [];
for ($i = 11; $i >= 0; $i--) {
    $mo = date('Y-m', strtotime("-{$i} months"));
    $monthLabels[] = date('M Y', strtotime("-{$i} months"));
    $monthValues[] = $monthMap[$mo] ?? 0;
}

// ── Revenue by type ───────────────────────────────────────────────────────────
$byType = $pdo->query("SELECT payment_type, COUNT(*) AS cnt, SUM(amount) AS revenue
    FROM platform_payments WHERE status = 'paid'
    GROUP BY payment_type ORDER BY revenue DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── Top paying users ──────────────────────────────────────────────────────────
$topUsers = $pdo->query("SELECT u.id, u.name, u.email, u.role,
    COUNT(pp.id) AS tx_count, SUM(pp.amount) AS total_spent
    FROM platform_payments pp JOIN users u ON u.id = pp.user_id
    WHERE pp.status = 'paid'
    GROUP BY pp.user_id ORDER BY total_spent DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// ── Conversion rate ───────────────────────────────────────────────────────────
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('admin')")->fetchColumn();
$convertedUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM platform_payments WHERE status='paid'")->fetchColumn();

$typeLabels = [
    'featured_job'    => 'Featured Job',
    'featured_worker' => 'Featured Worker',
    'verification'    => 'Verification',
    'job_post'        => 'Job Posting Fee',
    'worker_service'  => 'Worker Service',
    'escrow_payment'  => 'Escrow Payment',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Payments Dashboard — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .pay-nav { display:flex; gap:4px; padding:0 0 16px; flex-wrap:wrap; }
        .pay-nav a { padding:6px 14px; border-radius:var(--radius-sm); background:var(--surface-muted); color:var(--text); text-decoration:none; font-size:0.88rem; border:1px solid var(--border); }
        .pay-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:24px; }
        .stat-box { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px 16px; }
        .stat-box .val { font-size:1.5rem; font-weight:700; margin:0 0 2px; }
        .stat-box .lbl { font-size:0.8rem; color:var(--muted); margin:0; }
        .stat-box.highlight { border-color:var(--primary); background:color-mix(in srgb, var(--primary) 8%, transparent); }
        .section-title { font-size:1rem; font-weight:700; margin:24px 0 12px; }
        .chart-wrap { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; margin-bottom:24px; }
        .chart-toggle { display:flex; gap:6px; margin-bottom:12px; }
        .chart-toggle button { padding:4px 12px; border-radius:4px; border:1px solid var(--border); background:var(--surface-muted); cursor:pointer; font-size:0.85rem; }
        .chart-toggle button.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        table.data-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        table.data-table th { text-align:left; padding:8px 10px; border-bottom:2px solid var(--border); font-size:0.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        table.data-table td { padding:8px 10px; border-bottom:1px solid var(--border); }
        .badge-status { display:inline-block; padding:2px 8px; border-radius:4px; font-size:0.75rem; font-weight:600; color:#fff; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Admin</a>
        <span style="font-weight:700;margin-left:12px;">Payments Dashboard</span>
        <div class="topbar-actions">
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell" style="padding-bottom:40px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <nav class="pay-nav">
            <a href="payments.php" class="active">Overview</a>
            <a href="transactions.php">Transactions</a>
            <a href="escrow.php">Escrow</a>
            <a href="monetization.php">Settings</a>
        </nav>

        <!-- Revenue headline stats -->
        <div class="stat-grid">
            <div class="stat-box highlight">
                <p class="val">GH₵ <?php echo number_format((float)($stats['total_revenue'] ?? 0), 2); ?></p>
                <p class="lbl">Total Revenue (all time)</p>
            </div>
            <div class="stat-box">
                <p class="val">GH₵ <?php echo number_format((float)($stats['today_revenue'] ?? 0), 2); ?></p>
                <p class="lbl">Today's Revenue</p>
            </div>
            <div class="stat-box">
                <p class="val">GH₵ <?php echo number_format((float)($stats['month_revenue'] ?? 0), 2); ?></p>
                <p class="lbl">This Month</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#16a34a;"><?php echo (int)($stats['paid_count'] ?? 0); ?></p>
                <p class="lbl">Successful Transactions</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#f59e0b;"><?php echo (int)($stats['pending_count'] ?? 0); ?></p>
                <p class="lbl">Pending</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#dc2626;"><?php echo (int)(($stats['failed_count'] ?? 0) + ($stats['abandoned_count'] ?? 0)); ?></p>
                <p class="lbl">Failed / Abandoned</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#7c3aed;"><?php echo (int)($stats['refunded_count'] ?? 0); ?></p>
                <p class="lbl">Refunded</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#2563eb;">GH₵ <?php echo number_format((float)($escrowStats['commission_earned'] ?? 0), 2); ?></p>
                <p class="lbl">Commission from Escrow</p>
            </div>
            <div class="stat-box">
                <p class="val" style="color:#2563eb;">GH₵ <?php echo number_format((float)($escrowStats['held_value'] ?? 0), 2); ?></p>
                <p class="lbl">Funds Held in Escrow</p>
            </div>
        </div>

        <?php if (($stats['flagged_count'] ?? 0) > 0): ?>
            <div class="alert alert-warning" style="margin-bottom:16px;">
                ⚑ <strong><?php echo (int)$stats['flagged_count']; ?> flagged transaction<?php echo $stats['flagged_count'] != 1 ? 's' : ''; ?></strong> need review.
                <a href="transactions.php?flagged=1" style="margin-left:6px;">View →</a>
            </div>
        <?php endif; ?>

        <!-- Revenue chart -->
        <div class="chart-wrap">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <strong style="font-size:0.95rem;">Revenue</strong>
                <div class="chart-toggle">
                    <button id="btn-daily" class="active" onclick="showChart('daily')">Daily (30d)</button>
                    <button id="btn-monthly" onclick="showChart('monthly')">Monthly (12m)</button>
                </div>
            </div>
            <canvas id="revenueChart" height="80"></canvas>
        </div>

        <!-- Conversion / acquisition -->
        <div class="stat-grid" style="margin-bottom:24px;">
            <div class="stat-box">
                <p class="val"><?php echo $totalUsers > 0 ? round($convertedUsers / $totalUsers * 100, 1) : 0; ?>%</p>
                <p class="lbl">Free → Paid Conversion</p>
            </div>
            <div class="stat-box">
                <p class="val"><?php echo $convertedUsers; ?></p>
                <p class="lbl">Paying Users</p>
            </div>
            <div class="stat-box">
                <p class="val"><?php echo $totalUsers; ?></p>
                <p class="lbl">Total (Non-Admin) Users</p>
            </div>
            <div class="stat-box">
                <p class="val"><?php echo (int)($escrowStats['held_count'] ?? 0); ?></p>
                <p class="lbl">Active Escrows</p>
            </div>
        </div>

        <!-- Revenue by service type -->
        <p class="section-title">Revenue by Service Type</p>
        <?php if ($byType): ?>
            <div style="overflow-x:auto;margin-bottom:24px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th style="text-align:right;">Transactions</th>
                            <th style="text-align:right;">Revenue</th>
                            <th style="text-align:right;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalRev = (float)($stats['total_revenue'] ?? 0);
                        foreach ($byType as $row):
                            $pct = $totalRev > 0 ? round($row['revenue'] / $totalRev * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><?php echo sanitize($typeLabels[$row['payment_type']] ?? ucwords(str_replace('_', ' ', $row['payment_type']))); ?></td>
                                <td style="text-align:right;"><?php echo (int)$row['cnt']; ?></td>
                                <td style="text-align:right;font-weight:600;">GH₵ <?php echo number_format($row['revenue'], 2); ?></td>
                                <td style="text-align:right;">
                                    <span style="color:var(--muted);"><?php echo $pct; ?>%</span>
                                    <div style="width:80px;height:4px;background:var(--border);border-radius:2px;display:inline-block;margin-left:6px;vertical-align:middle;">
                                        <div style="width:<?php echo $pct; ?>%;height:100%;background:var(--primary);border-radius:2px;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="meta" style="margin-bottom:24px;">No paid transactions yet.</p>
        <?php endif; ?>

        <!-- Top paying users -->
        <p class="section-title">Top Paying Users</p>
        <?php if ($topUsers): ?>
            <div style="overflow-x:auto;margin-bottom:24px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Role</th>
                            <th style="text-align:right;">Transactions</th>
                            <th style="text-align:right;">Total Spent</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUsers as $i => $u): ?>
                            <tr>
                                <td style="color:var(--muted);"><?php echo $i + 1; ?></td>
                                <td>
                                    <strong><?php echo sanitize($u['name']); ?></strong><br>
                                    <span class="meta"><?php echo sanitize($u['email']); ?></span>
                                </td>
                                <td><span class="badge"><?php echo sanitize(ucfirst($u['role'])); ?></span></td>
                                <td style="text-align:right;"><?php echo (int)$u['tx_count']; ?></td>
                                <td style="text-align:right;font-weight:700;color:var(--primary);">GH₵ <?php echo number_format($u['total_spent'], 2); ?></td>
                                <td><a href="transactions.php?user_id=<?php echo $u['id']; ?>" class="button button-small button-secondary">Transactions</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="meta" style="margin-bottom:24px;">No paid transactions yet.</p>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="transactions.php" class="button button-primary">View All Transactions →</a>
            <a href="escrow.php" class="button button-secondary">Manage Escrow →</a>
            <a href="transactions.php?export=csv" class="button button-secondary">Export CSV</a>
        </div>
    </main>

    <script>
    var dailyLabels  = <?php echo json_encode($chartLabels); ?>;
    var dailyValues  = <?php echo json_encode($chartValues); ?>;
    var monthLabels  = <?php echo json_encode($monthLabels); ?>;
    var monthValues  = <?php echo json_encode($monthValues); ?>;

    var ctx = document.getElementById('revenueChart').getContext('2d');
    var chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Revenue (GH₵)',
                data: dailyValues,
                backgroundColor: 'rgba(37,99,235,0.25)',
                borderColor: 'rgba(37,99,235,0.9)',
                borderWidth: 1,
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false }, tooltip: {
                callbacks: { label: function(c) { return 'GH₵ ' + parseFloat(c.raw).toFixed(2); } }
            }},
            scales: { y: { beginAtZero: true, ticks: {
                callback: function(v) { return 'GH₵ ' + v; }
            }}}
        }
    });

    function showChart(mode) {
        document.getElementById('btn-daily').classList.toggle('active', mode === 'daily');
        document.getElementById('btn-monthly').classList.toggle('active', mode === 'monthly');
        chart.data.labels   = mode === 'daily' ? dailyLabels  : monthLabels;
        chart.data.datasets[0].data = mode === 'daily' ? dailyValues : monthValues;
        chart.data.datasets[0].label = mode === 'daily' ? 'Daily Revenue (GH₵)' : 'Monthly Revenue (GH₵)';
        chart.update();
    }
    </script>
</body>
</html>

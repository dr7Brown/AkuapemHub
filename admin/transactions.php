<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin() && !is_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$status  = in_array($_GET['status'] ?? '', ['pending','paid','failed','abandoned','refunded'], true) ? $_GET['status'] : '';
$type    = $_GET['type'] ?? '';
$from    = $_GET['from'] ?? '';
$to      = $_GET['to'] ?? '';
$flagged = isset($_GET['flagged']) ? 1 : null;
$userId  = intval($_GET['user_id'] ?? 0);
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$validTypes = ['featured_job','featured_worker','verification','job_post','worker_service','escrow_payment'];
if (!in_array($type, $validTypes, true)) $type = '';

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]    = '(u.name LIKE :q OR u.email LIKE :q OR pp.reference_code LIKE :q OR pp.paystack_reference LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[]    = 'pp.status = :status';
    $params[':status'] = $status;
}
if ($type !== '') {
    $where[]    = 'pp.payment_type = :type';
    $params[':type'] = $type;
}
if ($from !== '') {
    $where[]    = 'pp.created_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[]    = 'pp.created_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}
if ($flagged !== null) {
    $where[]    = 'pp.flagged = 1';
}
if ($userId > 0) {
    $where[]    = 'pp.user_id = :uid';
    $params[':uid'] = $userId;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && is_admin()) {
    $exportSQL = "SELECT pp.id, pp.reference_code, pp.payment_type, pp.amount, pp.currency,
        pp.status, pp.payment_method, pp.gateway, pp.created_at, pp.paid_at,
        pp.paystack_reference, pp.paystack_transaction_id, pp.flagged,
        u.name AS user_name, u.email AS user_email
        FROM platform_payments pp JOIN users u ON u.id = pp.user_id
        {$whereSQL}
        ORDER BY pp.created_at DESC";
    $exportStmt = $pdo->prepare($exportSQL);
    foreach ($params as $k => $v) $exportStmt->bindValue($k, $v);
    $exportStmt->execute();
    $rows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

// ── Count + paginate ──────────────────────────────────────────────────────────
$countSQL = "SELECT COUNT(*) FROM platform_payments pp JOIN users u ON u.id = pp.user_id {$whereSQL}";
$countStmt = $pdo->prepare($countSQL);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// ── Fetch rows ────────────────────────────────────────────────────────────────
$listSQL = "SELECT pp.*, u.name AS user_name, u.email AS user_email, u.username AS user_username
    FROM platform_payments pp JOIN users u ON u.id = pp.user_id
    {$whereSQL}
    ORDER BY pp.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}";
$listStmt = $pdo->prepare($listSQL);
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->execute();
$transactions = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'featured_job'    => 'Featured Job',
    'featured_worker' => 'Featured Worker',
    'verification'    => 'Verification',
    'job_post'        => 'Job Posting',
    'worker_service'  => 'Worker Service',
    'escrow_payment'  => 'Escrow',
];
$statusColors = [
    'paid'      => '#16a34a',
    'pending'   => '#f59e0b',
    'failed'    => '#dc2626',
    'abandoned' => '#94a3b8',
    'refunded'  => '#7c3aed',
];
$methodLabels = [
    'card'         => 'Card',
    'mobile_money' => 'Mobile Money',
    'bank'         => 'Bank Transfer',
    'ussd'         => 'USSD',
    'paystack'     => 'Paystack',
];

// Build query string helper (preserves filters, changes one param)
function qstr(array $overrides = []): string {
    $base = [];
    foreach (['q','status','type','from','to','flagged','user_id','page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return '?' . http_build_query(array_merge($base, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Transactions — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .pay-nav { display:flex; gap:4px; padding:0 0 16px; flex-wrap:wrap; }
        .pay-nav a { padding:6px 14px; border-radius:var(--radius-sm); background:var(--surface-muted); color:var(--text); text-decoration:none; font-size:0.88rem; border:1px solid var(--border); }
        .pay-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:16px; background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; }
        .filter-bar label { font-size:0.78rem; color:var(--muted); display:block; margin-bottom:2px; }
        .filter-bar input, .filter-bar select { font-size:0.88rem; padding:5px 8px; border:1px solid var(--border); border-radius:4px; background:var(--surface); color:var(--text); }
        table.data-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        table.data-table th { text-align:left; padding:8px 8px; border-bottom:2px solid var(--border); font-size:0.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap; }
        table.data-table td { padding:8px 8px; border-bottom:1px solid var(--border); vertical-align:middle; }
        table.data-table tr:hover td { background:var(--surface-muted); }
        .badge-status { display:inline-block; padding:2px 7px; border-radius:4px; font-size:0.75rem; font-weight:600; color:#fff; white-space:nowrap; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; margin-top:16px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:4px; border:1px solid var(--border); text-decoration:none; font-size:0.85rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted); }
        .pagination .current { background:var(--primary); color:#fff; border-color:var(--primary); }
        .flag-icon { color:#f59e0b; font-size:0.9rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Admin</a>
        <span style="font-weight:700;margin-left:12px;">Transactions</span>
        <div class="topbar-actions">
            <a href="transactions.php<?php echo qstr(['export'=>'csv','page'=>null]); ?>" class="button button-secondary button-small">Export CSV</a>
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell" style="padding-bottom:40px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <nav class="pay-nav">
            <a href="payments.php">Overview</a>
            <a href="transactions.php" class="active">Transactions</a>
            <a href="escrow.php">Escrow</a>
            <a href="monetization.php">Settings</a>
        </nav>

        <!-- Filters -->
        <form method="get" action="transactions.php" class="filter-bar">
            <div>
                <label>Search</label>
                <input type="text" name="q" value="<?php echo sanitize($search); ?>" placeholder="Name, email, or reference…" style="width:200px;" />
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['paid'=>'Paid','pending'=>'Pending','failed'=>'Failed','abandoned'=>'Abandoned','refunded'=>'Refunded'] as $v => $l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $status === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Type</label>
                <select name="type">
                    <option value="">All types</option>
                    <?php foreach ($typeLabels as $v => $l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $type === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>From</label>
                <input type="date" name="from" value="<?php echo sanitize($from); ?>" />
            </div>
            <div>
                <label>To</label>
                <input type="date" name="to" value="<?php echo sanitize($to); ?>" />
            </div>
            <div>
                <label>&nbsp;</label>
                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;margin:0;">
                    <input type="checkbox" name="flagged" value="1" <?php echo $flagged ? 'checked' : ''; ?>>
                    Flagged only
                </label>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" class="button button-primary" style="height:32px;">Filter</button>
                <a href="transactions.php" class="button button-secondary" style="height:32px;line-height:32px;padding:0 10px;font-size:0.85rem;">Clear</a>
            </div>
        </form>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <p class="meta" style="margin:0;">
                <?php echo number_format($total); ?> result<?php echo $total !== 1 ? 's' : ''; ?>
                <?php if ($search || $status || $type || $from || $to || $flagged): ?>
                    — filtered
                <?php endif; ?>
            </p>
        </div>

        <?php if (!$transactions): ?>
            <p class="meta" style="text-align:center;padding:40px 0;">No transactions found.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID / Reference</th>
                            <th>User</th>
                            <th>Type</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>
                                    <?php if ($tx['flagged']): ?><span class="flag-icon" title="Flagged">⚑</span> <?php endif; ?>
                                    <a href="transaction_detail.php?id=<?php echo $tx['id']; ?>" style="font-weight:600;color:var(--primary);text-decoration:none;">#<?php echo $tx['id']; ?></a>
                                    <br><span class="meta" style="font-size:0.75rem;word-break:break-all;"><?php echo sanitize(substr($tx['reference_code'] ?? '', 0, 22)); ?></span>
                                </td>
                                <td>
                                    <?php echo sanitize($tx['user_name']); ?><br>
                                    <span class="meta"><?php echo sanitize($tx['user_email']); ?></span>
                                </td>
                                <td><?php echo sanitize($typeLabels[$tx['payment_type']] ?? ucwords(str_replace('_', ' ', $tx['payment_type']))); ?></td>
                                <td style="text-align:right;font-weight:600;white-space:nowrap;">
                                    <?php echo $tx['currency'] ?? 'GHS'; ?> <?php echo number_format($tx['amount'], 2); ?>
                                </td>
                                <td><?php
                                    $method = $tx['payment_method'] ?: ($tx['gateway'] ?: '—');
                                    echo sanitize($methodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)));
                                ?></td>
                                <td>
                                    <span class="badge-status" style="background:<?php echo $statusColors[$tx['status']] ?? '#888'; ?>;">
                                        <?php echo strtoupper($tx['status']); ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap;font-size:0.82rem;">
                                    <?php echo date('d M Y', strtotime($tx['created_at'])); ?><br>
                                    <span class="meta"><?php echo date('g:i a', strtotime($tx['created_at'])); ?></span>
                                </td>
                                <td>
                                    <a href="transaction_detail.php?id=<?php echo $tx['id']; ?>" class="button button-small button-secondary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="transactions.php<?php echo qstr(['page' => $page - 1]); ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 3);
                    $end   = min($totalPages, $page + 3);
                    if ($start > 1) echo '<span>…</span>';
                    for ($p = $start; $p <= $end; $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="current"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="transactions.php<?php echo qstr(['page' => $p]); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor;
                    if ($end < $totalPages) echo '<span>…</span>';
                    ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="transactions.php<?php echo qstr(['page' => $page + 1]); ?>">Next ›</a>
                    <?php endif; ?>
                    <span style="color:var(--muted);border:none;padding-left:4px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>

<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin() && !is_manager()) {
    header('Location: ../jobs.php');
    exit;
}

// Stats
$stats = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(status = 'awaiting_payment') AS awaiting,
    SUM(status = 'held')    AS held,
    SUM(status = 'released') AS released,
    SUM(status = 'disputed') AS disputed,
    SUM(CASE WHEN status = 'held' THEN gross_amount ELSE 0 END) AS held_value
    FROM escrow_payments")->fetch();

$filter = in_array($_GET['filter'] ?? '', ['awaiting_payment','held','released','refunded','disputed'], true)
    ? $_GET['filter'] : '';

$where = $filter ? "WHERE ep.status = :status" : '';
$sql = "SELECT ep.*, sr.title AS job_title, sr.status AS job_status,
        c.name AS client_name, c.email AS client_email,
        w.name AS worker_name
    FROM escrow_payments ep
    JOIN service_requests sr ON sr.id = ep.job_id
    JOIN users c ON c.id = ep.client_id
    LEFT JOIN users w ON w.id = ep.worker_id
    {$where}
    ORDER BY ep.created_at DESC
    LIMIT 200";
$stmt = $pdo->prepare($sql);
if ($filter) $stmt->bindValue(':status', $filter);
$stmt->execute();
$escrows = $stmt->fetchAll();

$statusColors = [
    'awaiting_payment' => '#f59e0b',
    'held'     => '#2563eb',
    'released' => '#16a34a',
    'refunded' => '#dc2626',
    'disputed' => '#7c3aed',
];
$statusLabels = [
    'awaiting_payment' => 'Awaiting Payment',
    'held'     => 'Held',
    'released' => 'Released',
    'refunded' => 'Refunded',
    'disputed' => 'Disputed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Escrow Management — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="app-topbar">
        <a href="index.php" class="brand" style="text-decoration:none;">
            <span class="brand-icon">‹</span> Admin
        </a>
        <span style="font-weight:600;">Escrow Management</span>
    </header>
    <main class="page-shell" style="padding-bottom:40px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px;">
            <?php
            $statCards = [
                ['GH₵ ' . number_format((float)($stats['held_value'] ?? 0), 2), 'Held (GHS)', '#2563eb'],
                [$stats['held'] ?? 0,     'Currently Held', '#2563eb'],
                [$stats['awaiting'] ?? 0, 'Awaiting Payment', '#f59e0b'],
                [$stats['released'] ?? 0, 'Released', '#16a34a'],
                [$stats['disputed'] ?? 0, 'Disputed', '#7c3aed'],
                [$stats['total'] ?? 0,    'Total', '#64748b'],
            ];
            foreach ($statCards as [$val, $label, $color]): ?>
                <div style="background:var(--surface-muted);border-radius:var(--radius-sm);padding:14px;text-align:center;">
                    <p style="font-size:1.4rem;font-weight:700;color:<?php echo $color; ?>;margin:0 0 4px;"><?php echo $val; ?></p>
                    <p class="meta" style="margin:0;"><?php echo $label; ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter tabs -->
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">
            <a href="escrow.php" class="button button-small <?php echo $filter === '' ? 'button-primary' : 'button-secondary'; ?>">All</a>
            <?php foreach ($statusLabels as $key => $label): ?>
                <a href="escrow.php?filter=<?php echo $key; ?>" class="button button-small <?php echo $filter === $key ? 'button-primary' : 'button-secondary'; ?>">
                    <?php echo $label; ?>
                    <?php if ($key === 'held' && ($stats['held'] ?? 0) > 0): ?>
                        <span style="background:#fff;color:#2563eb;border-radius:10px;padding:0 5px;margin-left:4px;font-size:0.75rem;"><?php echo $stats['held']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$escrows): ?>
            <p class="meta" style="text-align:center;padding:40px 0;">No escrow records found.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border);">
                            <th style="text-align:left;padding:8px 6px;">Job</th>
                            <th style="text-align:left;padding:8px 6px;">Client</th>
                            <th style="text-align:left;padding:8px 6px;">Worker</th>
                            <th style="text-align:right;padding:8px 6px;">Gross</th>
                            <th style="text-align:right;padding:8px 6px;">Net</th>
                            <th style="text-align:left;padding:8px 6px;">Status</th>
                            <th style="text-align:left;padding:8px 6px;">Auto-release</th>
                            <th style="padding:8px 6px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($escrows as $e): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:8px 6px;">
                                    <a href="../request_detail.php?id=<?php echo $e['job_id']; ?>" style="font-weight:600;color:var(--primary);text-decoration:none;">
                                        <?php echo sanitize($e['job_title']); ?>
                                    </a>
                                    <br><span class="meta">#<?php echo $e['job_id']; ?> · <?php echo sanitize(strtoupper(str_replace('_', ' ', $e['job_status']))); ?></span>
                                </td>
                                <td style="padding:8px 6px;">
                                    <?php echo sanitize($e['client_name']); ?><br>
                                    <span class="meta"><?php echo sanitize($e['client_email']); ?></span>
                                </td>
                                <td style="padding:8px 6px;"><?php echo $e['worker_name'] ? sanitize($e['worker_name']) : '<span class="meta">Unassigned</span>'; ?></td>
                                <td style="padding:8px 6px;text-align:right;font-weight:600;">GH₵ <?php echo number_format($e['gross_amount'], 2); ?></td>
                                <td style="padding:8px 6px;text-align:right;">GH₵ <?php echo number_format($e['net_amount'], 2); ?></td>
                                <td style="padding:8px 6px;">
                                    <span style="background:<?php echo $statusColors[$e['status']] ?? '#888'; ?>;color:#fff;border-radius:4px;padding:2px 7px;font-size:0.75rem;white-space:nowrap;">
                                        <?php echo sanitize($statusLabels[$e['status']] ?? $e['status']); ?>
                                    </span>
                                </td>
                                <td style="padding:8px 6px;font-size:0.82rem;">
                                    <?php if ($e['status'] === 'held' && !empty($e['auto_release_at'])): ?>
                                        <?php
                                        $arDiff = strtotime($e['auto_release_at']) - time();
                                        $arDays = max(0, (int)ceil($arDiff / 86400));
                                        ?>
                                        <?php echo $arDays > 0 ? "In {$arDays}d" : 'Today'; ?>
                                        <br><span class="meta"><?php echo date('d M Y', strtotime($e['auto_release_at'])); ?></span>
                                    <?php elseif ($e['status'] === 'released' && !empty($e['released_at'])): ?>
                                        <span class="meta"><?php echo date('d M Y', strtotime($e['released_at'])); ?></span>
                                        <br><span class="meta">by <?php echo sanitize(ucfirst($e['release_initiated_by'] ?? '')); ?></span>
                                    <?php else: ?>
                                        <span class="meta">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px 6px;">
                                    <?php if ($e['status'] === 'held'): ?>
                                        <form method="post" action="../escrow_release.php"
                                              onsubmit="return confirm('Release escrow for job #<?php echo $e['job_id']; ?> (GH₵ <?php echo number_format($e['net_amount'], 2); ?> to worker)?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="job_id" value="<?php echo $e['job_id']; ?>" />
                                            <button type="submit" class="button button-small button-primary">Release</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="../request_detail.php?id=<?php echo $e['job_id']; ?>" class="button button-small button-secondary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>

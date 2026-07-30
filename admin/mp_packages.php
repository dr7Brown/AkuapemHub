<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$flash     = get_flash();
$error     = '';
$tab       = $_GET['tab'] ?? 'packages';

// ── POST: package CRUD ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $id                       = (int)($_POST['id'] ?? 0);
        $name                     = trim($_POST['name'] ?? '');
        $description              = trim($_POST['description'] ?? '') ?: null;
        $durationDays             = max(1, (int)($_POST['duration_days'] ?? 30));
        $price                    = max(0, (float)($_POST['price'] ?? 0));
        $yearlyPrice              = $_POST['yearly_price'] !== '' ? max(0, (float)$_POST['yearly_price']) : null;
        $unlimitedListings        = isset($_POST['unlimited_listings']) ? 1 : 0;
        $productLimit             = $unlimitedListings ? -1 : max(0, (int)($_POST['product_limit'] ?? 0));
        $unlimitedImages          = isset($_POST['unlimited_images']) ? 1 : 0;
        $maxImages                = $unlimitedImages ? 0 : max(1, (int)($_POST['max_images'] ?? 5));
        $featuredShopIncluded     = isset($_POST['featured_shop_included']) ? 1 : 0;
        $featuredProductsIncluded = max(0, (int)($_POST['featured_products_included'] ?? 0));
        $priorityRanking          = max(0, (int)($_POST['priority_ranking'] ?? 0));
        $adCredits                = max(0, (int)($_POST['ad_credits'] ?? 0));
        $analyticsAccess          = isset($_POST['analytics_access']) ? 1 : 0;
        $supportLevel             = in_array($_POST['support_level'] ?? '', ['standard','priority','premium'], true) ? $_POST['support_level'] : 'standard';
        $verificationIncluded     = isset($_POST['verification_included']) ? 1 : 0;
        $badgeName                = trim($_POST['badge_name'] ?? '') ?: null;
        $badgeColor               = trim($_POST['badge_color'] ?? '') ?: null;
        $displayOrder             = (int)($_POST['display_order'] ?? 0);
        $status                   = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

        if ($name === '') {
            $error = 'Package name is required.';
        } else {
            $params = [
                $name, $description, $durationDays, $price, $yearlyPrice, $productLimit,
                $maxImages, $unlimitedImages, $featuredShopIncluded, $featuredProductsIncluded,
                $priorityRanking, $adCredits, $analyticsAccess, $supportLevel, $verificationIncluded,
                $badgeName, $badgeColor, $displayOrder, $status,
            ];
            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE mp_seller_subscription_plans SET
                        name=?, description=?, duration_days=?, price=?, yearly_price=?, product_limit=?,
                        max_images=?, unlimited_images=?, featured_shop_included=?, featured_products_included=?,
                        priority_ranking=?, ad_credits=?, analytics_access=?, support_level=?, verification_included=?,
                        badge_name=?, badge_color=?, display_order=?, status=?, updated_at=NOW()
                     WHERE id=?"
                )->execute(array_merge($params, [$id]));
                log_audit_action($adminUser['id'], 'mp_package_edited', "Edited marketplace package #{$id}: '{$name}'");
                flash('Package updated.', 'success');
            } else {
                $pdo->prepare(
                    "INSERT INTO mp_seller_subscription_plans
                        (name, description, duration_days, price, yearly_price, product_limit,
                         max_images, unlimited_images, featured_shop_included, featured_products_included,
                         priority_ranking, ad_credits, analytics_access, support_level, verification_included,
                         badge_name, badge_color, display_order, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute($params);
                log_audit_action($adminUser['id'], 'mp_package_created', "Created marketplace package: '{$name}'");
                flash('Package created.', 'success');
            }
            header('Location: mp_packages.php');
            exit;
        }
    } elseif ($action === 'toggle_status' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name, status FROM mp_seller_subscription_plans WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE mp_seller_subscription_plans SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $id]);
            log_audit_action($adminUser['id'], 'mp_package_status_toggled', "Set package '{$row['name']}' (#{$id}) to {$newStatus}");
            flash('Package ' . ($newStatus === 'active' ? 'enabled' : 'disabled') . '.', 'success');
        }
        header('Location: mp_packages.php');
        exit;
    } elseif ($action === 'delete_plan' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name FROM mp_seller_subscription_plans WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM mp_seller_subscriptions WHERE plan_id=?");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            flash('Cannot delete — this package has subscription history. Disable it instead.', 'error');
        } elseif ($row) {
            $pdo->prepare('DELETE FROM mp_seller_subscription_plans WHERE id=?')->execute([$id]);
            log_audit_action($adminUser['id'], 'mp_package_deleted', "Deleted marketplace package '{$row['name']}' (#{$id})");
            flash('Package deleted.', 'success');
        }
        header('Location: mp_packages.php');
        exit;
    } elseif ($action === 'cancel_subscription' && !empty($_POST['id'])) {
        $subId = (int)$_POST['id'];
        $row = $pdo->prepare("SELECT ms.shop_name FROM mp_seller_subscriptions mss JOIN mp_shops ms ON mss.shop_id=ms.id WHERE mss.id=?");
        $row->execute([$subId]); $row = $row->fetch();
        if (mp_cancel_subscription($subId)) {
            log_audit_action($adminUser['id'], 'mp_subscription_cancelled', "Cancelled subscription #{$subId}" . ($row ? " for shop '{$row['shop_name']}'" : ''));
            flash('Subscription cancelled.', 'success');
        } else {
            flash('Could not cancel — subscription is not active.', 'error');
        }
        header('Location: mp_packages.php?tab=subscribers');
        exit;
    }
}

$plans = $pdo->query('SELECT * FROM mp_seller_subscription_plans ORDER BY display_order ASC, price ASC')->fetchAll();

// ── Analytics ────────────────────────────────────────────────────────────────
$activeShops  = (int)$pdo->query("SELECT COUNT(DISTINCT shop_id) FROM mp_seller_subscriptions WHERE status='active' AND end_date >= CURDATE()")->fetchColumn();
$expiredShops = (int)$pdo->query(
    "SELECT COUNT(DISTINCT mss.shop_id) FROM mp_seller_subscriptions mss
     WHERE mss.status='expired' AND NOT EXISTS (
        SELECT 1 FROM mp_seller_subscriptions mss2 WHERE mss2.shop_id=mss.shop_id AND mss2.status='active' AND mss2.end_date >= CURDATE()
     )"
)->fetchColumn();
$revenueToday = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='mp_subscription' AND status='paid' AND DATE(paid_at)=CURDATE()")->fetchColumn();
$revenueMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='mp_subscription' AND status='paid' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
$revenueYear  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='mp_subscription' AND status='paid' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-01-01')")->fetchColumn();

$popularPackages = $pdo->query(
    "SELECT msp.name, COUNT(*) AS cnt FROM mp_seller_subscriptions mss
     JOIN mp_seller_subscription_plans msp ON mss.plan_id=msp.id
     GROUP BY mss.plan_id, msp.name ORDER BY cnt DESC"
)->fetchAll();

$eventCounts = $pdo->query("SELECT event, COUNT(*) AS cnt FROM mp_subscription_history GROUP BY event")->fetchAll(PDO::FETCH_KEY_PAIR);
$renewedCnt    = (int)($eventCounts['renewed'] ?? 0);
$upgradedCnt   = (int)($eventCounts['upgraded'] ?? 0);
$downgradedCnt = (int)($eventCounts['downgraded'] ?? 0);
$expiredCnt    = (int)($eventCounts['expired'] ?? 0);
$purchasedCnt  = (int)($eventCounts['purchased'] ?? 0);
$totalEvents   = array_sum($eventCounts);
$renewalRate = ($renewedCnt + $expiredCnt) > 0 ? round($renewedCnt / ($renewedCnt + $expiredCnt) * 100, 1) : 0.0;
$upgradeRate = $totalEvents > 0 ? round($upgradedCnt / $totalEvents * 100, 1) : 0.0;

$avgProducts = (float)$pdo->query(
    "SELECT COALESCE(AVG(cnt),0) FROM (
        SELECT COUNT(*) AS cnt FROM mp_products p
        JOIN mp_seller_subscriptions mss ON mss.shop_id = p.shop_id AND mss.status='active' AND mss.end_date >= CURDATE()
        WHERE p.status IN ('pending_approval','approved','out_of_stock')
        GROUP BY p.shop_id
     ) t"
)->fetchColumn();

if ($tab === 'analytics' && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="mp_subscription_analytics_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Active Shops', $activeShops]);
    fputcsv($out, ['Expired Shops', $expiredShops]);
    fputcsv($out, ['Revenue Today (GHS)', number_format($revenueToday, 2, '.', '')]);
    fputcsv($out, ['Revenue This Month (GHS)', number_format($revenueMonth, 2, '.', '')]);
    fputcsv($out, ['Revenue This Year (GHS)', number_format($revenueYear, 2, '.', '')]);
    fputcsv($out, ['Renewal Rate (%)', $renewalRate]);
    fputcsv($out, ['Upgrade Rate (%)', $upgradeRate]);
    fputcsv($out, ['Average Active Listings per Subscribed Shop', round($avgProducts, 1)]);
    fputcsv($out, []);
    fputcsv($out, ['Package', 'Times Subscribed']);
    foreach ($popularPackages as $pp) fputcsv($out, [csv_safe($pp['name']), $pp['cnt']]);
    fclose($out);
    exit;
}

// ── Subscribers list ─────────────────────────────────────────────────────────
$subStatusFilter = $_GET['sstatus'] ?? 'all';
$subQ            = trim($_GET['sq'] ?? '');
$validSubStatuses = ['pending','active','expired','cancelled','suspended','pending_renewal'];
if (!in_array($subStatusFilter, $validSubStatuses, true)) $subStatusFilter = 'all';

$subWhere  = [];
$subParams = [];
if ($subStatusFilter !== 'all') { $subWhere[] = 'mss.status = ?'; $subParams[] = $subStatusFilter; }
if ($subQ !== '')               { $subWhere[] = 'ms.shop_name LIKE ?'; $subParams[] = '%' . $subQ . '%'; }
if (($_GET['expiring'] ?? '') === '1') { $subWhere[] = "mss.status='active' AND mss.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)"; }
$subWhereSql = $subWhere ? ('WHERE ' . implode(' AND ', $subWhere)) : '';

$subscribersStmt = $pdo->prepare(
    "SELECT mss.*, ms.shop_name, msp.name AS plan_name
     FROM mp_seller_subscriptions mss
     JOIN mp_shops ms ON mss.shop_id = ms.id
     JOIN mp_seller_subscription_plans msp ON mss.plan_id = msp.id
     $subWhereSql
     ORDER BY mss.created_at DESC
     LIMIT 300"
);
$subscribersStmt->execute($subParams);
$subscribers = $subscribersStmt->fetchAll();

if ($tab === 'subscribers' && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="mp_subscribers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Shop', 'Package', 'Status', 'Start Date', 'End Date', 'Price Paid (GHS)', 'Created At']);
    foreach ($subscribers as $s) {
        fputcsv($out, [csv_safe($s['shop_name']), csv_safe($s['plan_name']), $s['status'], $s['start_date'], $s['end_date'], number_format((float)$s['price_paid'], 2, '.', ''), $s['created_at']]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace Packages — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mpp-shell { max-width:1100px; margin:0 auto; padding:18px 16px 60px; }
        .mpp-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .mpp-table th { padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .mpp-table td { padding:10px 12px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .mpp-table tr:last-child td { border-bottom:none; }
        .mpp-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:700; }
        .mpp-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; overflow-x:auto; }
        .mpp-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .mpp-form-grid label { font-weight:600; font-size:.82rem; display:block; margin-bottom:4px; }
        .mpp-form-grid input[type=text], .mpp-form-grid input[type=number], .mpp-form-grid select, .mpp-form-grid textarea {
            width:100%; padding:7px 9px; border:1px solid var(--border); border-radius:8px; font-size:.84rem; box-sizing:border-box;
        }
        .mpp-check { display:flex; align-items:center; gap:6px; font-size:.82rem; font-weight:600; margin-top:22px; }
        .mpp-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:18px 0 10px; }
        .mpp-section-title:first-child { margin-top:0; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📦 Marketplace Packages</h1>
    <a href="monetization.php?tab=settings" class="button button-secondary button-small">Monetize Settings</a>
</header>

<main class="mpp-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div><?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        Sellers must hold an active subscription to list products once
        <a href="monetization.php?tab=settings">Monetize → Module Availability → Marketplace Subscriptions</a> is turned on.
        Deleting a package that already has subscribers is blocked — disable it instead.
    </p>

    <div class="mpp-tabs" style="display:flex;gap:5px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:18px;">
        <a href="?tab=packages" class="mp-tab <?php echo $tab==='packages'?'active':''; ?>" style="padding:7px 16px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;background:<?php echo $tab==='packages'?'var(--primary-soft,#d1fae5)':'var(--surface)'; ?>;border:1px solid var(--border);color:<?php echo $tab==='packages'?'var(--primary,#0f766e)':'var(--text-muted,#6b7280)'; ?>;">📦 Packages</a>
        <a href="?tab=subscribers" class="mp-tab <?php echo $tab==='subscribers'?'active':''; ?>" style="padding:7px 16px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;background:<?php echo $tab==='subscribers'?'var(--primary-soft,#d1fae5)':'var(--surface)'; ?>;border:1px solid var(--border);color:<?php echo $tab==='subscribers'?'var(--primary,#0f766e)':'var(--text-muted,#6b7280)'; ?>;">👥 Subscribers</a>
        <a href="?tab=analytics" class="mp-tab <?php echo $tab==='analytics'?'active':''; ?>" style="padding:7px 16px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;background:<?php echo $tab==='analytics'?'var(--primary-soft,#d1fae5)':'var(--surface)'; ?>;border:1px solid var(--border);color:<?php echo $tab==='analytics'?'var(--primary,#0f766e)':'var(--text-muted,#6b7280)'; ?>;">📊 Analytics</a>
    </div>

    <?php if ($tab === 'subscribers'): ?>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="hidden" name="tab" value="subscribers">
        <select name="sstatus" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <?php foreach (['all'=>'All Statuses','active'=>'Active','pending'=>'Pending','expired'=>'Expired','cancelled'=>'Cancelled','suspended'=>'Suspended','pending_renewal'=>'Pending Renewal (scheduled downgrade)'] as $sv=>$sl): ?>
            <option value="<?php echo $sv; ?>" <?php echo $subStatusFilter===$sv?'selected':''; ?>><?php echo $sl; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="sq" value="<?php echo sanitize($subQ); ?>" placeholder="Search shop name…" style="flex:1;min-width:160px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
        <button type="submit" class="button button-secondary button-small">Filter</button>
        <a href="?tab=subscribers&sstatus=active&expiring=1" class="button button-secondary button-small">⏰ Expiring (14d)</a>
        <a href="?tab=subscribers&sstatus=<?php echo $subStatusFilter; ?>&sq=<?php echo urlencode($subQ); ?>&export=csv" class="button button-secondary button-small">⬇ CSV</a>
        <?php if ($subStatusFilter !== 'all' || $subQ !== ''): ?><a href="?tab=subscribers" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>

    <div class="mpp-card">
        <table class="mpp-table">
            <thead><tr><th>Shop</th><th>Package</th><th>Status</th><th>Start</th><th>End</th><th style="text-align:right;">Price Paid</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php if (!$subscribers): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No subscriptions match this filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($subscribers as $s): ?>
            <?php $statusColors = ['active'=>['#d1fae5','#065f46'],'pending'=>['#fef3c7','#92400e'],'expired'=>['#fee2e2','#991b1b'],'cancelled'=>['#f1f5f9','#475569'],'suspended'=>['#fee2e2','#991b1b'],'pending_renewal'=>['#dbeafe','#1e40af']]; $sc = $statusColors[$s['status']] ?? ['#f1f5f9','#475569']; ?>
            <tr>
                <td><strong><?php echo sanitize($s['shop_name']); ?></strong></td>
                <td><?php echo sanitize($s['plan_name']); ?></td>
                <td><span class="mpp-badge" style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;"><?php echo ucfirst(str_replace('_',' ',$s['status'])); ?></span></td>
                <td><?php echo date('d M Y', strtotime($s['start_date'])); ?></td>
                <td><?php echo date('d M Y', strtotime($s['end_date'])); ?></td>
                <td style="text-align:right;">GH&#8373; <?php echo number_format((float)$s['price_paid'],2); ?></td>
                <td style="text-align:right;">
                    <?php if (in_array($s['status'], ['active','pending_renewal'], true)): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this subscription now?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel_subscription">
                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($tab === 'analytics'): ?>
    <div class="mpp-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px;">
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;color:var(--primary,#0f766e);"><?php echo $activeShops; ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Active Shops</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;color:#c0392b;"><?php echo $expiredShops; ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Expired Shops</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;">GH&#8373; <?php echo number_format($revenueToday,2); ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Revenue Today</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;">GH&#8373; <?php echo number_format($revenueMonth,2); ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Revenue This Month</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;">GH&#8373; <?php echo number_format($revenueYear,2); ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Revenue This Year</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;"><?php echo $renewalRate; ?>%</strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Renewal Rate</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;"><?php echo $upgradeRate; ?>%</strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Upgrade Rate</span></div>
        <div class="mpp-card" style="margin-bottom:0;text-align:center;padding:14px;"><strong style="display:block;font-size:1.3rem;font-weight:900;"><?php echo round($avgProducts,1); ?></strong><span style="font-size:.7rem;color:var(--text-muted,#6b7280);">Avg. Active Listings / Shop</span></div>
    </div>

    <div class="mpp-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h2 style="margin:0;font-size:1rem;">Package Popularity (all-time)</h2>
            <a href="?tab=analytics&export=csv" class="button button-secondary button-small">⬇ Download CSV</a>
        </div>
        <table class="mpp-table">
            <thead><tr><th>Package</th><th style="text-align:right;">Times Subscribed</th></tr></thead>
            <tbody>
            <?php if (!$popularPackages): ?>
            <tr><td colspan="2" style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No subscriptions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($popularPackages as $pp): ?>
            <tr><td><?php echo sanitize($pp['name']); ?></td><td style="text-align:right;"><?php echo (int)$pp['cnt']; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>

    <div class="mpp-card">
        <table class="mpp-table">
            <thead><tr><th>#</th><th>Name</th><th>Price</th><th>Listings</th><th>Badge</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php if (!$plans): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No packages yet — add one below.</td></tr>
            <?php endif; ?>
            <?php foreach ($plans as $p): ?>
            <tr>
                <td><?php echo (int)$p['display_order']; ?></td>
                <td><strong><?php echo sanitize($p['name']); ?></strong></td>
                <td>GH&#8373; <?php echo number_format((float)$p['price'],2); ?><?php if ($p['yearly_price'] !== null): ?><br><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">GH&#8373; <?php echo number_format((float)$p['yearly_price'],2); ?>/yr</span><?php endif; ?></td>
                <td><?php echo (int)$p['product_limit'] === -1 ? 'Unlimited' : (int)$p['product_limit']; ?></td>
                <td><?php if ($p['badge_name']): ?><span class="mpp-badge" style="background:<?php echo sanitize($p['badge_color'] ?: '#e4f4ea'); ?>;color:#1a2230;"><?php echo sanitize($p['badge_name']); ?></span><?php endif; ?></td>
                <td><span class="mpp-badge" style="background:<?php echo $p['status']==='active' ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo $p['status']==='active' ? '#065f46' : '#991b1b'; ?>;"><?php echo ucfirst($p['status']); ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <button type="button" class="button button-secondary button-small" onclick='editPlan(<?php echo json_encode($p); ?>)'>Edit</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo $p['status']==='active' ? 'Disable' : 'Enable'; ?> this package?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit" class="button button-secondary button-small"><?php echo $p['status']==='active' ? 'Disable' : 'Enable'; ?></button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this package permanently?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_plan">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mpp-card">
        <h2 id="mpp-form-heading" style="margin-top:0;font-size:1rem;">Add Package</h2>
        <form method="post" id="mpp-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="id" id="f_id" value="0">

            <p class="mpp-section-title">Basics</p>
            <div class="mpp-form-grid">
                <div><label>Package Name *</label><input type="text" name="name" id="f_name" required></div>
                <div><label>Monthly Price (GH&#8373;)</label><input type="number" step="0.01" min="0" name="price" id="f_price" value="0"></div>
                <div><label>Yearly Price (GH&#8373;, optional)</label><input type="number" step="0.01" min="0" name="yearly_price" id="f_yearly_price"></div>
                <div><label>Duration (days)</label><input type="number" min="1" name="duration_days" id="f_duration_days" value="30"></div>
                <div><label>Display Order</label><input type="number" name="display_order" id="f_display_order" value="0"></div>
                <div><label>Status</label>
                    <select name="status" id="f_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
            </div>
            <div class="form-group" style="margin-top:12px;"><label>Description</label><textarea name="description" id="f_description" rows="2"></textarea></div>

            <p class="mpp-section-title">Listing Limits</p>
            <div class="mpp-form-grid">
                <div><label>Max Active Product Listings</label><input type="number" min="0" name="product_limit" id="f_product_limit" value="10"></div>
                <div class="mpp-check"><label><input type="checkbox" name="unlimited_listings" id="f_unlimited_listings"> Unlimited listings</label></div>
                <div><label>Max Images per Product</label><input type="number" min="1" name="max_images" id="f_max_images" value="5"></div>
                <div class="mpp-check"><label><input type="checkbox" name="unlimited_images" id="f_unlimited_images"> Unlimited images</label></div>
            </div>

            <p class="mpp-section-title">Featured &amp; Marketing Benefits</p>
            <div class="mpp-form-grid">
                <div class="mpp-check"><label><input type="checkbox" name="featured_shop_included" id="f_featured_shop_included"> Featured shop included</label></div>
                <div><label>Featured Products Included</label><input type="number" min="0" name="featured_products_included" id="f_featured_products_included" value="0"></div>
                <div><label>Priority Search Ranking (0=none)</label><input type="number" min="0" name="priority_ranking" id="f_priority_ranking" value="0"></div>
                <div><label>Advertisement Credits</label><input type="number" min="0" name="ad_credits" id="f_ad_credits" value="0"></div>
            </div>

            <p class="mpp-section-title">Support &amp; Badge</p>
            <div class="mpp-form-grid">
                <div class="mpp-check"><label><input type="checkbox" name="analytics_access" id="f_analytics_access"> Analytics access</label></div>
                <div class="mpp-check"><label><input type="checkbox" name="verification_included" id="f_verification_included"> Verification included</label></div>
                <div><label>Support Level</label>
                    <select name="support_level" id="f_support_level">
                        <option value="standard">Standard</option>
                        <option value="priority">Priority</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div><label>Badge Name</label><input type="text" name="badge_name" id="f_badge_name" placeholder="e.g. PRO"></div>
                <div><label>Badge Colour</label><input type="text" name="badge_color" id="f_badge_color" placeholder="#fef3c7"></div>
            </div>

            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary">Save Package</button>
                <button type="button" class="button button-secondary" onclick="resetForm()">Cancel Edit</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</main>

<script>
function editPlan(p) {
    document.getElementById('mpp-form-heading').textContent = 'Edit Package — ' + p.name;
    document.getElementById('f_id').value = p.id;
    document.getElementById('f_name').value = p.name;
    document.getElementById('f_price').value = p.price;
    document.getElementById('f_yearly_price').value = p.yearly_price ?? '';
    document.getElementById('f_duration_days').value = p.duration_days;
    document.getElementById('f_display_order').value = p.display_order;
    document.getElementById('f_status').value = p.status;
    document.getElementById('f_description').value = p.description ?? '';
    document.getElementById('f_product_limit').value = p.product_limit == -1 ? 0 : p.product_limit;
    document.getElementById('f_unlimited_listings').checked = (p.product_limit == -1);
    document.getElementById('f_max_images').value = p.max_images;
    document.getElementById('f_unlimited_images').checked = !!(p.unlimited_images * 1);
    document.getElementById('f_featured_shop_included').checked = !!(p.featured_shop_included * 1);
    document.getElementById('f_featured_products_included').value = p.featured_products_included;
    document.getElementById('f_priority_ranking').value = p.priority_ranking;
    document.getElementById('f_ad_credits').value = p.ad_credits;
    document.getElementById('f_analytics_access').checked = !!(p.analytics_access * 1);
    document.getElementById('f_verification_included').checked = !!(p.verification_included * 1);
    document.getElementById('f_support_level').value = p.support_level;
    document.getElementById('f_badge_name').value = p.badge_name ?? '';
    document.getElementById('f_badge_color').value = p.badge_color ?? '';
    document.getElementById('mpp-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
    document.getElementById('mpp-form').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('mpp-form-heading').textContent = 'Add Package';
}
</script>

</body>
</html>

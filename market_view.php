<?php
/**
 * The "enter market" landing page — shown before a buyer can send a custom
 * order, so they see the schedule/pre-order window/storehouse info first
 * instead of jumping straight from the markets list into the order form.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('markets', 'Nearby Markets');

$user     = current_user();
$marketId = (int)($_GET['id'] ?? 0);
$market   = null;
if ($marketId) {
    $mStmt = $pdo->prepare('SELECT * FROM markets WHERE id = ?');
    $mStmt->execute([$marketId]);
    $market = $mStmt->fetch();
}
if (!$market) { header('Location: markets.php'); exit; }

$flash  = get_flash();
$sched  = get_market_schedule($market);
$shades = mkt_color_shades($market['color']);

$systemChargeType  = get_platform_setting('market_system_charge_type', 'flat');
$systemChargeValue = (float)get_platform_setting('market_system_charge_value', '0');

$deliveryTowns = $pdo->prepare(
    "SELECT t.name, t.district, mdt.delivery_fee
     FROM market_delivery_towns mdt JOIN towns t ON mdt.town_id = t.id
     WHERE mdt.market_id = ? AND mdt.status = 'active' ORDER BY t.district, t.name"
);
$deliveryTowns->execute([$marketId]);
$deliveryTowns = $deliveryTowns->fetchAll();

// The buyer's most recent still-relevant shopping list for THIS market, so
// they see "your quote is ready" / "awaiting price" the moment they land
// back on the page instead of having to dig through orders.php.
$myActiveOrder = null;
if ($user) {
    $moStmt = $pdo->prepare(
        "SELECT mqr.* FROM mp_quote_requests mqr
         JOIN mp_shops ms ON mqr.shop_id = ms.id
         WHERE ms.market_id = ? AND mqr.customer_id = ? AND mqr.status IN ('pending','quoted','paid')
         ORDER BY mqr.created_at DESC LIMIT 1"
    );
    $moStmt->execute([$marketId, $user['id']]);
    $myActiveOrder = $moStmt->fetch();
}

// Other markets — open-now first (same ranking as markets.php), so this
// market's page becomes a jumping-off point for discovering the rest.
$otherMarkets = $pdo->prepare("SELECT * FROM markets WHERE id != ? ORDER BY name");
$otherMarkets->execute([$marketId]);
$otherMarkets = $otherMarkets->fetchAll();
$otherMarketSchedules = [];
foreach ($otherMarkets as $om) { $otherMarketSchedules[$om['id']] = get_market_schedule($om); }
usort($otherMarkets, function ($a, $b) use ($otherMarketSchedules) {
    $rank = function ($s) { return $s['is_payment_open'] ? 0 : 1; };
    return $rank($otherMarketSchedules[$a['id']]) <=> $rank($otherMarketSchedules[$b['id']]);
});
$otherMarkets = array_slice($otherMarkets, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => sanitize($market['name']) . ' | ' . APP_NAME,
        'description' => $market['description'] ?: ('Send a custom order for ' . $market['name'] . '.'),
        'url'         => rtrim(BASE_URL, '/') . '/market_view.php?id=' . $marketId,
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mv-shell { max-width: 640px; margin: 0 auto; padding: 20px 16px 60px; }
        .mv-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 14px; }
        .mv-hero  {
            position:relative; overflow:hidden; color:#fff; border-radius:16px; padding:20px; margin-bottom:14px;
            background: linear-gradient(150deg, var(--mkt-g1) 0%, var(--mkt-g2) 55%, var(--mkt-g3) 100%),
                        radial-gradient(circle at 1px 1px, rgba(255,255,255,.08) 1px, transparent 0);
            background-size: auto, 20px 20px;
        }
        .mv-badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:.74rem; font-weight:800; margin-bottom:10px; }
        .mv-badge.open   { background:#d1fae5; color:#065f46; }
        .mv-badge.closed { background:#fee2e2; color:#c0392b; }
        .mv-row { font-size:.86rem; color:var(--text-muted,#6b7280); display:flex; align-items:center; gap:8px; margin-top:8px; }
        .mv-desc { font-size:.88rem; margin:10px 0 0; }
        .mv-hero .mv-row  { color:rgba(255,255,255,.88); }
        .mv-hero .mv-row a { color:#fff; text-decoration:underline; }
        .mv-hero .mv-desc { color:rgba(255,255,255,.92); }
        .mv-topbar { background: linear-gradient(120deg, var(--mkt-g1), var(--mkt-g3)); border-bottom:none; }
        .mv-topbar a, .mv-topbar span { color:#fff !important; }

        .mv-order-banner { display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--surface); border:1px solid var(--border); border-left:4px solid #f59e0b; border-radius:12px; padding:14px 16px; margin-bottom:14px; text-decoration:none; color:inherit; transition:box-shadow .15s; }
        .mv-order-banner:hover { box-shadow:0 6px 20px rgba(0,0,0,.08); }
        .mv-order-banner-title { font-weight:800; font-size:.88rem; }
        .mv-order-banner-sub { font-size:.76rem; color:var(--text-muted,#6b7280); margin-top:2px; }
        .mv-order-banner-cta { font-size:.8rem; font-weight:800; color:var(--primary,#0f766e); white-space:nowrap; flex-shrink:0; }

        .mv-other-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:10px; }
        .mv-other-card { border-radius:12px; padding:14px 12px; color:#fff; text-decoration:none; display:flex; flex-direction:column; gap:6px; transition:transform .15s,box-shadow .15s; }
        .mv-other-card:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,.18); }
        .mv-other-name  { font-weight:800; font-size:.82rem; line-height:1.25; }
        .mv-other-badge { font-size:.66rem; font-weight:700; opacity:.85; }
        .mv-other-badge.open { opacity:1; color:#bbf7d0; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>" style="--mkt-g1:<?php echo $shades['g1']; ?>; --mkt-g2:<?php echo $shades['g2']; ?>; --mkt-g3:<?php echo $shades['g3']; ?>;">

<header class="mkt-topbar mv-topbar">
    <a href="markets.php" style="font-weight:900;text-decoration:none;font-size:1.05rem;">← Markets</a>
    <span style="font-weight:900;font-size:.95rem;">🏬 <?php echo sanitize($market['name']); ?></span>
</header>

<main class="mv-shell">
    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($myActiveOrder):
        $qrColors = ['pending' => '#f59e0b', 'quoted' => '#3b82f6', 'paid' => '#10b981'];
        $qrColor  = $qrColors[$myActiveOrder['status']] ?? '#6b7280';
        $qrTitles = [
            'pending' => '📝 Your shopping list is with the market agent',
            'quoted'  => '💰 Your quote is ready!',
            'paid'    => '✅ Order paid — being fulfilled',
        ];
        $qrHref = $myActiveOrder['status'] === 'quoted' ? 'pay_quote.php?id=' . $myActiveOrder['id'] : 'orders.php?view=quotes';
    ?>
    <a href="<?php echo $qrHref; ?>" class="mv-order-banner" style="border-left-color:<?php echo $qrColor; ?>;">
        <div>
            <div class="mv-order-banner-title"><?php echo $qrTitles[$myActiveOrder['status']] ?? 'Your shopping list'; ?></div>
            <?php if ($myActiveOrder['status'] === 'quoted' && $myActiveOrder['total_amount']): ?>
            <div class="mv-order-banner-sub">Total: GH&#8373; <?php echo number_format((float)$myActiveOrder['total_amount'], 2); ?></div>
            <?php else: ?>
            <div class="mv-order-banner-sub">Request #<?php echo (int)$myActiveOrder['id']; ?> &middot; sent <?php echo date('d M', strtotime($myActiveOrder['created_at'])); ?></div>
            <?php endif; ?>
        </div>
        <span class="mv-order-banner-cta"><?php echo $myActiveOrder['status'] === 'quoted' ? 'Pay Now →' : 'View →'; ?></span>
    </a>
    <?php endif; ?>

    <div class="mv-hero">
        <?php if ($market['status'] === 'closed' && $sched['is_scheduled']): ?>
        <span class="mv-badge closed">Temporarily Closed</span>
        <?php elseif ($sched['is_payment_open']): ?>
        <span class="mv-badge open">● Open for Orders Now</span>
        <?php elseif ($sched['is_market_day']): ?>
        <span class="mv-badge closed">Closed for Today</span>
        <?php elseif ($sched['is_scheduled']): ?>
        <span class="mv-badge closed">Orders Not Open Yet</span>
        <?php else: ?>
        <span class="mv-badge <?php echo $market['status']; ?>"><?php echo $market['status'] === 'open' ? '● Open Now' : 'Closed'; ?></span>
        <?php endif; ?>

        <?php if ($market['description']): ?><p class="mv-desc"><?php echo sanitize($market['description']); ?></p><?php endif; ?>

        <?php if ($sched['is_scheduled'] && $sched['next_market_date']): ?>
        <div class="mv-row">🗓️ <?php echo sanitize(market_schedule_label($market)); ?><?php if (!$sched['is_market_day']): ?> — next market day <strong><?php echo $sched['next_market_date']->format('l, j F'); ?></strong><?php endif; ?></div>
        <?php if ($sched['is_market_day']): ?>
        <div class="mv-row">🎉 <strong>Today is market day!</strong></div>
        <?php if (!$sched['is_payment_open']): ?>
        <div class="mv-row">🔒 Orders have closed for today.</div>
        <?php endif; ?>
        <?php elseif (!$sched['is_preorder_open']): ?>
        <div class="mv-row">⏳ Orders open <strong><?php echo $sched['preorder_starts_at']->format('l, j F'); ?></strong></div>
        <?php endif; ?>
        <?php elseif ($market['schedule_note']): ?>
        <div class="mv-row">🗓️ <?php echo sanitize($market['schedule_note']); ?></div>
        <?php endif; ?>

        <?php if ($market['storehouse_location']): ?>
        <div class="mv-row">📍 <?php echo sanitize($market['storehouse_location']); ?><?php if ($market['storehouse_maps_link']): ?> — <a href="<?php echo sanitize($market['storehouse_maps_link']); ?>" target="_blank" rel="noopener">View on map</a><?php endif; ?></div>
        <?php endif; ?>
    </div>

    <div class="mv-card">
        <p style="margin:0 0 8px;font-weight:800;font-size:.88rem;">💰 Pricing</p>
        <div class="mv-row" style="margin-top:0;">🏬 Storehouse pickup — GH&#8373; <?php echo number_format((float)$market['pickup_fee'], 2); ?></div>
        <?php if ($deliveryTowns): ?>
        <div class="mv-row">🚚 Home delivery available to:</div>
        <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($deliveryTowns as $t): ?>
            <span style="font-size:.78rem;background:var(--surface-muted,#f3f4f6);border-radius:20px;padding:3px 10px;"><?php echo sanitize($t['name']); ?> — GH&#8373; <?php echo number_format((float)$t['delivery_fee'], 2); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($systemChargeValue > 0): ?>
        <div class="mv-row">💳 System charge — <?php echo $systemChargeType === 'percent' ? number_format($systemChargeValue, 2) . '%' : 'GH&#8373; ' . number_format($systemChargeValue, 2); ?> added at checkout</div>
        <?php endif; ?>
    </div>

    <div class="mv-card">
        <p style="margin:0 0 10px;font-size:.85rem;color:var(--text-muted,#6b7280);">List what you'd like from this market — an agent will price each item and let you know the total. You'll only pay once you accept the quote.</p>
        <?php if ($sched['is_preorder_open']): ?>
        <a href="request_market_order.php?market_id=<?php echo $marketId; ?>" class="button button-primary" style="width:100%;padding:13px;text-align:center;display:block;">📝 Send Custom Order</a>
        <?php else: ?>
        <button type="button" class="button button-primary" disabled style="width:100%;padding:13px;opacity:.5;cursor:not-allowed;">📝 Send Custom Order</button>
        <p style="margin:8px 0 0;font-size:.8rem;color:var(--text-muted,#6b7280);text-align:center;">
            <?php
                if ($sched['is_market_day']) echo 'Orders have closed for today — check back next market day.';
                elseif ($sched['is_scheduled'] && $sched['preorder_starts_at']) echo 'Check back from ' . $sched['preorder_starts_at']->format('j F') . '.';
                else echo 'Not accepting orders right now.';
            ?>
        </p>
        <?php endif; ?>
    </div>

    <?php if ($otherMarkets): ?>
    <div class="mv-card">
        <p style="margin:0 0 10px;font-weight:800;font-size:.85rem;">🏬 Other Nearby Markets</p>
        <div class="mv-other-grid">
            <?php foreach ($otherMarkets as $om): $os = $otherMarketSchedules[$om['id']]; $oshades = mkt_color_shades($om['color']); ?>
            <a href="market_view.php?id=<?php echo (int)$om['id']; ?>" class="mv-other-card"
               style="background:linear-gradient(150deg, <?php echo $oshades['g1']; ?>, <?php echo $oshades['g3']; ?>);">
                <div class="mv-other-name"><?php echo sanitize($om['name']); ?></div>
                <?php if ($os['is_payment_open']): ?>
                <span class="mv-other-badge open">● Open Now</span>
                <?php else: ?>
                <span class="mv-other-badge">Closed</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="markets.php" class="button button-secondary" style="width:100%;text-align:center;display:block;margin-top:10px;">View All Markets →</a>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user) { require __DIR__ . '/partials/bottom_nav.php'; } ?>
</body>
</html>

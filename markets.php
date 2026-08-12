<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('markets', 'Periodic Markets');

$user = current_user();

$markets = $pdo->query(
    "SELECT m.*, (SELECT COUNT(*) FROM mp_shops s WHERE s.market_id = m.id AND s.status='active') AS shop_count
     FROM markets m ORDER BY (m.status='open') DESC, m.name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Periodic Markets — Ofie, Nkurakan & More | ' . APP_NAME,
        'description' => 'Browse scheduled periodic markets across the Akuapem area — Ofie Market, Nkurakan Market and more. Order ahead, collect from the storehouse on market day.',
        'url'         => rtrim(BASE_URL, '/') . '/markets.php',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mkt-topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .mkt-shell  { max-width:1000px; margin:0 auto; padding:20px 16px 60px; }
        .mkt-intro  { font-size:.88rem; color:var(--text-muted,#6b7280); margin-bottom:20px; max-width:640px; }
        .mkt-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
        .mkt-card   { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; text-decoration:none; color:inherit; display:flex; flex-direction:column; gap:8px; transition:box-shadow .15s,transform .15s; }
        .mkt-card:hover { box-shadow:0 8px 26px rgba(0,0,0,.1); transform:translateY(-2px); }
        .mkt-card-head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .mkt-card-name { font-weight:800; font-size:1.02rem; margin:0; }
        .mkt-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.68rem; font-weight:800; flex-shrink:0; }
        .mkt-badge.open   { background:#d1fae5; color:#065f46; }
        .mkt-badge.closed { background:#fee2e2; color:#c0392b; }
        .mkt-card-row { font-size:.8rem; color:var(--text-muted,#6b7280); display:flex; align-items:center; gap:6px; }
        .mkt-card-desc { font-size:.82rem; color:var(--text-muted,#6b7280); margin:0; }
        .mkt-empty { text-align:center; padding:60px 20px; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="mkt-topbar">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.05rem;">← Back Home</a>
    <span style="font-weight:900;color:var(--primary,#0f766e);font-size:.95rem;">🏬 Periodic Markets</span>
    <a href="marketplace.php" class="button button-secondary button-small">🛍️ Regular Marketplace</a>
</header>

<main class="mkt-shell">
    <p class="mkt-intro">
        Scheduled market days across the Akuapem area. Browse and order ahead — sellers bring your
        purchase to the market's storehouse, and you (or a manager on your behalf) collect it there.
        Checkout only opens while a market is marked <strong>Open</strong>, on its market day.
    </p>

    <?php if (!$markets): ?>
    <div class="mkt-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">🏬</div>
        <p style="margin:0;font-weight:700;">No markets set up yet</p>
        <p style="margin:6px 0 0;font-size:.85rem;">Check back soon.</p>
    </div>
    <?php else: ?>
    <div class="mkt-grid">
        <?php foreach ($markets as $m): ?>
        <a href="marketplace.php?market_id=<?php echo (int)$m['id']; ?>" class="mkt-card">
            <div class="mkt-card-head">
                <p class="mkt-card-name"><?php echo sanitize($m['name']); ?></p>
                <span class="mkt-badge <?php echo $m['status']; ?>"><?php echo $m['status'] === 'open' ? '● Open Now' : 'Closed'; ?></span>
            </div>
            <?php if ($m['description']): ?><p class="mkt-card-desc"><?php echo sanitize(mb_substr($m['description'], 0, 140)); ?></p><?php endif; ?>
            <?php if ($m['schedule_note']): ?><div class="mkt-card-row">🗓️ <?php echo sanitize($m['schedule_note']); ?></div><?php endif; ?>
            <?php if ($m['storehouse_location']): ?><div class="mkt-card-row">📍 <?php echo sanitize($m['storehouse_location']); ?></div><?php endif; ?>
            <div class="mkt-card-row"><?php echo (int)$m['shop_count']; ?> shop<?php echo $m['shop_count']==1?'':'s'; ?></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php if ($user) { require __DIR__ . '/partials/bottom_nav.php'; } ?>
</body>
</html>

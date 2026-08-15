<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('markets', 'Nearby Markets');

$user = current_user();

$markets = $pdo->query("SELECT * FROM markets ORDER BY name")->fetchAll();

// Sort by how actionable each market is right now (open, then everything
// else), computed per-market since it depends on today's date relative to
// each market's own schedule.
$marketSchedules = [];
foreach ($markets as $m) { $marketSchedules[$m['id']] = get_market_schedule($m); }
usort($markets, function ($a, $b) use ($marketSchedules) {
    $rank = function ($s) { return $s['is_payment_open'] ? 0 : 1; };
    return $rank($marketSchedules[$a['id']]) <=> $rank($marketSchedules[$b['id']]);
});

// Delivery-town counts, one query for every market rather than N+1.
$deliveryTownCounts = [];
if ($markets) {
    $dtcRows = $pdo->query(
        "SELECT market_id, COUNT(*) AS cnt FROM market_delivery_towns WHERE status='active' GROUP BY market_id"
    )->fetchAll();
    foreach ($dtcRows as $r) { $deliveryTownCounts[$r['market_id']] = (int)$r['cnt']; }
}

$openNowCount = 0;
foreach ($marketSchedules as $s) { if ($s['is_payment_open']) $openNowCount++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Nearby Markets — Ofie, Nkurakan & More | ' . APP_NAME,
        'description' => 'Browse scheduled nearby markets across the Akuapem area — Ofie Market, Nkurakan Market and more. Order ahead, collect from the storehouse on market day.',
        'url'         => rtrim(BASE_URL, '/') . '/markets.php',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root { --mkt-g1:#3fa06c; --mkt-g2:#1f6b47; --mkt-g3:#123c28; }

        .mkt-topbar {
            background: linear-gradient(120deg, var(--mkt-g1), var(--mkt-g3));
            border-bottom: none; padding: 14px 16px;
            display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
        }
        .mkt-topbar .mkt-back { color:#fff; font-weight:900; text-decoration:none; font-size:1.02rem; }
        .mkt-topbar .mkt-title { color:#fff; font-weight:900; font-size:.95rem; }
        .mkt-topbar .button-secondary { background:rgba(255,255,255,.16); color:#fff; border-color:rgba(255,255,255,.32); }
        .mkt-topbar .button-secondary:hover { background:rgba(255,255,255,.26); }

        .mkt-shell  { max-width:1080px; margin:0 auto; padding:20px 16px 60px; }

        .mkt-hero {
            position:relative; overflow:hidden; color:#fff;
            border-radius:20px; padding:26px 24px 24px; margin-bottom:20px;
            background: linear-gradient(150deg, var(--mkt-g1) 0%, var(--mkt-g2) 55%, var(--mkt-g3) 100%),
                        radial-gradient(circle at 1px 1px, rgba(255,255,255,.09) 1px, transparent 0);
            background-size: auto, 22px 22px;
        }
        .mkt-hero h1 { margin:0 0 8px; font-size:1.5rem; font-weight:900; letter-spacing:-.01em; }
        .mkt-hero p  { margin:0; font-size:.88rem; opacity:.88; max-width:600px; line-height:1.55; }

        .mkt-stats { display:flex; gap:14px; flex-wrap:wrap; margin-top:22px; }
        .mkt-stat-circle {
            width:82px; height:82px; border-radius:50%; text-align:center;
            background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.28);
            display:flex; flex-direction:column; align-items:center; justify-content:center;
        }
        .mkt-stat-circle strong { font-size:1.35rem; font-weight:900; line-height:1; }
        .mkt-stat-circle span { font-size:.6rem; opacity:.88; padding:0 6px; margin-top:2px; }

        .mkt-search { width:100%; max-width:340px; padding:9px 12px; border:1px solid var(--border); border-radius:10px; font-size:.86rem; margin-bottom:16px; box-sizing:border-box; }

        .mkt-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:16px; }
        .mkt-card   {
            position:relative; overflow:hidden; color:#fff;
            border:1px solid rgba(255,255,255,.1); border-radius:16px; padding:18px;
            text-decoration:none; display:flex; flex-direction:column; gap:9px;
            transition:box-shadow .15s, transform .15s;
            background: linear-gradient(160deg, var(--mkt-g1) 0%, var(--mkt-g2) 55%, var(--mkt-g3) 100%),
                        radial-gradient(circle at 1px 1px, rgba(255,255,255,.07) 1px, transparent 0);
            background-size: auto, 18px 18px;
        }
        .mkt-card:hover { box-shadow:0 10px 28px rgba(10,40,25,.35); transform:translateY(-2px); }
        .mkt-card-head  { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
        .mkt-card-title { display:flex; align-items:center; gap:10px; min-width:0; }
        .mkt-card-icon  { flex-shrink:0; width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
        .mkt-card-name  { font-weight:800; font-size:1rem; margin:0; line-height:1.25; }
        .mkt-card-desc  { font-size:.78rem; opacity:.8; margin:0 0 0 48px; }

        .mkt-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.66rem; font-weight:800; flex-shrink:0; white-space:nowrap; }
        .mkt-badge.open   { background:#d1fae5; color:#065f46; }
        .mkt-badge.closed { background:#fee2e2; color:#c0392b; }

        .mkt-card-row { font-size:.78rem; opacity:.85; display:flex; align-items:center; gap:6px; }

        .mkt-countdown {
            font-size:1rem; font-weight:800; padding:13px 16px; border-radius:12px; margin-top:2px;
            display:flex; align-items:center; justify-content:center; gap:9px; font-variant-numeric:tabular-nums;
            background:rgba(0,0,0,.24); border:1px solid rgba(255,255,255,.12); text-align:center;
        }
        .mkt-countdown-icon { font-size:1.15rem; }
        .mkt-countdown.day    { background:rgba(255,255,255,.22); }
        .mkt-countdown.urgent { background:rgba(220,38,38,.4); animation:mkt-pulse 1.5s ease-in-out infinite; }
        @keyframes mkt-pulse { 0%,100% { opacity:1; } 50% { opacity:.72; } }

        .mkt-info-row { background:#fff; color:#123521; border-radius:10px; padding:7px 11px; font-size:.76rem; font-weight:600; display:flex; align-items:center; gap:7px; }
        .mkt-fees     { display:flex; gap:6px; flex-wrap:wrap; }
        .mkt-fee-chip { font-size:.72rem; font-weight:600; background:#fff; color:#123521; border-radius:20px; padding:4px 10px; display:inline-flex; align-items:center; gap:4px; }

        .mkt-card-enter {
            font-size:.82rem; font-weight:800; text-align:center; margin-top:auto; padding:10px 14px;
            background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.3); border-radius:10px;
            transition:background .15s;
        }
        .mkt-card:hover .mkt-card-enter { background:rgba(255,255,255,.26); }

        .mkt-empty { text-align:center; padding:60px 20px; color:var(--text-muted,#6b7280); }

        .mkt-how    { margin-top:32px; background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; }
        .mkt-how h2 { margin:0 0 14px; font-size:1rem; font-weight:800; }
        .mkt-how-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; }
        .mkt-how-step { display:flex; gap:10px; }
        .mkt-how-num  { flex-shrink:0; width:26px; height:26px; border-radius:50%; background:var(--primary-soft,#e4f4ea); color:var(--primary,#2f8f5b); font-weight:900; font-size:.82rem; display:flex; align-items:center; justify-content:center; }
        .mkt-how-step p { margin:0; font-size:.82rem; }
        .mkt-how-step strong { display:block; font-size:.86rem; margin-bottom:2px; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="mkt-topbar">
    <a href="index.php" class="mkt-back">← Back Home</a>
    <span class="mkt-title">🏬 Nearby Markets</span>
    <a href="marketplace.php" class="button button-secondary button-small">🛍️ Regular Marketplace</a>
</header>

<main class="mkt-shell">

    <div class="mkt-hero">
        <h1>🏬 Nearby Markets</h1>
        <p>Scheduled market days across the Akuapem area. Enter a market to send a shopping list for the
        items you need — an agent prices it, and once you pay, it's picked up from the storehouse or
        delivered to your town. Some markets open orders a few days ahead of market day.</p>
        <?php if ($markets): ?>
        <div class="mkt-stats">
            <div class="mkt-stat-circle"><strong><?php echo count($markets); ?></strong><span>Market<?php echo count($markets)===1?'':'s'; ?></span></div>
            <div class="mkt-stat-circle"><strong><?php echo $openNowCount; ?></strong><span>Open Now</span></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$markets): ?>
    <div class="mkt-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">🏬</div>
        <p style="margin:0;font-weight:700;">No markets set up yet</p>
        <p style="margin:6px 0 0;font-size:.85rem;">Check back soon.</p>
    </div>
    <?php else: ?>

    <?php if (count($markets) > 4): ?>
    <input type="text" id="mkt-search" class="mkt-search" placeholder="🔍 Search markets by name…" oninput="mktFilter(this.value)">
    <?php endif; ?>

    <div class="mkt-grid" id="mkt-grid">
        <?php foreach ($markets as $m): $sched = $marketSchedules[$m['id']]; $shades = mkt_color_shades($m['color']); ?>
        <a href="market_view.php?id=<?php echo (int)$m['id']; ?>" class="mkt-card" data-name="<?php echo sanitize(mb_strtolower($m['name'])); ?>"
           style="--mkt-g1:<?php echo $shades['g1']; ?>; --mkt-g2:<?php echo $shades['g2']; ?>; --mkt-g3:<?php echo $shades['g3']; ?>;">
            <div class="mkt-card-head">
                <div class="mkt-card-title">
                    <div class="mkt-card-icon">🏪</div>
                    <p class="mkt-card-name"><?php echo sanitize($m['name']); ?></p>
                </div>
                <?php if (!$sched['is_scheduled']): ?>
                <span class="mkt-badge <?php echo $m['status']; ?>"><?php echo $m['status'] === 'open' ? '● Open Now' : 'Closed'; ?></span>
                <?php elseif ($sched['is_payment_open']): ?>
                <span class="mkt-badge open">● Open Now</span>
                <?php elseif ($sched['is_market_day']): ?>
                <span class="mkt-badge closed">Closed for Today</span>
                <?php else: ?>
                <span class="mkt-badge closed">Closed</span>
                <?php endif; ?>
            </div>
            <?php if ($m['description']): ?><p class="mkt-card-desc"><?php echo sanitize(mb_substr($m['description'], 0, 90)); ?></p><?php endif; ?>

            <?php if ($sched['is_scheduled'] && $sched['next_market_date']): ?>
            <div class="mkt-card-row">🗓️ <?php echo sanitize(market_schedule_label($m)); ?></div>
            <?php if ($m['status'] !== 'closed'): ?>
            <div class="mkt-countdown"
                 data-preorder-start="<?php echo $sched['preorder_starts_at']->format('Y-m-d\TH:i:s\Z'); ?>"
                 data-market-day="<?php echo $sched['next_market_date']->format('Y-m-d\TH:i:s\Z'); ?>"
                 data-order-close="<?php echo $sched['order_close_at']->format('Y-m-d\TH:i:s\Z'); ?>">
                <span class="mkt-countdown-icon">⏳</span> <span class="mkt-countdown-text">calculating…</span>
            </div>
            <?php endif; ?>
            <?php elseif ($m['schedule_note']): ?>
            <div class="mkt-card-row">🗓️ <?php echo sanitize($m['schedule_note']); ?></div>
            <?php endif; ?>

            <?php if ($m['storehouse_location']): ?>
            <div class="mkt-info-row">📍 <?php echo sanitize($m['storehouse_location']); ?></div>
            <?php endif; ?>
            <div class="mkt-fees">
                <span class="mkt-fee-chip">🏬 Pickup GH&#8373; <?php echo number_format((float)$m['pickup_fee'], 2); ?></span>
                <?php if (!empty($deliveryTownCounts[$m['id']])): ?>
                <span class="mkt-fee-chip">🚚 Delivery to <?php echo $deliveryTownCounts[$m['id']]; ?> town<?php echo $deliveryTownCounts[$m['id']]===1?'':'s'; ?></span>
                <?php endif; ?>
            </div>

            <div class="mkt-card-enter">Enter Market →</div>
        </a>
        <?php endforeach; ?>
    </div>
    <p id="mkt-no-results" style="display:none;text-align:center;color:var(--text-muted,#6b7280);padding:30px;">No markets match your search.</p>
    <?php endif; ?>

    <div class="mkt-how">
        <h2>How it works</h2>
        <div class="mkt-how-grid">
            <div class="mkt-how-step">
                <div class="mkt-how-num">1</div>
                <p><strong>Enter a market</strong>See its schedule, pricing, and when orders open.</p>
            </div>
            <div class="mkt-how-step">
                <div class="mkt-how-num">2</div>
                <p><strong>Send your list</strong>Tell us what you want — no need to browse products.</p>
            </div>
            <div class="mkt-how-step">
                <div class="mkt-how-num">3</div>
                <p><strong>Agent prices it</strong>You get notified with a total once it's ready.</p>
            </div>
            <div class="mkt-how-step">
                <div class="mkt-how-num">4</div>
                <p><strong>Pay &amp; collect</strong>Pick it up at the storehouse, or have it delivered to your town.</p>
            </div>
        </div>
    </div>

</main>

<script>
function mktFilter(q) {
    q = q.trim().toLowerCase();
    var cards = document.querySelectorAll('#mkt-grid .mkt-card');
    var visible = 0;
    cards.forEach(function (c) {
        var match = c.getAttribute('data-name').indexOf(q) !== -1;
        c.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('mkt-no-results').style.display = visible === 0 ? '' : 'none';
}

function mktFormatCountdown(ms) {
    var s = Math.floor(ms / 1000);
    var d = Math.floor(s / 86400); s -= d * 86400;
    var h = Math.floor(s / 3600); s -= h * 3600;
    var m = Math.floor(s / 60); s -= m * 60;
    var parts = [];
    if (d > 0) parts.push(d + 'd');
    if (d > 0 || h > 0) parts.push(h + 'h');
    if (d > 0 || h > 0 || m > 0) parts.push(m + 'm');
    parts.push(s + 's');
    return parts.join(' ');
}
// Phase is computed live from all three timestamps every tick, so a card
// seamlessly moves preorder → market day → closing-soon → closed without
// ever needing a page refresh.
function mktUpdateCountdowns() {
    var now = Date.now();
    document.querySelectorAll('.mkt-countdown').forEach(function (el) {
        var preorderStart = new Date(el.getAttribute('data-preorder-start')).getTime();
        var marketDay      = new Date(el.getAttribute('data-market-day')).getTime();
        var orderClose     = new Date(el.getAttribute('data-order-close')).getTime();

        var phase;
        if (now < preorderStart)      phase = { target: preorderStart, label: 'left to start placing orders', icon: '⏳', cls: 'pre' };
        else if (now < marketDay)     phase = { target: marketDay,     label: 'left for market day',          icon: '🗓️', cls: 'pre' };
        else if (now <= orderClose)   phase = { target: orderClose,    label: 'left to place orders',         icon: '⏰', cls: 'day' };
        else                          phase = null;

        var textEl = el.querySelector('.mkt-countdown-text');
        var iconEl = el.querySelector('.mkt-countdown-icon');
        el.classList.remove('pre', 'day', 'urgent');

        if (!phase) {
            textEl.textContent = 'Orders closed for this cycle';
            iconEl.textContent = '🔒';
            return;
        }
        var diff = phase.target - now;
        var urgent = phase.cls === 'day' && diff < 3600000; // under 1 hour left to order
        el.classList.add(phase.cls);
        if (urgent) el.classList.add('urgent');
        iconEl.textContent = urgent ? '🔥' : phase.icon;
        textEl.textContent = mktFormatCountdown(diff) + ' ' + phase.label;
    });
}
if (document.querySelector('.mkt-countdown')) {
    mktUpdateCountdowns();
    setInterval(mktUpdateCountdowns, 1000);
}
</script>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user) { require __DIR__ . '/partials/bottom_nav.php'; } ?>
</body>
</html>

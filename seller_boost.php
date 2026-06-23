<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
$user = current_user();
$shop = get_shop_by_user((int)$user['id']);

if (!$shop) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$flash  = get_flash();
$error  = '';

// Boost type from query string: featured_product | sponsored_product | featured_shop | sponsored_shop
$boostType = $_GET['type'] ?? 'featured_product';
$productId = (int)($_GET['product_id'] ?? 0);
$validTypes = ['featured_product','sponsored_product','featured_shop','sponsored_shop'];
if (!in_array($boostType, $validTypes, true)) $boostType = 'featured_product';

// Pricing from platform settings
$prices = [
    'featured_product'  => ['7'=>(float)get_platform_setting('mp_featured_product_7day_price','15.00'),  '30'=>(float)get_platform_setting('mp_featured_product_30day_price','40.00')],
    'sponsored_product' => ['7'=>(float)get_platform_setting('mp_featured_product_7day_price','15.00'),  '30'=>(float)get_platform_setting('mp_featured_product_30day_price','40.00')],
    'featured_shop'     => ['7'=>(float)get_platform_setting('mp_featured_shop_7day_price','20.00'),     '30'=>(float)get_platform_setting('mp_featured_shop_30day_price','55.00')],
    'sponsored_shop'    => ['7'=>(float)get_platform_setting('mp_featured_shop_7day_price','20.00'),     '30'=>(float)get_platform_setting('mp_featured_shop_30day_price','55.00')],
];
$activePrices = $prices[$boostType];

// Load product if applicable
$product = null;
if ($productId && str_contains($boostType, 'product')) {
    $product = get_product($productId);
    if (!$product || (int)$product['shop_id'] !== (int)$shop['id']) {
        flash('Product not found.', 'error');
        header('Location: seller_dashboard.php?tab=products');
        exit;
    }
}

// Seller's current boosts
$activeBoostStmt = $pdo->prepare(
    "SELECT * FROM mp_boost_orders WHERE shop_id=? AND status='active' AND end_date >= CURDATE() ORDER BY boost_type, end_date DESC"
);
$activeBoostStmt->execute([$shop['id']]);
$activeBoosts = $activeBoostStmt->fetchAll();

// Payment methods
$paymentMethods = ['mtn_momo'=>'MTN Mobile Money','telecel'=>'Telecel Cash','airtel'=>'AirtelTigo Money','wallet'=>'Wallet Balance'];

// ── Handle submission ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $postBoostType = $_POST['boost_type'] ?? $boostType;
    $postProductId = (int)($_POST['product_id'] ?? 0);
    $days          = (int)($_POST['package_days'] ?? 7);
    $method        = $_POST['payment_method'] ?? '';
    $mobi          = trim($_POST['mobi_number'] ?? '');

    if (!in_array($postBoostType, $validTypes, true)) $error = 'Invalid boost type.';
    elseif (!in_array($days, [7,30], true)) $error = 'Select a package.';
    elseif (!array_key_exists($method, $paymentMethods)) $error = 'Select a payment method.';
    elseif ($method !== 'wallet' && $mobi === '') $error = 'Enter your mobile money number.';
    elseif (str_contains($postBoostType, 'product') && !$postProductId) $error = 'Select a product to boost.';

    if (!$error) {
        $price = $prices[$postBoostType][(string)$days] ?? 0.00;
        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+{$days} days"));

        $pdo->prepare(
            'INSERT INTO mp_boost_orders (shop_id, product_id, boost_type, package_days, price_paid, payment_method, mobi_number, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,?,?,\'pending\')'
        )->execute([
            $shop['id'],
            str_contains($postBoostType, 'product') ? $postProductId : null,
            $postBoostType, $days, $price, $method, $mobi ?: null, $start, $end,
        ]);
        $boostId = (int)$pdo->lastInsertId();

        // Notify admins
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll();
        foreach ($admins as $adm) {
            notify_user((int)$adm['id'], 'New Boost Order Request',
                $shop['shop_name'] . ' submitted a ' . ucwords(str_replace('_',' ',$postBoostType)) . ' boost order (GHS ' . number_format($price,2) . '). Review in Admin → Marketplace.',
                'info');
        }
        notify_user((int)$user['id'], 'Boost Order Submitted',
            'Your boost order has been submitted. An admin will activate it once payment is confirmed.',
            'info');

        $requiresPayment = get_platform_setting('delivery_sponsored_requires_payment', '0') === '1' && $price > 0;
        if ($requiresPayment) {
            // Redirect to Paystack checkout
            header('Location: pay_mp_boost.php?boost_id=' . $boostId);
        } else {
            flash('Boost order submitted! Admin will activate it once payment is confirmed.', 'success');
            header('Location: seller_dashboard.php?tab=products');
        }
        exit;
    }
}

// Products for selector
$myProductsStmt = $pdo->prepare("SELECT id, name FROM mp_products WHERE shop_id=? AND status='approved' ORDER BY name");
$myProductsStmt->execute([$shop['id']]);
$myProducts = $myProductsStmt->fetchAll();

$boostLabels = [
    'featured_product'  => ['icon'=>'⭐','label'=>'Featured Product', 'desc'=>'Your product appears at the top of marketplace search results and homepage.'],
    'sponsored_product' => ['icon'=>'🌟','label'=>'Sponsored Product','desc'=>'Highlighted "Sponsored" badge — maximum visibility in listings.'],
    'featured_shop'     => ['icon'=>'🏆','label'=>'Featured Shop',    'desc'=>'Your shop appears prominently in the seller directory.'],
    'sponsored_shop'    => ['icon'=>'💎','label'=>'Sponsored Shop',   'desc'=>'Sponsored badge on your shop — top placement in all shop listings.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boost Your Listing — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bst-shell { max-width:680px; margin:0 auto; padding:18px 16px 80px; }
        .bst-types { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:16px; }
        .bst-type input { display:none; }
        .bst-type label { display:block; cursor:pointer; border:2px solid var(--border); border-radius:12px; padding:14px; text-align:center; transition:all .12s; }
        .bst-type input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .bst-icon { font-size:1.8rem; display:block; margin-bottom:6px; }
        .bst-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .bst-card-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .bst-pkgs { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:4px; }
        .bst-pkg input { display:none; }
        .bst-pkg label { display:block; cursor:pointer; border:2px solid var(--border); border-radius:10px; padding:12px; text-align:center; transition:all .12s; }
        .bst-pkg input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .bst-price { font-size:1.3rem; font-weight:900; color:var(--primary,#0f766e); }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .bst-active { background:var(--surface); border:1px solid #6ee7b7; border-radius:12px; padding:14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:.84rem; }
        @media(max-width:460px){ .bst-types { grid-template-columns:1fr; } .bst-pkgs { grid-template-columns:1fr; } }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="seller_dashboard.php" class="button button-secondary button-small">← Dashboard</a>
    <span class="brand">⚡ Boost Listing</span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="bst-shell">

    <!-- Active boosts -->
    <?php if ($activeBoosts): ?>
    <p style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Active Boosts</p>
    <?php foreach ($activeBoosts as $b):
        $bl = $boostLabels[$b['boost_type']] ?? [];
    ?>
    <div class="bst-active">
        <div><?php echo $bl['icon'] ?? '⭐'; ?> <strong><?php echo sanitize($bl['label'] ?? ucfirst($b['boost_type'])); ?></strong>
            <?php if ($b['product_id']): ?>
                <?php $pnStmt=$pdo->prepare('SELECT name FROM mp_products WHERE id=?'); $pnStmt->execute([$b['product_id']]); $pn=$pnStmt->fetchColumn(); ?>
                — <?php echo sanitize((string)$pn); ?>
            <?php endif; ?>
        </div>
        <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">Expires <?php echo date('d M Y',strtotime($b['end_date'])); ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="seller_boost.php">
        <?php echo csrf_field(); ?>

        <!-- 1. Select boost type -->
        <div class="bst-card">
            <p class="bst-card-title">What do you want to boost?</p>
            <div class="bst-types">
                <?php foreach ($boostLabels as $type => $info): ?>
                <div class="bst-type">
                    <input type="radio" name="boost_type" id="bt_<?php echo $type; ?>" value="<?php echo $type; ?>"
                           <?php echo $boostType===$type?'checked':''; ?>
                           onchange="toggleProductRow()">
                    <label for="bt_<?php echo $type; ?>">
                        <span class="bst-icon"><?php echo $info['icon']; ?></span>
                        <strong style="font-size:.86rem;"><?php echo sanitize($info['label']); ?></strong>
                        <span style="display:block;font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:4px;"><?php echo sanitize($info['desc']); ?></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 2. Select product (if product boost) -->
        <div class="bst-card" id="product-row" style="display:<?php echo str_contains($boostType,'product')?'block':'none'; ?>;">
            <p class="bst-card-title">Select Product</p>
            <div class="form-group">
                <select name="product_id" id="product_select">
                    <option value="">— Choose a product —</option>
                    <?php foreach ($myProducts as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $productId===$p['id']?'selected':''; ?>><?php echo sanitize($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$myProducts): ?><p style="font-size:.8rem;color:#f59e0b;margin-top:6px;">No approved products yet. <a href="seller_product_form.php">Add a product →</a></p><?php endif; ?>
            </div>
        </div>

        <!-- 3. Package -->
        <div class="bst-card">
            <p class="bst-card-title">Choose Package</p>
            <div class="bst-pkgs">
                <div class="bst-pkg">
                    <input type="radio" name="package_days" id="pkg7" value="7" checked onchange="updatePrice()">
                    <label for="pkg7">
                        <span class="bst-price" id="price_7">GHS <?php echo number_format($activePrices['7'],2); ?></span>
                        <span style="font-size:.8rem;color:var(--text-muted,#6b7280);">7 Days</span>
                    </label>
                </div>
                <div class="bst-pkg">
                    <input type="radio" name="package_days" id="pkg30" value="30" onchange="updatePrice()">
                    <label for="pkg30">
                        <span class="bst-price" id="price_30">GHS <?php echo number_format($activePrices['30'],2); ?></span>
                        <span style="font-size:.8rem;color:var(--text-muted,#6b7280);">30 Days</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 4. Payment -->
        <div class="bst-card">
            <p class="bst-card-title">Payment Method</p>
            <?php foreach ($paymentMethods as $v=>$l): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer;font-size:.88rem;">
                <input type="radio" name="payment_method" value="<?php echo $v; ?>" <?php echo $v==='mtn_momo'?'checked':''; ?>
                       onchange="document.getElementById('mobi-row').style.display=this.value==='wallet'?'none':'block';">
                <?php echo sanitize($l); ?>
            </label>
            <?php endforeach; ?>
            <div style="margin-top:10px;" id="mobi-row">
                <label>Mobile Money Number *</label>
                <input type="tel" name="mobi_number" placeholder="e.g. 0244000000">
            </div>
        </div>

        <div style="background:var(--primary-soft,#d1fae5);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:.84rem;">
            💡 <strong>How it works:</strong> Submit your order → admin confirms payment → boost activates immediately. Active boosts improve your visibility in search and listings.
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
            Submit Boost Order →
        </button>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>

<script>
var priceData = <?php echo json_encode($prices); ?>;

function toggleProductRow() {
    var sel = document.querySelector('input[name="boost_type"]:checked');
    var show = sel && (sel.value.indexOf('product') !== -1);
    document.getElementById('product-row').style.display = show ? 'block' : 'none';
    updatePrice();
}

function updatePrice() {
    var type = (document.querySelector('input[name="boost_type"]:checked') || {}).value || 'featured_product';
    var p = priceData[type] || {};
    var p7  = document.getElementById('price_7');
    var p30 = document.getElementById('price_30');
    if (p7)  p7.textContent  = 'GHS ' + (p['7']  || 0).toFixed(2);
    if (p30) p30.textContent = 'GHS ' + (p['30'] || 0).toFixed(2);
}
</script>
</body>
</html>

<?php
/**
 * "Special Offers" — browse/claim eligible promotions, redeem a promo code,
 * and view your own claimed promotions (?view=mine). Free-access enforcement
 * happens in user_has_complimentary_access() (functions.php); discount
 * enforcement happens in initializePayment() (paystack.php) — this page only
 * handles browsing and claiming.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('promotions', 'Promotions');
require_login();

$user = current_user();
$view = ($_GET['view'] ?? '') === 'mine' ? 'mine' : 'browse';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'claim') {
        $promotionId = (int)($_POST['promotion_id'] ?? 0);
        $result = claim_promotion((int)$user['id'], $promotionId);
        if ($result['ok']) {
            $stmt = $pdo->prepare('SELECT name FROM promotions WHERE id=?');
            $stmt->execute([$promotionId]);
            $name = $stmt->fetchColumn();
            notify_user(
                (int)$user['id'], '✅ Promotion Activated',
                'Your promotion "' . $name . '" is active until ' . date('d M Y', strtotime($result['claim']['expiry_date'])) . '.',
                'success', 'promotions.php?view=mine'
            );
            flash('Promotion claimed! It\'s active until ' . date('d M Y', strtotime($result['claim']['expiry_date'])) . '.', 'success');
            header('Location: promotions.php?view=mine'); exit;
        }
        flash($result['error'], 'error');
        header('Location: promotions.php'); exit;
    }

    if ($action === 'redeem_code') {
        $code = strtoupper(trim($_POST['promo_code'] ?? ''));
        if ($code === '') {
            flash('Please enter a promo code.', 'error');
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM promotions WHERE promo_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $promo = $stmt->fetch();
            if (!$promo) {
                flash('That promo code was not found.', 'error');
            } else {
                $result = claim_promotion((int)$user['id'], (int)$promo['id']);
                if ($result['ok']) {
                    notify_user(
                        (int)$user['id'], '✅ Promotion Activated',
                        'Your promotion "' . $promo['name'] . '" is active until ' . date('d M Y', strtotime($result['claim']['expiry_date'])) . '.',
                        'success', 'promotions.php?view=mine'
                    );
                    flash('Promo code applied! Your promotion is active until ' . date('d M Y', strtotime($result['claim']['expiry_date'])) . '.', 'success');
                    header('Location: promotions.php?view=mine'); exit;
                }
                flash($result['error'], 'error');
            }
        }
        header('Location: promotions.php'); exit;
    }
}

$PROMO_ICONS = [
    'free_package'          => '📦',
    'free_listing'          => '📋',
    'free_featured_listing' => '⭐',
    'discount'               => '💸',
    'free_service'           => '🛠️',
];
$FEATURE_KEYS = all_complimentary_features();

if ($view === 'browse') {
    $eligible = eligible_promotions_for_user((int)$user['id'], 20);
} else {
    $stmt = $pdo->prepare(
        "SELECT pc.*, p.name AS promotion_name, p.description, p.type
         FROM promotion_claims pc JOIN promotions p ON pc.promotion_id = p.id
         WHERE pc.user_id = ? ORDER BY pc.claimed_at DESC"
    );
    $stmt->execute([$user['id']]);
    $myClaims = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Special Offers | ' . APP_NAME,
        'description' => 'Claim free access and discounts on AkuapemConnect features.',
        'url'         => rtrim(BASE_URL, '/') . '/promotions.php',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pr-shell  { max-width: 700px; margin: 0 auto; padding: 16px 16px 80px; }
        .pr-tabs   { display:flex; gap:5px; margin-bottom:16px; }
        .pr-tab    { padding:6px 14px; border-radius:20px; border:1px solid var(--border); font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .pr-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .pr-card   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:18px; margin-bottom:14px; }
        .pr-offer-icon  { font-size:1.8rem; margin-bottom:6px; }
        .pr-offer-title { font-weight:800; font-size:1rem; margin-bottom:4px; }
        .pr-offer-desc  { font-size:.86rem; color:var(--text-muted,#6b7280); margin-bottom:10px; }
        .pr-offer-meta  { font-size:.76rem; color:var(--text-muted,#6b7280); margin-bottom:12px; }
        .pr-code-form   { display:flex; gap:8px; }
        .pr-code-form input { flex:1; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:.9rem; text-transform:uppercase; }
        .pr-badge  { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .pr-badge.active  { background:#d1fae5; color:#065f46; }
        .pr-badge.expired { background:#f3f4f6; color:#9ca3af; }
        .pr-badge.revoked { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="index.php" class="button button-secondary button-small">← Home</a>
    <span class="brand">🎁 Special Offers</span>
</header>

<main class="pr-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <div class="pr-tabs">
        <a href="promotions.php" class="pr-tab <?php echo $view === 'browse' ? 'active' : ''; ?>">Available Offers</a>
        <a href="promotions.php?view=mine" class="pr-tab <?php echo $view === 'mine' ? 'active' : ''; ?>">My Promotions</a>
    </div>

    <?php if ($view === 'browse'): ?>

        <?php if (!$eligible): ?>
        <div class="pr-card" style="text-align:center;color:var(--text-muted,#6b7280);">
            <p style="margin:0;">No offers available for you right now — check back later.</p>
        </div>
        <?php else: foreach ($eligible as $p): ?>
        <div class="pr-card">
            <div class="pr-offer-icon"><?php echo $PROMO_ICONS[$p['type']] ?? '🎁'; ?></div>
            <div class="pr-offer-title"><?php echo sanitize($p['name']); ?></div>
            <?php if ($p['description']): ?><div class="pr-offer-desc"><?php echo sanitize($p['description']); ?></div><?php endif; ?>
            <div class="pr-offer-meta">
                <?php if ($p['type'] === 'discount'): ?>
                <?php echo (int)$p['discount_percent']; ?>% off <?php echo sanitize($FEATURE_KEYS[$p['feature_key']] ?? $p['feature_key']); ?> for <?php echo (int)$p['duration_days']; ?> days
                <?php else: ?>
                Free <?php echo sanitize($FEATURE_KEYS[$p['feature_key']] ?? $p['feature_key']); ?> for <?php echo (int)$p['duration_days']; ?> days
                <?php endif; ?>
            </div>
            <form method="post" action="promotions.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="claim">
                <input type="hidden" name="promotion_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" class="button button-primary" style="width:100%;">Claim Offer</button>
            </form>
        </div>
        <?php endforeach; endif; ?>

        <div class="pr-card">
            <p style="margin:0 0 8px;font-weight:700;font-size:.86rem;">Have a promo code?</p>
            <form method="post" action="promotions.php" class="pr-code-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="redeem_code">
                <input type="text" name="promo_code" placeholder="e.g. AKUAPEM2026" required>
                <button type="submit" class="button button-primary">Apply</button>
            </form>
        </div>

    <?php else: /* view = mine */ ?>

        <?php if (!$myClaims): ?>
        <div class="pr-card" style="text-align:center;color:var(--text-muted,#6b7280);">
            <p style="margin:0;">You haven't claimed any promotions yet.</p>
        </div>
        <?php else: foreach ($myClaims as $c):
            $displayStatus = ($c['status'] === 'active' && $c['expiry_date'] < date('Y-m-d')) ? 'expired' : $c['status'];
        ?>
        <div class="pr-card">
            <div class="pr-offer-icon"><?php echo $PROMO_ICONS[$c['type']] ?? '🎁'; ?></div>
            <div class="pr-offer-title"><?php echo sanitize($c['promotion_name']); ?></div>
            <p class="pr-badge <?php echo $displayStatus; ?>"><?php echo $displayStatus === 'active' ? '✅ Active' : ucfirst($displayStatus); ?></p>
            <div class="pr-offer-meta" style="margin-top:8px;">
                Claimed <?php echo date('d M Y', strtotime($c['claimed_at'])); ?> ·
                <?php echo $displayStatus === 'active' ? 'Active until' : 'Expired'; ?> <?php echo date('d M Y', strtotime($c['expiry_date'])); ?>
            </div>
        </div>
        <?php endforeach; endif; ?>

    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

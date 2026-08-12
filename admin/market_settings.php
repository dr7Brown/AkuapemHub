<?php
/**
 * Dedicated global settings page for Periodic Markets — deliberately
 * separate from the regular Marketplace's settings (admin/marketplace.php)
 * since storehouse pickup/home delivery is a different process from a
 * shipped delivery and the two are expected to diverge over time.
 * Per-market config (schedule, pickup fee, delivery towns, managers) lives
 * on markets.php instead — this page is for settings that apply platform-wide
 * across every periodic market.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$flash     = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $days = max(0, (int)($_POST['market_payout_confirmation_days'] ?? 3));
        set_platform_setting('market_payout_confirmation_days', (string)$days);

        $chargeType  = ($_POST['market_system_charge_type'] ?? '') === 'percent' ? 'percent' : 'flat';
        $chargeValue = max(0, (float)($_POST['market_system_charge_value'] ?? 0));
        set_platform_setting('market_system_charge_type', $chargeType);
        set_platform_setting('market_system_charge_value', (string)$chargeValue);

        log_audit_action($adminUser['id'], 'market_settings_updated',
            "Set market payout confirmation window to {$days} day(s), system charge to " .
            ($chargeType === 'percent' ? "{$chargeValue}%" : "GH₵{$chargeValue}"));
        flash('Settings saved.', 'success');
        header('Location: market_settings.php'); exit;
    }
}

$marketPayoutDays = (int)get_platform_setting('market_payout_confirmation_days', get_platform_setting('mp_payout_confirmation_days', 3));
$systemChargeType  = get_platform_setting('market_system_charge_type', 'flat');
$systemChargeValue = (float)get_platform_setting('market_system_charge_value', '0');

// Snapshot stats, purely informational — helps an admin sanity-check the
// scope of what these settings apply to before changing them.
$marketCount        = (int)$pdo->query('SELECT COUNT(*) FROM markets')->fetchColumn();
$deliveryTownsCount  = (int)$pdo->query('SELECT COUNT(*) FROM market_delivery_towns')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Periodic Market Settings — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ms-shell { max-width: 720px; margin: 0 auto; padding: 18px 16px 60px; }
        .ms-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
        .ms-stats { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:4px; }
        .ms-stat  { font-size:.84rem; color:var(--text-muted,#6b7280); }
        .ms-stat strong { color:var(--text,#111); font-size:1rem; display:block; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="markets.php" class="button button-secondary button-small">← Periodic Markets</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">⚙️ Periodic Market Settings</h1>
</header>

<main class="ms-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <div class="ms-card">
        <div class="ms-stats">
            <div class="ms-stat"><strong><?php echo $marketCount; ?></strong>Markets</div>
            <div class="ms-stat"><strong><?php echo $deliveryTownsCount; ?></strong>Delivery towns priced across all markets</div>
        </div>
    </div>

    <div class="ms-card">
        <h2 style="margin-top:0;font-size:1rem;">Payouts &amp; Charges</h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-top:-8px;">
            These settings apply to every periodic market, and are intentionally separate from the
            regular Marketplace's own settings (<a href="marketplace.php?tab=settings">Marketplace → Settings</a>).
        </p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="form-group" style="max-width:320px;">
                <label for="market_payout_confirmation_days">Seller payout confirmation window (days)</label>
                <input type="number" id="market_payout_confirmation_days" name="market_payout_confirmation_days" min="0" max="30" value="<?php echo $marketPayoutDays; ?>">
                <p class="form-hint">How long after a buyer's order is marked handed over (pickup or delivery) before the seller's payout is released, if the buyer raises no dispute.</p>
            </div>

            <div class="form-group" style="max-width:420px;">
                <label>System Charge</label>
                <div style="display:flex;gap:14px;margin-bottom:8px;">
                    <label style="font-weight:400;display:flex;align-items:center;gap:5px;">
                        <input type="radio" name="market_system_charge_type" value="flat" <?php echo $systemChargeType !== 'percent' ? 'checked' : ''; ?>> Flat GH&#8373;
                    </label>
                    <label style="font-weight:400;display:flex;align-items:center;gap:5px;">
                        <input type="radio" name="market_system_charge_type" value="percent" <?php echo $systemChargeType === 'percent' ? 'checked' : ''; ?>> % of item total
                    </label>
                </div>
                <input type="number" name="market_system_charge_value" min="0" step="0.01" value="<?php echo $systemChargeValue; ?>">
                <p class="form-hint">Added on top of the item total and pickup/delivery fee at payment — every market custom order pays the same rate. Leave at 0 to charge nothing.</p>
            </div>

            <button type="submit" class="button button-primary button-small">Save Settings</button>
        </form>
    </div>

    <div class="ms-card">
        <h2 style="margin-top:0;font-size:1rem;">Per-Market Configuration</h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:0;">
            Schedule (weekly/monthly recurrence &amp; order window), storehouse pickup fee,
            per-town home-delivery pricing, and assigned managers are all set per market — open a
            market's <strong>Manage</strong> panel from the <a href="markets.php">Periodic Markets</a>
            list to edit those.
        </p>
    </div>

</main>
</body>
</html>

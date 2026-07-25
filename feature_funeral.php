<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user = current_user();
$faId = (int)($_GET['id'] ?? $_POST['funeral_id'] ?? 0);

if (!$faId) { header('Location: my_funerals.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM funeral_announcements WHERE id=? AND user_id=? LIMIT 1");
$stmt->execute([$faId, $user['id']]);
$fa = $stmt->fetch();

if (!$fa) {
    flash('Announcement not found or not yours.', 'error');
    header('Location: my_funerals.php'); exit;
}
if ($fa['status'] !== 'approved') {
    flash('Only approved announcements can be featured.', 'info');
    header('Location: my_funerals.php'); exit;
}

$isPaid     = is_feature_paid('enable_paid_featured_funerals');
$featEnd    = $fa['featured_end_date'] ?? null;
$featActive = !empty($fa['featured']) && ($featEnd === null || $featEnd >= date('Y-m-d'));
$renewSoon  = !empty($fa['featured']) && $featEnd !== null && $featEnd < date('Y-m-d', strtotime('+7 days'));

$existing = $pdo->prepare("SELECT id, reference_code, gateway FROM platform_payments WHERE user_id=? AND payment_type='featured_funeral' AND reference_id=? AND status='pending' LIMIT 1");
$existing->execute([$user['id'], $faId]);
$existingPay = $existing->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($existingPay) {
        flash('A payment is already in progress. Complete it first.', 'info');
        header('Location: my_payments.php'); exit;
    }

    if (!$isPaid) {
        $pdo->prepare("UPDATE funeral_announcements SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=?")
            ->execute([$faId]);
        log_audit_action($user['id'], 'feature_funeral', "Featured funeral #{$faId} '{$fa['deceased_name']}' — free mode");
        flash('Announcement is now featured for 30 days!', 'success');
        header('Location: my_funerals.php'); exit;
    }

    $packageId = (int)($_POST['package_id'] ?? 0);
    $pkg = $pdo->prepare("SELECT * FROM featured_funeral_packages WHERE id=? AND status='active'");
    $pkg->execute([$packageId]);
    $package = $pkg->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: feature_funeral.php?id=' . $faId); exit;
    }

    $result = initializePayment(
        $user['id'], $user['email'],
        'featured_funeral', $faId, $packageId,
        (float)$package['price'],
        ['deceased_name' => $fa['deceased_name']]
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: feature_funeral.php?id=' . $faId); exit;
    }

    log_audit_action($user['id'], 'feature_funeral_checkout', "Paystack checkout for featuring funeral #{$faId} — {$package['name']} GH₵{$package['price']}");
    header('Location: ' . $result['checkout_url']); exit;
}

$packages = get_active_packages('featured_funeral_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Announcement — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pkg-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; margin:18px 0; }
        .pkg-card { border:2px solid var(--border); border-radius:12px; padding:16px; text-align:center; cursor:pointer; transition:border-color .15s; }
        .pkg-card:has(input:checked) { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .pkg-card input { display:none; }
        .pkg-card strong { display:block; font-size:1rem; font-weight:900; }
        .pkg-card .price { font-size:1.3rem; font-weight:900; color:var(--primary,#0f766e); margin:6px 0; }
        .pkg-card .days  { font-size:.76rem; color:var(--muted,#6b7280); }
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <a href="my_funerals.php" class="button button-secondary button-small">← My Announcements</a>
    <span class="brand">⭐ Feature Announcement</span>
</header>
<main class="page-shell small-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <div class="card">
        <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:12px 14px;margin-bottom:18px;border:1px solid var(--border);">
            <div style="font-weight:800;font-size:.92rem;">🕊️ <?php echo sanitize($fa['deceased_name']); ?></div>
            <?php if ($fa['burial_date']): ?>
            <div style="font-size:.78rem;color:var(--muted,#6b7280);margin-top:3px;">⚰️ <?php echo date('d M Y', strtotime($fa['burial_date'])); ?></div>
            <?php endif; ?>
            <?php if ($featActive): ?>
            <div style="margin-top:8px;font-size:.78rem;background:#fef3c7;border-radius:6px;padding:4px 8px;display:inline-block;">
                ⭐ Featured until <strong><?php echo date('d M Y', strtotime($featEnd)); ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <h3 style="font-size:.9rem;font-weight:800;margin:0 0 6px;">Why feature this announcement?</h3>
        <ul style="font-size:.84rem;color:var(--muted,#6b7280);padding-left:18px;line-height:1.9;margin:0 0 16px;">
            <li>Pinned to the top of funeral announcements with a ⭐ badge</li>
            <li>Shown in the community homepage featured section</li>
            <li>Reaches more community members during the mourning period</li>
        </ul>

        <?php if ($existingPay): ?>
        <div class="alert alert-info">
            A payment is already in progress (ref: <strong><?php echo sanitize(strtoupper($existingPay['reference_code'])); ?></strong>).
            <br><a href="resume_payment.php?id=<?php echo (int)$existingPay['id']; ?>" style="color:var(--primary);">Complete payment →</a>
        </div>
        <?php elseif (!$isPaid): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;text-align:center;margin-bottom:16px;">
            <p style="margin:0;font-size:.86rem;color:#166534;font-weight:700;">✨ Featuring is currently free!</p>
        </div>
        <form method="post" action="feature_funeral.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="funeral_id" value="<?php echo $faId; ?>">
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">
                ⭐ Feature This Announcement (Free)
            </button>
        </form>
        <?php elseif ($packages): ?>
        <form method="post" action="feature_funeral.php" id="feat-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="funeral_id" value="<?php echo $faId; ?>">
            <p style="font-size:.82rem;color:var(--muted,#6b7280);margin:0 0 6px;">Choose a featuring package:</p>
            <div class="pkg-grid">
            <?php foreach ($packages as $pkg): ?>
            <label class="pkg-card">
                <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required>
                <strong><?php echo sanitize($pkg['name']); ?></strong>
                <div class="price">GH₵ <?php echo number_format((float)$pkg['price'],2); ?></div>
                <div class="days"><?php echo (int)$pkg['duration_days']; ?> days</div>
            </label>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">
                🔒 Pay &amp; Feature This Announcement
            </button>
            <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout via Paystack · Card &amp; Mobile Money</p>
        </form>
        <?php else: ?>
        <div class="empty-state">No featuring packages available. Contact admin.</div>
        <?php endif; ?>
    </div>
</main>
<?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

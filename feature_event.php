<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('events', 'Events');
require_login();
$user    = current_user();
$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);

if (!$eventId) { header('Location: my_events.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM events WHERE id=? AND user_id=? LIMIT 1");
$stmt->execute([$eventId, $user['id']]);
$event = $stmt->fetch();

if (!$event) {
    flash('Event not found or not yours.', 'error');
    header('Location: my_events.php'); exit;
}
if ($event['status'] !== 'published') {
    flash('Only published events can be featured.', 'info');
    header('Location: my_events.php'); exit;
}

$isPaid     = is_feature_paid('enable_paid_featured_events');
$featEnd    = $event['featured_end_date'] ?? null;
$featActive = !empty($event['featured']) && ($featEnd === null || $featEnd >= date('Y-m-d'));
$renewSoon  = !empty($event['featured']) && $featEnd !== null && $featEnd < date('Y-m-d', strtotime('+7 days'));

$existing = $pdo->prepare("SELECT id, reference_code, gateway FROM platform_payments WHERE user_id=? AND payment_type='featured_event' AND reference_id=? AND status='pending' LIMIT 1");
$existing->execute([$user['id'], $eventId]);
$existingPay = $existing->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($existingPay) {
        flash('A payment is already in progress. Complete it or cancel it first.', 'info');
        header('Location: my_payments.php'); exit;
    }

    if (!$isPaid) {
        // Free mode — activate immediately for 30 days
        $pdo->prepare("UPDATE events SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=?")
            ->execute([$eventId]);
        log_audit_action($user['id'], 'feature_event', "Featured event #{$eventId} '{$event['title']}' — free mode");
        flash('Your event is now featured for 30 days!', 'success');
        header('Location: my_events.php'); exit;
    }

    $packageId = (int)($_POST['package_id'] ?? 0);
    $pkg = $pdo->prepare("SELECT * FROM featured_event_packages WHERE id=? AND status='active'");
    $pkg->execute([$packageId]);
    $package = $pkg->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: feature_event.php?id=' . $eventId); exit;
    }

    $result = initializePayment(
        $user['id'], $user['email'],
        'featured_event', $eventId, $packageId,
        (float)$package['price'],
        ['event_title' => $event['title']]
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: feature_event.php?id=' . $eventId); exit;
    }

    log_audit_action($user['id'], 'feature_event_checkout', "Paystack checkout for featuring event #{$eventId} — {$package['name']} GH₵{$package['price']}");
    header('Location: ' . $result['checkout_url']); exit;
}

$packages = get_active_packages('featured_event_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Event — <?php echo APP_NAME; ?></title>
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
    <a href="my_events.php" class="button button-secondary button-small">← My Events</a>
    <span class="brand">⭐ Feature Event</span>
</header>
<main class="page-shell small-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <div class="card">
        <!-- Event summary -->
        <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:12px 14px;margin-bottom:18px;border:1px solid var(--border);">
            <div style="font-weight:800;font-size:.92rem;"><?php echo sanitize($event['title']); ?></div>
            <div style="font-size:.78rem;color:var(--muted,#6b7280);margin-top:3px;">
                📅 <?php echo date('d M Y', strtotime($event['start_date'])); ?>
                <?php if ($event['venue']): ?> · <?php echo sanitize(mb_substr($event['venue'],0,50)); ?><?php endif; ?>
            </div>
            <?php if ($featActive): ?>
            <div style="margin-top:8px;font-size:.78rem;background:#fef3c7;border-radius:6px;padding:4px 8px;display:inline-block;">
                ⭐ Currently featured until <strong><?php echo date('d M Y', strtotime($featEnd)); ?></strong>
                <?php if ($renewSoon): ?> — renewing soon<?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <h3 style="font-size:.9rem;font-weight:800;margin:0 0 6px;">Why feature your event?</h3>
        <ul style="font-size:.84rem;color:var(--muted,#6b7280);padding-left:18px;line-height:1.9;margin:0 0 16px;">
            <li>Appears at the top of the events listing with a ⭐ badge</li>
            <li>Highlighted in a distinct card style to grab attention</li>
            <li>Shown on the community homepage featured section</li>
        </ul>

        <?php if ($existingPay): ?>
        <div class="alert alert-info">
            A payment is already in progress for this event (ref: <strong><?php echo sanitize(strtoupper($existingPay['reference_code'])); ?></strong>).
            <br><a href="resume_payment.php?id=<?php echo (int)$existingPay['id']; ?>" style="color:var(--primary);">Complete payment →</a>
        </div>
        <?php elseif (!$isPaid): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;text-align:center;margin-bottom:16px;">
            <p style="margin:0;font-size:.86rem;color:#166534;font-weight:700;">✨ Featuring is currently free!</p>
            <p style="margin:4px 0 0;font-size:.78rem;color:#166534;">Your event will be featured for 30 days at no cost.</p>
        </div>
        <form method="post" action="feature_event.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">
                ⭐ Feature This Event (Free)
            </button>
        </form>
        <?php elseif ($packages): ?>
        <form method="post" action="feature_event.php" id="feat-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
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
                🔒 Pay &amp; Feature This Event
            </button>
            <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout via Paystack · Card &amp; Mobile Money</p>
        </form>
        <?php else: ?>
        <div class="empty-state">No featuring packages are available. Contact admin.</div>
        <?php endif; ?>
    </div>
</main>
<?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

<?php
/**
 * "My Listings" — own accommodation listings, mirrors my_news.php's shape
 * (own-records list, status badges, edit link, delete-if-draft-or-rejected).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');
require_login();
$user = current_user();

// Delete own draft/rejected listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $chk = $pdo->prepare("SELECT id, status FROM accommodation_listings WHERE id=? AND user_id=? LIMIT 1");
    $chk->execute([$did, $user['id']]);
    $row = $chk->fetch();
    if ($row && in_array($row['status'], ['draft', 'rejected'], true)) {
        $pdo->prepare("DELETE FROM accommodation_listings WHERE id=?")->execute([$did]);
        flash('Listing deleted.', 'info');
    }
    header('Location: my_accommodation.php');
    exit;
}

// Toggle availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['availability_id'])) {
    csrf_check();
    $aid    = (int)$_POST['availability_id'];
    $target = $_POST['availability_target'] ?? '';
    $valid  = ['available', 'unavailable', 'rented', 'temporarily_unavailable', 'fully_booked'];
    $chk = $pdo->prepare("SELECT id FROM accommodation_listings WHERE id=? AND user_id=? AND status='approved' LIMIT 1");
    $chk->execute([$aid, $user['id']]);
    if ($chk->fetch() && in_array($target, $valid, true)) {
        $pdo->prepare("UPDATE accommodation_listings SET availability_status=?, updated_at=NOW() WHERE id=?")->execute([$target, $aid]);
        flash('Availability updated.', 'success');
    }
    header('Location: my_accommodation.php');
    exit;
}

$listings = $pdo->prepare(
    'SELECT al.*, at.name AS type_name, at.icon AS type_icon,
            (SELECT image_path FROM accommodation_images WHERE listing_id=al.id AND is_primary=1 LIMIT 1) AS primary_image
     FROM accommodation_listings al
     JOIN accommodation_types at ON al.accommodation_type_id = at.id
     WHERE al.user_id = ? ORDER BY al.created_at DESC'
);
$listings->execute([$user['id']]);
$listings = $listings->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accommodation Listings — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ma-shell { max-width:760px; margin:0 auto; padding:20px 16px 80px; }
        .ma-card  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; margin-bottom:10px; display:flex; gap:12px; align-items:center; }
        .ma-img   { width:56px; height:56px; border-radius:8px; background:var(--surface-muted,#f9fafb); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; }
        .ma-img img { width:100%; height:100%; object-fit:cover; }
        .ma-badge { font-size:.66rem; font-weight:800; padding:2px 8px; border-radius:10px; display:inline-block; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="accommodation.php" class="button button-secondary button-small">← Accommodation</a>
    <span class="brand">My Listings</span>
    <a href="accommodation_form.php" class="button button-primary button-small">+ Add</a>
</header>

<main class="ma-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <?php if ($listings): foreach ($listings as $l):
        $isFeatured = !empty($l['featured']) && (empty($l['featured_end_date']) || $l['featured_end_date'] >= date('Y-m-d'));
    ?>
    <div class="ma-card">
        <div class="ma-img">
            <?php if ($l['primary_image']): ?><img src="<?php echo sanitize($l['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1.4rem;opacity:.3;"><?php echo $l['type_icon'] ?? '🏠'; ?></span><?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo sanitize($l['title']); ?></div>
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($l['type_name']); ?></div>
            <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;">
                <span class="ma-badge" style="background:<?php echo accommodation_status_color($l['status']); ?>22;color:<?php echo accommodation_status_color($l['status']); ?>;"><?php echo accommodation_status_label($l['status']); ?></span>
                <?php if ($l['status'] === 'approved'): ?>
                <span class="ma-badge" style="background:#f3f4f6;color:#6b7280;"><?php echo accommodation_availability_label($l['availability_status']); ?></span>
                <?php endif; ?>
                <?php if ($isFeatured): ?>
                <span class="ma-badge" style="background:#fef3c7;color:#92400e;">⭐ Featured</span>
                <?php endif; ?>
            </div>
            <?php if ($l['status'] === 'rejected' && $l['rejection_reason']): ?>
            <div style="font-size:.74rem;color:#c0392b;margin-top:4px;">❌ <?php echo sanitize($l['rejection_reason']); ?></div>
            <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;">
            <a href="accommodation_detail.php?id=<?php echo $l['id']; ?>" class="button button-secondary button-small">View</a>
            <a href="accommodation_form.php?id=<?php echo $l['id']; ?>" class="button button-secondary button-small">Edit</a>
            <?php if ($l['status'] === 'approved'): ?>
            <a href="feature_accommodation.php?id=<?php echo $l['id']; ?>" class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;"><?php echo $isFeatured ? '⭐ Renew' : '⭐ Feature'; ?></a>
            <?php endif; ?>
            <?php if (in_array($l['status'], ['draft','rejected'], true)): ?>
            <form method="post" onsubmit="return confirm('Delete this listing?');"><?php echo csrf_field(); ?><input type="hidden" name="delete_id" value="<?php echo $l['id']; ?>"><button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;width:100%;">Delete</button></form>
            <?php endif; ?>
            <?php if ($l['status'] === 'approved'): ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="availability_id" value="<?php echo $l['id']; ?>">
                <select name="availability_target" onchange="this.form.submit()" style="font-size:.72rem;padding:3px 5px;">
                    <?php foreach (['available','unavailable','rented','temporarily_unavailable','fully_booked'] as $v): ?>
                    <option value="<?php echo $v; ?>" <?php echo $l['availability_status']===$v?'selected':''; ?>><?php echo accommodation_availability_label($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);">
        <p>No accommodation listings yet. <a href="accommodation_form.php" style="color:var(--primary,#0f766e);">Add your first listing →</a></p>
    </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

<?php
/**
 * Accommodation listing detail — mirrors product.php's structure (gallery,
 * admin-preview banner, owner card) without cart/reviews, which don't apply
 * here. Contact/viewing/booking actions post to accommodation_enquiry.php;
 * reporting posts to report_accommodation.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { render_not_found('accommodation.php', 'Browse Accommodation', 'Listing not found.'); }

$listing = get_accommodation_listing($id);
$user    = current_user();

$isOwner       = $user && (int)$listing['user_id'] === (int)$user['id'];
$isAdminViewer = is_admin_or_manager();
$publicStatuses = ['approved'];

if (!$listing || ((!in_array($listing['status'], $publicStatuses, true) || $listing['owner_banned']) && !$isAdminViewer && !$isOwner)) {
    render_not_found('accommodation.php', 'Browse Accommodation', 'This listing is no longer available.');
}

$flash  = get_flash();
$images = get_accommodation_images($id);

// Only count views for publicly visible listings
$viewKey = 'viewed_accommodation_' . $id;
if (in_array($listing['status'], $publicStatuses, true) && empty($_SESSION[$viewKey])) {
    $pdo->prepare('UPDATE accommodation_listings SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
    $_SESSION[$viewKey] = true;
}

$facilityLabels = accommodation_facility_labels();
$listingFacilities = [];
foreach (json_decode($listing['facilities'] ?? '[]', true) ?: [] as $fid) {
    if (isset($facilityLabels[(int)$fid])) $listingFacilities[] = $facilityLabels[(int)$fid];
}

$displayPhone = $listing['contact_phone'] ?: $listing['owner_phone'];
$waLink = $listing['contact_whatsapp'] ? whatsapp_contact_link($listing['contact_whatsapp'], $listing['title']) : false;

$isHotel = $listing['type_category'] === 'hotel';
$isFeatured = !empty($listing['featured']) && (empty($listing['featured_end_date']) || $listing['featured_end_date'] >= date('Y-m-d'));

// "Keep browsing" feed — same owner's other listings first, then other
// public listings, so the page continues past this one listing instead of
// dead-ending. Mirrors product.php's Related Products query shape, but
// seeded by owner rather than category.
$moreLimit = 12;
$moreCardCols = "al.*, at.name AS type_name, at.icon AS type_icon, t.name AS town_name,
        (SELECT image_path FROM accommodation_images WHERE listing_id = al.id AND is_primary = 1 LIMIT 1) AS primary_image";
$moreJoins = "FROM accommodation_listings al
              JOIN accommodation_types at ON al.accommodation_type_id = at.id
              LEFT JOIN towns t ON al.town_id = t.id";

$sameOwnerSt = $pdo->prepare(
    "SELECT $moreCardCols $moreJoins
     WHERE al.user_id = ? AND al.id != ? AND " . accommodation_public_where() . "
     ORDER BY al.created_at DESC LIMIT $moreLimit"
);
$sameOwnerSt->execute([$listing['user_id'], $id]);
$moreListings = $sameOwnerSt->fetchAll();
foreach ($moreListings as &$ml) { $ml['same_owner'] = true; }
unset($ml);

$moreRemaining = $moreLimit - count($moreListings);
if ($moreRemaining > 0) {
    $excludeIds = array_merge([$id], array_column($moreListings, 'id'));
    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $othersSt = $pdo->prepare(
        "SELECT $moreCardCols $moreJoins
         WHERE al.id NOT IN ($placeholders) AND " . accommodation_public_where() . "
         ORDER BY (al.featured=1 AND (al.featured_end_date IS NULL OR al.featured_end_date>=CURDATE())) DESC, al.created_at DESC
         LIMIT $moreRemaining"
    );
    $othersSt->execute($excludeIds);
    foreach ($othersSt->fetchAll() as $o) { $o['same_owner'] = false; $moreListings[] = $o; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $adIsPublic = $listing['status'] === 'approved';
    $adImage    = $images ? (rtrim(BASE_URL,'/') . '/' . ltrim($images[0]['image_path'],'/')) : null;
    echo seo_meta([
        'title'       => $listing['title'] . ' | ' . APP_NAME . ' Accommodation',
        'description' => mb_substr(strip_tags($listing['description'] ?? ''), 0, 300) ?: ('View ' . $listing['title'] . ' on ' . APP_NAME . '.'),
        'image'       => $adImage,
        'url'         => rtrim(BASE_URL, '/') . '/accommodation_detail.php?id=' . (int)$listing['id'],
        'noindex'     => !$adIsPublic,
    ]);
    ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ad-topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .ad-shell  { max-width:1060px; margin:0 auto; padding:16px 16px 60px; }
        .ad-layout { display:grid; grid-template-columns:1fr 1fr; gap:28px; margin-bottom:28px; }
        @media(max-width:680px){ .ad-layout { grid-template-columns:1fr; } }
        .ad-main-img { aspect-ratio:4/3; background:#f8fafc; border-radius:14px; overflow:hidden; display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
        .ad-main-img img { width:100%; height:100%; object-fit:cover; }
        .ad-thumbs { display:flex; gap:8px; flex-wrap:wrap; }
        .ad-thumb  { width:60px; height:60px; border-radius:8px; overflow:hidden; border:2px solid var(--border); cursor:pointer; flex-shrink:0; }
        .ad-thumb.active { border-color:var(--primary,#0f766e); }
        .ad-thumb img { width:100%; height:100%; object-fit:cover; }
        .ad-title { font-size:1.3rem; font-weight:900; margin:0 0 8px; line-height:1.3; }
        .ad-price { font-size:1.5rem; font-weight:900; color:var(--primary,#0f766e); }
        .ad-meta  { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; font-size:.83rem; }
        .ad-meta-item { display:flex; align-items:center; gap:5px; color:var(--text-muted,#6b7280); }
        .ad-facilities { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
        .ad-facility { display:inline-flex; align-items:center; gap:5px; background:var(--surface-muted,#f9fafb); border:1px solid var(--border); border-radius:20px; padding:5px 11px; font-size:.8rem; }
        .ad-owner-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; display:flex; gap:12px; align-items:center; }
        .ad-actions { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; }
        .ad-wa-btn { background:#25D366; color:#fff; border-color:#25D366; display:inline-flex; align-items:center; gap:7px; }
        .ad-wa-btn:hover { background:#1ebe57; border-color:#1ebe57; }
        .adm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }
        .adm-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .15s, transform .15s; }
        .adm-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .adm-card--featured { border:2px solid #f59e0b; }
        .adm-card-img { aspect-ratio:4/3; background:linear-gradient(135deg,#f8fafc,#f1f5f9); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; position:relative; }
        .adm-card-img img { width:100%; height:100%; object-fit:cover; }
        .adm-card-icon { font-size:2.2rem; opacity:.3; }
        .adm-card-badge { position:absolute; top:6px; left:6px; background:var(--primary,#0f766e); color:#fff; font-size:.6rem; font-weight:800; padding:2px 7px; border-radius:10px; }
        .adm-card-body { padding:10px 12px 12px; flex:1; display:flex; flex-direction:column; }
        .adm-card-type { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--primary,#0f766e); margin-bottom:3px; }
        .adm-card-title { font-weight:700; font-size:.88rem; line-height:1.35; margin:0 0 4px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .adm-card-class { font-size:.72rem; font-weight:800; color:var(--primary,#0f766e); margin:-2px 0 4px; }
        .adm-card-loc { font-size:.74rem; color:var(--text-muted,#6b7280); margin-bottom:6px; }
        .adm-card-price { font-weight:900; font-size:.92rem; color:var(--primary,#0f766e); margin-top:auto; }
        @media(max-width:480px){ .adm-grid { grid-template-columns:repeat(2,1fr); } }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<?php if ($listing['status'] !== 'approved'): ?>
<div style="background:#fef3c7;border-bottom:2px solid #f59e0b;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:.85rem;">
    <span>
        ⚠️ <strong>Admin Preview</strong> — this listing is
        <strong style="color:<?php echo accommodation_status_color($listing['status']); ?>;"><?php echo accommodation_status_label($listing['status']); ?></strong>
        and not yet visible to the public.
        <?php if ($listing['rejection_reason']): ?>Rejection reason: <em><?php echo sanitize($listing['rejection_reason']); ?></em><?php endif; ?>
    </span>
    <?php if ($isAdminViewer && $listing['status'] === 'pending_approval'): ?>
    <div style="display:flex;gap:8px;">
        <form method="post" action="admin/accommodation.php?tab=listings"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_listing"><input type="hidden" name="listing_id" value="<?php echo $id; ?>"><input type="hidden" name="return_to" value="detail"><button type="submit" class="button button-primary button-small">✅ Approve</button></form>
        <form method="post" action="admin/accommodation.php?tab=listings" style="display:flex;gap:6px;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_listing"><input type="hidden" name="listing_id" value="<?php echo $id; ?>"><input type="hidden" name="return_to" value="detail"><input type="text" name="rejection_reason" placeholder="Rejection reason" style="font-size:.78rem;padding:4px 8px;width:180px;" required><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject this listing?');">❌ Reject</button></form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<header class="ad-topbar">
    <a href="accommodation_listings.php?category=<?php echo sanitize($listing['type_category']); ?>" class="button button-secondary button-small">← Back</a>
    <?php if (!$user): ?><a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a><?php endif; ?>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="ad-shell">
    <div class="ad-layout">
        <!-- Images -->
        <div>
            <div class="ad-main-img" id="main-img">
                <?php $firstImg = $images[0]['image_path'] ?? null; ?>
                <?php if ($firstImg): ?>
                    <img id="main-img-el" src="<?php echo sanitize($firstImg); ?>" alt="">
                <?php else: ?>
                    <span style="font-size:4rem;opacity:.3;">🏠</span>
                <?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
            <div class="ad-thumbs">
                <?php foreach ($images as $i => $img): ?>
                <div class="ad-thumb <?php echo $i===0?'active':''; ?>" onclick="switchImg(this,'<?php echo sanitize($img['image_path']); ?>')">
                    <img src="<?php echo sanitize($img['image_path']); ?>" alt="">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div>
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--primary,#0f766e);margin-bottom:4px;">
                <?php echo sanitize($listing['type_name']); ?>
                <?php if ($listing['verification_status'] === 'approved'): ?><span style="color:#10b981;">· ✓ Verified</span><?php endif; ?>
                <?php if ($isFeatured): ?><span style="color:#f59e0b;">· ⭐ Featured</span><?php endif; ?>
            </div>
            <h1 class="ad-title"><?php echo sanitize($listing['title']); ?></h1>
            <?php if (!empty($listing['room_class'])): ?><div style="font-size:.88rem;font-weight:800;color:var(--primary,#0f766e);margin:-4px 0 8px;"><?php echo sanitize($listing['room_class']); ?></div><?php endif; ?>

            <div class="ad-price">
                <?php if ($listing['price']): ?>GH&#8373; <?php echo number_format((float)$listing['price'],2); ?> <small style="font-size:.55em;font-weight:600;color:var(--text-muted,#6b7280);"><?php echo accommodation_price_period_label($listing['price_period']); ?></small>
                <?php else: ?><?php echo ucfirst(accommodation_price_period_label($listing['price_period'])); ?><?php endif; ?>
            </div>

            <div class="ad-meta">
                <span class="ad-meta-item">📍 <?php echo sanitize(accommodation_location_label($listing['area'], $listing['town_name'] ?? null) ?: 'Location on request'); ?></span>
                <span class="ad-meta-item" style="font-weight:700;color:<?php echo $listing['availability_status']==='available'?'#10b981':'#ef4444'; ?>;">
                    <?php echo accommodation_availability_label($listing['availability_status']); ?>
                </span>
                <?php if (!$isHotel && $listing['bedrooms']): ?><span class="ad-meta-item">🛏️ <?php echo (int)$listing['bedrooms']; ?> bed<?php echo $listing['bedrooms']==1?'':'s'; ?></span><?php endif; ?>
                <?php if (!$isHotel && $listing['bathrooms']): ?><span class="ad-meta-item">🚿 <?php echo (int)$listing['bathrooms']; ?> bath<?php echo $listing['bathrooms']==1?'':'s'; ?></span><?php endif; ?>
                <?php if (!$isHotel && $listing['furnished_status']): ?><span class="ad-meta-item"><?php echo ucfirst(str_replace('_',' ',$listing['furnished_status'])); ?></span><?php endif; ?>
                <?php if ($isHotel && $listing['guests_capacity']): ?><span class="ad-meta-item">👥 Up to <?php echo (int)$listing['guests_capacity']; ?> guests</span><?php endif; ?>
            </div>

            <?php if ($isHotel && ($listing['checkin_info'] || $listing['checkout_info'])): ?>
            <div style="font-size:.82rem;color:var(--text-muted,#6b7280);margin-bottom:8px;">
                <?php if ($listing['checkin_info']): ?>🕒 Check-in: <?php echo sanitize($listing['checkin_info']); ?><br><?php endif; ?>
                <?php if ($listing['checkout_info']): ?>🕒 Check-out: <?php echo sanitize($listing['checkout_info']); ?><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($listingFacilities): ?>
            <div class="ad-facilities">
                <?php foreach ($listingFacilities as $f): ?>
                <span class="ad-facility"><?php echo $f['icon'] ? $f['icon'].' ' : ''; ?><?php echo sanitize($f['name']); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Owner card -->
            <div style="margin-top:18px;">
                <p style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Listed by</p>
                <div class="ad-owner-card">
                    <div style="width:44px;height:44px;border-radius:10px;background:var(--primary-soft,#d1fae5);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--primary,#0f766e);flex-shrink:0;">
                        <?php echo strtoupper(substr($listing['owner_name'],0,1)); ?>
                    </div>
                    <div style="font-weight:700;"><?php echo sanitize($listing['owner_name']); ?></div>
                </div>
            </div>

            <!-- Actions -->
            <?php if (!$isOwner): ?>
            <div class="ad-actions">
                <?php if ($user): ?>
                <?php if ($displayPhone): ?>
                <a href="tel:<?php echo sanitize($displayPhone); ?>" class="button button-primary">📞 Call <?php echo sanitize($displayPhone); ?></a>
                <?php endif; ?>
                <?php if ($waLink): ?>
                <a href="<?php echo sanitize($waLink); ?>" target="_blank" rel="noopener" class="button ad-wa-btn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884M20.463 3.488A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
                    WhatsApp
                </a>
                <?php endif; ?>
                <form method="post" action="accommodation_enquiry.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="listing_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="enquiry_type" value="<?php echo $isHotel ? 'booking' : 'viewing'; ?>">
                    <button type="submit" class="button <?php echo $displayPhone ? 'button-secondary' : 'button-primary'; ?>"><?php echo $isHotel ? '📅 Send Booking Enquiry' : '🔑 Request Viewing'; ?></button>
                </form>
                <?php else: ?>
                <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-primary">Sign in to Contact Owner</a>
                <?php endif; ?>
            </div>
            <?php if ($user): ?>
            <details style="margin-top:14px;">
                <summary style="font-size:.78rem;color:var(--text-muted,#6b7280);cursor:pointer;">🚩 Report this listing</summary>
                <form method="post" action="report_accommodation.php" style="margin-top:10px;display:flex;flex-direction:column;gap:8px;max-width:320px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="listing_id" value="<?php echo $id; ?>">
                    <select name="reason" required>
                        <option value="">Select a reason…</option>
                        <option value="fake">Fake listing</option>
                        <option value="wrong_info">Wrong information</option>
                        <option value="already_rented">Already rented</option>
                        <option value="scam">Scam/suspicious listing</option>
                        <option value="inappropriate">Inappropriate content</option>
                        <option value="other">Other</option>
                    </select>
                    <textarea name="details" rows="2" placeholder="Additional details (optional)"></textarea>
                    <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;align-self:flex-start;">Submit Report</button>
                </form>
            </details>
            <?php endif; ?>
            <?php else: ?>
            <div class="ad-actions">
                <a href="accommodation_form.php?id=<?php echo $id; ?>" class="button button-primary">✏️ Edit Listing</a>
                <?php if ($listing['status'] === 'approved'): ?>
                <a href="feature_accommodation.php?id=<?php echo $id; ?>" class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;"><?php echo $isFeatured ? '⭐ Featured — Renew' : '⭐ Feature This Listing'; ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Description -->
    <?php if ($listing['description']): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Description</p>
        <div style="font-size:.88rem;line-height:1.7;"><?php echo render_rich($listing['description']); ?></div>
    </div>
    <?php endif; ?>

    <!-- Keep browsing: same owner's listings first, then other listings -->
    <?php if ($moreListings): ?>
    <div style="margin-top:24px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 10px;">Keep Browsing</p>
        <div class="adm-grid">
            <?php foreach ($moreListings as $l):
                $lFeatured = !empty($l['featured']) && (empty($l['featured_end_date']) || $l['featured_end_date'] >= date('Y-m-d'));
            ?>
            <a href="accommodation_detail.php?id=<?php echo $l['id']; ?>" class="adm-card<?php echo $lFeatured?' adm-card--featured':''; ?>">
                <div class="adm-card-img">
                    <?php if ($l['primary_image']): ?>
                        <img src="<?php echo sanitize($l['primary_image']); ?>" alt="<?php echo sanitize($l['title']); ?>">
                    <?php else: ?>
                        <span class="adm-card-icon"><?php echo $l['type_icon'] ?? '🏠'; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($l['same_owner'])): ?><span class="adm-card-badge">Same host</span>
                    <?php elseif ($lFeatured): ?><span class="adm-card-badge" style="background:#f59e0b;">⭐ Featured</span><?php endif; ?>
                </div>
                <div class="adm-card-body">
                    <div class="adm-card-type"><?php echo sanitize($l['type_name']); ?></div>
                    <div class="adm-card-title"><?php echo sanitize($l['title']); ?></div>
                    <?php if (!empty($l['room_class'])): ?><div class="adm-card-class"><?php echo sanitize($l['room_class']); ?></div><?php endif; ?>
                    <div class="adm-card-loc">📍 <?php echo sanitize(accommodation_location_label($l['area'], $l['town_name'] ?? null)); ?></div>
                    <div class="adm-card-price">
                        <?php if ($l['price']): ?>GH&#8373; <?php echo number_format((float)$l['price'],2); ?> <small><?php echo accommodation_price_period_label($l['price_period']); ?></small>
                        <?php else: ?><?php echo ucfirst(accommodation_price_period_label($l['price_period'])); ?><?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>

<script>
function switchImg(thumb, src) {
    document.getElementById('main-img-el').src = src;
    document.querySelectorAll('.ad-thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
}
</script>
</body>
</html>

<?php
/**
 * Small "Special Offers" homepage/promotions-page widget — the caller must
 * already have $user (logged-in) and have checked module_enabled('promotions')
 * before requiring this file. $promoWidgetLimit (default 6) caps the query;
 * the homepage passes 1 so it stays a single small card, never a dominant
 * section. Wrapped in try/catch so a mid-deploy DB state never breaks the
 * page that includes it.
 */
$promoWidgetLimit = $promoWidgetLimit ?? 6;
$promoWidgetItems = [];
try {
    $promoWidgetItems = eligible_promotions_for_user((int)$user['id'], $promoWidgetLimit);
} catch (Exception $e) {}

if ($promoWidgetItems):
    $promoWidgetIcons = [
        'free_package'          => '📦',
        'free_listing'          => '📋',
        'free_featured_listing' => '⭐',
        'discount'               => '💸',
        'free_service'           => '🛠️',
    ];
?>
<div class="cm-section">
    <div class="cm-section-head">
        <h2>🎁 Special Offers</h2>
        <a href="promotions.php">View all →</a>
    </div>
    <div class="cm-promo-row">
        <?php foreach ($promoWidgetItems as $pw): ?>
        <a href="promotions.php" class="cm-promo-card">
            <div class="cm-promo-icon"><?php echo $promoWidgetIcons[$pw['type']] ?? '🎁'; ?></div>
            <div class="cm-promo-title"><?php echo sanitize($pw['name']); ?></div>
            <?php if ($pw['description']): ?><div class="cm-promo-desc"><?php echo sanitize(mb_substr($pw['description'], 0, 80)); ?></div><?php endif; ?>
            <span class="cm-promo-cta">Claim Offer →</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<style>
.cm-promo-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }
.cm-promo-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:18px; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
.cm-promo-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-3px); }
.cm-promo-icon  { font-size:1.6rem; margin-bottom:6px; }
.cm-promo-title { font-weight:800; font-size:.92rem; margin-bottom:3px; }
.cm-promo-desc  { font-size:.76rem; color:var(--muted,#6b7280); margin-bottom:8px; }
.cm-promo-cta   { font-size:.78rem; font-weight:700; color:var(--primary,#0f766e); }
</style>
<?php endif; ?>

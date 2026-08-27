<?php
/**
 * Accommodation hub — the two-option chooser the homepage card links to.
 * Both options feed into the same accommodation_listings.php browse engine,
 * pre-filtered by category.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('accommodation', 'Accommodation');

$user = current_user();
$acAd = get_ads_for_placement('accommodation', ['banner', 'video'], 1)[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Accommodation | ' . APP_NAME,
        'description' => 'Find rooms, houses, hotels and guest houses in the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/accommodation.php',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ac-shell { max-width: 700px; margin: 0 auto; padding: 20px 16px 60px; }
        .ac-hero  { text-align: center; margin-bottom: 26px; }
        .ac-hero h1 { margin: 0 0 6px; }
        .ac-hero p  { color: var(--text-muted,#6b7280); margin: 0; }
        .ac-options { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 480px) { .ac-options { grid-template-columns: 1fr; } }
        .ac-option { background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb); border-radius: 16px; padding: 28px 20px; text-align: center; text-decoration: none; color: inherit; transition: box-shadow .15s, transform .15s; }
        .ac-option:hover { box-shadow: 0 8px 28px rgba(0,0,0,.1); transform: translateY(-3px); }
        .ac-option-icon  { font-size: 2.4rem; margin-bottom: 10px; }
        .ac-option-title { font-weight: 800; font-size: 1.05rem; margin-bottom: 6px; }
        .ac-option-desc  { font-size: .84rem; color: var(--text-muted,#6b7280); }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="index.php" class="button button-secondary button-small">← Home</a>
    <span class="brand">🏠 Accommodation</span>
    <?php if ($user): ?><a href="my_accommodation.php" class="button button-secondary button-small">My Listings</a><?php endif; ?>
</header>

<main class="ac-shell">
    <div class="ac-hero">
        <h1>Find a place to stay</h1>
        <p>Rooms, houses, hotels and guest houses across the Akuapem area.</p>
    </div>

    <div class="ac-options">
        <a href="accommodation_listings.php?category=room_house" class="ac-option">
            <div class="ac-option-icon">🏡</div>
            <div class="ac-option-title">Find a Room / House</div>
            <div class="ac-option-desc">Single rooms, self-contained, apartments &amp; houses</div>
        </a>
        <a href="accommodation_listings.php?category=hotel" class="ac-option">
            <div class="ac-option-icon">🏨</div>
            <div class="ac-option-title">Hotels &amp; Guest Houses</div>
            <div class="ac-option-desc">Short stays, lodges &amp; bed and breakfasts</div>
        </a>
    </div>

    <!-- Ad -->
    <?php if ($acAd): ?>
    <div style="max-width:600px;margin:24px auto 0;">
        <?php render_ad_unit($acAd); ?>
    </div>
    <?php endif; ?>

    <?php if ($user): ?>
    <div style="text-align:center;margin-top:24px;">
        <a href="accommodation_form.php" class="button button-primary">+ List Your Accommodation</a>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>

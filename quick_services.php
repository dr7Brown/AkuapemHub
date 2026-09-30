<?php
/**
 * Quick Services — public catalog listing. Database-driven so new services
 * (Airtime, ECG, etc.) appear automatically once an admin activates them,
 * with no code changes. Mirrors fm_stations.php's page shape.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/quick_service_functions.php';

require_module_enabled('quick_services', 'Quick Services');
$user = current_user();

$services = $pdo->query("SELECT * FROM quick_services WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Quick Services — ' . APP_NAME,
        'description' => 'Buy mobile data, check BECE/WASSCE results, and more — everyday digital errands handled quickly.',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .qsx-hero { background:linear-gradient(135deg,var(--primary,#2f8f5b),var(--primary-dark,#246b45)); color:#fff; padding:36px 20px 46px; text-align:center; }
        .qsx-hero h1 { font-size:clamp(1.5rem,5vw,2rem); font-weight:900; margin:0 0 6px; }
        .qsx-hero p  { font-size:.92rem; color:rgba(255,255,255,.88); margin:0; }
        .qsx-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin-top:-24px; position:relative; z-index:2; }
        .qsx-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:22px 18px; text-decoration:none; color:inherit; box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); transition:box-shadow .2s,transform .2s; }
        .qsx-card:hover { box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); transform:translateY(-3px); }
        .qsx-icon { font-size:2.4rem; margin-bottom:10px; }
        .qsx-name { font-weight:800; font-size:1rem; margin:0 0 6px; }
        .qsx-desc { font-size:.82rem; color:var(--muted,#6b7280); line-height:1.5; margin:0; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="<?php echo $user ? 'jobs.php' : 'index.php'; ?>" class="button button-secondary button-small">‹ Home</a>
    <span class="brand">⚡ Quick Services</span>
</header>

<div class="qsx-hero">
    <h1>⚡ Quick Services</h1>
    <p>Access essential digital services quickly and conveniently.</p>
</div>

<main class="page-shell" style="padding-top:0;">
    <?php if (!$services): ?>
    <div class="empty-state"><p>No services are available right now — check back soon.</p></div>
    <?php else: ?>
    <div class="qsx-grid">
        <?php foreach ($services as $s): ?>
        <a href="quick_service.php?slug=<?php echo urlencode($s['slug']); ?>" class="qsx-card">
            <div class="qsx-icon"><?php echo sanitize($s['icon']) ?: '⚡'; ?></div>
            <p class="qsx-name"><?php echo sanitize($s['name']); ?></p>
            <?php if ($s['description']): ?><p class="qsx-desc"><?php echo sanitize($s['description']); ?></p><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>

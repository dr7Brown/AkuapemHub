<?php
/**
 * "View all Services" — browse every active Quick Service.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('quick_services', 'Quick Services');

$user = current_user();

$services = $pdo->query("SELECT * FROM quick_services WHERE status='active' ORDER BY display_order, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Quick Services | ' . APP_NAME,
        'description' => 'Airtime, ECG, exam results, passport & Ghana Card assistance and more — request a service and let our team handle it.',
        'url'         => rtrim(BASE_URL, '/') . '/quick_services.php',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .qsv-shell { max-width: 900px; margin: 0 auto; padding: 16px 16px 80px; }
        .qsv-grid  { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; }
        .qsv-card  { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px 14px; text-align:center; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
        .qsv-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-3px); }
        .qsv-icon  { font-size:2rem; margin-bottom:8px; }
        .qsv-icon img { width:40px; height:40px; border-radius:50%; object-fit:cover; }
        .qsv-title { font-weight:800; font-size:.9rem; margin-bottom:3px; }
        .qsv-desc  { font-size:.74rem; color:var(--muted,#6b7280); }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="index.php" class="button button-secondary button-small">← Home</a>
    <span class="brand">⚡ Quick Services</span>
</header>

<main class="qsv-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <p style="color:var(--muted,#6b7280);font-size:.9rem;margin:0 0 16px;">Pick a service, fill a short form, pay — our team handles the rest and gets back to you.</p>

    <?php if (!$services): ?>
    <div class="card" style="text-align:center;padding:40px 20px;">
        <p style="margin:0;color:var(--muted,#6b7280);">No services are available right now. Please check back later.</p>
    </div>
    <?php else: ?>
    <div class="qsv-grid">
        <?php foreach ($services as $s): ?>
        <a href="quick_service.php?slug=<?php echo urlencode($s['slug']); ?>" class="qsv-card">
            <div class="qsv-icon"><?php if (!empty($s['image_path'])): ?><img src="<?php echo sanitize($s['image_path']); ?>" alt=""><?php else: ?><?php echo sanitize($s['icon']) ?: '⚡'; ?><?php endif; ?></div>
            <div class="qsv-title"><?php echo sanitize($s['name']); ?></div>
            <?php if ($s['description']): ?><div class="qsv-desc"><?php echo sanitize($s['description']); ?></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user) { require __DIR__ . '/partials/bottom_nav.php'; } ?>
</body>
</html>

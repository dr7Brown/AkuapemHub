<?php
/**
 * Worker Portfolio — the worker's own management view of their showcased
 * projects. Mirrors seller_dashboard.php's Products tab: a grid of items
 * with Edit/Delete, and a link to worker_portfolio_form.php to add/edit one.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
require_role('worker');

$stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
if (!$profile) {
    flash('Your worker profile could not be found. Please contact support.', 'error');
    header('Location: jobs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    csrf_check();
    $itemId = (int)($_POST['item_id'] ?? 0);
    $pdo->prepare('DELETE FROM worker_portfolio_items WHERE id=? AND worker_profile_id=?')
        ->execute([$itemId, $profile['id']]);
    flash('Project removed.', 'success');
    header('Location: worker_portfolio.php');
    exit;
}

$items = $pdo->prepare(
    'SELECT wpi.*, (SELECT image_path FROM worker_portfolio_images WHERE item_id=wpi.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image
     FROM worker_portfolio_items wpi
     WHERE wpi.worker_profile_id = ?
     ORDER BY wpi.sort_order ASC, wpi.created_at DESC'
);
$items->execute([$profile['id']]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Portfolio — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .wf-shell { max-width:680px; margin:0 auto; padding:20px 16px 80px; }
        .wf-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:16px; }
        .wf-head p { margin:2px 0 0; font-size:.82rem; color:var(--text-muted,#6b7280); }
        .wf-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
        .wf-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
        .wf-card-img { aspect-ratio:1/1; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .wf-card-img img { width:100%; height:100%; object-fit:cover; }
        .wf-card-body { padding:10px 12px 12px; }
        .wf-card-title { font-weight:700; font-size:.86rem; line-height:1.35; margin:0 0 8px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .wf-card-actions { display:flex; gap:6px; }
        .wf-empty { text-align:center; padding:50px 20px; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="worker_profile.php" class="button button-secondary button-small">← Profile</a>
    <span class="brand">My Portfolio</span>
    <a href="worker_portfolio_form.php" class="button button-primary button-small">+ Add Project</a>
</header>

<main class="wf-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <div class="wf-head">
        <div>
            <strong>Showcase your work</strong>
            <p>Add photos of past projects so customers can see the quality of your work before they hire you.</p>
        </div>
    </div>

    <?php if ($items): ?>
    <div class="wf-grid">
        <?php foreach ($items as $it): ?>
        <div class="wf-card">
            <div class="wf-card-img">
                <?php if ($it['primary_image']): ?><img src="<?php echo sanitize($it['primary_image']); ?>" alt="<?php echo sanitize($it['title']); ?>">
                <?php else: ?><span style="font-size:2rem;opacity:.3;">🛠️</span><?php endif; ?>
            </div>
            <div class="wf-card-body">
                <div class="wf-card-title"><?php echo sanitize($it['title']); ?></div>
                <div class="wf-card-actions">
                    <a href="worker_portfolio_form.php?id=<?php echo $it['id']; ?>" class="button button-secondary button-small">Edit</a>
                    <form method="post" class="inline-form" onsubmit="return confirm('Remove this project?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                        <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="wf-empty">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🛠️</div>
        <p style="margin:0 0 16px;">No projects yet. Add your first one to start showcasing your work.</p>
        <a href="worker_portfolio_form.php" class="button button-primary">+ Add Project</a>
    </div>
    <?php endif; ?>
</main>

<?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

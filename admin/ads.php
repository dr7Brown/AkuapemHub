<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

// Toggle status
require_mod_permission('manage_ads');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    csrf_check();
    $tid = (int)$_POST['toggle_id'];
    $cur = $pdo->prepare("SELECT status FROM advertisements WHERE id=? LIMIT 1");
    $cur->execute([$tid]);
    $row = $cur->fetch();
    if ($row) {
        $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE advertisements SET status=? WHERE id=?")->execute([$newStatus, $tid]);
        log_audit_action($user['id'], 'ad_toggle', "Ad #$tid → $newStatus");
    }
    header('Location: ads.php?saved=1'); exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM advertisements WHERE id=?")->execute([$did]);
    log_audit_action($user['id'], 'ad_delete', "Deleted ad #$did");
    header('Location: ads.php?deleted=1'); exit;
}

$ads    = $pdo->query("SELECT * FROM advertisements ORDER BY created_at DESC")->fetchAll();
$total  = count($ads);
$active = count(array_filter($ads, fn($a) => $a['status'] === 'active'));
$totalClicks = array_sum(array_column($ads, 'click_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Advertisements — AkuapemConnect Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .ad-shell { max-width:980px; margin:0 auto; padding:20px 16px 60px; }
        .ad-stats  { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
        .ad-stat   { flex:1; min-width:110px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px 16px; text-align:center; }
        .ad-stat strong { display:block; font-size:1.5rem; }
        .ad-stat span   { font-size:.78rem; color:var(--text-muted); }
        .ad-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
        .ad-card   { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
        .ad-img    { aspect-ratio:16/6; overflow:hidden; background:var(--surface-muted,#f3f4f6); display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:.85rem; }
        .ad-img img { width:100%; height:100%; object-fit:cover; }
        .ad-body   { padding:12px 14px 14px; flex:1; display:flex; flex-direction:column; gap:6px; }
        .ad-title  { font-weight:700; font-size:.95rem; }
        .ad-meta   { font-size:.76rem; color:var(--text-muted); display:flex; gap:10px; flex-wrap:wrap; }
        .ad-url    { font-size:.75rem; color:var(--primary); word-break:break-all; }
        .ad-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:auto; padding-top:8px; border-top:1px solid var(--border); }
        .badge-active   { background:#d1fae5; color:#065f46; font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:10px; }
        .badge-inactive { background:#f3f4f6; color:#6b7280; font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:10px; }
        .badge-banner   { background:#dbeafe; color:#1e40af; font-size:.7rem; padding:2px 8px; border-radius:10px; }
        .badge-sponsored{ background:#fef3c7; color:#92400e; font-size:.7rem; padding:2px 8px; border-radius:10px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Advertisements</h1>
        <a href="ads_edit.php" class="button button-primary button-small">+ New Ad</a>
    </header>

    <main class="ad-shell">
        <?php if (isset($_GET['saved'])):   ?><div class="alert alert-success" style="margin-bottom:12px;">Ad status updated.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success" style="margin-bottom:12px;">Ad deleted.</div><?php endif; ?>

        <div class="ad-stats">
            <div class="ad-stat"><strong><?php echo $total; ?></strong><span>Total Ads</span></div>
            <div class="ad-stat"><strong><?php echo $active; ?></strong><span>Active</span></div>
            <div class="ad-stat"><strong><?php echo $total - $active; ?></strong><span>Inactive</span></div>
            <div class="ad-stat"><strong><?php echo number_format($totalClicks); ?></strong><span>Total Clicks</span></div>
        </div>

        <?php if (!$ads): ?>
            <div class="alert" style="text-align:center;padding:30px;">No advertisements yet. <a href="ads_edit.php">Create the first one →</a></div>
        <?php else: ?>
        <div class="ad-grid">
            <?php foreach ($ads as $ad): ?>
            <div class="ad-card">
                <div class="ad-img">
                    <?php if ($ad['image']): ?>
                        <img src="../<?php echo sanitize($ad['image']); ?>" alt="<?php echo sanitize($ad['title']); ?>">
                    <?php else: ?>
                        No image
                    <?php endif; ?>
                </div>
                <div class="ad-body">
                    <div class="ad-title"><?php echo sanitize($ad['title']); ?></div>
                    <div class="ad-meta">
                        <span class="badge-<?php echo $ad['status']; ?>"><?php echo $ad['status']; ?></span>
                        <span class="badge-<?php echo $ad['ad_type']; ?>"><?php echo $ad['ad_type']; ?></span>
                        <span>👆 <?php echo number_format((int)$ad['click_count']); ?> clicks</span>
                    </div>
                    <?php if ($ad['destination_url']): ?>
                        <div class="ad-url"><?php echo sanitize(mb_substr($ad['destination_url'], 0, 50)) . (strlen($ad['destination_url']) > 50 ? '…' : ''); ?></div>
                    <?php endif; ?>
                    <?php if ($ad['start_date'] || $ad['end_date']): ?>
                        <div style="font-size:.75rem;color:var(--text-muted);">
                            <?php echo $ad['start_date'] ? 'From ' . date('M j', strtotime($ad['start_date'])) : ''; ?>
                            <?php echo $ad['end_date']   ? ' – ' . date('M j, Y', strtotime($ad['end_date']))   : ''; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ad-actions">
                        <a href="ads_edit.php?id=<?php echo (int)$ad['id']; ?>" class="button button-small">Edit</a>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="toggle_id" value="<?php echo (int)$ad['id']; ?>">
                            <button type="submit" class="button button-small button-secondary">
                                <?php echo $ad['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this ad permanently?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo (int)$ad['id']; ?>">
                            <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>

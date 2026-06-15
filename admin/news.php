<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

// Toggle publish/unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    csrf_check();
    $tid = (int)$_POST['toggle_id'];
    $cur = $pdo->prepare("SELECT status, title, notification_sent FROM news WHERE id=? LIMIT 1");
    $cur->execute([$tid]);
    $row = $cur->fetch();
    if ($row) {
        $newStatus = $row['status'] === 'published' ? 'draft' : 'published';
        if ($newStatus === 'published') {
            $pdo->prepare("UPDATE news SET status=?, published_at=? WHERE id=?")->execute([$newStatus, date('Y-m-d H:i:s'), $tid]);
        } else {
            $pdo->prepare("UPDATE news SET status=? WHERE id=?")->execute([$newStatus, $tid]);
        }
        // Notify all users the first time an article goes live
        if ($newStatus === 'published' && !$row['notification_sent']) {
            $uids = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $uid) {
                notify_user((int)$uid, '📰 New article published', sanitize($row['title']) . ' — read the latest from ' . APP_NAME . '.', 'info');
            }
            $pdo->prepare("UPDATE news SET notification_sent=1 WHERE id=?")->execute([$tid]);
        }
        admin_log('news_toggle', "Article #$tid → $newStatus");
    }
    header('Location: news.php?saved=1'); exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM news_comments WHERE news_id=?")->execute([$did]);
    $pdo->prepare("DELETE FROM news_likes    WHERE news_id=?")->execute([$did]);
    $pdo->prepare("DELETE FROM news_saves    WHERE news_id=?")->execute([$did]);
    $pdo->prepare("DELETE FROM news WHERE id=?")->execute([$did]);
    admin_log('news_delete', "Deleted article #$did");
    header('Location: news.php?deleted=1'); exit;
}

$articles = $pdo->query("SELECT id, title, slug, status, view_count, published_at, created_at FROM news ORDER BY created_at DESC")->fetchAll();
$total     = count($articles);
$published = count(array_filter($articles, fn($a) => $a['status'] === 'published'));
$drafts    = $total - $published;
$totalViews = array_sum(array_column($articles, 'view_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>News Management — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .an-shell { max-width:960px; margin:0 auto; padding:20px 16px 60px; }
        .an-stats  { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
        .an-stat   { flex:1; min-width:120px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px 16px; text-align:center; }
        .an-stat strong { display:block; font-size:1.5rem; }
        .an-stat span   { font-size:.78rem; color:var(--text-muted); }
        .an-table  { width:100%; border-collapse:collapse; font-size:.9rem; }
        .an-table th { text-align:left; padding:10px 12px; border-bottom:2px solid var(--border); font-size:.8rem; text-transform:uppercase; color:var(--text-muted); }
        .an-table td { padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .an-table tr:hover td { background:var(--surface-muted,#f8fafc); }
        .an-title a { color:inherit; text-decoration:none; font-weight:600; }
        .an-title a:hover { color:var(--primary); }
        .badge-pub  { background:#d1fae5; color:#065f46; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:10px; }
        .badge-dft  { background:#f3f4f6; color:#6b7280; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:10px; }
        .an-actions { display:flex; gap:6px; align-items:center; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>News Management</h1>
        <a href="news_edit.php" class="button button-primary button-small">+ New Article</a>
    </header>

    <main class="an-shell">
        <?php if (isset($_GET['saved'])):  ?><div class="alert alert-success" style="margin-bottom:12px;">Article status updated.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success" style="margin-bottom:12px;">Article deleted.</div><?php endif; ?>

        <div class="an-stats">
            <div class="an-stat"><strong><?php echo $total; ?></strong><span>Total Articles</span></div>
            <div class="an-stat"><strong><?php echo $published; ?></strong><span>Published</span></div>
            <div class="an-stat"><strong><?php echo $drafts; ?></strong><span>Drafts</span></div>
            <div class="an-stat"><strong><?php echo number_format($totalViews); ?></strong><span>Total Views</span></div>
        </div>

        <?php if (!$articles): ?>
            <div class="alert" style="text-align:center;padding:30px;">No articles yet. <a href="news_edit.php">Create the first one →</a></div>
        <?php else: ?>
        <div class="card" style="overflow:auto;padding:0;">
            <table class="an-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Published</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($articles as $a): ?>
                <tr>
                    <td class="an-title">
                        <a href="../news_article.php?slug=<?php echo urlencode($a['slug']); ?>" target="_blank"><?php echo sanitize($a['title']); ?> ↗</a>
                        <div style="font-size:.75rem;color:var(--text-muted);">/<?php echo sanitize($a['slug']); ?></div>
                    </td>
                    <td><span class="badge-<?php echo $a['status'] === 'published' ? 'pub' : 'dft'; ?>"><?php echo $a['status']; ?></span></td>
                    <td><?php echo number_format((int)$a['view_count']); ?></td>
                    <td><?php echo $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '—'; ?></td>
                    <td>
                        <div class="an-actions">
                            <a href="news_edit.php?id=<?php echo (int)$a['id']; ?>" class="button button-small">Edit</a>
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="button button-small button-secondary">
                                    <?php echo $a['status'] === 'published' ? 'Unpublish' : 'Publish'; ?>
                                </button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this article permanently?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>

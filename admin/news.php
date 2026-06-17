<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();

// Fee settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fee'])) {
    csrf_check();
    set_platform_setting('news_fee_enabled', (int)isset($_POST['fee_enabled']));
    set_platform_setting('news_fee_amount', max(0, (float)($_POST['fee_amount'] ?? 0)));
    log_audit_action($user['id'], 'news_fee_update', 'Updated news article submission fee settings');
    header('Location: news.php?saved=1'); exit;
}

// Toggle publish/unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    csrf_check();
    $tid = (int)$_POST['toggle_id'];
    $cur = $pdo->prepare("SELECT status, title, notification_sent, user_id FROM news WHERE id=? LIMIT 1");
    $cur->execute([$tid]);
    $row = $cur->fetch();
    if ($row) {
        $newStatus = $row['status'] === 'published' ? 'draft' : 'published';
        if ($newStatus === 'published') {
            $pdo->prepare("UPDATE news SET status=?, published_at=NOW() WHERE id=?")->execute([$newStatus, $tid]);
        } else {
            $pdo->prepare("UPDATE news SET status=? WHERE id=?")->execute([$newStatus, $tid]);
        }
        // Notify submitter (user-submitted article) when published
        if ($newStatus === 'published' && $row['user_id']) {
            notify_user((int)$row['user_id'], 'Your article is now live!',
                '"' . $row['title'] . '" has been reviewed and published on the news page.', 'success');
        }
        // Notify all users the first time an article goes live
        if ($newStatus === 'published' && !$row['notification_sent']) {
            $uids = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $uid) {
                if ((int)$uid !== (int)($row['user_id'] ?? 0)) { // skip submitter (already notified)
                    notify_user((int)$uid, '📰 New article published', $row['title'] . ' — read the latest from ' . APP_NAME . '.', 'info');
                }
            }
            $pdo->prepare("UPDATE news SET notification_sent=1 WHERE id=?")->execute([$tid]);
        }
        // Notify submitter when returned to draft
        if ($newStatus === 'draft' && $row['user_id']) {
            notify_user((int)$row['user_id'], 'Article returned for revision',
                '"' . $row['title'] . '" has been unpublished and returned to draft.', 'info');
        }
        log_audit_action($user['id'], 'news_toggle', "Article #$tid → $newStatus");
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
    log_audit_action($user['id'], 'news_delete', "Deleted article #$did");
    header('Location: news.php?deleted=1'); exit;
}

// Reject article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    csrf_check();
    $tid    = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    $row    = $pdo->prepare("SELECT id, title, user_id FROM news WHERE id=? LIMIT 1");
    $row->execute([$tid]);
    $art = $row->fetch();
    if ($art) {
        $pdo->prepare("UPDATE news SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
            ->execute([$reason ?: null, $tid]);
        log_audit_action($user['id'], 'news_reject', "Rejected article #{$tid}: {$art['title']}");
        if ($art['user_id']) {
            $reasonNote = $reason ? ' Reason: ' . $reason : '';
            notify_user((int)$art['user_id'], 'Article not approved',
                '"' . $art['title'] . '" was not approved.' . $reasonNote . ' You can edit and resubmit it.', 'warning');
        }
    }
    header('Location: news.php?saved=1'); exit;
}

$feeEnabled = (bool)(int)get_platform_setting('news_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('news_fee_amount', '10');

$articles = $pdo->query("
    SELECT n.id, n.title, n.slug, n.status, n.view_count, n.published_at, n.created_at, n.user_id,
           n.rejection_reason, u.name AS submitter_name
    FROM news n LEFT JOIN users u ON u.id = n.user_id
    ORDER BY n.created_at DESC")->fetchAll();
$total      = count($articles);
$published  = count(array_filter($articles, fn($a) => $a['status'] === 'published'));
$rejected   = count(array_filter($articles, fn($a) => $a['status'] === 'rejected'));
$drafts     = $total - $published - $rejected;
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
        .badge-rej  { background:#fee2e2; color:#991b1b; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:10px; }
        .an-actions { display:flex; gap:6px; align-items:center; }
        .an-fee-panel { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:20px; }
        .an-fee-panel h3 { font-size:.88rem; font-weight:800; margin:0 0 12px; }
        .an-fee-row   { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .badge-user { background:#eff6ff; color:#1d4ed8; font-size:.65rem; font-weight:800; padding:2px 7px; border-radius:10px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>News Management</h1>
        <a href="news_edit.php" class="button button-primary button-small">+ New Article</a>
    </header>

    <main class="an-shell">
        <?php if (isset($_GET['saved'])):  ?><div class="alert alert-success" style="margin-bottom:12px;">Saved.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success" style="margin-bottom:12px;">Article deleted.</div><?php endif; ?>

        <!-- Fee settings panel -->
        <div class="an-fee-panel">
            <h3>⚙️ Article Submission Fee</h3>
            <form method="post" action="news.php" class="an-fee-row">
                <?php echo csrf_field(); ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;cursor:pointer;">
                    <input type="checkbox" name="fee_enabled" value="1" <?php echo $feeEnabled ? 'checked' : ''; ?>>
                    Charge a fee for user-submitted articles
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;">
                    Amount: <strong>GH₵</strong>
                    <input type="number" name="fee_amount" value="<?php echo number_format($feeAmount,2,'.',''); ?>"
                           min="0" step="0.01" style="width:90px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;">
                </label>
                <button type="submit" name="save_fee" class="button button-primary button-small">Save</button>
                <span style="font-size:.78rem;color:var(--text-muted);">
                    <?php echo $feeEnabled ? '⚠️ Users must pay GH₵ ' . number_format($feeAmount,2) . ' per submission.' : 'Currently free for all users.'; ?>
                </span>
            </form>
        </div>

        <div class="an-stats">
            <div class="an-stat"><strong><?php echo $total; ?></strong><span>Total Articles</span></div>
            <div class="an-stat"><strong><?php echo $published; ?></strong><span>Published</span></div>
            <div class="an-stat"><strong><?php echo $drafts; ?></strong><span>Drafts</span></div>
            <div class="an-stat"><strong style="color:#dc2626;"><?php echo $rejected; ?></strong><span>Rejected</span></div>
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
                        <th>Submitter</th>
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
                        <?php if ($a['status'] === 'rejected' && $a['rejection_reason']): ?>
                        <div style="font-size:.74rem;color:#991b1b;margin-top:4px;background:#fff1f2;border:1px solid #fecdd3;border-radius:6px;padding:4px 8px;">
                            <strong>Reason:</strong> <?php echo sanitize($a['rejection_reason']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;">
                        <?php if ($a['user_id'] && $a['submitter_name']): ?>
                            <span class="badge-user">User</span>
                            <div style="color:var(--text-muted);font-size:.75rem;margin-top:2px;"><?php echo sanitize($a['submitter_name']); ?></div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">Admin</span>
                        <?php endif; ?>
                    </td>
                    <td><?php
                        $bc = match($a['status']) { 'published' => 'badge-pub', 'rejected' => 'badge-rej', default => 'badge-dft' };
                        echo '<span class="' . $bc . '">' . sanitize($a['status']) . '</span>';
                    ?></td>
                    <td><?php echo number_format((int)$a['view_count']); ?></td>
                    <td><?php echo $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '—'; ?></td>
                    <td>
                        <div class="an-actions">
                            <a href="news_edit.php?id=<?php echo (int)$a['id']; ?>" class="button button-small">Edit</a>
                            <?php if ($a['status'] !== 'rejected'): ?>
                            <form method="post" action="news.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="button button-small button-secondary">
                                    <?php echo $a['status'] === 'published' ? 'Unpublish' : 'Publish'; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($a['status'] === 'rejected'): ?>
                            <form method="post" action="news.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="button button-small button-secondary">↩ Draft</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($a['status'] !== 'published' && $a['user_id']): ?>
                            <button onclick="openRejectModal(<?php echo (int)$a['id']; ?>)"
                                    class="button button-small"
                                    style="background:#fff7ed;color:#c2410c;border-color:#fdba74;">Reject</button>
                            <?php endif; ?>
                            <form method="post" action="news.php" style="display:inline;" onsubmit="return confirm('Delete this article permanently?')">
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

<!-- Reject modal -->
<div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeRejectModal()">
    <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 6px;font-size:1rem;">Reject Article</h3>
        <p style="font-size:.85rem;color:#6b7280;margin:0 0 14px;">Optionally explain why. The author will see this and can edit and resubmit.</p>
        <form method="post" action="news.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject-target-id" value="">
            <textarea name="rejection_reason" rows="4"
                      placeholder="e.g. Needs more detail, incorrect information, unrelated to the community…"
                      style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font-size:.88rem;resize:vertical;margin-bottom:12px;"></textarea>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="button button-primary" style="background:#dc2626;border-color:#dc2626;">Reject article</button>
                <button type="button" onclick="closeRejectModal()" class="button button-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openRejectModal(id) {
    document.getElementById('reject-target-id').value = id;
    var m = document.getElementById('reject-modal');
    m.style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}
</script>
</body>
</html>

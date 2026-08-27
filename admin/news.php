<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();
require_mod_permission('approve_news');

// Fee settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fee'])) {
    csrf_check();
    set_platform_setting('news_fee_enabled', (int)isset($_POST['fee_enabled']));
    set_platform_setting('news_fee_amount', max(0, (float)($_POST['fee_amount'] ?? 0)));
    log_audit_action($user['id'], 'news_fee_update', 'Updated news article submission fee settings');
    header('Location: news.php?saved=1'); exit;
}

// Featured settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_featured'])) {
    csrf_check();
    set_platform_setting('enable_paid_featured_news', isset($_POST['feat_paid']) ? '1' : '0');
    log_audit_action($user['id'], 'news_feat_update', 'Updated news featuring settings');
    header('Location: news.php?saved=1'); exit;
}

// Package CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pkg_action'])) {
    csrf_check();
    $pa = $_POST['pkg_action'];
    if ($pa === 'add_pkg') {
        $pName = trim($_POST['pkg_name'] ?? ''); $pDays = max(1,(int)($_POST['pkg_days']??30)); $pPrice = max(0,(float)($_POST['pkg_price']??0));
        if ($pName) { $pdo->prepare("INSERT INTO featured_news_packages (name,duration_days,price,status) VALUES (?,?,?,'active')")->execute([$pName,$pDays,$pPrice]); flash('Package added.','success'); }
    } elseif ($pa === 'toggle_pkg' && !empty($_POST['pkg_id'])) {
        $pdo->prepare("UPDATE featured_news_packages SET status=IF(status='active','inactive','active') WHERE id=?")->execute([(int)$_POST['pkg_id']]);
    } elseif ($pa === 'delete_pkg' && !empty($_POST['pkg_id'])) {
        $pdo->prepare("DELETE FROM featured_news_packages WHERE id=?")->execute([(int)$_POST['pkg_id']]); flash('Package deleted.','info');
    }
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
            if (check_mod_coi('news', $tid, $user['id'])) {
                log_coi_violation($user['id'], 'news', $tid, 'publish');
                header('Location: news.php'); exit;
            }
            $pdo->prepare("UPDATE news SET status=?, published_at=NOW() WHERE id=?")->execute([$newStatus, $tid]);
        } else {
            $pdo->prepare("UPDATE news SET status=? WHERE id=?")->execute([$newStatus, $tid]);
        }
        // Notify submitter (user-submitted article) when published
        if ($newStatus === 'published' && $row['user_id']) {
            require_once __DIR__ . '/../modules/referrals/service.php';
            award_points((int)$row['user_id'], 'news_approved', $tid);
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
        log_mod_activity($user['id'], 'news', 'reject_news', $tid);
        if ($art['user_id']) {
            $body = '"' . $art['title'] . '" was not approved and needs your attention.';
            if ($reason) $body .= "\n\nReason: " . $reason;
            $body .= "\n\nClick to edit and resubmit for review.";
            notify_user((int)$art['user_id'], '❌ Article Rejected — Action Required',
                $body, 'error', 'my_news.php?edit=' . $tid);
        }
    }
    header('Location: news.php?saved=1'); exit;
}

$feeEnabled = (bool)(int)get_platform_setting('news_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('news_fee_amount', '10');

// Monetization stats
$feeRevenue      = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='news_post' AND status='paid'")->fetchColumn();
$feeThisMonth    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='news_post' AND status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
$feePendingCount = (int)$pdo->query("SELECT COUNT(*) FROM platform_payments WHERE payment_type='news_post' AND status='pending'")->fetchColumn();
// Featuring
$featPaid        = get_platform_setting('enable_paid_featured_news','0') === '1';
$featPkgs        = $pdo->query("SELECT * FROM featured_news_packages ORDER BY duration_days ASC")->fetchAll();
$featRevenue     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='featured_news' AND status='paid'")->fetchColumn();
$featActiveCount = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())")->fetchColumn();

// Lightweight aggregate for the stat strip — avoids loading every article
// just to count/sum them.
$newsStats  = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='published') AS published,
           SUM(status='rejected') AS rejected,
           COALESCE(SUM(view_count),0) AS total_views
    FROM news")->fetch();
$total      = (int)$newsStats['total'];
$published  = (int)$newsStats['published'];
$rejected   = (int)$newsStats['rejected'];
$drafts     = $total - $published - $rejected;
$totalViews = (int)$newsStats['total_views'];

$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 30;
$offset     = ($page - 1) * $perPage;

$articles = $pdo->query("
    SELECT n.id, n.title, n.slug, n.status, n.view_count, n.published_at, n.created_at, n.user_id,
           n.rejection_reason, u.name AS submitter_name
    FROM news n LEFT JOIN users u ON u.id = n.user_id
    ORDER BY n.created_at DESC
    LIMIT $perPage OFFSET $offset")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>News Management — AkuapemConnect Admin</title>
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
        <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
            <a href="news_edit.php" class="button button-primary button-small">+ New Article</a>
        </div>

        <?php if (isset($_GET['saved'])):  ?><div class="alert alert-success" style="margin-bottom:12px;">Saved.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success" style="margin-bottom:12px;">Article deleted.</div><?php endif; ?>

        <!-- Submission fee panel -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div>
                    <h3 style="font-size:.9rem;font-weight:800;margin:0 0 2px;">📰 Article Submission Fee</h3>
                    <p style="font-size:.76rem;color:var(--muted,#6b7280);margin:0;">Charge users to submit articles for review and publication.</p>
                </div>
                <span style="background:<?php echo $feeEnabled?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $feeEnabled?'#065f46':'#6b7280'; ?>;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:20px;">
                    <?php echo $feeEnabled ? '● ENABLED' : '○ DISABLED'; ?>
                </span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:14px;">
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($feeRevenue,2); ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">Total Revenue</span>
                </div>
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($feeThisMonth,2); ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">This Month</span>
                </div>
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:<?php echo $feePendingCount?'#f59e0b':'#6b7280'; ?>;"><?php echo $feePendingCount; ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">Pending Payment</span>
                </div>
            </div>
            <?php if ($feePendingCount > 0): ?>
            <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:8px 12px;font-size:.8rem;margin-bottom:12px;">
                ⏳ <strong><?php echo $feePendingCount; ?> article<?php echo $feePendingCount!==1?'s':''; ?></strong> awaiting submission fee payment.
            </div>
            <?php endif; ?>
            <form method="post" action="news.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--border);">
                <?php echo csrf_field(); ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="fee_enabled" value="1" <?php echo $feeEnabled ? 'checked' : ''; ?>>
                    Require fee to submit articles
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;">
                    GH₵ <input type="number" name="fee_amount" value="<?php echo number_format($feeAmount,2,'.',''); ?>"
                           min="0" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;">
                </label>
                <button type="submit" name="save_fee" class="button button-primary button-small">Save Settings</button>
            </form>
        </div>

        <!-- Featuring panel -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div>
                    <h3 style="font-size:.9rem;font-weight:800;margin:0 0 2px;">⭐ Article Featuring</h3>
                    <p style="font-size:.76rem;color:var(--muted,#6b7280);margin:0;">Allow writers to pay to pin their articles at the top of the news feed.</p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.76rem;font-weight:800;padding:4px 10px;border-radius:10px;">GH₵ <?php echo number_format($featRevenue,2); ?> earned</span>
                    <span style="background:var(--surface-muted,#f3f4f6);font-size:.76rem;font-weight:800;padding:4px 10px;border-radius:10px;"><?php echo $featActiveCount; ?> active now</span>
                </div>
            </div>
            <form method="post" action="news.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--border);margin-bottom:14px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_featured" value="1">
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="feat_paid" value="1" <?php echo $featPaid ? 'checked' : ''; ?>>
                    Charge users to feature articles
                </label>
                <span style="font-size:.76rem;color:var(--muted,#6b7280);"><?php echo $featPaid ? 'Users must choose a package and pay.' : 'Currently free — articles featured instantly.'; ?></span>
                <button type="submit" class="button button-primary button-small">Save</button>
            </form>
            <p style="font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#6b7280);margin:0 0 10px;">Packages</p>
            <?php if ($featPkgs): ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem;margin-bottom:12px;">
                <thead><tr>
                    <?php foreach(['Name','Days','Price','Status',''] as $th): ?><th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);font-size:.7rem;font-weight:800;text-transform:uppercase;color:var(--muted);"><?php echo $th; ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($featPkgs as $pkg): ?>
                <tr style="<?php echo $pkg['status']==='inactive'?'opacity:.5':''; ?>">
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);"><?php echo sanitize($pkg['name']); ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:center;"><?php echo (int)$pkg['duration_days']; ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);font-weight:700;color:var(--primary);">GH₵ <?php echo number_format((float)$pkg['price'],2); ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);">
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:<?php echo $pkg['status']==='active'?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $pkg['status']==='active'?'#065f46':'#6b7280'; ?>;"><?php echo ucfirst($pkg['status']); ?></span>
                    </td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);">
                        <form method="post" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="pkg_action" value="toggle_pkg"><input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="button button-small button-secondary" style="font-size:.7rem;padding:2px 8px;"><?php echo $pkg['status']==='active'?'Disable':'Enable'; ?></button></form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete?')"><?php echo csrf_field(); ?><input type="hidden" name="pkg_action" value="delete_pkg"><input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>"><button type="submit" class="button button-small" style="font-size:.7rem;padding:2px 8px;background:#fee2e2;color:#991b1b;border-color:transparent;">Del</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <form method="post" action="news.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <?php echo csrf_field(); ?><input type="hidden" name="pkg_action" value="add_pkg">
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Name</label><input type="text" name="pkg_name" placeholder="e.g. 30 Days" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:110px;"></div>
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Days</label><input type="number" name="pkg_days" min="1" value="30" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:70px;"></div>
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Price (GH₵)</label><input type="number" name="pkg_price" min="0" step="0.01" value="0" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:90px;"></div>
                <button type="submit" class="button button-primary button-small">+ Add Package</button>
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
                        <?php $newsCoi = !is_admin() && (int)($a['user_id'] ?? 0) === (int)$user['id']; ?>
                        <div class="an-actions">
                            <a href="news_edit.php?id=<?php echo (int)$a['id']; ?>" class="button button-small button-primary">View</a>
                            <a href="news_edit.php?id=<?php echo (int)$a['id']; ?>" class="button button-small">Edit</a>
                            <?php if ($newsCoi && $a['status'] === 'draft'): ?>
                            <span style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:8px;">⚠️ Yours</span>
                            <?php elseif ($a['status'] !== 'rejected'): ?>
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
                            <?php if ($a['status'] !== 'rejected' && $a['user_id']): ?>
                            <button onclick="openRejectModal(<?php echo (int)$a['id']; ?>, <?php echo $a['status']==='published'?'true':'false'; ?>)"
                                    class="button button-small"
                                    style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Reject</button>
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
        <?php if ($total > $perPage): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
            <a href="news.php?page=<?php echo $p; ?>"
               class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Reject modal: must stay inside <main> — the admin AJAX loader
             (admin/index.php) only injects main.outerHTML, so anything placed
             after </main> never reaches the DOM when this page loads via AJAX. -->
        <div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeRejectModal()">
            <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <h3 id="reject-modal-title" style="margin:0 0 6px;font-size:1rem;">Reject Article</h3>
                <p id="reject-modal-desc" style="font-size:.85rem;color:#6b7280;margin:0 0 14px;">Explain why. The author will see this reason and can edit and resubmit.</p>
                <form method="post" action="news.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" id="reject-target-id" value="">
                    <textarea name="rejection_reason" rows="4"
                              placeholder="e.g. Needs more detail, incorrect information, unrelated to the community…"
                              style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font-size:.88rem;resize:vertical;margin-bottom:12px;"></textarea>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="button button-primary" style="background:#dc2626;border-color:#dc2626;">Confirm Rejection</button>
                        <button type="button" onclick="closeRejectModal()" class="button button-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
<script>
function openRejectModal(id, isPublished) {
    document.getElementById('reject-target-id').value = id;
    if (isPublished) {
        document.getElementById('reject-modal-title').textContent = 'Reject & Unpublish Article';
        document.getElementById('reject-modal-desc').textContent = 'This article is currently published. Rejecting it will remove it from the public feed and notify the author.';
    } else {
        document.getElementById('reject-modal-title').textContent = 'Reject Article';
        document.getElementById('reject-modal-desc').textContent = 'Explain why. The author will see this reason and can edit and resubmit.';
    }
    var m = document.getElementById('reject-modal');
    m.style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}
</script>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('news', 'News');
require_login();
$user = current_user();

$feeEnabled = (bool)(int)get_platform_setting('news_fee_enabled', '0') && !user_has_complimentary_access();
$feeAmount  = (float)get_platform_setting('news_fee_amount', '10');

$errors  = [];
$success = match($_GET['msg'] ?? '') {
    'deleted'   => 'Article deleted.',
    'updated'   => 'Article updated.',
    'submitted' => 'Article submitted and is pending admin review.',
    default     => '',
};

// Unique slug
function mn_slug($pdo, $base, $excludeId = 0) {
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $s = trim($s, '-') ?: 'article';
    $t = $s; $i = 2;
    while ($pdo->query("SELECT id FROM news WHERE slug='$t' AND id!=$excludeId LIMIT 1")->fetch()) {
        $t = $s . '-' . $i++;
    }
    return $t;
}

// Delete own draft / pending_payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $chk = $pdo->prepare("SELECT id, status FROM news WHERE id=? AND user_id=? LIMIT 1");
    $chk->execute([$did, $user['id']]);
    $row = $chk->fetch();
    if ($row && in_array($row['status'], ['draft', 'pending_payment', 'rejected'])) {
        $pdo->prepare("DELETE FROM news WHERE id=?")->execute([$did]);
        header('Location: my_news.php?msg=deleted'); exit;
    }
}

// Submit / update article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    csrf_check();

    $editId  = (int)($_POST['edit_id'] ?? 0);
    $title   = trim($_POST['title']   ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if (!$title)   $errors[] = 'Title is required.';
    if (!$content) $errors[] = 'Article body is required.';
    if (!$editId && requires_verified_email('news_post') && !is_email_verified()) {
        $errors[] = 'Please verify your email address before submitting an article.';
    }
    if (!$editId && is_banned_from_feature((int)$user['id'], 'news')) {
        $errors[] = 'You have been restricted from News. Contact support if you believe this is an error.';
    }

    // Verify ownership if editing
    $existingImg = null;
    if ($editId) {
        $chk = $pdo->prepare("SELECT * FROM news WHERE id=? AND user_id=? LIMIT 1");
        $chk->execute([$editId, $user['id']]);
        $existingRow = $chk->fetch();
        if (!$existingRow || $existingRow['status'] === 'published') {
            $errors[] = 'Cannot edit this article.';
        } else {
            $existingImg = $existingRow['featured_image'];
        }
    }

    $imgPath = $existingImg;
    if (!empty($_FILES['featured_image']['name'])) {
        $p = save_uploaded_image($_FILES['featured_image'], 'uploads/news', 1200, 85);
        if ($p) $imgPath = $p; else $errors[] = 'Image upload failed. JPEG/PNG/WebP, max 5 MB.';
    }

    if (!$errors) {
        $slug = mn_slug($pdo, $title . ' ' . date('Y'), $editId);
        if ($editId) {
            // Check if article was previously rejected (so we know this is a resubmission)
            $prevStatus = $pdo->prepare("SELECT status FROM news WHERE id=? AND user_id=? LIMIT 1");
            $prevStatus->execute([$editId, $user['id']]);
            $prevRow = $prevStatus->fetch();
            $wasRejected = ($prevRow['status'] ?? '') === 'rejected';

            $pdo->prepare(
                "UPDATE news SET title=?,slug=?,summary=?,content=?,featured_image=?,
                 status='draft',rejection_reason=NULL,updated_at=NOW()
                 WHERE id=? AND user_id=? AND status != 'published'"
            )->execute([$title, $slug, $summary, $content, $imgPath, $editId, $user['id']]);

            if ($wasRejected) {
                notify_admins_and_managers(
                    'Article Resubmitted for Review',
                    display_name($user) . ' has resubmitted "' . $title . '" after rejection. Please review in Admin → News.',
                    'info'
                );
                flash('Article resubmitted for review. Admin has been notified.', 'success');
            }
            header('Location: my_news.php?msg=updated'); exit;
        } else {
            $initStatus = $feeEnabled ? 'pending_payment' : 'draft';
            $pdo->prepare(
                "INSERT INTO news (user_id,title,slug,summary,content,featured_image,status,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,NOW(),NOW())"
            )->execute([$user['id'], $title, $slug, $summary, $content, $imgPath, $initStatus]);
            $newId = (int)$pdo->lastInsertId();
            if ($feeEnabled) {
                header('Location: pay_news.php?id=' . $newId); exit;
            }
            notify_admins_and_managers(
                'New article submitted',
                display_name($user) . ' submitted a new article: "' . $title . '". Review and publish in the News admin panel.',
                'info'
            );
            header('Location: my_news.php?msg=submitted'); exit;
        }
    }
}

// Edit mode pre-fill
$editArticle = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $chk = $pdo->prepare("SELECT * FROM news WHERE id=? AND user_id=? LIMIT 1");
    $chk->execute([(int)$_GET['edit'], $user['id']]);
    $editArticle = $chk->fetch();
    if ($editArticle && $editArticle['status'] === 'published') {
        $editArticle = null;
    }
}

$myArticles = $pdo->prepare("SELECT * FROM news WHERE user_id=? ORDER BY created_at DESC");
$myArticles->execute([$user['id']]);
$myList = $myArticles->fetchAll();

$statusLabels = [
    'pending_payment' => ['label' => 'Awaiting Payment', 'color' => '#92400e', 'bg' => '#fffbeb'],
    'draft'           => ['label' => 'Under Review',     'color' => '#2563eb', 'bg' => '#eff6ff'],
    'published'       => ['label' => 'Published',        'color' => '#059669', 'bg' => '#ecfdf5'],
    'rejected'        => ['label' => 'Rejected',         'color' => '#dc2626', 'bg' => '#fee2e2'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Articles — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mn-shell  { max-width:1080px; margin:0 auto; padding:20px 16px 60px; }
        .mn-layout { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }
        @media(max-width:860px){ .mn-layout { grid-template-columns:1fr; } }

        /* Sidebar */
        .mn-sidebar { position:sticky; top:16px; }
        .mn-sidebar-hd { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:12px; }
        .mn-list   { display:flex; flex-direction:column; gap:10px; }
        .mn-item   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:12px 14px; display:flex; align-items:flex-start; gap:12px; }
        .mn-thumb  { width:56px; height:42px; border-radius:6px; background:#f3f4f6; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:1.2rem; }
        .mn-thumb img { width:100%; height:100%; object-fit:cover; }
        .mn-info   { flex:1; min-width:0; }
        .mn-title  { font-weight:700; font-size:.88rem; margin:0 0 3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mn-meta   { font-size:.75rem; color:var(--text-muted,#6b7280); }
        .mn-status { display:inline-block; font-size:.66rem; font-weight:800; padding:2px 8px; border-radius:20px; margin-top:4px; }
        .mn-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:8px; }

        /* Form */
        .mn-form-wrap { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px; }
        .mn-form-wrap h2 { font-size:1rem; font-weight:800; margin:0 0 6px; }
        .mn-form-wrap > p { font-size:.83rem; color:var(--text-muted); margin:0 0 16px; }
        .mn-field  { margin-bottom:16px; }
        .mn-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .mn-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .mn-field input, .mn-field textarea { width:100%; box-sizing:border-box; }
        .mn-field textarea { resize:vertical; }
        .mn-fee-notice { background:#fffbeb; border:1px solid #f59e0b; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:.88rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="community.php" class="button button-secondary button-small">← Community</a>
        <h1>My Articles</h1>
        <a href="news.php" class="button button-small">All News</a>
    </header>

    <div class="mn-shell">
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px;"><?php echo sanitize($success); ?></div><?php endif; ?>

        <div class="mn-layout">
            <!-- Submit / Edit form -->
            <div class="mn-main">
            <div class="mn-form-wrap">
            <h2><?php echo $editArticle ? 'Edit Article' : 'Submit a News Article'; ?></h2>
            <p>Articles are reviewed by an admin before appearing on the news page.</p>

            <?php if ($feeEnabled && !$editArticle): ?>
            <div class="mn-fee-notice">
                💳 A <strong>GH₵ <?php echo number_format($feeAmount,2); ?></strong> publishing fee applies.
                After submitting, you'll be taken to a secure Paystack checkout to complete payment.
            </div>
            <?php endif; ?>

            <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_submit" value="1">
                <?php if ($editArticle): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$editArticle['id']; ?>">
                <?php endif; ?>

                <div class="mn-field">
                    <label>Article Title *</label>
                    <?php $_errN = ($errors && !$editArticle) ? $_POST : []; ?>
                    <input type="text" name="title" class="form-control" required
                           value="<?php echo sanitize($editArticle['title'] ?? $_errN['title'] ?? ''); ?>"
                           placeholder="Enter a clear, descriptive title">
                </div>

                <div class="mn-field">
                    <label>Summary / Teaser</label>
                    <div class="desc">One or two sentences shown in article cards. Leave blank to auto-generate from body.</div>
                    <textarea name="summary" class="form-control" rows="2"
                              placeholder="Brief description of the article…"><?php echo sanitize($editArticle['summary'] ?? $_errN['summary'] ?? ''); ?></textarea>
                </div>

                <div class="mn-field">
                    <label>Article Body *</label>
                    <textarea name="content" class="form-control rich-editor" rows="12" required
                              placeholder="Write your full article here…"><?php echo $editArticle ? $editArticle['content'] : ($_errN['content'] ?? ''); ?></textarea>
                </div>

                <div class="mn-field">
                    <label>Featured Image</label>
                    <div class="desc">Recommended 1200×630 px · JPEG/PNG/WebP · Max 5 MB</div>
                    <input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp">
                    <?php if ($editArticle && !empty($editArticle['featured_image'])): ?>
                        <img src="<?php echo sanitize($editArticle['featured_image']); ?>" alt=""
                             style="max-width:220px;border-radius:8px;margin-top:6px;">
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;">
                    <button type="submit" class="button button-primary">
                        <?php echo $editArticle ? 'Save Changes' : 'Submit Article'; ?>
                    </button>
                    <?php if ($editArticle): ?>
                    <a href="my_news.php" class="button button-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
            </div><!-- /.mn-main -->

            <!-- Sidebar: submissions list -->
            <aside class="mn-sidebar">
                <?php if ($myList): ?>
                <div class="mn-sidebar-hd">My Articles (<?php echo count($myList); ?>)</div>
                <div class="mn-list">
                    <?php foreach ($myList as $art): ?>
                    <?php $sl = $statusLabels[$art['status']] ?? $statusLabels['draft']; ?>
                    <?php $isRejected = $art['status'] === 'rejected'; ?>
                    <div class="mn-item" style="<?php echo $isRejected ? 'border-left:4px solid #ef4444;' : ''; ?>">
                        <div class="mn-thumb">
                            <?php if ($art['featured_image']): ?>
                                <img src="<?php echo sanitize($art['featured_image']); ?>" alt="">
                            <?php else: ?>
                                📰
                            <?php endif; ?>
                        </div>
                        <div class="mn-info">
                            <?php if ($isRejected): ?>
                            <p style="font-size:.68rem;font-weight:800;color:#ef4444;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">❌ Rejected — Action needed</p>
                            <?php endif; ?>
                            <p class="mn-title"><?php echo sanitize($art['title']); ?></p>
                            <p class="mn-meta">Submitted <?php echo date('d M Y', strtotime($art['created_at'])); ?></p>
                            <span class="mn-status" style="color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;">
                                <?php echo $sl['label']; ?>
                            </span>
                            <?php if ($isRejected): ?>
                            <div style="background:#fee2e2;border-left:3px solid #ef4444;border-radius:0 6px 6px 0;padding:6px 8px;margin:6px 0;font-size:.76rem;color:#7f1d1d;line-height:1.5;">
                                <?php echo $isRejected && !empty($art['rejection_reason'])
                                    ? nl2br(sanitize($art['rejection_reason']))
                                    : '<em style="color:#991b1b;">No reason given — contact admin.</em>'; ?>
                            </div>
                            <?php endif; ?>
                            <div class="mn-actions">
                                <?php if ($art['status'] === 'published' && $art['slug']): ?>
                                    <a href="news_article.php?slug=<?php echo urlencode($art['slug']); ?>" class="button button-small" target="_blank">View</a>
                                    <?php
                                    $artFeatEnd    = $art['featured_end_date'] ?? null;
                                    $artFeatActive = !empty($art['featured']) && ($artFeatEnd === null || $artFeatEnd >= date('Y-m-d'));
                                    ?>
                                    <?php if ($artFeatActive): ?>
                                    <span style="font-size:.68rem;font-weight:800;padding:3px 8px;border-radius:20px;background:#fef3c7;color:#92400e;">⭐ Featured</span>
                                    <?php else: ?>
                                    <a href="feature_news.php?id=<?php echo (int)$art['id']; ?>" class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;">⭐ Feature</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($art['status'] === 'pending_payment'): ?>
                                    <a href="pay_news.php?id=<?php echo (int)$art['id']; ?>" class="button button-small button-primary">Pay</a>
                                <?php endif; ?>
                                <?php if ($isRejected): ?>
                                    <a href="my_news.php?edit=<?php echo (int)$art['id']; ?>" class="button button-primary button-small" style="background:#ef4444;border-color:#ef4444;width:100%;text-align:center;display:block;margin-bottom:5px;">✏️ Fix &amp; Resubmit</a>
                                    <form method="post" onsubmit="return confirm('Delete this article?')" style="width:100%;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$art['id']; ?>">
                                        <button class="button button-small" style="width:100%;background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                                    </form>
                                <?php elseif (in_array($art['status'], ['draft', 'pending_payment'])): ?>
                                    <a href="my_news.php?edit=<?php echo (int)$art['id']; ?>" class="button button-small button-secondary">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this article?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$art['id']; ?>">
                                        <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Del</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.83rem;color:var(--text-muted,#6b7280);">No articles submitted yet.</p>
                <?php endif; ?>
            </aside>
        </div><!-- /.mn-layout -->
    </div>

<?php $activeNav = 'community'; require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

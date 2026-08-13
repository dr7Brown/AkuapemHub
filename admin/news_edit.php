<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

require_mod_permission('approve_news');
$id = (int)($_GET['id'] ?? 0);
$article = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM news WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article) { header('Location: news.php'); exit; }
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']   ?? '');
    $slug        = trim($_POST['slug']    ?? '');
    $summary     = trim($_POST['summary'] ?? '');
    $content     = $_POST['content']      ?? '';
    $status      = in_array($_POST['status'] ?? '', ['draft','published']) ? $_POST['status'] : 'draft';
    $publishedAt = trim($_POST['published_at'] ?? '');

    if (!$title)   $errors[] = 'Title is required.';
    if (!$slug)    $errors[] = 'Slug is required.';

    // Sanitize slug
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $slug)));
    if (!$slug)  $errors[] = 'Slug contains invalid characters.';

    // Ensure slug unique
    if (!$errors) {
        $dupStmt = $pdo->prepare("SELECT id FROM news WHERE slug=? AND id!=?");
        $dupStmt->execute([$slug, $id]);
        if ($dupStmt->fetch()) $errors[] = "The slug '$slug' is already in use. Please choose a different one.";
    }

    // Image upload
    $imagePath = $article['featured_image'] ?? null;
    if (!empty($_FILES['featured_image']['name'])) {
        $newPath = save_uploaded_image($_FILES['featured_image'], 'uploads/news', 1600, 84);
        if ($newPath) {
            $imagePath = $newPath;
        } else {
            $errors[] = 'Image upload failed. Only JPEG/PNG/WebP up to 5 MB are allowed.';
        }
    }

    $pubAt = ($publishedAt && strtotime($publishedAt)) ? date('Y-m-d H:i:s', strtotime($publishedAt)) : null;
    if ($status === 'published' && !$pubAt) $pubAt = date('Y-m-d H:i:s');

    if (!$errors) {
        $wasNotified = (int)($article['notification_sent'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE news SET title=?, slug=?, summary=?, content=?, featured_image=?, status=?, published_at=?, updated_at=NOW() WHERE id=?")
                ->execute([$title, $slug, $summary, $content, $imagePath, $status, $pubAt, $id]);
            log_audit_action($user['id'], 'news_edit', "Edited article #$id: $title");
        } else {
            $pdo->prepare("INSERT INTO news (title, slug, summary, content, featured_image, status, published_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$title, $slug, $summary, $content, $imagePath, $status, $pubAt]);
            $id = (int)$pdo->lastInsertId();
            log_audit_action($user['id'], 'news_create', "Created article #$id: $title");
        }
        // Notify all users the first time this article goes live
        if ($status === 'published' && !$wasNotified) {
            $uids = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $uid) {
                notify_user((int)$uid, '📰 New article published', $title . ' — read the latest from ' . APP_NAME . '.', 'info');
            }
            $pdo->prepare("UPDATE news SET notification_sent=1 WHERE id=?")->execute([$id]);
        }
        header('Location: news_edit.php?id=' . $id . '&saved=1');
        exit;
    }

    // Re-populate on error
    $article = array_merge($article ?? [], compact('title','slug','summary','content','status','published_at'));
}

$isNew = !$id;
$pageTitle = $isNew ? 'New Article' : 'Edit: ' . sanitize($article['title'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $pageTitle; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .ne-shell { max-width:760px; margin:0 auto; padding:20px 16px 60px; }
        .ne-field  { margin-bottom:18px; }
        .ne-field label { display:block; font-weight:600; font-size:.88rem; margin-bottom:4px; }
        .ne-field .desc { font-size:.78rem; color:var(--text-muted); margin-bottom:5px; }
        .ne-field input[type=text], .ne-field input[type=datetime-local], .ne-field select,
        .ne-field textarea { width:100%; box-sizing:border-box; }
        .ne-field textarea { resize:vertical; min-height:80px; }
        #content-area { font-family:monospace; min-height:280px; }
        .ne-img-preview { max-width:240px; border-radius:8px; margin-top:8px; }
        .ne-row  { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media (max-width:540px) { .ne-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="news.php" class="button button-secondary button-small">← News</a>
        <h1><?php echo $isNew ? 'New Article' : 'Edit Article'; ?></h1>
        <?php if (!$isNew): ?>
            <a href="../news_article.php?slug=<?php echo urlencode($article['slug']); ?>" target="_blank" class="button button-small">Preview ↗</a>
        <?php endif; ?>
    </header>

    <main class="ne-shell">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success" style="margin-bottom:16px;">Article saved successfully.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
        <?php endforeach; ?>

        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="ne-field">
                <label for="ne-title">Title *</label>
                <input type="text" id="ne-title" name="title" class="form-control" required
                       value="<?php echo sanitize($article['title'] ?? ''); ?>" oninput="autoSlug()">
            </div>

            <div class="ne-field">
                <label for="ne-slug">Slug (URL) *</label>
                <div class="desc">Auto-generated from title. Must be unique and URL-safe (a–z, 0–9, hyphens).</div>
                <input type="text" id="ne-slug" name="slug" class="form-control" required
                       value="<?php echo sanitize($article['slug'] ?? ''); ?>" oninput="userEditedSlug=true">
            </div>

            <div class="ne-field">
                <label for="ne-summary">Summary</label>
                <div class="desc">Short description shown on the news feed and in social previews.</div>
                <textarea id="ne-summary" name="summary" class="form-control" rows="3"><?php echo sanitize($article['summary'] ?? ''); ?></textarea>
            </div>

            <div class="ne-field">
                <label for="content-area">Content *</label>
                <div class="desc">Select any text to format it — Bold, Italic, Headings, Lists, and more.</div>
                <textarea id="content-area" name="content" class="form-control rich-editor" rows="16"><?php echo $article['content'] ?? ''; ?></textarea>
            </div>

            <div class="ne-field">
                <label for="ne-image">Featured Image</label>
                <div class="desc">JPEG, PNG, or WebP · Max 5 MB · Recommended 1200×630 px</div>
                <input type="file" id="ne-image" name="featured_image" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($article['featured_image'])): ?>
                    <img src="../<?php echo sanitize($article['featured_image']); ?>" alt="Current image" class="ne-img-preview">
                    <p style="font-size:.78rem;color:var(--text-muted);margin:4px 0 0;">Upload a new file to replace the current image.</p>
                <?php endif; ?>
            </div>

            <div class="ne-row">
                <div class="ne-field">
                    <label for="ne-status">Status</label>
                    <select id="ne-status" name="status" class="form-control">
                        <option value="draft"     <?php echo ($article['status'] ?? 'draft') === 'draft'     ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo ($article['status'] ?? 'draft') === 'published' ? 'selected' : ''; ?>>Published</option>
                    </select>
                </div>
                <div class="ne-field">
                    <label for="ne-pubat">Publish date/time</label>
                    <div class="desc">Leave blank to publish immediately when status is set to Published.</div>
                    <input type="datetime-local" id="ne-pubat" name="published_at" class="form-control"
                           value="<?php echo !empty($article['published_at']) ? date('Y-m-d\TH:i', strtotime($article['published_at'])) : ''; ?>">
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="button button-primary">
                    <?php echo $isNew ? 'Create Article' : 'Save Changes'; ?>
                </button>
                <a href="news.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
    </main>

    <script>
    var userEditedSlug = <?php echo $isNew ? 'false' : 'true'; ?>;
    function generateSlug(str) {
        return str.toLowerCase().replace(/[^a-z0-9\s-]/g,'').replace(/[\s-]+/g,'-').replace(/^-|-$/g,'');
    }
    function autoSlug() {
        if (!userEditedSlug) {
            document.getElementById('ne-slug').value = generateSlug(document.getElementById('ne-title').value);
        }
    }
    </script>
    <script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>

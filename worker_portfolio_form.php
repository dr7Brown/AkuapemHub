<?php
/**
 * Add/edit a single worker portfolio item (a past project) + its photo
 * gallery. Mirrors seller_product_form.php's image-upload pattern
 * (is_valid_image_upload()/save_uploaded_image() from functions.php,
 * existing images shown with a delete button that calls ajax.php).
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

$maxImages = 6;
$editId = (int)($_GET['id'] ?? 0);
$item   = null;
$images = [];
if ($editId) {
    $itemSt = $pdo->prepare('SELECT * FROM worker_portfolio_items WHERE id=? AND worker_profile_id=?');
    $itemSt->execute([$editId, $profile['id']]);
    $item = $itemSt->fetch();
    if (!$item) {
        flash('Project not found.', 'error');
        header('Location: worker_portfolio.php');
        exit;
    }
    $imgSt = $pdo->prepare('SELECT * FROM worker_portfolio_images WHERE item_id=? ORDER BY is_primary DESC, sort_order ASC');
    $imgSt->execute([$editId]);
    $images = $imgSt->fetchAll();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '') $error = 'Project title is required.';

    if (!$error) {
        if ($editId) {
            $pdo->prepare('UPDATE worker_portfolio_items SET title=?, description=?, updated_at=NOW() WHERE id=?')
                ->execute([$title, $description ?: null, $editId]);
        } else {
            $pdo->prepare('INSERT INTO worker_portfolio_items (worker_profile_id, title, description) VALUES (?,?,?)')
                ->execute([$profile['id'], $title, $description ?: null]);
            $editId = (int)$pdo->lastInsertId();
        }

        if (!empty($_FILES['project_images']['name'][0])) {
            $existCheck = $pdo->prepare('SELECT COUNT(*) FROM worker_portfolio_images WHERE item_id=?');
            $existCheck->execute([$editId]);
            $existingCount = (int)$existCheck->fetchColumn();
            $maxNew = max(0, $maxImages - $existingCount);

            $files = $_FILES['project_images'];
            for ($i = 0; $i < min($maxNew, count($files['name'])); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !$files['name'][$i]) continue;
                $file = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                if (!is_valid_image_upload($file)) continue;
                $path = save_uploaded_image($file, 'uploads/worker_portfolio/' . $editId, 1200, 85);
                if ($path) {
                    $isPrimary = ($existingCount === 0 && $i === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO worker_portfolio_images (item_id, image_path, is_primary, sort_order) VALUES (?,?,?,?)')
                        ->execute([$editId, $path, $isPrimary, $existingCount + $i]);
                }
            }
        }

        flash($item ? 'Project updated.' : 'Project added.', 'success');
        header('Location: worker_portfolio.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $item ? 'Edit Project' : 'Add Project'; ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .wpf-shell { max-width:680px; margin:0 auto; padding:20px 16px 80px; }
        .wpf-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .wpf-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .wpf-existing-imgs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .wpf-existing-img  { position:relative; }
        .wpf-existing-img img { width:64px; height:64px; border-radius:8px; object-fit:cover; border:2px solid var(--border); }
        .wpf-del-img { position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; border:none; border-radius:50%; width:18px; height:18px; font-size:.7rem; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="worker_portfolio.php" class="button button-secondary button-small">← My Portfolio</a>
    <span class="brand"><?php echo $item ? 'Edit Project' : 'Add Project'; ?></span>
</header>

<main class="wpf-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="worker_portfolio_form.php<?php echo $editId ? '?id='.$editId : ''; ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="wpf-section">
            <p class="wpf-section-title">Project Details</p>
            <div class="form-group">
                <label for="title">Project Title *</label>
                <input type="text" id="title" name="title" required value="<?php echo sanitize($_POST['title'] ?? ($item['title'] ?? '')); ?>" placeholder="e.g. Kitchen tiling — Aburi">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="rich-editor" rows="4" placeholder="What was the job, what did it involve, any details customers should know…"><?php echo $_POST['description'] ?? ($item['description'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="wpf-section">
            <p class="wpf-section-title">Photos (up to <?php echo $maxImages; ?>)</p>
            <?php if ($images): ?>
            <div class="wpf-existing-imgs">
                <?php foreach ($images as $img): ?>
                <div class="wpf-existing-img">
                    <img src="<?php echo sanitize($img['image_path']); ?>" alt="">
                    <button type="button" class="wpf-del-img" onclick="deletePortfolioImage(<?php echo (int)$img['id']; ?>, this)">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <input type="file" name="project_images[]" multiple accept="image/jpeg,image/png,image/webp">
            <p class="form-hint">JPEG/PNG/WEBP, max 5MB each. First image becomes the cover photo.</p>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="button button-primary" style="flex:1;"><?php echo $item ? 'Save Changes' : 'Add Project'; ?></button>
        </div>
    </form>
</main>

<script src="assets/js/rich-editor.js"></script>
<script>
var CSRF = <?php echo json_encode(csrf_token()); ?>;
function deletePortfolioImage(imageId, btn) {
    if (!confirm('Remove this photo?')) return;
    btn.disabled = true;
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_portfolio_image&image_id=' + imageId + '&csrf_token=' + encodeURIComponent(CSRF)
    }).then(function () {
        location.reload();
    }).catch(function () {
        btn.disabled = false;
        alert('Could not remove photo. Please try again.');
    });
}
</script>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

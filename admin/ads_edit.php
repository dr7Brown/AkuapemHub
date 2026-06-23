<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
require_mod_permission('manage_ads');
$ad = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM advertisements WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $ad = $stmt->fetch();
    if (!$ad) { header('Location: ads.php'); exit; }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title   = trim($_POST['title']           ?? '');
    $destUrl = trim($_POST['destination_url'] ?? '');
    $adType  = in_array($_POST['ad_type'] ?? '', ['banner','sponsored']) ? $_POST['ad_type'] : 'banner';
    $status  = in_array($_POST['status']  ?? '', ['active','inactive'])  ? $_POST['status']  : 'inactive';
    $startDate = trim($_POST['start_date'] ?? '') ?: null;
    $endDate   = trim($_POST['end_date']   ?? '') ?: null;

    if (!$title)   $errors[] = 'Title is required.';
    if ($destUrl && !filter_var($destUrl, FILTER_VALIDATE_URL) && !preg_match('#^https?://#i', $destUrl)) {
        // Accept bare domains too
    }

    $imagePath = $ad['image'] ?? null;
    if (!empty($_FILES['image']['name'])) {
        $newPath = save_uploaded_image($_FILES['image'], 'uploads/ads', 1200, 86);
        if ($newPath) {
            $imagePath = $newPath;
        } else {
            $errors[] = 'Image upload failed. Only JPEG/PNG/WebP up to 5 MB are allowed.';
        }
    }

    if (!$errors) {
        if ($id) {
            $pdo->prepare("UPDATE advertisements SET title=?, image=?, destination_url=?, ad_type=?, status=?, start_date=?, end_date=?, updated_at=NOW() WHERE id=?")
                ->execute([$title, $imagePath, $destUrl, $adType, $status, $startDate, $endDate, $id]);
            log_audit_action($user['id'], 'ad_edit', "Edited ad #$id: $title");
        } else {
            $pdo->prepare("INSERT INTO advertisements (title, image, destination_url, ad_type, status, start_date, end_date) VALUES (?,?,?,?,?,?,?)")
                ->execute([$title, $imagePath, $destUrl, $adType, $status, $startDate, $endDate]);
            $id = (int)$pdo->lastInsertId();
            log_audit_action($user['id'], 'ad_create', "Created ad #$id: $title");
        }
        header('Location: ads_edit.php?id=' . $id . '&saved=1');
        exit;
    }

    $ad = array_merge($ad ?? [], compact('title','destination_url','ad_type','status','start_date','end_date'));
    $ad['destination_url'] = $destUrl;
    $ad['ad_type']  = $adType;
    $ad['status']   = $status;
    $ad['start_date'] = $startDate;
    $ad['end_date']   = $endDate;
}

$isNew = !($ad['id'] ?? false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $isNew ? 'New Ad' : 'Edit Ad'; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .ae-shell { max-width:640px; margin:0 auto; padding:20px 16px 60px; }
        .ae-field  { margin-bottom:18px; }
        .ae-field label { display:block; font-weight:600; font-size:.88rem; margin-bottom:4px; }
        .ae-field .desc { font-size:.78rem; color:var(--text-muted); margin-bottom:5px; }
        .ae-field input, .ae-field select, .ae-field textarea { width:100%; box-sizing:border-box; }
        .ae-row  { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media (max-width:480px) { .ae-row { grid-template-columns:1fr; } }
        .ae-img-preview { max-width:320px; border-radius:8px; margin-top:8px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="ads.php" class="button button-secondary button-small">← Ads</a>
        <h1><?php echo $isNew ? 'New Advertisement' : 'Edit Advertisement'; ?></h1>
    </header>

    <main class="ae-shell">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success" style="margin-bottom:16px;">Advertisement saved.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
        <?php endforeach; ?>

        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="ae-field">
                <label for="ae-title">Title *</label>
                <input type="text" id="ae-title" name="title" class="form-control" required
                       value="<?php echo sanitize($ad['title'] ?? ''); ?>" placeholder="Ad title (internal reference)">
            </div>

            <div class="ae-field">
                <label for="ae-image">Ad Image</label>
                <div class="desc">Banner: recommended 728×90 px. Sponsored: 600×400 px. JPEG/PNG/WebP · Max 5 MB.</div>
                <input type="file" id="ae-image" name="image" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($ad['image'])): ?>
                    <img src="../<?php echo sanitize($ad['image']); ?>" alt="Current image" class="ae-img-preview">
                    <p style="font-size:.78rem;color:var(--text-muted);margin:4px 0 0;">Upload a new file to replace.</p>
                <?php endif; ?>
            </div>

            <div class="ae-field">
                <label for="ae-url">Destination URL</label>
                <div class="desc">Where clicking the ad takes the user. Clicks are tracked via ad_click.php.</div>
                <input type="text" id="ae-url" name="destination_url" class="form-control"
                       value="<?php echo sanitize($ad['destination_url'] ?? ''); ?>" placeholder="https://example.com">
            </div>

            <div class="ae-row">
                <div class="ae-field">
                    <label for="ae-type">Ad Type</label>
                    <select id="ae-type" name="ad_type" class="form-control">
                        <option value="banner"    <?php echo ($ad['ad_type'] ?? 'banner') === 'banner'    ? 'selected' : ''; ?>>Banner (full-width strip)</option>
                        <option value="sponsored" <?php echo ($ad['ad_type'] ?? 'banner') === 'sponsored' ? 'selected' : ''; ?>>Sponsored Post (in feed)</option>
                    </select>
                </div>
                <div class="ae-field">
                    <label for="ae-status">Status</label>
                    <select id="ae-status" name="status" class="form-control">
                        <option value="inactive" <?php echo ($ad['status'] ?? 'inactive') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="active"   <?php echo ($ad['status'] ?? 'inactive') === 'active'   ? 'selected' : ''; ?>>Active</option>
                    </select>
                </div>
            </div>

            <div class="ae-row">
                <div class="ae-field">
                    <label for="ae-start">Start Date (optional)</label>
                    <div class="desc">Ad only shows from this date onward.</div>
                    <input type="date" id="ae-start" name="start_date" class="form-control"
                           value="<?php echo sanitize($ad['start_date'] ?? ''); ?>">
                </div>
                <div class="ae-field">
                    <label for="ae-end">End Date (optional)</label>
                    <div class="desc">Ad stops showing after this date.</div>
                    <input type="date" id="ae-end" name="end_date" class="form-control"
                           value="<?php echo sanitize($ad['end_date'] ?? ''); ?>">
                </div>
            </div>

            <?php if (!$isNew): ?>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px;">
                👆 <strong><?php echo number_format((int)($ad['click_count'] ?? 0)); ?></strong> total clicks tracked on this ad.
            </p>
            <?php endif; ?>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create Ad' : 'Save Changes'; ?></button>
                <a href="ads.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
    </main>
</body>
</html>

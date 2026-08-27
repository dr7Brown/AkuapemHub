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
    $adType  = in_array($_POST['ad_type'] ?? '', ['banner','sponsored','video'], true) ? $_POST['ad_type'] : 'banner';
    $status  = in_array($_POST['status']  ?? '', ['active','inactive'])  ? $_POST['status']  : 'inactive';
    $startDate = trim($_POST['start_date'] ?? '') ?: null;
    $endDate   = trim($_POST['end_date']   ?? '') ?: null;
    $weight    = max(1, min(10, (int)($_POST['weight'] ?? 1)));

    $validPlacements = ['homepage','jobs','marketplace','accommodation','delivery','markets','quick_services','events','funerals','news'];
    $chosenPlacements = array_values(array_intersect((array)($_POST['placements'] ?? []), $validPlacements));
    $placements = $chosenPlacements ? implode(',', $chosenPlacements) : null;

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

    $videoPath = $ad['video'] ?? null;
    if (!empty($_FILES['video']['name'])) {
        $newVideoPath = save_uploaded_document($_FILES['video'], 'uploads/ads', ['video/mp4', 'video/webm'], 25 * 1024 * 1024);
        if ($newVideoPath) {
            $videoPath = $newVideoPath;
        } else {
            $errors[] = 'Video upload failed. Only MP4/WebM up to 25 MB are allowed.';
        }
    }
    if ($adType === 'video' && !$videoPath) {
        $errors[] = 'Upload a video file for a Video ad.';
    }

    if (!$errors) {
        if ($id) {
            $pdo->prepare("UPDATE advertisements SET title=?, image=?, video=?, destination_url=?, ad_type=?, status=?, placements=?, weight=?, start_date=?, end_date=?, updated_at=NOW() WHERE id=?")
                ->execute([$title, $imagePath, $videoPath, $destUrl, $adType, $status, $placements, $weight, $startDate, $endDate, $id]);
            log_audit_action($user['id'], 'ad_edit', "Edited ad #$id: $title");
        } else {
            $pdo->prepare("INSERT INTO advertisements (title, image, video, destination_url, ad_type, status, placements, weight, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$title, $imagePath, $videoPath, $destUrl, $adType, $status, $placements, $weight, $startDate, $endDate]);
            $id = (int)$pdo->lastInsertId();
            log_audit_action($user['id'], 'ad_create', "Created ad #$id: $title");
        }
        header('Location: ads_edit.php?id=' . $id . '&saved=1');
        exit;
    }

    $ad = array_merge($ad ?? [], compact('title','destination_url','ad_type','status','start_date','end_date','weight'));
    $ad['destination_url'] = $destUrl;
    $ad['ad_type']  = $adType;
    $ad['status']   = $status;
    $ad['start_date'] = $startDate;
    $ad['end_date']   = $endDate;
    $ad['weight']     = $weight;
    $ad['placements'] = $placements;
}

$isNew = !($ad['id'] ?? false);
$adPlacementsSelected = array_filter(explode(',', $ad['placements'] ?? ''));
$placementLabels = [
    'homepage' => 'Homepage', 'jobs' => 'Jobs Dashboard', 'marketplace' => 'Marketplace',
    'accommodation' => 'Accommodation', 'delivery' => 'Delivery Services', 'markets' => 'Nearby Markets',
    'quick_services' => 'Quick Services', 'events' => 'Events', 'funerals' => 'Funeral Announcements', 'news' => 'News',
];
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
        <?php if (!$isNew): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);">
            <a href="ads_edit.php" class="button button-primary button-small">+ New Ad</a>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success" style="margin-bottom:16px;">Advertisement saved. <a href="ads_edit.php">Create another →</a></div>
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

            <div class="ae-field" id="ae-image-field">
                <label for="ae-image">Ad Image</label>
                <div class="desc">Banner: recommended 728×90 px. Sponsored: 600×400 px. JPEG/PNG/WebP · Max 5 MB.</div>
                <input type="file" id="ae-image" name="image" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($ad['image'])): ?>
                    <img src="../<?php echo sanitize($ad['image']); ?>" alt="Current image" class="ae-img-preview">
                    <p style="font-size:.78rem;color:var(--text-muted);margin:4px 0 0;">Upload a new file to replace.</p>
                <?php endif; ?>
            </div>

            <div class="ae-field" id="ae-video-field" style="display:none;">
                <label for="ae-video">Ad Video</label>
                <div class="desc">Shown muted, autoplay, looping. MP4/WebM · Max 25 MB.</div>
                <input type="file" id="ae-video" name="video" accept="video/mp4,video/webm">
                <?php if (!empty($ad['video'])): ?>
                    <video src="../<?php echo sanitize($ad['video']); ?>" muted loop playsinline controls style="max-width:320px;border-radius:8px;margin-top:8px;display:block;"></video>
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
                    <select id="ae-type" name="ad_type" class="form-control" onchange="aeToggleMediaField(this.value)">
                        <option value="banner"    <?php echo ($ad['ad_type'] ?? 'banner') === 'banner'    ? 'selected' : ''; ?>>Banner (full-width strip)</option>
                        <option value="sponsored" <?php echo ($ad['ad_type'] ?? 'banner') === 'sponsored' ? 'selected' : ''; ?>>Sponsored Post (in feed)</option>
                        <option value="video"     <?php echo ($ad['ad_type'] ?? 'banner') === 'video'     ? 'selected' : ''; ?>>Video (autoplay banner)</option>
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

            <div class="ae-field">
                <label for="ae-weight">Priority Weight (1–10)</label>
                <div class="desc">Higher weight rotates in more often. Delivery also self-balances against how many times each ad has already been shown, so a weight-1 ad still gets fair rotation over time — this just tilts the odds.</div>
                <input type="number" id="ae-weight" name="weight" class="form-control" min="1" max="10" step="1"
                       value="<?php echo sanitize($ad['weight'] ?? 1); ?>">
            </div>

            <div class="ae-field">
                <label>Placements</label>
                <div class="desc">Which pages this ad is eligible to appear on. Leave all unchecked to show on every page ads support.</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <?php foreach ($placementLabels as $key => $label): ?>
                    <label style="font-weight:400;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" name="placements[]" value="<?php echo $key; ?>" style="width:auto;"
                               <?php echo in_array($key, $adPlacementsSelected, true) ? 'checked' : ''; ?>>
                        <?php echo sanitize($label); ?>
                    </label>
                    <?php endforeach; ?>
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

            <?php if (!$isNew):
                $impressions = (int)($ad['impression_count'] ?? 0);
                $clicks      = (int)($ad['click_count'] ?? 0);
                $ctr         = $impressions > 0 ? round($clicks / $impressions * 100, 2) : null;
            ?>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px;">
                👁️ <strong><?php echo number_format($impressions); ?></strong> impressions ·
                👆 <strong><?php echo number_format($clicks); ?></strong> clicks
                <?php if ($ctr !== null): ?> · <strong><?php echo $ctr; ?>%</strong> CTR<?php endif; ?>
            </p>
            <?php endif; ?>

            <script>
            function aeToggleMediaField(type) {
                document.getElementById('ae-image-field').style.display = type === 'video' ? 'none' : '';
                document.getElementById('ae-video-field').style.display = type === 'video' ? '' : 'none';
            }
            aeToggleMediaField(document.getElementById('ae-type').value);
            </script>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create Ad' : 'Save Changes'; ?></button>
                <a href="ads.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
    </main>
</body>
</html>

<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

require_mod_permission('approve_funerals');
$id = (int)($_GET['id'] ?? 0);
$fa = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM funeral_announcements WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $fa = $stmt->fetch();
    if (!$fa) { header('Location: funerals.php'); exit; }
}

$errors = [];

function fa_unique_slug($pdo, $base, $excludeId = 0) {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $slug = trim($slug, '-');
    $test = $slug; $i = 2;
    while ($pdo->query("SELECT id FROM funeral_announcements WHERE slug='" . addslashes($test) . "' AND id!=$excludeId LIMIT 1")->fetch()) {
        $test = $slug . '-' . $i++;
    }
    return $test;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $locLat = ($_POST['loc_lat'] ?? '') !== '' ? (float)$_POST['loc_lat'] : null;
    $locLng = ($_POST['loc_lng'] ?? '') !== '' ? (float)$_POST['loc_lng'] : null;
    $googleMapsLink = trim($_POST['google_maps_link'] ?? '') ?: null;
    if (!$googleMapsLink && $locLat && $locLng) {
        $googleMapsLink = 'https://maps.google.com/?q=' . $locLat . ',' . $locLng;
    }
    $fields = ['deceased_name','gender','age','date_of_birth','date_of_death','biography',
               'wake_keeping_date','burial_date','thanksgiving_date','venue','gps_address',
               'organizer_name','organizer_phone','organizer_email','status','featured'];
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
        if ($data[$f] === '') $data[$f] = null;
    }

    if (!$data['deceased_name']) $errors[] = 'Deceased name is required.';
    if (!in_array($data['status'] ?? 'pending', ['pending_payment','pending','approved','rejected'])) {
        $data['status'] = 'pending';
    }
    $data['featured'] = isset($_POST['featured']) ? 1 : 0;

    $photoPath  = $fa['photograph']    ?? null;
    $posterPath = $fa['funeral_poster'] ?? null;

    if (!empty($_FILES['photograph']['name'])) {
        $p = save_uploaded_image($_FILES['photograph'], 'uploads/funerals', 800, 85);
        if ($p) $photoPath = $p; else $errors[] = 'Photograph upload failed.';
    }
    if (!empty($_FILES['funeral_poster']['name'])) {
        $p = save_uploaded_image($_FILES['funeral_poster'], 'uploads/funerals', 1200, 85);
        if ($p) $posterPath = $p; else $errors[] = 'Poster upload failed.';
    }

    if (!$errors) {
        $slug = fa_unique_slug($pdo, $data['deceased_name'] . ' ' . date('Y', strtotime($data['date_of_death'] ?? 'now')), $id);
        if ($id) {
            $pdo->prepare(
                "UPDATE funeral_announcements SET deceased_name=?,gender=?,age=?,photograph=?,funeral_poster=?,
                 date_of_birth=?,date_of_death=?,biography=?,wake_keeping_date=?,burial_date=?,thanksgiving_date=?,
                 venue=?,gps_address=?,latitude=?,longitude=?,
                 organizer_name=?,organizer_phone=?,organizer_email=?,google_maps_link=?,status=?,featured=?,slug=?,updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $data['deceased_name'], $data['gender'] ?? 'male', $data['age'],
                $photoPath, $posterPath, $data['date_of_birth'], $data['date_of_death'], $data['biography'],
                $data['wake_keeping_date'], $data['burial_date'], $data['thanksgiving_date'],
                $data['venue'], $data['gps_address'], $locLat, $locLng,
                $data['organizer_name'],
                $data['organizer_phone'], $data['organizer_email'], $googleMapsLink, $data['status'], $data['featured'], $slug, $id
            ]);
            log_audit_action($user['id'], 'funeral_edit', "Edited funeral #{$id}: {$data['deceased_name']}");
        } else {
            $pdo->prepare(
                "INSERT INTO funeral_announcements
                 (deceased_name,gender,age,photograph,funeral_poster,date_of_birth,date_of_death,biography,
                  wake_keeping_date,burial_date,thanksgiving_date,venue,gps_address,latitude,longitude,
                  organizer_name,organizer_phone,organizer_email,google_maps_link,status,featured,slug)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $data['deceased_name'], $data['gender'] ?? 'male', $data['age'],
                $photoPath, $posterPath, $data['date_of_birth'], $data['date_of_death'], $data['biography'],
                $data['wake_keeping_date'], $data['burial_date'], $data['thanksgiving_date'],
                $data['venue'], $data['gps_address'], $locLat, $locLng,
                $data['organizer_name'],
                $data['organizer_phone'], $data['organizer_email'], $googleMapsLink, $data['status'], $data['featured'], $slug
            ]);
            $id = (int)$pdo->lastInsertId();
            log_audit_action($user['id'], 'funeral_create', "Created funeral #{$id}: {$data['deceased_name']}");
        }
        header('Location: funeral_edit.php?id=' . $id . '&saved=1'); exit;
    }

    $fa = array_merge($fa ?? [], $data);
}

$isNew = !$id;
$v = fn($k) => sanitize($fa[$k] ?? '');
$dt = fn($k,$fmt='Y-m-d') => !empty($fa[$k]) ? date($fmt, strtotime($fa[$k])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isNew ? 'New Funeral Announcement' : 'Edit Funeral'; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .afe-shell { max-width:740px; margin:0 auto; padding:20px 16px 60px; }
        .afe-field  { margin-bottom:16px; }
        .afe-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .afe-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .afe-field input, .afe-field select, .afe-field textarea { width:100%; box-sizing:border-box; }
        .afe-field textarea { resize:vertical; min-height:80px; }
        .afe-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .afe-row { grid-template-columns:1fr; } }
        .afe-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:20px 0 12px; border-top:1px solid var(--border); padding-top:16px; }
        .afe-img-preview { max-width:180px; border-radius:8px; margin-top:6px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="funerals.php" class="button button-secondary button-small">← Funerals</a>
        <h1><?php echo $isNew ? 'New Announcement' : 'Edit Announcement'; ?></h1>
        <?php if (!$isNew && $fa['slug']): ?>
        <a href="../funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" target="_blank" class="button button-small">Preview ↗</a>
        <?php endif; ?>
    </header>

    <div class="afe-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:16px;">Saved successfully.</div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="afe-field">
                <label>Deceased Name *</label>
                <input type="text" name="deceased_name" class="form-control" required value="<?php echo $v('deceased_name'); ?>">
            </div>
            <div class="afe-row">
                <div class="afe-field">
                    <label>Gender</label>
                    <select name="gender" class="form-control">
                        <option value="male"   <?php echo ($fa['gender']??'male')==='male'   ? 'selected':'' ?>>Male</option>
                        <option value="female" <?php echo ($fa['gender']??'')==='female' ? 'selected':'' ?>>Female</option>
                        <option value="other"  <?php echo ($fa['gender']??'')==='other'  ? 'selected':'' ?>>Other</option>
                    </select>
                </div>
                <div class="afe-field">
                    <label>Age</label>
                    <input type="number" name="age" class="form-control" min="0" max="150" value="<?php echo (int)($fa['age'] ?? 0) ?: ''; ?>">
                </div>
            </div>
            <div class="afe-row">
                <div class="afe-field">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo $dt('date_of_birth'); ?>">
                </div>
                <div class="afe-field">
                    <label>Date of Death</label>
                    <input type="date" name="date_of_death" class="form-control" value="<?php echo $dt('date_of_death'); ?>">
                </div>
            </div>
            <div class="afe-field">
                <label>Photograph</label>
                <div class="desc">JPEG/PNG/WebP · Max 5 MB</div>
                <input type="file" name="photograph" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($fa['photograph'])): ?><img src="../<?php echo sanitize($fa['photograph']); ?>" class="afe-img-preview" alt=""><?php endif; ?>
            </div>
            <div class="afe-field">
                <label>Funeral Poster / Programme</label>
                <div class="desc">Flyer or programme image · Max 5 MB</div>
                <input type="file" name="funeral_poster" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($fa['funeral_poster'])): ?><img src="../<?php echo sanitize($fa['funeral_poster']); ?>" class="afe-img-preview" alt=""><?php endif; ?>
            </div>
            <div class="afe-field">
                <label>Biography</label>
                <textarea name="biography" class="form-control rich-editor" rows="6" placeholder="Brief biography or tribute…"><?php echo $v('biography'); ?></textarea>
            </div>

            <p class="afe-section">Funeral Schedule</p>
            <div class="afe-row">
                <div class="afe-field"><label>Wake Keeping</label><input type="datetime-local" name="wake_keeping_date" class="form-control" value="<?php echo $dt('wake_keeping_date','Y-m-d\TH:i'); ?>"></div>
                <div class="afe-field"><label>Burial</label><input type="datetime-local" name="burial_date" class="form-control" value="<?php echo $dt('burial_date','Y-m-d\TH:i'); ?>"></div>
            </div>
            <div class="afe-field"><label>Thanksgiving Service</label><input type="datetime-local" name="thanksgiving_date" class="form-control" value="<?php echo $dt('thanksgiving_date','Y-m-d\TH:i'); ?>"></div>
            <div class="afe-field">
                <label>Venue / Location Name</label>
                <input type="text" name="venue" class="form-control" value="<?php echo $v('venue'); ?>" placeholder="e.g. St. Peter's Church, Akropong">
            </div>
            <div class="afe-field">
                <label>GPS / Address</label>
                <input type="text" name="gps_address" class="form-control" value="<?php echo $v('gps_address'); ?>" placeholder="e.g. Akuapem-Mampong, Eastern Region">
            </div>
            <div class="afe-field">
                <label>Google Maps Link <span style="font-weight:400;color:var(--text-muted)">(optional — paste a Google Maps share link)</span></label>
                <input type="url" name="google_maps_link" class="form-control" value="<?php echo $v('google_maps_link'); ?>" placeholder="https://maps.google.com/…">
                <p style="font-size:.75rem;color:var(--text-muted);margin:4px 0 0;">Tip: open Google Maps, find the location, click Share → Copy link, then paste here.</p>
            </div>

            <p class="afe-section">Organizer</p>
            <div class="afe-field"><label>Organizer Name</label><input type="text" name="organizer_name" class="form-control" value="<?php echo $v('organizer_name'); ?>"></div>
            <div class="afe-row">
                <div class="afe-field"><label>Phone</label><input type="tel" name="organizer_phone" class="form-control" value="<?php echo $v('organizer_phone'); ?>"></div>
                <div class="afe-field"><label>Email</label><input type="email" name="organizer_email" class="form-control" value="<?php echo $v('organizer_email'); ?>"></div>
            </div>

            <p class="afe-section">Publication</p>
            <div class="afe-row">
                <div class="afe-field">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="pending_payment" <?php echo ($fa['status']??'')==='pending_payment' ? 'selected':'' ?>>Awaiting Payment</option>
                        <option value="pending"         <?php echo ($fa['status']??'pending')==='pending'  ? 'selected':'' ?>>Under Review</option>
                        <option value="approved"        <?php echo ($fa['status']??'')==='approved'        ? 'selected':'' ?>>Approved / Published</option>
                        <option value="rejected"        <?php echo ($fa['status']??'')==='rejected'        ? 'selected':'' ?>>Rejected</option>
                    </select>
                </div>
                <div class="afe-field" style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                    <input type="checkbox" name="featured" value="1" id="afe-feat" <?php echo !empty($fa['featured']) ? 'checked' : ''; ?>>
                    <label for="afe-feat" style="cursor:pointer;">⭐ Featured announcement</label>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:10px;">
                <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create' : 'Save Changes'; ?></button>
                <a href="funerals.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>

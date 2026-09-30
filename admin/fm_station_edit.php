<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../fm_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

// A "manage_fm_programmes" manager may only ever touch the schedule of an
// EXISTING station, never create/edit/delete the station record itself —
// mirrors the manage_fm_stations/manage_fm_programmes split in fm_stations.php.
$id = (int)($_GET['id'] ?? 0);
if (!is_admin() && !has_mod_permission('manage_fm_stations') && !has_mod_permission('manage_fm_programmes')) {
    require_mod_permission('manage_fm_stations');
}
if (!$id) {
    require_mod_permission('manage_fm_stations'); // creating a new station needs full access
}
$canEditStation      = is_admin() || has_mod_permission('manage_fm_stations');
$canManageProgrammes = $canEditStation || has_mod_permission('manage_fm_programmes');
$user = current_user();

$fs = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM fm_stations WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $fs = $stmt->fetch();
    if (!$fs) { header('Location: fm_stations.php'); exit; }
}

$errors = [];
$warning = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['save_station'])) {
        require_mod_permission('manage_fm_stations');
        $fields = ['name','frequency','description','stream_url','website_url','phone','whatsapp','facebook_url','youtube_url'];
        $data = [];
        foreach ($fields as $f) {
            $v = trim($_POST[$f] ?? '');
            $data[$f] = $v === '' ? null : $v;
        }
        $townId    = (int)($_POST['town_id'] ?? 0) ?: null;
        $streamType= in_array($_POST['stream_type'] ?? '', array_keys(fm_stream_type_labels()), true) ? $_POST['stream_type'] : 'mp3';
        $liveMode  = in_array($_POST['live_mode'] ?? '', ['auto','live','offline'], true) ? $_POST['live_mode'] : 'auto';
        $status    = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';
        $featured  = isset($_POST['featured']) ? 1 : 0;
        $dispOrder = (int)($_POST['display_order'] ?? 0);
        $themeColorRaw = trim($_POST['theme_color'] ?? '');
        $themeColor    = preg_match('/^#[0-9a-f]{6}$/i', $themeColorRaw) ? $themeColorRaw : null;

        if (!$data['name']) $errors[] = 'Station name is required.';
        if ($data['stream_url'] && !fm_is_valid_stream_url($data['stream_url'])) {
            $errors[] = 'Stream URL must start with http:// or https://.';
        }

        $logoPath  = $fs['logo_path']  ?? null;
        $coverPath = $fs['cover_path'] ?? null;

        if (!empty($_FILES['logo']['name'])) {
            $p = save_uploaded_image($_FILES['logo'], 'uploads/fm_stations', 500, 85);
            if ($p) $logoPath = $p; else $errors[] = 'Logo upload failed.';
        }
        if (!empty($_FILES['cover']['name'])) {
            $p = save_uploaded_image($_FILES['cover'], 'uploads/fm_stations', 1200, 85);
            if ($p) $coverPath = $p; else $errors[] = 'Cover image upload failed.';
        }

        if (!$errors) {
            $slug = fm_unique_slug($pdo, $data['name'], $id);
            if ($id) {
                $pdo->prepare(
                    "UPDATE fm_stations SET name=?, slug=?, frequency=?, town_id=?, description=?, logo_path=?, cover_path=?,
                     stream_url=?, stream_type=?, live_mode=?, website_url=?, phone=?, whatsapp=?, facebook_url=?, youtube_url=?,
                     status=?, featured=?, theme_color=?, display_order=?, updated_at=NOW() WHERE id=?"
                )->execute([
                    $data['name'], $slug, $data['frequency'], $townId, $data['description'], $logoPath, $coverPath,
                    $data['stream_url'], $streamType, $liveMode, $data['website_url'], $data['phone'], $data['whatsapp'],
                    $data['facebook_url'], $data['youtube_url'], $status, $featured, $themeColor, $dispOrder, $id
                ]);
                log_audit_action($user['id'], 'fm_station_edit', "Edited FM station #{$id}: {$data['name']}");
            } else {
                $pdo->prepare(
                    "INSERT INTO fm_stations
                     (name, slug, frequency, town_id, description, logo_path, cover_path, stream_url, stream_type, live_mode,
                      website_url, phone, whatsapp, facebook_url, youtube_url, status, featured, theme_color, display_order, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $data['name'], $slug, $data['frequency'], $townId, $data['description'], $logoPath, $coverPath,
                    $data['stream_url'], $streamType, $liveMode, $data['website_url'], $data['phone'], $data['whatsapp'],
                    $data['facebook_url'], $data['youtube_url'], $status, $featured, $themeColor, $dispOrder, $user['id']
                ]);
                $id = (int)$pdo->lastInsertId();
                log_audit_action($user['id'], 'fm_station_create', "Created FM station #{$id}: {$data['name']}");
            }
            header('Location: fm_station_edit.php?id=' . $id . '&saved=1'); exit;
        }
        $fs = array_merge($fs ?? [], $data, ['town_id' => $townId, 'stream_type' => $streamType, 'live_mode' => $liveMode, 'status' => $status, 'featured' => $featured, 'theme_color' => $themeColor, 'display_order' => $dispOrder, 'logo_path' => $logoPath, 'cover_path' => $coverPath]);

    } elseif (isset($_POST['prog_action']) && $id) {
        if (!$canManageProgrammes) {
            flash('You do not have permission to manage programmes.', 'error');
            header('Location: fm_stations.php'); exit;
        }
        $pa = $_POST['prog_action'];
        if ($pa === 'add_programme' || $pa === 'edit_programme') {
            $pname = trim($_POST['p_name'] ?? '');
            $day   = max(0, min(6, (int)($_POST['p_day'] ?? 0)));
            $start = trim($_POST['p_start'] ?? '');
            $end   = trim($_POST['p_end'] ?? '');
            $host  = trim($_POST['p_host'] ?? '') ?: null;
            $pdesc = trim($_POST['p_description'] ?? '') ?: null;
            $pid   = (int)($_POST['p_id'] ?? 0);
            $presenterId = (int)($_POST['p_presenter_id'] ?? 0) ?: null;
            if ($presenterId) {
                $pcheck = $pdo->prepare("SELECT id FROM fm_presenters WHERE id=? AND station_id=?");
                $pcheck->execute([$presenterId, $id]);
                if (!$pcheck->fetch()) $presenterId = null; // ignore a presenter that doesn't belong to this station
            }

            if (!$pname) $errors[] = 'Programme name is required.';
            if (!$start || !$end) $errors[] = 'Start and end time are required.';

            $imagePath = null;
            if ($pid) {
                $existing = $pdo->prepare("SELECT image_path FROM fm_programmes WHERE id=? AND station_id=?");
                $existing->execute([$pid, $id]);
                $imagePath = $existing->fetchColumn() ?: null;
            }
            if (!empty($_FILES['p_image']['name'])) {
                $p = save_uploaded_image($_FILES['p_image'], 'uploads/fm_programmes', 600, 85);
                if ($p) $imagePath = $p; else $errors[] = 'Programme image upload failed.';
            }

            if (!$errors) {
                $warning = fm_check_schedule_overlap($id, $day, $start, $end, $pid);
                $pslug = fm_programme_slug_for($pdo, $id, $pname, $pid);
                if ($pid) {
                    $pdo->prepare(
                        "UPDATE fm_programmes SET name=?, description=?, host=?, presenter_id=?, slug=?, image_path=?, day_of_week=?, start_time=?, end_time=?, updated_at=NOW()
                         WHERE id=? AND station_id=?"
                    )->execute([$pname, $pdesc, $host, $presenterId, $pslug, $imagePath, $day, $start, $end, $pid, $id]);
                    log_audit_action($user['id'], 'fm_programme_edit', "Edited programme #{$pid} on station #{$id}");
                } else {
                    $pdo->prepare(
                        "INSERT INTO fm_programmes (station_id, name, description, host, presenter_id, slug, image_path, day_of_week, start_time, end_time)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$id, $pname, $pdesc, $host, $presenterId, $pslug, $imagePath, $day, $start, $end]);
                    log_audit_action($user['id'], 'fm_programme_create', "Added programme to station #{$id}: {$pname}");
                }
                if ($warning) flash($warning, 'warning');
                header('Location: fm_station_edit.php?id=' . $id . '&saved=1#schedule'); exit;
            }
        } elseif ($pa === 'toggle_programme_status' && !empty($_POST['p_id'])) {
            $pdo->prepare("UPDATE fm_programmes SET status=IF(status='active','inactive','active') WHERE id=? AND station_id=?")
                ->execute([(int)$_POST['p_id'], $id]);
            header('Location: fm_station_edit.php?id=' . $id . '#schedule'); exit;
        } elseif ($pa === 'delete_programme' && !empty($_POST['p_id'])) {
            $pdo->prepare("DELETE FROM fm_programmes WHERE id=? AND station_id=?")->execute([(int)$_POST['p_id'], $id]);
            log_audit_action($user['id'], 'fm_programme_delete', "Deleted programme #{$_POST['p_id']} from station #{$id}");
            header('Location: fm_station_edit.php?id=' . $id . '#schedule'); exit;
        } elseif ($pa === 'generate_sample_schedule') {
            // Quick-start tool: fills each EMPTY day with 10 evenly-spaced
            // placeholder slots (24h / 10 = 144 min each) so an admin setting
            // up a new station has a full week to rename/edit instead of
            // adding 70 rows by hand. Days that already have programmes are
            // left untouched — this never overwrites real schedule data.
            $slotsPerDay = 10;
            $slotMinutes = (int)(24 * 60 / $slotsPerDay);
            $inserted = 0;
            $skippedDays = [];
            for ($day = 0; $day <= 6; $day++) {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fm_programmes WHERE station_id=? AND day_of_week=?");
                $countStmt->execute([$id, $day]);
                if ((int)$countStmt->fetchColumn() > 0) {
                    $skippedDays[] = get_weekday_names()[$day];
                    continue;
                }
                $insStmt = $pdo->prepare("INSERT INTO fm_programmes (station_id, name, day_of_week, start_time, end_time, display_order) VALUES (?,?,?,?,?,?)");
                for ($slot = 0; $slot < $slotsPerDay; $slot++) {
                    $startMin = $slot * $slotMinutes;
                    $endMin   = $startMin + $slotMinutes;
                    $start = sprintf('%02d:%02d:00', intdiv($startMin, 60), $startMin % 60);
                    $end   = $endMin >= 1440 ? '00:00:00' : sprintf('%02d:%02d:00', intdiv($endMin, 60), $endMin % 60);
                    $insStmt->execute([$id, 'Programme ' . ($slot + 1), $day, $start, $end, $slot]);
                    $inserted++;
                }
            }
            log_audit_action($user['id'], 'fm_programme_seed', "Generated sample schedule for station #{$id}: {$inserted} programmes added");
            if ($inserted > 0) {
                $msg = "Sample schedule generated: {$inserted} placeholder programmes added.";
                if ($skippedDays) $msg .= ' Left unchanged (already had programmes): ' . implode(', ', $skippedDays) . '.';
                flash($msg, 'success');
            } else {
                flash('Every day already has programmes — nothing was added. Delete a day\'s programmes first if you want to regenerate it.', 'info');
            }
            header('Location: fm_station_edit.php?id=' . $id . '#schedule'); exit;
        }

    } elseif (isset($_POST['presenter_action']) && $id) {
        // Presenters are managed alongside programmes (same manage_fm_programmes
        // permission) since scheduling a new show often means adding its host.
        if (!$canManageProgrammes) {
            flash('You do not have permission to manage presenters.', 'error');
            header('Location: fm_stations.php'); exit;
        }
        $pa = $_POST['presenter_action'];
        if ($pa === 'add_presenter' || $pa === 'edit_presenter') {
            $prName = trim($_POST['pr_name'] ?? '');
            $prBio  = trim($_POST['pr_bio'] ?? '') ?: null;
            $prFb   = trim($_POST['pr_facebook_url'] ?? '') ?: null;
            $prId   = (int)($_POST['pr_id'] ?? 0);

            if (!$prName) $errors[] = 'Presenter name is required.';

            $photoPath = null;
            if ($prId) {
                $existing = $pdo->prepare("SELECT photo_path FROM fm_presenters WHERE id=? AND station_id=?");
                $existing->execute([$prId, $id]);
                $photoPath = $existing->fetchColumn() ?: null;
            }
            if (!empty($_FILES['pr_photo']['name'])) {
                $p = save_uploaded_image($_FILES['pr_photo'], 'uploads/fm_presenters', 500, 85);
                if ($p) $photoPath = $p; else $errors[] = 'Presenter photo upload failed.';
            }

            if (!$errors) {
                $prSlug = fm_presenter_unique_slug($pdo, $id, $prName, $prId);
                if ($prId) {
                    $pdo->prepare(
                        "UPDATE fm_presenters SET name=?, slug=?, bio=?, facebook_url=?, photo_path=?, updated_at=NOW() WHERE id=? AND station_id=?"
                    )->execute([$prName, $prSlug, $prBio, $prFb, $photoPath, $prId, $id]);
                    log_audit_action($user['id'], 'fm_presenter_edit', "Edited presenter #{$prId} on station #{$id}");
                } else {
                    $pdo->prepare(
                        "INSERT INTO fm_presenters (station_id, name, slug, bio, facebook_url, photo_path) VALUES (?,?,?,?,?,?)"
                    )->execute([$id, $prName, $prSlug, $prBio, $prFb, $photoPath]);
                    log_audit_action($user['id'], 'fm_presenter_create', "Added presenter to station #{$id}: {$prName}");
                }
                header('Location: fm_station_edit.php?id=' . $id . '&saved=1#presenters'); exit;
            }
        } elseif ($pa === 'toggle_presenter_status' && !empty($_POST['pr_id'])) {
            $pdo->prepare("UPDATE fm_presenters SET status=IF(status='active','inactive','active') WHERE id=? AND station_id=?")
                ->execute([(int)$_POST['pr_id'], $id]);
            header('Location: fm_station_edit.php?id=' . $id . '#presenters'); exit;
        } elseif ($pa === 'delete_presenter' && !empty($_POST['pr_id'])) {
            $pdo->prepare("DELETE FROM fm_presenters WHERE id=? AND station_id=?")->execute([(int)$_POST['pr_id'], $id]);
            log_audit_action($user['id'], 'fm_presenter_delete', "Deleted presenter #{$_POST['pr_id']} from station #{$id}");
            header('Location: fm_station_edit.php?id=' . $id . '#presenters'); exit;
        }

    } elseif (isset($_POST['announce_action']) && $id) {
        // Announcements read as station-facing content (like editing the
        // station's own info), so this stays under manage_fm_stations rather
        // than the narrower manage_fm_programmes permission.
        require_mod_permission('manage_fm_stations');
        $aa = $_POST['announce_action'];
        if ($aa === 'add_announcement') {
            $msg   = trim($_POST['a_message'] ?? '');
            $start = trim($_POST['a_starts_at'] ?? '') ?: null;
            $end   = trim($_POST['a_ends_at'] ?? '') ?: null;
            if (!$msg) {
                $errors[] = 'Announcement message is required.';
            } else {
                $pdo->prepare(
                    "INSERT INTO fm_station_announcements (station_id, message, starts_at, ends_at, created_by) VALUES (?,?,?,?,?)"
                )->execute([$id, $msg, $start, $end, $user['id']]);
                log_audit_action($user['id'], 'fm_announcement_create', "Added announcement to station #{$id}");
                header('Location: fm_station_edit.php?id=' . $id . '&saved=1#announcements'); exit;
            }
        } elseif ($aa === 'toggle_announcement_status' && !empty($_POST['a_id'])) {
            $pdo->prepare("UPDATE fm_station_announcements SET status=IF(status='active','inactive','active') WHERE id=? AND station_id=?")
                ->execute([(int)$_POST['a_id'], $id]);
            header('Location: fm_station_edit.php?id=' . $id . '#announcements'); exit;
        } elseif ($aa === 'delete_announcement' && !empty($_POST['a_id'])) {
            $pdo->prepare("DELETE FROM fm_station_announcements WHERE id=? AND station_id=?")->execute([(int)$_POST['a_id'], $id]);
            log_audit_action($user['id'], 'fm_announcement_delete', "Deleted announcement #{$_POST['a_id']} from station #{$id}");
            header('Location: fm_station_edit.php?id=' . $id . '#announcements'); exit;
        }
    }
}

$isNew = !$id;
$v    = fn($k) => sanitize($fs[$k] ?? '');
$vRaw = fn($k) => $fs[$k] ?? ''; // rich-editor field — sanitize() would double-escape stored HTML

$weekdayNames = get_weekday_names();
$weeklySchedule = $id ? fm_get_weekly_schedule($id) : array_fill(0, 7, []);
$hasEmptyDay    = (bool)array_filter($weeklySchedule, fn($d) => empty($d));
$flashMsg = get_flash();
$editProgramme = null;
if ($id && !empty($_GET['edit_programme'])) {
    $stmt = $pdo->prepare("SELECT * FROM fm_programmes WHERE id=? AND station_id=?");
    $stmt->execute([(int)$_GET['edit_programme'], $id]);
    $editProgramme = $stmt->fetch();
}

$presenters = [];
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM fm_presenters WHERE station_id=? ORDER BY display_order ASC, name ASC");
    $stmt->execute([$id]);
    $presenters = $stmt->fetchAll();
}
$editPresenter = null;
if ($id && !empty($_GET['edit_presenter'])) {
    $stmt = $pdo->prepare("SELECT * FROM fm_presenters WHERE id=? AND station_id=?");
    $stmt->execute([(int)$_GET['edit_presenter'], $id]);
    $editPresenter = $stmt->fetch();
}

$announcements = [];
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM fm_station_announcements WHERE station_id=? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $announcements = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isNew ? 'New FM Station' : 'Edit FM Station'; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .fme-shell   { max-width:820px; margin:0 auto; padding:20px 16px 60px; }
        .fme-field   { margin-bottom:16px; }
        .fme-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .fme-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .fme-field input, .fme-field select, .fme-field textarea { width:100%; box-sizing:border-box; }
        .fme-field textarea { resize:vertical; min-height:80px; }
        .fme-row     { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .fme-row { grid-template-columns:1fr; } }
        .fme-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:20px 0 12px; border-top:1px solid var(--border); padding-top:16px; }
        .fme-img-preview { max-width:180px; border-radius:8px; margin-top:6px; display:block; }
        .fme-day-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .fme-day-tab  { padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface,#fff); font-size:.78rem; font-weight:700; cursor:pointer; }
        .fme-day-tab.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .fme-prog-panel { display:none; }
        .fme-prog-panel.active { display:block; }
        .fme-prog-card { display:flex; gap:10px; align-items:center; background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 12px; margin-bottom:8px; }
        .fme-prog-card img { width:44px; height:44px; border-radius:8px; object-fit:cover; }
        .fme-prog-info { flex:1; min-width:0; }
        .fme-prog-time { font-size:.72rem; color:var(--muted,#6b7280); font-weight:700; }
        .fme-prog-name { font-weight:700; font-size:.86rem; }
        .fme-prog-host { font-size:.76rem; color:var(--muted,#6b7280); }
        .fme-prog-actions { display:flex; gap:4px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="fm_stations.php" class="button button-secondary button-small">← FM Stations</a>
        <h1><?php echo $isNew ? 'New Station' : ($canEditStation ? 'Edit Station' : 'Manage Programmes'); ?></h1>
        <?php if (!$isNew && $fs['slug']): ?>
        <a href="../fm_station.php?slug=<?php echo urlencode($fs['slug']); ?>" target="_blank" class="button button-small">Preview ↗</a>
        <?php endif; ?>
    </header>

    <div class="fme-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:16px;">Saved successfully.</div><?php endif; ?>
        <?php if ($flashMsg): ?><div class="alert alert-<?php echo sanitize($flashMsg['type']); ?>" style="margin-bottom:16px;"><?php echo sanitize($flashMsg['message']); ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

        <?php if (!$canEditStation): ?>
        <!-- Programmes-only manager: station details are read-only, only the schedule below is editable -->
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
            <?php if (!empty($fs['logo_path'])): ?><img src="../<?php echo sanitize($fs['logo_path']); ?>" alt="" style="width:56px;height:56px;border-radius:12px;object-fit:cover;"><?php endif; ?>
            <div>
                <strong style="font-size:1.05rem;"><?php echo sanitize($fs['name']); ?></strong>
                <?php if ($fs['frequency']): ?><span class="meta"> · <?php echo sanitize($fs['frequency']); ?></span><?php endif; ?>
                <p class="meta" style="margin:2px 0 0;">You can manage this station's programme schedule below. Editing station details requires the "FM Stations" permission.</p>
            </div>
        </div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="save_station" value="1">

            <div class="fme-field">
                <label>Station Name *</label>
                <input type="text" name="name" class="form-control" required value="<?php echo $v('name'); ?>">
            </div>
            <div class="fme-row">
                <div class="fme-field">
                    <label>Frequency</label>
                    <input type="text" name="frequency" class="form-control" value="<?php echo $v('frequency'); ?>" placeholder="e.g. 101.7 FM">
                </div>
                <div class="fme-field">
                    <label>Town</label>
                    <?php $selTownId = (int)($fs['town_id'] ?? 0); ?>
                    <select name="town_id" class="form-control">
                        <option value="">— Select town —</option>
                        <?php foreach (get_towns_grouped_by_district() as $district => $ts): ?>
                        <optgroup label="<?php echo sanitize($district); ?>">
                            <?php foreach ($ts as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $selTownId===(int)$t['id']?'selected':''; ?>><?php echo sanitize($t['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="fme-field">
                <label>Description</label>
                <textarea name="description" class="form-control rich-editor" rows="5" placeholder="About this station…"><?php echo $vRaw('description'); ?></textarea>
            </div>
            <div class="fme-row">
                <div class="fme-field">
                    <label>Logo</label>
                    <div class="desc">Square image works best · Max 5 MB</div>
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($fs['logo_path'])): ?><img src="../<?php echo sanitize($fs['logo_path']); ?>" class="fme-img-preview" alt=""><?php endif; ?>
                </div>
                <div class="fme-field">
                    <label>Cover Image</label>
                    <div class="desc">Wide banner image · Max 5 MB</div>
                    <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($fs['cover_path'])): ?><img src="../<?php echo sanitize($fs['cover_path']); ?>" class="fme-img-preview" alt=""><?php endif; ?>
                </div>
            </div>

            <p class="fme-section">Live Stream</p>
            <div class="fme-field">
                <label>Stream URL</label>
                <input type="url" name="stream_url" class="form-control" value="<?php echo $v('stream_url'); ?>" placeholder="https://stream.example.com/station.mp3">
                <p style="font-size:.75rem;color:var(--text-muted);margin:4px 0 0;">Direct stream URL from the station's broadcast provider (Icecast/Shoutcast/CDN). Must start with http:// or https://.</p>
            </div>
            <div class="fme-row">
                <div class="fme-field">
                    <label>Stream Type</label>
                    <select name="stream_type" class="form-control">
                        <?php foreach (fm_stream_type_labels() as $k => $l): ?>
                        <option value="<?php echo $k; ?>" <?php echo ($fs['stream_type'] ?? 'mp3')===$k ? 'selected':''; ?>><?php echo sanitize($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fme-field">
                    <label>Live Status</label>
                    <select name="live_mode" class="form-control">
                        <option value="auto"    <?php echo ($fs['live_mode'] ?? 'auto')==='auto'    ? 'selected':''; ?>>Auto-detect from stream</option>
                        <option value="live"    <?php echo ($fs['live_mode'] ?? '')==='live'    ? 'selected':''; ?>>Force Live</option>
                        <option value="offline" <?php echo ($fs['live_mode'] ?? '')==='offline' ? 'selected':''; ?>>Force Offline</option>
                    </select>
                    <p style="font-size:.75rem;color:var(--text-muted);margin:4px 0 0;">"Station is active" (below) just controls whether the page is visible — it does not mean the station is broadcasting right now.</p>
                </div>
            </div>

            <p class="fme-section">Contact &amp; Social</p>
            <div class="fme-row">
                <div class="fme-field"><label>Phone</label><input type="tel" name="phone" class="form-control" value="<?php echo $v('phone'); ?>"></div>
                <div class="fme-field"><label>WhatsApp</label><input type="tel" name="whatsapp" class="form-control" value="<?php echo $v('whatsapp'); ?>"></div>
            </div>
            <div class="fme-field"><label>Website</label><input type="url" name="website_url" class="form-control" value="<?php echo $v('website_url'); ?>" placeholder="https://…"></div>
            <div class="fme-row">
                <div class="fme-field"><label>Facebook</label><input type="url" name="facebook_url" class="form-control" value="<?php echo $v('facebook_url'); ?>" placeholder="https://facebook.com/…"></div>
                <div class="fme-field"><label>YouTube</label><input type="url" name="youtube_url" class="form-control" value="<?php echo $v('youtube_url'); ?>" placeholder="https://youtube.com/…"></div>
            </div>

            <p class="fme-section">Branding</p>
            <div class="fme-field">
                <label>Station Colour Theme</label>
                <input type="color" name="theme_color" value="<?php echo sanitize($fs['theme_color'] ?? '#2f8f5b'); ?>" style="width:100%;max-width:200px;height:38px;padding:2px;cursor:pointer;">
                <p style="font-size:.75rem;color:var(--text-muted);margin:4px 0 0;">Tints this station's public page (hero, player, buttons). Leave as the default green if you don't want a custom look.</p>
            </div>

            <p class="fme-section">Publication</p>
            <div class="fme-row">
                <div class="fme-field">
                    <label>Station is active on AkuapemConnect</label>
                    <select name="status" class="form-control">
                        <option value="active"   <?php echo ($fs['status'] ?? 'active')==='active'   ? 'selected':''; ?>>Active — visible to visitors</option>
                        <option value="inactive" <?php echo ($fs['status'] ?? '')==='inactive' ? 'selected':''; ?>>Inactive — hidden</option>
                    </select>
                </div>
                <div class="fme-field" style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                    <input type="checkbox" name="featured" value="1" id="fme-feat" <?php echo !empty($fs['featured']) ? 'checked' : ''; ?>>
                    <label for="fme-feat" style="cursor:pointer;">⭐ Featured station</label>
                </div>
            </div>
            <div class="fme-field">
                <label>Display Order</label>
                <input type="number" name="display_order" class="form-control" value="<?php echo (int)($fs['display_order'] ?? 0); ?>" style="max-width:120px;">
                <p style="font-size:.75rem;color:var(--text-muted);margin:4px 0 0;">Lower numbers appear first. Featured stations always show before non-featured ones.</p>
            </div>

            <div style="display:flex;gap:10px;margin-top:10px;">
                <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create Station' : 'Save Changes'; ?></button>
                <a href="fm_stations.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
        <?php endif; // $canEditStation ?>

        <?php if (!$isNew && $canManageProgrammes): ?>
        <div id="schedule" class="fme-section" style="margin-top:32px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <span>Programme Schedule</span>
            <?php if ($hasEmptyDay): ?>
            <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" onsubmit="return confirm('Generate 10 placeholder programmes for each empty day? Days that already have programmes will be left untouched.');" style="text-transform:none;letter-spacing:normal;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="prog_action" value="generate_sample_schedule">
                <button type="submit" class="button button-small button-secondary">✨ Generate Sample Schedule</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="fme-day-tabs">
            <?php foreach ($weekdayNames as $dnum => $dname): ?>
            <button type="button" class="fme-day-tab<?php echo $dnum===(int)date('w') ? ' active' : ''; ?>" data-day="<?php echo $dnum; ?>" onclick="fmeShowDay(<?php echo $dnum; ?>)"><?php echo substr($dname,0,3); ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($weekdayNames as $dnum => $dname): ?>
        <div class="fme-prog-panel<?php echo $dnum===(int)date('w') ? ' active' : ''; ?>" id="fme-day-<?php echo $dnum; ?>">
            <?php if (empty($weeklySchedule[$dnum])): ?>
            <p style="color:var(--text-muted);font-size:.85rem;">No programmes scheduled for <?php echo $dname; ?> yet.</p>
            <?php else: ?>
            <?php foreach ($weeklySchedule[$dnum] as $p): ?>
            <div class="fme-prog-card" style="<?php echo $p['status']==='inactive' ? 'opacity:.5;' : ''; ?>">
                <?php if ($p['image_path']): ?><img src="../<?php echo sanitize($p['image_path']); ?>" alt=""><?php endif; ?>
                <div class="fme-prog-info">
                    <div class="fme-prog-time"><?php echo format_time_range($p['start_time'], $p['end_time']); ?></div>
                    <div class="fme-prog-name"><?php echo sanitize($p['name']); ?></div>
                    <?php if ($p['host']): ?><div class="fme-prog-host">with <?php echo sanitize($p['host']); ?></div><?php endif; ?>
                </div>
                <div class="fme-prog-actions">
                    <a href="fm_station_edit.php?id=<?php echo $id; ?>&edit_programme=<?php echo (int)$p['id']; ?>#programme-form" class="button button-small">Edit</a>
                    <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>"><input type="hidden" name="prog_action" value="toggle_programme_status"><input type="hidden" name="p_id" value="<?php echo (int)$p['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $p['status']==='active' ? 'Disable' : 'Enable'; ?></button></form>
                    <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" onsubmit="return confirm('Delete this programme?')"><input type="hidden" name="prog_action" value="delete_programme"><input type="hidden" name="p_id" value="<?php echo (int)$p['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div id="programme-form" style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:16px;margin-top:16px;">
            <h3 style="font-size:.9rem;font-weight:800;margin:0 0 12px;"><?php echo $editProgramme ? 'Edit Programme' : 'Add Programme'; ?></h3>
            <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="prog_action" value="<?php echo $editProgramme ? 'edit_programme' : 'add_programme'; ?>">
                <?php if ($editProgramme): ?><input type="hidden" name="p_id" value="<?php echo (int)$editProgramme['id']; ?>"><?php endif; ?>
                <div class="fme-row">
                    <div class="fme-field"><label>Programme Name *</label><input type="text" name="p_name" class="form-control" required value="<?php echo sanitize($editProgramme['name'] ?? ''); ?>"></div>
                    <div class="fme-field">
                        <label>Day</label>
                        <select name="p_day" class="form-control">
                            <?php foreach ($weekdayNames as $dnum => $dname): ?>
                            <option value="<?php echo $dnum; ?>" <?php echo (int)($editProgramme['day_of_week'] ?? -1)===$dnum ? 'selected':''; ?>><?php echo $dname; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="fme-row">
                    <div class="fme-field"><label>Start Time *</label><input type="time" name="p_start" class="form-control" required value="<?php echo $editProgramme ? substr($editProgramme['start_time'],0,5) : ''; ?>"></div>
                    <div class="fme-field"><label>End Time *</label><input type="time" name="p_end" class="form-control" required value="<?php echo $editProgramme ? substr($editProgramme['end_time'],0,5) : ''; ?>"></div>
                </div>
                <div class="fme-row">
                    <div class="fme-field">
                        <label>Linked Presenter Profile</label>
                        <select name="p_presenter_id" class="form-control">
                            <option value="">— None —</option>
                            <?php foreach ($presenters as $pr): ?>
                            <option value="<?php echo (int)$pr['id']; ?>" <?php echo (int)($editProgramme['presenter_id'] ?? 0)===(int)$pr['id'] ? 'selected':''; ?>><?php echo sanitize($pr['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="desc" style="margin-top:4px;">Optional — links to a presenter's profile page. Add presenters below first.</p>
                    </div>
                    <div class="fme-field">
                        <label>Host Name (if no profile)</label>
                        <input type="text" name="p_host" class="form-control" value="<?php echo sanitize($editProgramme['host'] ?? ''); ?>" placeholder="e.g. Kwame Mensah">
                    </div>
                </div>
                <div class="fme-field"><label>Description</label><textarea name="p_description" class="form-control rich-editor" rows="4"><?php echo $editProgramme['description'] ?? ''; ?></textarea></div>
                <div class="fme-field">
                    <label>Programme Image</label>
                    <input type="file" name="p_image" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($editProgramme['image_path'])): ?><img src="../<?php echo sanitize($editProgramme['image_path']); ?>" class="fme-img-preview" alt=""><?php endif; ?>
                </div>
                <p style="font-size:.75rem;color:var(--text-muted);margin:0 0 12px;">Overlapping time slots aren't blocked — you'll just get a warning to double-check.</p>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary"><?php echo $editProgramme ? 'Save Programme' : 'Add Programme'; ?></button>
                    <?php if ($editProgramme): ?><a href="fm_station_edit.php?id=<?php echo $id; ?>#schedule" class="button button-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Presenters -->
        <div id="presenters" class="fme-section" style="margin-top:32px;">Presenters</div>
        <?php if (!$presenters): ?>
        <p style="color:var(--text-muted);font-size:.85rem;">No presenter profiles yet — add one below, then link programmes to them.</p>
        <?php else: foreach ($presenters as $pr): ?>
        <div class="fme-prog-card" style="<?php echo $pr['status']==='inactive' ? 'opacity:.5;' : ''; ?>">
            <?php if ($pr['photo_path']): ?><img src="../<?php echo sanitize($pr['photo_path']); ?>" alt=""><?php endif; ?>
            <div class="fme-prog-info">
                <div class="fme-prog-name"><?php echo sanitize($pr['name']); ?></div>
                <?php if ($pr['bio']): ?><div class="fme-prog-host"><?php echo sanitize(mb_substr(trim(strip_tags($pr['bio'])), 0, 80)); ?></div><?php endif; ?>
            </div>
            <div class="fme-prog-actions">
                <a href="../fm_presenter.php?station=<?php echo urlencode($fs['slug']); ?>&slug=<?php echo urlencode($pr['slug']); ?>" target="_blank" class="button button-small">View ↗</a>
                <a href="fm_station_edit.php?id=<?php echo $id; ?>&edit_presenter=<?php echo (int)$pr['id']; ?>#presenter-form" class="button button-small">Edit</a>
                <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>"><input type="hidden" name="presenter_action" value="toggle_presenter_status"><input type="hidden" name="pr_id" value="<?php echo (int)$pr['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $pr['status']==='active' ? 'Disable' : 'Enable'; ?></button></form>
                <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" onsubmit="return confirm('Delete this presenter? Any programmes linking to them will just show as unlinked.');"><input type="hidden" name="presenter_action" value="delete_presenter"><input type="hidden" name="pr_id" value="<?php echo (int)$pr['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <div id="presenter-form" style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:16px;margin-top:16px;">
            <h3 style="font-size:.9rem;font-weight:800;margin:0 0 12px;"><?php echo $editPresenter ? 'Edit Presenter' : 'Add Presenter'; ?></h3>
            <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="presenter_action" value="<?php echo $editPresenter ? 'edit_presenter' : 'add_presenter'; ?>">
                <?php if ($editPresenter): ?><input type="hidden" name="pr_id" value="<?php echo (int)$editPresenter['id']; ?>"><?php endif; ?>
                <div class="fme-field"><label>Name *</label><input type="text" name="pr_name" class="form-control" required value="<?php echo sanitize($editPresenter['name'] ?? ''); ?>"></div>
                <div class="fme-field"><label>Bio</label><textarea name="pr_bio" class="form-control rich-editor" rows="4" placeholder="A short bio for their profile page…"><?php echo $editPresenter['bio'] ?? ''; ?></textarea></div>
                <div class="fme-field"><label>Facebook</label><input type="url" name="pr_facebook_url" class="form-control" value="<?php echo sanitize($editPresenter['facebook_url'] ?? ''); ?>" placeholder="https://facebook.com/…"></div>
                <div class="fme-field">
                    <label>Photo</label>
                    <input type="file" name="pr_photo" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($editPresenter['photo_path'])): ?><img src="../<?php echo sanitize($editPresenter['photo_path']); ?>" class="fme-img-preview" alt=""><?php endif; ?>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary"><?php echo $editPresenter ? 'Save Presenter' : 'Add Presenter'; ?></button>
                    <?php if ($editPresenter): ?><a href="fm_station_edit.php?id=<?php echo $id; ?>#presenters" class="button button-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($canEditStation): ?>
        <!-- Announcements -->
        <div id="announcements" class="fme-section" style="margin-top:32px;">Announcements</div>
        <?php if (!$announcements): ?>
        <p style="color:var(--text-muted);font-size:.85rem;">No announcements yet — post a short notice below (e.g. a frequency change or an off-air notice).</p>
        <?php else: foreach ($announcements as $an): ?>
        <div class="fme-prog-card" style="<?php echo $an['status']==='inactive' ? 'opacity:.5;' : ''; ?>">
            <div class="fme-prog-info">
                <div class="fme-prog-name"><?php echo sanitize(mb_substr(trim(strip_tags($an['message'])), 0, 140)); ?></div>
                <div class="fme-prog-host">
                    <?php if ($an['starts_at'] || $an['ends_at']): ?>
                    <?php echo $an['starts_at'] ? date('d M Y', strtotime($an['starts_at'])) : 'Now'; ?> – <?php echo $an['ends_at'] ? date('d M Y', strtotime($an['ends_at'])) : 'Until removed'; ?>
                    <?php else: ?>No expiry set<?php endif; ?>
                </div>
            </div>
            <div class="fme-prog-actions">
                <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>"><input type="hidden" name="announce_action" value="toggle_announcement_status"><input type="hidden" name="a_id" value="<?php echo (int)$an['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $an['status']==='active' ? 'Disable' : 'Enable'; ?></button></form>
                <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>" onsubmit="return confirm('Delete this announcement?');"><input type="hidden" name="announce_action" value="delete_announcement"><input type="hidden" name="a_id" value="<?php echo (int)$an['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:16px;margin-top:16px;">
            <h3 style="font-size:.9rem;font-weight:800;margin:0 0 12px;">Post Announcement</h3>
            <form method="post" action="fm_station_edit.php?id=<?php echo $id; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="announce_action" value="add_announcement">
                <div class="fme-field"><label>Message *</label><textarea name="a_message" class="form-control rich-editor" rows="3" required placeholder="e.g. We'll be off air for maintenance this Sunday from 2–4pm."></textarea></div>
                <div class="fme-row">
                    <div class="fme-field"><label>Starts (optional)</label><input type="date" name="a_starts_at" class="form-control"></div>
                    <div class="fme-field"><label>Ends (optional)</label><input type="date" name="a_ends_at" class="form-control"></div>
                </div>
                <button type="submit" class="button button-primary">Post Announcement</button>
            </form>
        </div>
        <?php endif; // $canEditStation ?>
        <?php endif; ?>
    </div>
    <script src="../assets/js/rich-editor.js" defer></script>
    <script>
        function fmeShowDay(day) {
            document.querySelectorAll('.fme-prog-panel').forEach(function (el) { el.classList.remove('active'); });
            document.querySelectorAll('.fme-day-tab').forEach(function (el) { el.classList.remove('active'); });
            document.getElementById('fme-day-' + day).classList.add('active');
            document.querySelector('.fme-day-tab[data-day="' + day + '"]').classList.add('active');
        }
        <?php if ($editProgramme): ?>
        fmeShowDay(<?php echo (int)$editProgramme['day_of_week']; ?>);
        <?php endif; ?>
    </script>
</body>
</html>

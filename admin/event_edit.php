<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
$ev = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $ev = $stmt->fetch();
    if (!$ev) { header('Location: events.php'); exit; }
}

$errors = [];

function ev_unique_slug($pdo, $base, $excludeId = 0) {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $slug = trim($slug, '-');
    $test = $slug; $i = 2;
    while ($pdo->query("SELECT id FROM events WHERE slug='" . addslashes($test) . "' AND id!=$excludeId LIMIT 1")->fetch()) {
        $test = $slug . '-' . $i++;
    }
    return $test;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']      ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $venue      = trim($_POST['venue']       ?? '');
    $gps        = trim($_POST['gps_address'] ?? '');
    $googleMapsLink = trim($_POST['google_maps_link'] ?? '') ?: null;
    $startDate  = trim($_POST['start_date']  ?? '');
    $endDate    = trim($_POST['end_date']    ?? '') ?: null;
    $startTime  = trim($_POST['start_time']  ?? '') ?: null;
    $endTime    = trim($_POST['end_time']    ?? '') ?: null;
    $organizer  = trim($_POST['organizer_name'] ?? '');
    $ticketType = in_array($_POST['ticket_type'] ?? '', ['free','paid','registration']) ? $_POST['ticket_type'] : 'free';
    $ticketPrice = max(0, (float)($_POST['ticket_price'] ?? 0));
    $regLink    = trim($_POST['registration_link'] ?? '') ?: null;
    $status     = in_array($_POST['status'] ?? '', ['draft','published','cancelled']) ? $_POST['status'] : 'draft';
    $featured   = isset($_POST['featured']) ? 1 : 0;

    if (!$title)     $errors[] = 'Title is required.';
    if (!$startDate) $errors[] = 'Start date is required.';
    if ($regLink && !filter_var($regLink, FILTER_VALIDATE_URL)) $errors[] = 'Invalid registration link URL.';

    $imgPath = $ev['featured_image'] ?? null;
    if (!empty($_FILES['featured_image']['name'])) {
        $p = save_uploaded_image($_FILES['featured_image'], 'uploads/events', 1200, 85);
        if ($p) $imgPath = $p; else $errors[] = 'Image upload failed. JPEG/PNG/WebP, max 5 MB.';
    }

    if (!$errors) {
        $slug = ev_unique_slug($pdo, $title . ' ' . date('Y', strtotime($startDate)), $id);
        if ($id) {
            $pdo->prepare(
                "UPDATE events SET title=?,slug=?,featured_image=?,description=?,venue=?,gps_address=?,
                 start_date=?,end_date=?,start_time=?,end_time=?,organizer_name=?,
                 ticket_type=?,ticket_price=?,registration_link=?,google_maps_link=?,status=?,featured=?,updated_at=NOW()
                 WHERE id=?"
            )->execute([$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,$startTime,$endTime,
                        $organizer,$ticketType,$ticketPrice,$regLink,$googleMapsLink,$status,$featured,$id]);
            log_audit_action($user['id'], 'event_edit', "Edited event #{$id}: {$title}");
        } else {
            $pdo->prepare(
                "INSERT INTO events (title,slug,featured_image,description,venue,gps_address,start_date,end_date,
                 start_time,end_time,organizer_name,ticket_type,ticket_price,registration_link,google_maps_link,status,featured)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,$startTime,$endTime,
                        $organizer,$ticketType,$ticketPrice,$regLink,$googleMapsLink,$status,$featured]);
            $id = (int)$pdo->lastInsertId();
            log_audit_action($user['id'], 'event_create', "Created event #{$id}: {$title}");
        }
        header('Location: event_edit.php?id=' . $id . '&saved=1'); exit;
    }

    $ev = array_merge($ev ?? [], compact('title','desc','venue','gps','startDate','endDate','startTime','endTime',
          'organizer','ticketType','ticketPrice','regLink','status','featured'));
}

$isNew = !$id;
$v  = fn($k) => sanitize($ev[$k] ?? '');
$dt = fn($k) => !empty($ev[$k]) ? $ev[$k] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isNew ? 'New Event' : 'Edit Event'; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ee-shell { max-width:740px; margin:0 auto; padding:20px 16px 60px; }
        .ee-field  { margin-bottom:16px; }
        .ee-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .ee-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .ee-field input, .ee-field select, .ee-field textarea { width:100%; box-sizing:border-box; }
        .ee-field textarea { resize:vertical; min-height:120px; }
        .ee-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .ee-row { grid-template-columns:1fr; } }
        .ee-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:20px 0 12px; border-top:1px solid var(--border); padding-top:16px; }
        .ee-img-preview { max-width:280px; border-radius:8px; margin-top:6px; }
        #ticket-price-row { display:none; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="events.php" class="button button-secondary button-small">← Events</a>
        <h1><?php echo $isNew ? 'New Event' : 'Edit Event'; ?></h1>
        <?php if (!$isNew && $ev['slug']): ?>
        <a href="../event.php?slug=<?php echo urlencode($ev['slug']); ?>" target="_blank" class="button button-small">Preview ↗</a>
        <?php endif; ?>
    </header>

    <div class="ee-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:16px;">Event saved.</div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="ee-field">
                <label>Event Title *</label>
                <input type="text" name="title" class="form-control" required value="<?php echo $v('title'); ?>" placeholder="e.g. Annual Community Festival 2025">
            </div>
            <div class="ee-field">
                <label>Featured Image</label>
                <div class="desc">Recommended 1200×630 px · JPEG/PNG/WebP · Max 5 MB</div>
                <input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($ev['featured_image'])): ?><img src="../<?php echo sanitize($ev['featured_image']); ?>" class="ee-img-preview" alt=""><?php endif; ?>
            </div>
            <div class="ee-field">
                <label>Description</label>
                <textarea name="description" class="form-control rich-editor" rows="6" placeholder="Describe the event…"><?php echo $v('description'); ?></textarea>
            </div>

            <p class="ee-section">Date &amp; Time</p>
            <div class="ee-row">
                <div class="ee-field"><label>Start Date *</label><input type="date" name="start_date" class="form-control" required value="<?php echo $dt('start_date'); ?>"></div>
                <div class="ee-field"><label>End Date</label><input type="date" name="end_date" class="form-control" value="<?php echo $dt('end_date'); ?>"></div>
            </div>
            <div class="ee-row">
                <div class="ee-field"><label>Start Time</label><input type="time" name="start_time" class="form-control" value="<?php echo $dt('start_time'); ?>"></div>
                <div class="ee-field"><label>End Time</label><input type="time" name="end_time" class="form-control" value="<?php echo $dt('end_time'); ?>"></div>
            </div>

            <p class="ee-section">Location</p>
            <div class="ee-field">
                <label>Venue / Location Name</label>
                <input type="text" name="venue" class="form-control" value="<?php echo $v('venue'); ?>" placeholder="Hall name, church, stadium…">
            </div>
            <div class="ee-field">
                <label>GPS / Address</label>
                <input type="text" name="gps_address" class="form-control" value="<?php echo $v('gps_address'); ?>" placeholder="e.g. Akuapem-Mampong, Eastern Region">
            </div>
            <div class="ee-field">
                <label>Google Maps Link <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                <input type="url" name="google_maps_link" class="form-control" value="<?php echo $v('google_maps_link'); ?>" placeholder="https://maps.google.com/…">
            </div>

            <p class="ee-section">Organizer &amp; Tickets</p>
            <div class="ee-field"><label>Organizer Name</label><input type="text" name="organizer_name" class="form-control" value="<?php echo $v('organizer_name'); ?>" placeholder="Organisation or person responsible"></div>
            <div class="ee-row">
                <div class="ee-field">
                    <label>Ticket Type</label>
                    <select name="ticket_type" class="form-control" id="ticket-type-sel">
                        <option value="free"         <?php echo ($ev['ticket_type']??'free')==='free'         ? 'selected':'' ?>>Free Entry</option>
                        <option value="paid"         <?php echo ($ev['ticket_type']??'')==='paid'         ? 'selected':'' ?>>Paid Entry</option>
                        <option value="registration" <?php echo ($ev['ticket_type']??'')==='registration' ? 'selected':'' ?>>Registration Required</option>
                    </select>
                </div>
                <div class="ee-field" id="ticket-price-row">
                    <label>Ticket Price (GH₵)</label>
                    <input type="number" name="ticket_price" class="form-control" min="0" step="0.01" value="<?php echo number_format((float)($ev['ticket_price'] ?? 0), 2, '.', ''); ?>">
                </div>
            </div>
            <div class="ee-field">
                <label>Registration / Ticket Link</label>
                <div class="desc">External URL for ticket purchases or event registration.</div>
                <input type="url" name="registration_link" class="form-control" value="<?php echo $v('registration_link'); ?>" placeholder="https://…">
            </div>

            <p class="ee-section">Publication</p>
            <div class="ee-row">
                <div class="ee-field">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="draft"     <?php echo ($ev['status']??'draft')==='draft'     ? 'selected':'' ?>>Draft</option>
                        <option value="published" <?php echo ($ev['status']??'')==='published' ? 'selected':'' ?>>Published</option>
                        <option value="cancelled" <?php echo ($ev['status']??'')==='cancelled' ? 'selected':'' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="ee-field" style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                    <input type="checkbox" name="featured" value="1" id="ee-feat" <?php echo !empty($ev['featured']) ? 'checked' : ''; ?>>
                    <label for="ee-feat" style="cursor:pointer;">⭐ Featured event</label>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:12px;">
                <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create Event' : 'Save Changes'; ?></button>
                <a href="events.php" class="button button-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    var sel = document.getElementById('ticket-type-sel');
    var priceRow = document.getElementById('ticket-price-row');
    function togglePrice() { priceRow.style.display = sel.value === 'paid' ? '' : 'none'; }
    sel.addEventListener('change', togglePrice);
    togglePrice();
    </script>
    <script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>

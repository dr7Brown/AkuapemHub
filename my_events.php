<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$feeEnabled = (bool)(int)get_platform_setting('event_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('event_fee_amount', '15');

$errors  = [];
$success = '';

// Unique slug helper
function ev_slug($pdo, $base, $excludeId = 0) {
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $s = trim($s, '-') ?: 'event';
    $t = $s; $i = 2;
    while ($pdo->query("SELECT id FROM events WHERE slug='$t' AND id!=$excludeId LIMIT 1")->fetch()) {
        $t = $s . '-' . $i++;
    }
    return $t;
}

// Delete own draft/rejected submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $check = $pdo->prepare("SELECT id, status FROM events WHERE id=? AND user_id=? LIMIT 1");
    $check->execute([$did, $user['id']]);
    $row = $check->fetch();
    if ($row && in_array($row['status'], ['draft','cancelled'])) {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$did]);
        $success = 'Event deleted.';
    }
}

// Submit / update event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    csrf_check();

    $editId    = (int)($_POST['edit_id'] ?? 0);
    $title     = trim($_POST['title']         ?? '');
    $desc      = trim($_POST['description']   ?? '');
    $venue     = trim($_POST['venue']         ?? '');
    $gps       = trim($_POST['gps_address']   ?? '');
    $startDate = trim($_POST['start_date']    ?? '');
    $endDate   = trim($_POST['end_date']      ?? '') ?: null;
    $startTime = trim($_POST['start_time']    ?? '') ?: null;
    $endTime   = trim($_POST['end_time']      ?? '') ?: null;
    $organizer = trim($_POST['organizer_name']?? '');
    $ticketType = in_array($_POST['ticket_type'] ?? '', ['free','paid','registration'])
                  ? $_POST['ticket_type'] : 'free';
    $ticketPrice  = max(0, (float)($_POST['ticket_price'] ?? 0));
    $regLink      = trim($_POST['registration_link'] ?? '') ?: null;

    if (!$title)     $errors[] = 'Event title is required.';
    if (!$startDate) $errors[] = 'Start date is required.';
    if ($regLink && !filter_var($regLink, FILTER_VALIDATE_URL)) $errors[] = 'Invalid registration/ticket link URL.';

    // Verify ownership if editing
    $existingImg = null;
    if ($editId) {
        $chk = $pdo->prepare("SELECT * FROM events WHERE id=? AND user_id=? LIMIT 1");
        $chk->execute([$editId, $user['id']]);
        $existingRow = $chk->fetch();
        if (!$existingRow || $existingRow['status'] === 'published') {
            $errors[] = 'Cannot edit this event.';
        } else {
            $existingImg = $existingRow['featured_image'];
        }
    }

    $imgPath = $existingImg;
    if (!empty($_FILES['featured_image']['name'])) {
        $p = save_uploaded_image($_FILES['featured_image'], 'uploads/events', 1200, 85);
        if ($p) $imgPath = $p; else $errors[] = 'Image upload failed. JPEG/PNG/WebP, max 5 MB.';
    }

    if (!$errors) {
        $slug = ev_slug($pdo, $title . ' ' . date('Y', strtotime($startDate ?: 'now')), $editId);
        if ($editId) {
            $pdo->prepare(
                "UPDATE events SET title=?,slug=?,featured_image=?,description=?,venue=?,gps_address=?,
                 start_date=?,end_date=?,start_time=?,end_time=?,organizer_name=?,
                 ticket_type=?,ticket_price=?,registration_link=?,status='draft',updated_at=NOW()
                 WHERE id=? AND user_id=?"
            )->execute([$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,$startTime,$endTime,
                        $organizer,$ticketType,$ticketPrice,$regLink,$editId,$user['id']]);
            $success = 'Event updated. It is under review and will be published once approved.';
        } else {
            $pdo->prepare(
                "INSERT INTO events (user_id,title,slug,featured_image,description,venue,gps_address,
                 start_date,end_date,start_time,end_time,organizer_name,ticket_type,ticket_price,
                 registration_link,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')"
            )->execute([$user['id'],$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,
                        $startTime,$endTime,$organizer,$ticketType,$ticketPrice,$regLink]);
            notify_admins_and_managers(
                'New event submitted',
                display_name($user) . ' submitted a new event: "' . $title . '". Review and publish in the Events admin panel.',
                'info'
            );
            if ($feeEnabled) {
                $success = 'Event submitted. Please contact the admin to pay the GH₵ ' . number_format($feeAmount,2) . ' publishing fee.';
            } else {
                $success = 'Event submitted! It will appear on the site once an admin publishes it.';
            }
        }
    }
}

// Edit mode: pre-fill form
$editEvent = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $chk = $pdo->prepare("SELECT * FROM events WHERE id=? AND user_id=? LIMIT 1");
    $chk->execute([(int)$_GET['edit'], $user['id']]);
    $editEvent = $chk->fetch();
    if ($editEvent && $editEvent['status'] === 'published') {
        $editEvent = null; // can't edit published events
    }
}

$myEvents = $pdo->prepare(
    "SELECT * FROM events WHERE user_id=? ORDER BY created_at DESC"
);
$myEvents->execute([$user['id']]);
$myList = $myEvents->fetchAll();

$statusLabels = [
    'draft'     => ['label'=>'Under Review', 'color'=>'#2563eb','bg'=>'#eff6ff'],
    'published' => ['label'=>'Published',    'color'=>'#059669','bg'=>'#ecfdf5'],
    'cancelled' => ['label'=>'Cancelled',    'color'=>'#dc2626','bg'=>'#fee2e2'],
];

$v  = fn($k) => sanitize($editEvent[$k] ?? '');
$dt = fn($k) => !empty($editEvent[$k]) ? $editEvent[$k] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .me-shell  { max-width:800px; margin:0 auto; padding:20px 16px 60px; }
        .me-list   { display:flex; flex-direction:column; gap:12px; margin-bottom:28px; }
        .me-item   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:14px 16px; display:flex; align-items:flex-start; gap:14px; }
        .me-thumb  { width:56px; height:56px; border-radius:10px; background:#f3f4f6; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:1.5rem; }
        .me-thumb img { width:100%; height:100%; object-fit:cover; }
        .me-info   { flex:1; min-width:0; }
        .me-name   { font-weight:800; font-size:.95rem; margin:0 0 4px; }
        .me-meta   { font-size:.78rem; color:var(--text-muted,#6b7280); }
        .me-status { display:inline-block; font-size:.68rem; font-weight:800; padding:3px 9px; border-radius:20px; margin-top:5px; }
        .me-actions { display:flex; gap:8px; align-items:center; flex-shrink:0; flex-wrap:wrap; }

        .me-form-wrap { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px; }
        .me-form-wrap h2 { font-size:1rem; font-weight:800; margin:0 0 16px; }
        .me-field  { margin-bottom:16px; }
        .me-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .me-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .me-field input, .me-field select, .me-field textarea { width:100%; box-sizing:border-box; }
        .me-field textarea { resize:vertical; min-height:100px; }
        .me-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .me-row { grid-template-columns:1fr; } }
        .me-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:20px 0 12px; border-top:1px solid var(--border,#e5e7eb); padding-top:16px; }
        #price-row { display:none; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="community.php" class="button button-secondary button-small">← Community</a>
        <h1>My Events</h1>
        <a href="events.php" class="button button-small">All Events</a>
    </header>

    <div class="me-shell">
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px;"><?php echo sanitize($success); ?></div><?php endif; ?>

        <!-- Existing submissions -->
        <?php if ($myList): ?>
        <div class="me-list">
            <?php foreach ($myList as $ev): ?>
            <?php $sl = $statusLabels[$ev['status']] ?? $statusLabels['draft']; ?>
            <div class="me-item">
                <div class="me-thumb">
                    <?php if ($ev['featured_image']): ?>
                        <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="">
                    <?php else: ?>
                        📅
                    <?php endif; ?>
                </div>
                <div class="me-info">
                    <p class="me-name"><?php echo sanitize($ev['title']); ?></p>
                    <p class="me-meta">
                        📅 <?php echo date('d M Y', strtotime($ev['start_date'])); ?>
                        <?php if ($ev['venue']): ?>&nbsp;·&nbsp;<?php echo sanitize(mb_substr($ev['venue'],0,40)); ?><?php endif; ?>
                    </p>
                    <span class="me-status" style="color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;">
                        <?php echo $sl['label']; ?>
                    </span>
                    <?php if ($ev['status'] === 'draft'): ?>
                    <p style="font-size:.77rem;color:#1d4ed8;margin:5px 0 0;">Awaiting admin review before going live.</p>
                    <?php endif; ?>
                </div>
                <div class="me-actions">
                    <?php if ($ev['status'] === 'published' && $ev['slug']): ?>
                        <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" class="button button-small" target="_blank">View</a>
                    <?php endif; ?>
                    <?php if (in_array($ev['status'], ['draft','cancelled'])): ?>
                        <a href="my_events.php?edit=<?php echo (int)$ev['id']; ?>" class="button button-small button-secondary">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this event?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo (int)$ev['id']; ?>">
                            <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Submit / Edit form -->
        <div class="me-form-wrap">
            <h2><?php echo $editEvent ? 'Edit Event' : 'Submit a Community Event'; ?></h2>

            <p style="font-size:.83rem;color:var(--text-muted);margin:-4px 0 16px;">
                Events are reviewed by an admin before going live on the platform.
            </p>

            <?php if ($feeEnabled && !$editEvent): ?>
            <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;">
                💳 A <strong>GH₵ <?php echo number_format($feeAmount,2); ?></strong> fee applies to publish your event.
                Submit your event and contact the admin to complete payment.
            </div>
            <?php endif; ?>

            <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_submit" value="1">
                <?php if ($editEvent): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$editEvent['id']; ?>">
                <?php endif; ?>

                <div class="me-field">
                    <label>Event Title *</label>
                    <input type="text" name="title" class="form-control" required
                           value="<?php echo $editEvent ? sanitize($editEvent['title']) : ''; ?>"
                           placeholder="e.g. Annual Community Festival 2025">
                </div>

                <div class="me-field">
                    <label>Featured Image</label>
                    <div class="desc">Recommended 1200×630 px · JPEG/PNG/WebP · Max 5 MB</div>
                    <input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp">
                    <?php if ($editEvent && !empty($editEvent['featured_image'])): ?>
                        <img src="<?php echo sanitize($editEvent['featured_image']); ?>" alt=""
                             style="max-width:220px;border-radius:8px;margin-top:6px;">
                    <?php endif; ?>
                </div>

                <div class="me-field">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="5"
                              placeholder="Describe the event…"><?php echo $editEvent ? sanitize($editEvent['description']) : ''; ?></textarea>
                </div>

                <p class="me-section">Date &amp; Time</p>
                <div class="me-row">
                    <div class="me-field">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" class="form-control" required value="<?php echo $dt('start_date'); ?>">
                    </div>
                    <div class="me-field">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $dt('end_date'); ?>">
                    </div>
                </div>
                <div class="me-row">
                    <div class="me-field">
                        <label>Start Time</label>
                        <input type="time" name="start_time" class="form-control" value="<?php echo $dt('start_time'); ?>">
                    </div>
                    <div class="me-field">
                        <label>End Time</label>
                        <input type="time" name="end_time" class="form-control" value="<?php echo $dt('end_time'); ?>">
                    </div>
                </div>

                <p class="me-section">Location</p>
                <div class="me-field">
                    <label>Venue</label>
                    <input type="text" name="venue" class="form-control"
                           value="<?php echo $v('venue'); ?>" placeholder="Hall name, church, field…">
                </div>
                <div class="me-field">
                    <label>GPS Address</label>
                    <input type="text" name="gps_address" class="form-control"
                           value="<?php echo $v('gps_address'); ?>" placeholder="e.g. GA-123-4567">
                </div>

                <p class="me-section">Organizer &amp; Tickets</p>
                <div class="me-field">
                    <label>Organizer Name</label>
                    <input type="text" name="organizer_name" class="form-control"
                           value="<?php echo $v('organizer_name'); ?>" placeholder="Organisation or person responsible">
                </div>
                <div class="me-row">
                    <div class="me-field">
                        <label>Ticket Type</label>
                        <select name="ticket_type" id="ticket-type-sel" class="form-control">
                            <option value="free"         <?php echo ($editEvent['ticket_type']??'free')==='free'         ?'selected':''; ?>>Free Entry</option>
                            <option value="paid"         <?php echo ($editEvent['ticket_type']??'')==='paid'         ?'selected':''; ?>>Paid Entry</option>
                            <option value="registration" <?php echo ($editEvent['ticket_type']??'')==='registration' ?'selected':''; ?>>Registration Required</option>
                        </select>
                    </div>
                    <div class="me-field" id="price-row">
                        <label>Ticket Price (GH₵)</label>
                        <input type="number" name="ticket_price" class="form-control" min="0" step="0.01"
                               value="<?php echo number_format((float)($editEvent['ticket_price'] ?? 0), 2, '.', ''); ?>">
                    </div>
                </div>
                <div class="me-field">
                    <label>Registration / Ticket Link</label>
                    <div class="desc">External URL for ticket purchases or registration (optional).</div>
                    <input type="url" name="registration_link" class="form-control"
                           value="<?php echo $v('registration_link'); ?>" placeholder="https://…">
                </div>

                <div style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap;">
                    <button type="submit" class="button button-primary">
                        <?php echo $editEvent ? 'Save Changes' : 'Submit Event'; ?>
                    </button>
                    <?php if ($editEvent): ?>
                    <a href="my_events.php" class="button button-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var sel      = document.getElementById('ticket-type-sel');
        var priceRow = document.getElementById('price-row');
        function toggle() { priceRow.style.display = sel.value === 'paid' ? '' : 'none'; }
        sel.addEventListener('change', toggle);
        toggle();
    })();
    </script>

<?php $activeNav = 'community'; require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

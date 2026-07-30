<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('events', 'Events');
require_login();
$user = current_user();

$feeEnabled = (bool)(int)get_platform_setting('event_fee_enabled', '0') && !user_has_complimentary_access();
$feeAmount  = (float)get_platform_setting('event_fee_amount', '15');

$errors  = [];
$success = match($_GET['msg'] ?? '') {
    'deleted'   => 'Event deleted.',
    'updated'   => 'Event updated. It is under review and will be published once approved.',
    'submitted' => 'Event submitted! It will appear on the site once an admin publishes it.',
    default     => '',
};

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

// Delete own draft/pending_payment/cancelled submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $check = $pdo->prepare("SELECT id, status FROM events WHERE id=? AND user_id=? LIMIT 1");
    $check->execute([$did, $user['id']]);
    $row = $check->fetch();
    if ($row && in_array($row['status'], ['pending_payment','draft','cancelled','rejected'])) {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$did]);
        header('Location: my_events.php?msg=deleted'); exit;
    }
}

// Submit / update event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    csrf_check();

    $editId    = (int)($_POST['edit_id'] ?? 0);
    $title     = trim($_POST['title']         ?? '');
    $desc      = trim($_POST['description']   ?? '');
    $venue         = trim($_POST['venue']          ?? '');
    $gps           = trim($_POST['gps_address']    ?? '');
    $googleMapsLink = trim($_POST['google_maps_link'] ?? '') ?: null;
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
    if (!$editId && requires_verified_email('event_post') && !is_email_verified()) {
        $errors[] = 'Please verify your email address before submitting an event.';
    }
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
            $prevEv = $pdo->prepare("SELECT status FROM events WHERE id=? AND user_id=? LIMIT 1");
            $prevEv->execute([$editId, $user['id']]);
            $wasRejected = ($prevEv->fetchColumn() ?? '') === 'rejected';

            $pdo->prepare(
                "UPDATE events SET title=?,slug=?,featured_image=?,description=?,venue=?,gps_address=?,
                 start_date=?,end_date=?,start_time=?,end_time=?,organizer_name=?,
                 ticket_type=?,ticket_price=?,registration_link=?,google_maps_link=?,
                 status='draft',rejection_reason=NULL,updated_at=NOW()
                 WHERE id=? AND user_id=?"
            )->execute([$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,$startTime,$endTime,
                        $organizer,$ticketType,$ticketPrice,$regLink,$googleMapsLink,$editId,$user['id']]);

            if ($wasRejected) {
                notify_admins_and_managers(
                    'Event Resubmitted for Review',
                    display_name($user) . ' has resubmitted "' . $title . '" after rejection. Review in Admin → Events.',
                    'info'
                );
                flash('Event resubmitted for review. Admin has been notified.', 'success');
            }
            header('Location: my_events.php?msg=updated'); exit;
        } else {
            $initStatus = $feeEnabled ? 'pending_payment' : 'draft';
            $pdo->prepare(
                "INSERT INTO events (user_id,title,slug,featured_image,description,venue,gps_address,
                 start_date,end_date,start_time,end_time,organizer_name,ticket_type,ticket_price,
                 registration_link,google_maps_link,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$user['id'],$title,$slug,$imgPath,$desc,$venue,$gps,$startDate,$endDate,
                        $startTime,$endTime,$organizer,$ticketType,$ticketPrice,$regLink,$googleMapsLink,$initStatus]);
            $newId = (int)$pdo->lastInsertId();
            if ($feeEnabled) {
                header('Location: pay_event.php?id=' . $newId); exit;
            }
            notify_admins_and_managers(
                'New event submitted',
                display_name($user) . ' submitted a new event: "' . $title . '". Review and publish in the Events admin panel.',
                'info'
            );
            header('Location: my_events.php?msg=submitted'); exit;
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
    'pending_payment' => ['label'=>'Awaiting Payment', 'color'=>'#92400e','bg'=>'#fffbeb'],
    'draft'           => ['label'=>'Under Review',     'color'=>'#2563eb','bg'=>'#eff6ff'],
    'published'       => ['label'=>'Published',        'color'=>'#059669','bg'=>'#ecfdf5'],
    'cancelled'       => ['label'=>'Cancelled',        'color'=>'#dc2626','bg'=>'#fee2e2'],
    'rejected'        => ['label'=>'Rejected',         'color'=>'#dc2626','bg'=>'#fee2e2'],
];

// On validation error for a new submission, fall back to $_POST so fields retain their values
$_errPost = ($errors && !$editEvent) ? $_POST : [];
$v  = fn($k) => sanitize($editEvent[$k] ?? $_errPost[$k] ?? '');
$dt = fn($k) => $editEvent[$k] ?? $_errPost[$k] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .me-shell   { max-width:1080px; margin:0 auto; padding:20px 16px 60px; }
        .me-layout  { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }
        @media(max-width:860px){ .me-layout { grid-template-columns:1fr; } }

        /* Sidebar */
        .me-sidebar { position:sticky; top:16px; }
        .me-sidebar-hd { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:12px; }
        .me-list   { display:flex; flex-direction:column; gap:10px; }
        .me-item   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:12px 14px; display:flex; align-items:flex-start; gap:12px; }
        .me-thumb  { width:48px; height:48px; border-radius:8px; background:#f3f4f6; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:1.3rem; }
        .me-thumb img { width:100%; height:100%; object-fit:cover; }
        .me-info   { flex:1; min-width:0; }
        .me-name   { font-weight:700; font-size:.88rem; margin:0 0 3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .me-meta   { font-size:.75rem; color:var(--text-muted,#6b7280); }
        .me-status { display:inline-block; font-size:.66rem; font-weight:800; padding:2px 8px; border-radius:20px; margin-top:4px; }
        .me-actions { display:flex; gap:6px; align-items:center; flex-shrink:0; flex-wrap:wrap; margin-top:8px; }

        /* Form */
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

        <div class="me-layout">
            <!-- Submit / Edit form -->
            <div class="me-main">
            <div class="me-form-wrap">
            <h2><?php echo $editEvent ? 'Edit Event' : 'Submit a Community Event'; ?></h2>

            <p style="font-size:.83rem;color:var(--text-muted);margin:-4px 0 16px;">
                Events are reviewed by an admin before going live on the platform.
            </p>

            <?php if ($feeEnabled && !$editEvent): ?>
            <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;">
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
                <?php if ($editEvent): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$editEvent['id']; ?>">
                <?php endif; ?>

                <div class="me-field">
                    <label>Event Title *</label>
                    <input type="text" name="title" class="form-control" required
                           value="<?php echo $v('title'); ?>"
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
                    <textarea name="description" class="form-control rich-editor" rows="5"
                              placeholder="Describe the event…"><?php echo $editEvent ? $editEvent['description'] : ($_errPost['description'] ?? ''); ?></textarea>
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
                    <label>Venue / Location Name</label>
                    <input type="text" name="venue" class="form-control"
                           value="<?php echo $v('venue'); ?>" placeholder="Hall name, church, field…">
                </div>
                <div class="me-field">
                    <label>GPS / Address</label>
                    <input type="text" name="gps_address" class="form-control"
                           value="<?php echo $v('gps_address'); ?>" placeholder="e.g. Akuapem-Mampong, Eastern Region">
                </div>
                <div class="me-field">
                    <label>Google Maps Link <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                    <div class="desc">Paste a Google Maps share link. Attendees can click it to get directions.</div>
                    <input type="url" name="google_maps_link" class="form-control"
                           value="<?php echo $v('google_maps_link'); ?>" placeholder="https://maps.google.com/…">
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
                            <?php $selTicket = $editEvent['ticket_type'] ?? $_errPost['ticket_type'] ?? 'free'; ?>
                            <option value="free"         <?php echo $selTicket==='free'         ?'selected':''; ?>>Free Entry</option>
                            <option value="paid"         <?php echo $selTicket==='paid'         ?'selected':''; ?>>Paid Entry</option>
                            <option value="registration" <?php echo $selTicket==='registration' ?'selected':''; ?>>Registration Required</option>
                        </select>
                    </div>
                    <div class="me-field" id="price-row">
                        <label>Ticket Price (GH₵)</label>
                        <input type="number" name="ticket_price" class="form-control" min="0" step="0.01"
                               value="<?php echo number_format((float)($editEvent['ticket_price'] ?? $_errPost['ticket_price'] ?? 0), 2, '.', ''); ?>">
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
        </div><!-- /.me-main -->

            <!-- Sidebar: submissions list -->
            <aside class="me-sidebar">
                <?php if ($myList): ?>
                <div class="me-sidebar-hd">My Events (<?php echo count($myList); ?>)</div>
                <div class="me-list">
                    <?php foreach ($myList as $ev): ?>
                    <?php $sl = $statusLabels[$ev['status']] ?? $statusLabels['draft']; ?>
                    <div class="me-item" style="<?php echo $ev['status']==='rejected' ? 'border-left:4px solid #ef4444;' : ''; ?>">
                        <div class="me-thumb">
                            <?php if ($ev['featured_image']): ?>
                                <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="">
                            <?php else: ?>
                                📅
                            <?php endif; ?>
                        </div>
                        <div class="me-info">
                            <?php if ($ev['status'] === 'rejected'): ?>
                            <p style="font-size:.68rem;font-weight:800;color:#ef4444;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">❌ Rejected — Action needed</p>
                            <?php endif; ?>
                            <p class="me-name"><?php echo sanitize($ev['title']); ?></p>
                            <p class="me-meta">📅 <?php echo date('d M Y', strtotime($ev['start_date'])); ?></p>
                            <span class="me-status" style="color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;">
                                <?php echo $sl['label']; ?>
                            </span>
                            <?php if ($ev['status'] === 'rejected'): ?>
                            <div style="background:#fee2e2;border-left:3px solid #ef4444;border-radius:0 6px 6px 0;padding:6px 8px;margin:5px 0;font-size:.76rem;color:#7f1d1d;line-height:1.5;">
                                <?php echo !empty($ev['rejection_reason'])
                                    ? nl2br(sanitize($ev['rejection_reason']))
                                    : '<em style="color:#991b1b;">No reason given — contact admin.</em>'; ?>
                            </div>
                            <?php endif; ?>
                            <div class="me-actions">
                                <?php if ($ev['status'] === 'published' && $ev['slug']): ?>
                                    <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" class="button button-small" target="_blank">View</a>
                                    <?php
                                    $evFeatEnd    = $ev['featured_end_date'] ?? null;
                                    $evFeatActive = !empty($ev['featured']) && ($evFeatEnd === null || $evFeatEnd >= date('Y-m-d'));
                                    ?>
                                    <?php if ($evFeatActive): ?>
                                    <span style="font-size:.68rem;font-weight:800;padding:3px 8px;border-radius:20px;background:#fef3c7;color:#92400e;">⭐ Featured</span>
                                    <?php else: ?>
                                    <a href="feature_event.php?id=<?php echo (int)$ev['id']; ?>" class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;">⭐ Feature</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($ev['status'] === 'pending_payment'): ?>
                                    <a href="pay_event.php?id=<?php echo (int)$ev['id']; ?>" class="button button-small button-primary">Pay</a>
                                <?php endif; ?>
                                <?php if ($ev['status'] === 'rejected'): ?>
                                    <a href="my_events.php?edit=<?php echo (int)$ev['id']; ?>" class="button button-primary button-small" style="background:#ef4444;border-color:#ef4444;">✏️ Fix &amp; Resubmit</a>
                                <?php elseif (in_array($ev['status'], ['pending_payment','draft','cancelled'])): ?>
                                    <a href="my_events.php?edit=<?php echo (int)$ev['id']; ?>" class="button button-small button-secondary">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this event?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$ev['id']; ?>">
                                        <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Del</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.83rem;color:var(--text-muted,#6b7280);">No events submitted yet.</p>
                <?php endif; ?>
            </aside>
        </div><!-- /.me-layout -->
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

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$feeEnabled = (bool)(int)get_platform_setting('funeral_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('funeral_fee_amount', '20');

$errors  = [];
$success = match($_GET['msg'] ?? '') {
    'deleted'   => 'Announcement deleted.',
    'submitted' => 'Announcement submitted and is pending admin review.',
    'updated'   => 'Announcement updated and resubmitted for review.',
    default     => '',
};

// Helper: generate unique slug
function fa_slug($pdo, $base, $excludeId = 0) {
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($base));
    $s = trim($s, '-');
    $t = $s; $i = 2;
    while ($pdo->prepare("SELECT id FROM funeral_announcements WHERE slug=? AND id!=? LIMIT 1")->execute([$t, $excludeId]) &&
           $pdo->prepare("SELECT id FROM funeral_announcements WHERE slug=? AND id!=? LIMIT 1")->execute([$t, $excludeId]) &&
           $pdo->query("SELECT id FROM funeral_announcements WHERE slug='$t' AND id!=$excludeId LIMIT 1")->fetch()) {
        $t = $s . '-' . $i++;
    }
    return $t;
}

// Delete own submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $did = (int)$_POST['delete_id'];
    $check = $pdo->prepare("SELECT id, status FROM funeral_announcements WHERE id=? AND user_id=? LIMIT 1");
    $check->execute([$did, $user['id']]);
    $row = $check->fetch();
    if ($row && in_array($row['status'], ['pending_payment','pending','rejected'])) {
        $pdo->prepare("DELETE FROM funeral_announcements WHERE id=?")->execute([$did]);
        header('Location: my_funerals.php?msg=deleted'); exit;
    }
}

// Submit / update announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    csrf_check();
    $postEditId     = (int)($_POST['edit_id'] ?? 0);
    $googleMapsLink = trim($_POST['google_maps_link'] ?? '') ?: null;
    $fields = ['deceased_name','gender','age','date_of_birth','date_of_death','biography',
               'wake_keeping_date','burial_date','thanksgiving_date','venue','gps_address',
               'organizer_name','organizer_phone','organizer_email'];

    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
        if ($data[$f] === '') $data[$f] = null;
    }
    if (!$data['deceased_name']) $errors[] = 'Deceased name is required.';
    if ($data['organizer_phone'] && !preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $data['organizer_phone'])) {
        $errors[] = 'Invalid phone number.';
    }
    if ($data['organizer_email'] && !filter_var($data['organizer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    // For edit mode, fetch existing photos as fallback
    $existingPhoto  = null;
    $existingPoster = null;
    if ($postEditId) {
        $exRow = $pdo->prepare("SELECT photograph, funeral_poster FROM funeral_announcements WHERE id=? AND user_id=? AND status='rejected' LIMIT 1");
        $exRow->execute([$postEditId, $user['id']]);
        $exData = $exRow->fetch();
        if ($exData) {
            $existingPhoto  = $exData['photograph'];
            $existingPoster = $exData['funeral_poster'];
        } else {
            $errors[] = 'Cannot edit this announcement.';
        }
    }

    $photoPath  = $existingPhoto;
    $posterPath = $existingPoster;
    if (!empty($_FILES['photograph']['name'])) {
        $p = save_uploaded_image($_FILES['photograph'], 'uploads/funerals', 800, 85);
        if ($p) $photoPath = $p; else $errors[] = 'Photograph upload failed. JPEG/PNG/WebP, max 5 MB.';
    }
    if (!empty($_FILES['funeral_poster']['name'])) {
        $p2 = save_uploaded_image($_FILES['funeral_poster'], 'uploads/funerals', 1200, 85);
        if ($p2) $posterPath = $p2; else $errors[] = 'Poster upload failed. JPEG/PNG/WebP, max 5 MB.';
    }

    if (!$errors) {
        if ($postEditId) {
            $pdo->prepare(
                "UPDATE funeral_announcements SET
                 deceased_name=?,gender=?,age=?,photograph=?,funeral_poster=?,date_of_birth=?,date_of_death=?,biography=?,
                 wake_keeping_date=?,burial_date=?,thanksgiving_date=?,venue=?,gps_address=?,
                 organizer_name=?,organizer_phone=?,organizer_email=?,google_maps_link=?,
                 status='pending',rejection_reason=NULL,updated_at=NOW()
                 WHERE id=? AND user_id=?"
            )->execute([
                $data['deceased_name'], $data['gender'] ?: 'male', $data['age'] ?: null,
                $photoPath, $posterPath, $data['date_of_birth'], $data['date_of_death'], $data['biography'],
                $data['wake_keeping_date'] ?: null, $data['burial_date'] ?: null, $data['thanksgiving_date'] ?: null,
                $data['venue'], $data['gps_address'], $data['organizer_name'], $data['organizer_phone'],
                $data['organizer_email'], $googleMapsLink, $postEditId, $user['id']
            ]);
            notify_admins_and_managers(
                'Funeral announcement resubmitted',
                display_name($user) . ' resubmitted a funeral announcement for "' . $data['deceased_name'] . '". Review in Admin → Funerals.',
                'info'
            );
            header('Location: my_funerals.php?msg=updated'); exit;
        }

        $status = $feeEnabled ? 'pending_payment' : 'pending';
        $slug   = fa_slug($pdo, $data['deceased_name'] . ' ' . date('Y'));
        $pdo->prepare(
            "INSERT INTO funeral_announcements
             (user_id, deceased_name, gender, age, photograph, funeral_poster, date_of_birth, date_of_death, biography,
              wake_keeping_date, burial_date, thanksgiving_date, venue, gps_address,
              organizer_name, organizer_phone, organizer_email, google_maps_link, status, slug)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $user['id'], $data['deceased_name'], $data['gender'] ?: 'male', $data['age'] ?: null,
            $photoPath, $posterPath, $data['date_of_birth'], $data['date_of_death'], $data['biography'],
            $data['wake_keeping_date'] ?: null, $data['burial_date'] ?: null, $data['thanksgiving_date'] ?: null,
            $data['venue'], $data['gps_address'], $data['organizer_name'], $data['organizer_phone'],
            $data['organizer_email'], $googleMapsLink, $status, $slug
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($feeEnabled) {
            header('Location: pay_funeral.php?id=' . $newId); exit;
        }
        notify_admins_and_managers(
            'Funeral announcement submitted',
            display_name($user) . ' submitted a funeral announcement for "' . $data['deceased_name'] . '". Review in Admin → Funerals.',
            'info'
        );
        header('Location: my_funerals.php?msg=submitted'); exit;
    }
}

// Edit pre-fill (only rejected announcements can be edited)
$editAnnouncement = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $editStmt = $pdo->prepare("SELECT * FROM funeral_announcements WHERE id=? AND user_id=? AND status='rejected' LIMIT 1");
    $editStmt->execute([(int)$_GET['edit'], $user['id']]);
    $editAnnouncement = $editStmt->fetch() ?: null;
}

$myAnnouncements = $pdo->prepare(
    "SELECT * FROM funeral_announcements WHERE user_id=? ORDER BY created_at DESC"
);
$myAnnouncements->execute([$user['id']]);
$myList = $myAnnouncements->fetchAll();

$statusLabels = [
    'pending_payment' => ['label'=>'Awaiting Payment','color'=>'#f59e0b','bg'=>'#fffbeb'],
    'pending'         => ['label'=>'Under Review',    'color'=>'#2563eb','bg'=>'#eff6ff'],
    'approved'        => ['label'=>'Published',       'color'=>'#059669','bg'=>'#ecfdf5'],
    'rejected'        => ['label'=>'Rejected',        'color'=>'#dc2626','bg'=>'#fee2e2'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Funeral Announcements — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mf-shell   { max-width:1080px; margin:0 auto; padding:20px 16px 60px; }
        .mf-layout  { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }
        @media(max-width:860px){ .mf-layout { grid-template-columns:1fr; } }

        /* Sidebar */
        .mf-sidebar { position:sticky; top:16px; }
        .mf-sidebar-hd { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:12px; }
        .mf-list    { display:flex; flex-direction:column; gap:10px; }
        .mf-item    { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:12px 14px; display:flex; align-items:flex-start; gap:12px; }
        .mf-thumb   { width:48px; height:48px; border-radius:8px; background:#f3f4f6; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .mf-thumb img { width:100%; height:100%; object-fit:cover; }
        .mf-thumb-init { font-size:1.1rem; font-weight:900; color:#d1d5db; }
        .mf-info    { flex:1; min-width:0; }
        .mf-name    { font-weight:700; font-size:.88rem; margin:0 0 3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mf-meta    { font-size:.75rem; color:var(--text-muted,#6b7280); }
        .mf-status  { display:inline-block; font-size:.66rem; font-weight:800; padding:2px 8px; border-radius:20px; margin-top:4px; }
        .mf-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:8px; }

        /* Form */
        .mf-form-wrap { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px; }
        .mf-form-wrap h2 { font-size:1rem; font-weight:800; margin:0 0 16px; }
        .mf-field   { margin-bottom:16px; }
        .mf-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .mf-field .desc { font-size:.76rem; color:var(--text-muted); margin-bottom:5px; }
        .mf-field input, .mf-field select, .mf-field textarea { width:100%; box-sizing:border-box; }
        .mf-field textarea { resize:vertical; min-height:80px; }
        .mf-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .mf-row { grid-template-columns:1fr; } }
        .mf-section-label { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:20px 0 12px; border-top:1px solid var(--border,#e5e7eb); padding-top:16px; }
        .mf-fee-notice { background:#fffbeb; border:1px solid #f59e0b; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:.88rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="community.php" class="button button-secondary button-small">← Community</a>
        <h1>My Funeral Announcements</h1>
        <a href="funerals.php" class="button button-small">All Announcements</a>
    </header>

    <div class="mf-shell">
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px;"><?php echo sanitize($success); ?></div><?php endif; ?>

        <div class="mf-layout">
            <!-- Submit form -->
            <div class="mf-main">
            <div class="mf-form-wrap">
            <h2><?php echo $editAnnouncement ? 'Edit Announcement' : 'Submit a Funeral Announcement'; ?></h2>

            <?php if ($editAnnouncement && !empty($editAnnouncement['rejection_reason'])): ?>
            <div class="alert alert-error" style="margin-bottom:14px;">
                <strong>Not approved.</strong> Reason: <?php echo sanitize($editAnnouncement['rejection_reason']); ?>
                Please update and resubmit.
            </div>
            <?php endif; ?>

            <?php if ($feeEnabled && !$editAnnouncement): ?>
            <div class="mf-fee-notice">
                💳 A <strong>GH₵ <?php echo number_format($feeAmount, 2); ?></strong> publishing fee applies.
                After submitting your details, you'll be taken to a secure Paystack checkout to complete payment.
            </div>
            <?php endif; ?>

            <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_submit" value="1">
                <?php if ($editAnnouncement): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$editAnnouncement['id']; ?>">
                <?php endif; ?>
                <?php $fp = $errors ? $_POST : ($editAnnouncement ?: []); $fv = fn($k) => sanitize($fp[$k] ?? ''); ?>

                <!-- Deceased info -->
                <div class="mf-field">
                    <label>Deceased Name *</label>
                    <input type="text" name="deceased_name" class="form-control" required placeholder="Full name" value="<?php echo $fv('deceased_name'); ?>">
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Gender</label>
                        <?php $selGender = $fp['gender'] ?? 'male'; ?>
                        <select name="gender" class="form-control">
                            <option value="male"   <?php echo $selGender==='male'   ?'selected':''; ?>>Male</option>
                            <option value="female" <?php echo $selGender==='female' ?'selected':''; ?>>Female</option>
                            <option value="other"  <?php echo $selGender==='other'  ?'selected':''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="mf-field">
                        <label>Age at Death</label>
                        <input type="number" name="age" class="form-control" min="0" max="150" placeholder="e.g. 72" value="<?php echo $fv('age'); ?>">
                    </div>
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?php echo $fv('date_of_birth'); ?>">
                    </div>
                    <div class="mf-field">
                        <label>Date of Death</label>
                        <input type="date" name="date_of_death" class="form-control" value="<?php echo $fv('date_of_death'); ?>">
                    </div>
                </div>
                <div class="mf-field">
                    <label>Photograph</label>
                    <div class="desc">Photo of the deceased. JPEG/PNG/WebP · Max 5 MB</div>
                    <input type="file" name="photograph" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="mf-field">
                    <label>Funeral Poster / Programme</label>
                    <div class="desc">Flyer or programme image. JPEG/PNG/WebP · Max 5 MB</div>
                    <input type="file" name="funeral_poster" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="mf-field">
                    <label>Biography</label>
                    <textarea name="biography" class="form-control rich-editor" rows="5" placeholder="Brief biography or tribute…"><?php echo $fp['biography'] ?? ''; ?></textarea>
                </div>

                <p class="mf-section-label">Funeral Schedule</p>

                <div class="mf-row">
                    <div class="mf-field">
                        <label>Wake Keeping Date &amp; Time</label>
                        <input type="datetime-local" name="wake_keeping_date" class="form-control" value="<?php echo $fv('wake_keeping_date'); ?>">
                    </div>
                    <div class="mf-field">
                        <label>Burial Date &amp; Time</label>
                        <input type="datetime-local" name="burial_date" class="form-control" value="<?php echo $fv('burial_date'); ?>">
                    </div>
                </div>
                <div class="mf-field">
                    <label>Thanksgiving Service Date &amp; Time</label>
                    <input type="datetime-local" name="thanksgiving_date" class="form-control" value="<?php echo $fv('thanksgiving_date'); ?>">
                </div>
                <div class="mf-field">
                    <label>Venue / Location Name</label>
                    <input type="text" name="venue" class="form-control" placeholder="Church, hall or location name" value="<?php echo $fv('venue'); ?>">
                </div>
                <div class="mf-field">
                    <label>GPS / Address</label>
                    <input type="text" name="gps_address" class="form-control" placeholder="e.g. Akuapem-Mampong, Eastern Region" value="<?php echo $fv('gps_address'); ?>">
                </div>
                <div class="mf-field">
                    <label>Google Maps Link <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                    <div class="desc">Paste a Google Maps share link. Visitors can click it for directions.</div>
                    <input type="url" name="google_maps_link" class="form-control"
                           value="<?php echo $fv('google_maps_link'); ?>" placeholder="https://maps.google.com/…">
                </div>

                <p class="mf-section-label">Organizer Contact</p>

                <div class="mf-field">
                    <label>Organizer / Family Contact Name</label>
                    <input type="text" name="organizer_name" class="form-control" placeholder="Name of point of contact" value="<?php echo $fv('organizer_name'); ?>">
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Phone</label>
                        <input type="tel" name="organizer_phone" class="form-control" placeholder="+233 …" value="<?php echo $fv('organizer_phone'); ?>">
                    </div>
                    <div class="mf-field">
                        <label>Email</label>
                        <input type="email" name="organizer_email" class="form-control" placeholder="contact@example.com" value="<?php echo $fv('organizer_email'); ?>">
                    </div>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
                    <button type="submit" class="button button-primary">
                        <?php echo $editAnnouncement ? 'Save & Resubmit' : 'Submit Announcement'; ?>
                    </button>
                    <?php if ($editAnnouncement): ?>
                    <a href="my_funerals.php" class="button button-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
            </div><!-- /.mf-main -->

            <!-- Sidebar: submissions list -->
            <aside class="mf-sidebar">
                <?php if ($myList): ?>
                <div class="mf-sidebar-hd">My Announcements (<?php echo count($myList); ?>)</div>
                <div class="mf-list">
                    <?php foreach ($myList as $fa): ?>
                    <?php $sl = $statusLabels[$fa['status']] ?? $statusLabels['pending']; ?>
                    <div class="mf-item">
                        <div class="mf-thumb">
                            <?php if ($fa['photograph']): ?>
                                <img src="<?php echo sanitize($fa['photograph']); ?>" alt="">
                            <?php else: ?>
                                <span class="mf-thumb-init"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="mf-info">
                            <p class="mf-name"><?php echo sanitize($fa['deceased_name']); ?></p>
                            <p class="mf-meta">
                                <?php if ($fa['burial_date']): ?>⚰️ <?php echo date('d M Y', strtotime($fa['burial_date'])); ?><?php endif; ?>
                            </p>
                            <span class="mf-status" style="color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;">
                                <?php echo $sl['label']; ?>
                            </span>
                            <?php if ($fa['status'] === 'rejected' && !empty($fa['rejection_reason'])): ?>
                            <p style="font-size:.72rem;color:#991b1b;margin:4px 0 0;background:#fff1f2;border:1px solid #fecdd3;border-radius:6px;padding:4px 8px;">
                                <strong>Reason:</strong> <?php echo sanitize($fa['rejection_reason']); ?>
                            </p>
                            <?php endif; ?>
                            <div class="mf-actions">
                                <?php if ($fa['status'] === 'approved' && $fa['slug']): ?>
                                <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="button button-small" target="_blank">View</a>
                                <?php endif; ?>
                                <?php if ($fa['status'] === 'pending_payment'): ?>
                                <a href="pay_funeral.php?id=<?php echo (int)$fa['id']; ?>" class="button button-small button-primary">Pay</a>
                                <?php endif; ?>
                                <?php if ($fa['status'] === 'rejected'): ?>
                                <a href="my_funerals.php?edit=<?php echo (int)$fa['id']; ?>" class="button button-small button-secondary">Edit</a>
                                <?php endif; ?>
                                <?php if (in_array($fa['status'], ['pending_payment','pending','rejected'])): ?>
                                <form method="post" onsubmit="return confirm('Delete this announcement?')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="delete_id" value="<?php echo (int)$fa['id']; ?>">
                                    <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Del</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.83rem;color:var(--text-muted,#6b7280);">No announcements submitted yet.</p>
                <?php endif; ?>
            </aside>
        </div><!-- /.mf-layout -->
    </div>

<?php $activeNav = 'community'; require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

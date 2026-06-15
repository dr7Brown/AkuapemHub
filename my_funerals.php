<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$feeEnabled = (bool)(int)get_platform_setting('funeral_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('funeral_fee_amount', '20');

$errors  = [];
$success = '';

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
        $success = 'Announcement deleted.';
    }
}

// Submit new announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    csrf_check();
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

    $photoPath  = null;
    $posterPath = null;
    if (!empty($_FILES['photograph']['name'])) {
        $photoPath = save_uploaded_image($_FILES['photograph'], 'uploads/funerals', 800, 85);
        if (!$photoPath) $errors[] = 'Photograph upload failed. JPEG/PNG/WebP, max 5 MB.';
    }
    if (!empty($_FILES['funeral_poster']['name'])) {
        $posterPath = save_uploaded_image($_FILES['funeral_poster'], 'uploads/funerals', 1200, 85);
        if (!$posterPath) $errors[] = 'Poster upload failed. JPEG/PNG/WebP, max 5 MB.';
    }

    if (!$errors) {
        $status = $feeEnabled ? 'pending_payment' : 'pending';
        $slug   = fa_slug($pdo, $data['deceased_name'] . ' ' . date('Y'));
        $pdo->prepare(
            "INSERT INTO funeral_announcements
             (user_id, deceased_name, gender, age, photograph, funeral_poster, date_of_birth, date_of_death, biography,
              wake_keeping_date, burial_date, thanksgiving_date, venue, gps_address,
              organizer_name, organizer_phone, organizer_email, status, slug)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $user['id'], $data['deceased_name'], $data['gender'] ?: 'male', $data['age'] ?: null,
            $photoPath, $posterPath, $data['date_of_birth'], $data['date_of_death'], $data['biography'],
            $data['wake_keeping_date'] ?: null, $data['burial_date'] ?: null, $data['thanksgiving_date'] ?: null,
            $data['venue'], $data['gps_address'], $data['organizer_name'], $data['organizer_phone'],
            $data['organizer_email'], $status, $slug
        ]);
        if ($feeEnabled) {
            $success = 'Submission received. Please contact the admin to complete payment of GH₵ ' . number_format($feeAmount, 2) . ' to publish your announcement.';
        } else {
            $success = 'Announcement submitted and is pending admin review.';
        }
    }
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
        .mf-shell   { max-width:800px; margin:0 auto; padding:20px 16px 60px; }
        .mf-list    { display:flex; flex-direction:column; gap:12px; margin-bottom:28px; }
        .mf-item    { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:14px 16px; display:flex; align-items:flex-start; gap:14px; }
        .mf-thumb   { width:56px; height:56px; border-radius:10px; background:#f3f4f6; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .mf-thumb img { width:100%; height:100%; object-fit:cover; }
        .mf-thumb-init { font-size:1.3rem; font-weight:900; color:#d1d5db; }
        .mf-info    { flex:1; min-width:0; }
        .mf-name    { font-weight:800; font-size:.95rem; margin:0 0 4px; }
        .mf-meta    { font-size:.78rem; color:var(--text-muted,#6b7280); }
        .mf-status  { display:inline-block; font-size:.68rem; font-weight:800; padding:3px 9px; border-radius:20px; margin-top:5px; }
        .mf-actions { display:flex; gap:8px; align-items:center; flex-shrink:0; flex-wrap:wrap; }

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

        <!-- Existing submissions -->
        <?php if ($myList): ?>
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
                        <?php if ($fa['burial_date']): ?>⚰️ <?php echo date('d M Y', strtotime($fa['burial_date'])); ?> &nbsp;·&nbsp;<?php endif; ?>
                        Submitted <?php echo date('d M Y', strtotime($fa['created_at'])); ?>
                    </p>
                    <span class="mf-status" style="color:<?php echo $sl['color']; ?>;background:<?php echo $sl['bg']; ?>;">
                        <?php echo $sl['label']; ?>
                    </span>
                    <?php if ($fa['status'] === 'pending_payment'): ?>
                    <p style="font-size:.78rem;color:#92400e;margin:6px 0 0;">Contact admin to pay GH₵ <?php echo number_format($feeAmount, 2); ?> for publication.</p>
                    <?php endif; ?>
                </div>
                <div class="mf-actions">
                    <?php if ($fa['status'] === 'approved' && $fa['slug']): ?>
                    <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="button button-small" target="_blank">View</a>
                    <?php endif; ?>
                    <?php if (in_array($fa['status'], ['pending_payment','pending','rejected'])): ?>
                    <form method="post" onsubmit="return confirm('Delete this announcement?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_id" value="<?php echo (int)$fa['id']; ?>">
                        <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Submit form -->
        <div class="mf-form-wrap">
            <h2>Submit a Funeral Announcement</h2>

            <?php if ($feeEnabled): ?>
            <div class="mf-fee-notice">
                💳 There is a <strong>GH₵ <?php echo number_format($feeAmount, 2); ?></strong> fee to publish a funeral announcement.
                Submit your details below and contact the admin to complete payment.
            </div>
            <?php endif; ?>

            <?php foreach ($errors as $e): ?>
            <div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_submit" value="1">

                <!-- Deceased info -->
                <div class="mf-field">
                    <label>Deceased Name *</label>
                    <input type="text" name="deceased_name" class="form-control" required placeholder="Full name">
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Gender</label>
                        <select name="gender" class="form-control">
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mf-field">
                        <label>Age at Death</label>
                        <input type="number" name="age" class="form-control" min="0" max="150" placeholder="e.g. 72">
                    </div>
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control">
                    </div>
                    <div class="mf-field">
                        <label>Date of Death</label>
                        <input type="date" name="date_of_death" class="form-control">
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
                    <textarea name="biography" class="form-control" rows="5" placeholder="Brief biography or tribute…"></textarea>
                </div>

                <p class="mf-section-label">Funeral Schedule</p>

                <div class="mf-row">
                    <div class="mf-field">
                        <label>Wake Keeping Date &amp; Time</label>
                        <input type="datetime-local" name="wake_keeping_date" class="form-control">
                    </div>
                    <div class="mf-field">
                        <label>Burial Date &amp; Time</label>
                        <input type="datetime-local" name="burial_date" class="form-control">
                    </div>
                </div>
                <div class="mf-field">
                    <label>Thanksgiving Service Date &amp; Time</label>
                    <input type="datetime-local" name="thanksgiving_date" class="form-control">
                </div>
                <div class="mf-field">
                    <label>Venue</label>
                    <input type="text" name="venue" class="form-control" placeholder="Church, hall or location name">
                </div>
                <div class="mf-field">
                    <label>GPS Address</label>
                    <input type="text" name="gps_address" class="form-control" placeholder="e.g. GA-123-4567">
                </div>

                <p class="mf-section-label">Organizer Contact</p>

                <div class="mf-field">
                    <label>Organizer / Family Contact Name</label>
                    <input type="text" name="organizer_name" class="form-control" placeholder="Name of point of contact">
                </div>
                <div class="mf-row">
                    <div class="mf-field">
                        <label>Phone</label>
                        <input type="tel" name="organizer_phone" class="form-control" placeholder="+233 …">
                    </div>
                    <div class="mf-field">
                        <label>Email</label>
                        <input type="email" name="organizer_email" class="form-control" placeholder="contact@example.com">
                    </div>
                </div>

                <button type="submit" class="button button-primary">Submit Announcement</button>
            </form>
        </div>
    </div>

<?php $activeNav = 'community'; require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

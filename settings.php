<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$towns = get_towns();
$error = '';
$section = $_GET['section'] ?? '';
$success = match($_GET['msg'] ?? '') {
    'profile_updated'       => 'Profile updated.',
    'location_updated'      => 'Location updated.',
    'password_updated'      => 'Password updated.',
    'notifications_updated' => 'Notification preferences updated.',
    'bio_updated'           => 'Bio and availability updated.',
    'id_updated'            => 'Identity information updated.',
    'skill_removed'         => 'Skill removed.',
    'skills_added'          => 'Skills added.',
    'message_sent'          => 'Message sent! We\'ll get back to you within 1–2 business days.',
    default                 => '',
};
$skillCategories = get_skill_categories_with_skills();
$pendingDeletionRequest = null;
$dr = $pdo->prepare("SELECT * FROM account_deletion_requests WHERE user_id=? AND status='pending' LIMIT 1");
$dr->execute([$user['id']]);
$pendingDeletionRequest = $dr->fetch() ?: null;
$workerProfile = null;
if ($user['role'] === 'worker') {
    $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
    $wpStmt->execute([$user['id']]);
    $workerProfile = $wpStmt->fetch() ?: null;
}

function settings_refresh_user(PDO $pdo, $userId) {
    $stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, role, phone, town_id, custom_town, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $fresh = $stmt->fetch();
    $_SESSION['user'] = $fresh;
    return $fresh;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_profile') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    if ($name === '' || $username === '' || $email === '' || $phone === '') {
        $error = 'Name, username, email and phone are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters: letters, numbers, underscores only.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } elseif (!empty($_FILES['profile_photo']['name']) && !is_valid_image_upload($_FILES['profile_photo'])) {
        $error = 'Profile picture must be a JPEG, PNG, or WEBP image under 5MB.';
    } else {
        $stmtEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmtEmail->execute([$email, $user['id']]);
        $stmtUser  = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $stmtUser->execute([$username, $user['id']]);
        $stmtPhone = $pdo->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
        $stmtPhone->execute([$phone, $user['id']]);
        if ($stmtEmail->fetch()) {
            $error = 'Another account already uses this email address.';
        } elseif ($stmtUser->fetch()) {
            $error = 'This username is already taken.';
        } elseif ($stmtPhone->fetch()) {
            $error = 'This phone number is already registered to another account.';
        } else {
            $pdo->prepare('UPDATE users SET name=?,username=?,email=?,phone=? WHERE id=?')->execute([$name,$username,$email,$phone,$user['id']]);
            if (!empty($_FILES['profile_photo']['name'])) {
                $pp = process_profile_image($_FILES['profile_photo'], 'uploads/profiles/' . $user['id']);
                if ($pp) $pdo->prepare('UPDATE users SET profile_photo=? WHERE id=?')->execute([$pp,$user['id']]);
                require_once __DIR__ . '/modules/referrals/service.php';
                award_points((int)$user['id'], 'profile_photo');
            }
            $user = settings_refresh_user($pdo, $user['id']);
            header("Location: settings.php?section={$section}&msg=profile_updated"); exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'location') {
    $townIdRaw   = $_POST['town_id'] ?? '';
    $customTown  = null;
    if ($townIdRaw === '__other__') {
        $customTown = trim($_POST['custom_town'] ?? '');
        $townId = $customTown !== '' ? get_other_town_id() : null;
    } else {
        $townId = intval($townIdRaw) ?: null;
    }
    $latitude  = ($_POST['latitude']  ?? '') !== '' ? (float)$_POST['latitude']  : ($user['latitude']  ?? null);
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : ($user['longitude'] ?? null);
    if (!$townId) {
        $error = ($townIdRaw === '__other__') ? 'Please specify your location.' : 'Please select your town.';
    } else {
        $pdo->prepare('UPDATE users SET town_id=?,custom_town=?,latitude=?,longitude=? WHERE id=?')->execute([$townId,$customTown,$latitude,$longitude,$user['id']]);
        $user = settings_refresh_user($pdo, $user['id']);
        header("Location: settings.php?section={$section}&msg=location_updated"); exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password']     ?? '';
    if ($newPassword === '' || strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
            header("Location: settings.php?section={$section}&msg=password_updated"); exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'notifications') {
    $pdo->prepare('UPDATE users SET email_notifications_enabled=? WHERE id=?')->execute([isset($_POST['email_notifications_enabled'])?1:0, $user['id']]);
    $user = settings_refresh_user($pdo, $user['id']);
    header("Location: settings.php?section={$section}&msg=notifications_updated"); exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_bio') {
    if ($workerProfile) {
        $bio   = trim($_POST['bio'] ?? '');
        $avail = in_array($_POST['availability'] ?? '', ['available','busy','on_leave'], true) ? $_POST['availability'] : 'available';
        $pdo->prepare('UPDATE worker_profiles SET bio=?,availability=? WHERE user_id=?')->execute([$bio,$avail,$user['id']]);
        $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id=?'); $wpStmt->execute([$user['id']]);
        $workerProfile = $wpStmt->fetch() ?: null;
        header("Location: settings.php?section={$section}&msg=bio_updated"); exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_id') {
    if ($workerProfile) {
        $idType   = $_POST['id_type']  ?? '';
        $idNumber = trim($_POST['id_number'] ?? '');
        if (!in_array($idType, ['ghana_card','passport'], true) || $idNumber === '') {
            $error = 'Select an ID type and enter your ID card number.';
        } elseif (!empty($_FILES['id_document']['name']) && !is_valid_image_upload($_FILES['id_document'])) {
            $error = 'ID photo must be a JPEG, PNG, or WEBP image under 5MB.';
        } else {
            $idDocPath = $workerProfile['id_document_path'];
            if (!empty($_FILES['id_document']['name'])) {
                $up = save_uploaded_image($_FILES['id_document'], 'uploads/worker_ids/' . $user['id']);
                if ($up) $idDocPath = $up;
            }
            $pdo->prepare('UPDATE worker_profiles SET id_type=?,id_number=?,id_document_path=? WHERE user_id=?')->execute([$idType,$idNumber,$idDocPath,$user['id']]);
            $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id=?'); $wpStmt->execute([$user['id']]);
            $workerProfile = $wpStmt->fetch() ?: null;
            header("Location: settings.php?section={$section}&msg=id_updated"); exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_remove_skill') {
    if ($workerProfile) {
        $skillId = intval($_POST['skill_id'] ?? 0);
        if ($skillId > 0) $pdo->prepare('DELETE FROM worker_skills WHERE id=? AND worker_profile_id=?')->execute([$skillId,$workerProfile['id']]);
        header("Location: settings.php?section={$section}&msg=skill_removed"); exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_add_skill') {
    if ($workerProfile) {
        $newSkills = json_decode($_POST['skills_json'] ?? '', true);
        if (is_array($newSkills) && $newSkills) {
            $skillStmt = $pdo->prepare('INSERT INTO worker_skills (worker_profile_id,category_id,skill_name) VALUES (?,?,?)');
            foreach ($newSkills as $entry) {
                if (!is_array($entry)) continue;
                $sn  = trim((string)($entry['skill_name'] ?? ''));
                $cid = intval($entry['category_id'] ?? 0) ?: null;
                $ccn = trim((string)($entry['category_name'] ?? ''));
                if ($cid === null && $ccn !== '') {
                    $cc = $pdo->prepare('SELECT id FROM skill_categories WHERE LOWER(name)=LOWER(?)'); $cc->execute([$ccn]);
                    $cid = ($cr = $cc->fetch()) ? (int)$cr['id'] : (function() use ($pdo,$ccn) { $pdo->prepare('INSERT INTO skill_categories (name) VALUES (?)')->execute([$ccn]); return (int)$pdo->lastInsertId(); })();
                }
                if ($sn !== '') $skillStmt->execute([$workerProfile['id'], $cid, $sn]);
            }
            header("Location: settings.php?section={$section}&msg=skills_added"); exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'contact_message') {
    $cfName  = trim($_POST['cf_name']    ?? $user['name']);
    $cfEmail = trim($_POST['cf_email']   ?? $user['email']);
    $cfSubj  = trim($_POST['cf_subject'] ?? '');
    $cfMsg   = trim($_POST['cf_body']    ?? '');
    if (!$cfSubj || strlen($cfMsg) < 10) {
        $error = 'Please complete all fields (message must be at least 10 characters).';
    } elseif (!filter_var($cfEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $mailBody = "Name: {$cfName}\nEmail: {$cfEmail}\nSubject: {$cfSubj}\n\nMessage:\n{$cfMsg}";
        @mail(ADMIN_EMAIL, '[AkuapemConnect] ' . $cfSubj, $mailBody, "From: " . MAIL_FROM . "\r\nReply-To: {$cfEmail}");
        header("Location: settings.php?section={$section}&msg=message_sent"); exit;
    }
}

$workerSkills = [];
if ($workerProfile) {
    $wsStmt = $pdo->prepare('SELECT ws.id,ws.skill_name,ws.category_id,sc.name AS category_name FROM worker_skills ws LEFT JOIN skill_categories sc ON ws.category_id=sc.id WHERE ws.worker_profile_id=? ORDER BY sc.name,ws.skill_name');
    $wsStmt->execute([$workerProfile['id']]);
    $workerSkills = $wsStmt->fetchAll();
}

$sectionMeta = [
    'profile'       => ['icon' => '✏️',  'title' => 'Profile Settings'],
    'worker'        => ['icon' => '🛠️', 'title' => 'Worker Profile'],
    'payments'      => ['icon' => '💳',  'title' => 'My Payments'],
    'password'      => ['icon' => '🔑',  'title' => 'Change Password'],
    'privacy'       => ['icon' => '🔒',  'title' => 'Privacy & Security'],
    'privacypolicy' => ['icon' => '📄',  'title' => 'Privacy Policy'],
    'terms'         => ['icon' => '📋',  'title' => 'Terms of Service'],
    'help'          => ['icon' => '❓',  'title' => 'Help & Support'],
    'contact'       => ['icon' => '📞',  'title' => 'Contact Us'],
    'about'         => ['icon' => 'ℹ️', 'title' => 'About AkuapemConnect'],
];
$activeSection = isset($sectionMeta[$section]) ? $section : '';

// Load payments data only when needed
$myPendingPayments = $myPaidPayments = $myFailedPayments = [];
if ($activeSection === 'payments') {
    $pyStmt = $pdo->prepare("
        SELECT pp.*,
            CASE pp.payment_type WHEN 'featured_job' THEN sr.title WHEN 'job_post' THEN sr2.title ELSE NULL END AS job_title,
            CASE pp.payment_type
                WHEN 'featured_job'    THEN COALESCE(fp.name,'—')
                WHEN 'featured_worker' THEN COALESCE(wp2.name,'—')
                WHEN 'verification'    THEN COALESCE(vp.name,'—')
                WHEN 'job_post'        THEN COALESCE(jp.name,'—')
                WHEN 'worker_service'  THEN COALESCE(ws.name,'—')
            END AS package_name
        FROM platform_payments pp
        LEFT JOIN service_requests sr  ON pp.payment_type='featured_job' AND sr.id =pp.reference_id
        LEFT JOIN service_requests sr2 ON pp.payment_type='job_post'     AND sr2.id=pp.reference_id
        LEFT JOIN featured_job_packages fp      ON pp.payment_type='featured_job'    AND fp.id =pp.package_id
        LEFT JOIN worker_promotion_packages wp2 ON pp.payment_type='featured_worker' AND wp2.id=pp.package_id
        LEFT JOIN verification_packages vp      ON pp.payment_type='verification'    AND vp.id =pp.package_id
        LEFT JOIN job_posting_packages jp       ON pp.payment_type='job_post'        AND jp.id =pp.package_id
        LEFT JOIN worker_service_packages ws    ON pp.payment_type='worker_service'  AND ws.id =pp.package_id
        WHERE pp.user_id=? ORDER BY pp.created_at DESC
    ");
    $pyStmt->execute([$user['id']]);
    $allPy = $pyStmt->fetchAll();
    $myPendingPayments = array_filter($allPy, fn($p) => $p['status'] === 'pending');
    $myPaidPayments    = array_filter($allPy, fn($p) => $p['status'] === 'paid');
    $myFailedPayments  = array_filter($allPy, fn($p) => in_array($p['status'],['failed','abandoned','refunded'],true));
}

$pyTypeLabel = [
    'featured_job'        => 'Featured Job',
    'featured_worker'     => 'Featured Profile',
    'verification'        => 'Verification Badge',
    'job_post'            => 'Job Posting Fee',
    'worker_service'      => 'Service Listing',
    'escrow_payment'      => 'Escrow Payment',
    'escrow_with_posting' => 'Escrow + Posting Fee',
];

$isAjax = !empty($_GET['ajax']) && $activeSection !== '';

if (!$isAjax): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $activeSection ? sanitize($sectionMeta[$activeSection]['title']).' — ' : ''; ?>Settings — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        /* ── Settings sidebar layout ──────────────────────── */
        .settings-shell { padding: 0 0 80px; }

        .sn-sidebar { display: block; }
        .sn-content { display: none; padding: 10px 12px; min-height: 200px; }
        body.has-section .sn-sidebar { display: none; }
        body.has-section .sn-content { display: block; }

        @media (min-width: 760px) {
            .settings-shell {
                display: grid;
                grid-template-columns: 228px 1fr;
                gap: 20px;
                max-width: 940px;
                margin: 0 auto;
                padding: 18px 16px 80px;
                align-items: start;
            }
            .sn-sidebar  { display: block !important; position: sticky; top: 62px; }
            .sn-content  { display: block !important; padding: 0; }
            .sn-back-btn { display: none !important; }
            .sn-mob-logout { display: none !important; }
        }

        /* ── Sidebar ─────────────────────────────────────── */
        .sn-user-card {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 14px; margin: 10px 12px 0;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px 10px 0 0; border-bottom: none;
        }
        @media (min-width: 760px) { .sn-user-card { margin: 0; } }
        .sn-user-info { overflow: hidden; min-width: 0; line-height: 1.3; }
        .sn-user-info strong { display: block; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sn-user-info em     { font-style: normal; font-size: .76rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }

        .sn-nav {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 0 0 10px 10px; overflow: hidden; margin: 0 12px;
        }
        @media (min-width: 760px) { .sn-nav { margin: 0; } }

        .sn-link {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; color: var(--text); text-decoration: none;
            font-size: .91rem; transition: background .12s;
        }
        .sn-link:hover  { background: var(--surface-muted); }
        .sn-link.sn-active { background: var(--primary-soft); color: var(--primary); font-weight: 600; }
        .sn-icon { width: 20px; text-align: center; flex-shrink: 0; }
        .sn-chev { margin-left: auto; color: var(--text-muted); font-size: .85rem; }
        @media (min-width: 760px) { .sn-chev { display: none; } }
        .sn-sep { height: 1px; background: var(--border); margin: 3px 0; }
        .sn-logout-link { color: #c0392b !important; }
        .sn-logout-link:hover { background: #fff5f5 !important; }

        /* ── Panel content ───────────────────────────────── */
        .sn-panel-title {
            font-size: 1.05rem; font-weight: 700;
            padding: 0 0 12px; margin: 0 0 14px;
            border-bottom: 1px solid var(--border); display: none;
        }
        @media (min-width: 760px) { .sn-panel-title { display: block; } }

        /* Divider between sub-sections inside a combined panel */
        .sn-sub-sep {
            border: none; border-top: 1px solid var(--border);
            margin: 20px 0;
        }
        .sn-sub-title {
            font-size: .92rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .06em;
            margin: 0 0 14px;
        }

        /* Legal / payments scroll box */
        .sn-scroll-box {
            max-height: 520px; overflow-y: auto;
            padding: 20px 22px; line-height: 1.75;
        }
        .sn-scroll-box h3 { font-size: .95rem; margin: 18px 0 6px; }
        .sn-scroll-box h3:first-child { margin-top: 0; }
        .sn-scroll-box p  { margin: 0 0 10px; font-size: .9rem; color: var(--text); }

        .sn-welcome {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; min-height: 260px;
            gap: 8px; color: var(--text-muted); text-align: center; padding: 24px;
        }
        .sn-welcome-icon { font-size: 2.6rem; margin-bottom: 4px; }
        .sn-loading { text-align: center; padding: 64px 0; color: var(--text-muted); font-size: .9rem; }

        /* Payment rows */
        .py-row {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 8px; padding: 12px; background: var(--surface);
            border-radius: 8px; border: 1px solid var(--border);
        }
        .py-row + .py-row { margin-top: 8px; }

        /* Header back/logout */
        .sn-back-btn {
            display: none; align-items: center; gap: 4px;
            color: var(--primary); font-weight: 600; text-decoration: none; font-size: .95rem;
        }
        body.has-section .sn-back-btn { display: flex; }
        body.has-section .sn-mob-logout { display: none; }
    </style>
</head>
<body class="has-bottom-nav<?php echo $activeSection ? ' has-section' : ''; ?>">

    <header class="app-topbar">
        <!-- <a href="settings.php" class="sn-back-btn">‹ Back</a> -->
        <span class="brand">⚙️ Settings</span>
        <a href="logout.php" class="button button-secondary button-small sn-mob-logout">Logout</a>
    </header>

    <div class="settings-shell">

        <!-- ── Sidebar ──────────────────────────────────── -->
        <aside class="sn-sidebar">
            <div class="sn-user-card">
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="" class="avatar avatar-md" />
                <?php else: ?>
                    <span class="avatar avatar-md"><?php echo sanitize(strtoupper(substr(display_name($user), 0, 1))); ?></span>
                <?php endif; ?>
                <div class="sn-user-info">
                    <strong><?php echo sanitize(display_name($user)); ?></strong>
                    <em><?php echo sanitize($user['email']); ?></em>
                </div>
            </div>

            <nav class="sn-nav">
                <?php
                $a = $activeSection;
                function snl(string $href, string $icon, string $label, string $section, string $active): string {
                    $cls = 'sn-link' . ($section === $active ? ' sn-active' : '');
                    $da  = $section ? ' data-section="' . htmlspecialchars($section, ENT_QUOTES) . '"' : '';
                    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="' . $cls . '"' . $da . '>'
                         . '<span class="sn-icon">' . $icon . '</span>'
                         . htmlspecialchars($label) . '<span class="sn-chev">›</span></a>';
                }
                ?>
                <!-- Group: Personal -->
                <?php echo snl('?section=profile',  '✏️',  'Profile Settings', 'profile',  $a); ?>
                <?php if ($user['role'] === 'worker'): ?>
                    <?php echo snl('?section=worker', '🛠️', 'Worker Profile',  'worker',   $a); ?>
                <?php endif; ?>
                <?php echo snl('?section=payments', '💳',  'My Payments',      'payments', $a); ?>

                <div class="sn-sep"></div>

                <!-- Group: Security -->
                <?php echo snl('?section=password', '🔑', 'Change Password',     'password', $a); ?>
                <?php echo snl('?section=privacy',  '🔒', 'Privacy & Security', 'privacy',  $a); ?>

                <div class="sn-sep"></div>

                <!-- Group: Legal -->
                <?php echo snl('?section=privacypolicy', '📄', 'Privacy Policy',   'privacypolicy', $a); ?>
                <?php echo snl('?section=terms',         '📋', 'Terms of Service', 'terms',         $a); ?>

                <div class="sn-sep"></div>

                <!-- Group: Support -->
                <?php echo snl('?section=help',    '❓',  'Help & Support',  'help',    $a); ?>
                <?php echo snl('?section=contact', '📞',  'Contact Us',      'contact', $a); ?>
                <?php echo snl('?section=about',   'ℹ️', 'About AkuapemConnect', 'about',   $a); ?>

                <div class="sn-sep"></div>

                <a href="logout.php" class="sn-link sn-logout-link">
                    <span class="sn-icon">🚪</span>Logout<span class="sn-chev">›</span>
                </a>
            </nav>
        </aside>

        <!-- ── Content ──────────────────────────────────── -->
        <div id="sn-content" class="sn-content">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo sanitize($success); ?></div>
            <?php endif; ?>

<?php endif; // !$isAjax — layout wrapper open ?>

<?php /* ═══════════════ PANEL CONTENT ═══════════════ */ ?>

<?php if ($activeSection === 'profile'): ?>
<h2 class="sn-panel-title">✏️ Profile Settings</h2>

<!-- Edit Profile -->
<h3 class="sn-sub-title">Edit Profile</h3>
<form class="card form-card" method="post" action="settings.php?section=profile" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="edit_profile" />
    <?php if (!empty($user['profile_photo'])): ?>
        <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile photo" style="width:72px;height:72px;border-radius:50%;object-fit:cover;margin-bottom:8px;" />
    <?php endif; ?>
    <label>Profile picture</label>
    <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" />
    <p class="meta">JPEG, PNG, or WEBP, up to 5 MB. Leave blank to keep current picture.</p>
    <label>Full name</label>
    <input type="text" name="name" value="<?php echo sanitize($user['name']); ?>" required />
    <label>Username</label>
    <input type="text" name="username" value="<?php echo sanitize($user['username'] ?? ''); ?>" required pattern="[a-zA-Z0-9_]{3,30}" title="3–30 characters: letters, numbers, underscores" placeholder="e.g. kwame_builds" />
    <p class="meta" style="margin-top:4px;">Shown to other users instead of your real name.</p>
    <label>Email</label>
    <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required />
    <label>Phone number</label>
    <input type="text" name="phone" value="<?php echo sanitize($user['phone'] ?? ''); ?>" required placeholder="e.g. 0244000000" />
    <p class="meta">Used as your contact info across the app — requests, worker contact, and notifications all use it.</p>
    <button type="submit" class="button button-primary">Save profile</button>
</form>

<hr class="sn-sub-sep" />

<!-- Location -->
<h3 class="sn-sub-title">Location</h3>
<form class="card form-card" method="post" action="settings.php?section=profile">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="location" />
    <?php $isOtherTown = !empty($user['custom_town']); ?>
    <label>Town</label>
    <select name="town_id" required onchange="document.getElementById('custom-town-row').style.display=this.value==='__other__'?'block':'none';">
        <option value="">Select your town</option>
        <?php $currentDistrict = null; ?>
        <?php foreach ($towns as $town): ?>
            <?php if ($town['district'] !== $currentDistrict): ?>
                <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                <optgroup label="<?php echo sanitize($town['district']); ?>">
                <?php $currentDistrict = $town['district']; ?>
            <?php endif; ?>
            <option value="<?php echo $town['id']; ?>" <?php echo (!$isOtherTown && (int)($user['town_id'] ?? 0) === (int)$town['id']) ? 'selected' : ''; ?>><?php echo sanitize($town['name']); ?></option>
        <?php endforeach; ?>
        <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
        <option value="__other__" <?php echo $isOtherTown ? 'selected' : ''; ?>>Other (outside Akuapem — specify)</option>
    </select>
    <div id="custom-town-row" style="display:<?php echo $isOtherTown ? 'block' : 'none'; ?>;margin-top:8px;">
        <input type="text" name="custom_town" placeholder="Enter your town/city" value="<?php echo sanitize($user['custom_town'] ?? ''); ?>" />
    </div>
    <input type="hidden" name="latitude"  id="latitude"  value="<?php echo $user['latitude']  !== null ? sanitize($user['latitude'])  : ''; ?>" />
    <input type="hidden" name="longitude" id="longitude" value="<?php echo $user['longitude'] !== null ? sanitize($user['longitude']) : ''; ?>" />
    <button type="button" id="use-my-location" class="button button-secondary button-small">Update my GPS location</button>
    <p class="meta" id="location-status"><?php echo $user['latitude'] !== null ? 'Saved coordinates: ' . sanitize($user['latitude']) . ', ' . sanitize($user['longitude']) : 'No coordinates saved — sharing your location helps with nearby matching.'; ?></p>
    <button type="submit" class="button button-primary">Save location</button>
</form>
<script>
(function() {
    var btn = document.getElementById('use-my-location'); if (!btn) return;
    btn.addEventListener('click', function() {
        var s = document.getElementById('location-status');
        if (!navigator.geolocation) { s.textContent = 'Geolocation is not supported by your browser.'; return; }
        s.textContent = 'Locating…';
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('latitude').value  = pos.coords.latitude;
            document.getElementById('longitude').value = pos.coords.longitude;
            s.textContent = 'Captured: ' + pos.coords.latitude.toFixed(5) + ', ' + pos.coords.longitude.toFixed(5) + '. Click "Save location" to store.';
        }, function() { s.textContent = 'Unable to retrieve your location. Please allow location access and try again.'; });
    });
})();
</script>

<hr class="sn-sub-sep" />

<!-- Notifications -->
<h3 class="sn-sub-title">Notifications</h3>
<form class="card form-card" method="post" action="settings.php?section=profile">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="notifications" />
    <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;font-weight:normal;">
        <input type="checkbox" name="email_notifications_enabled" <?php echo !empty($user['email_notifications_enabled']) ? 'checked' : ''; ?> />
        Email me about job updates, requests, and account activity
    </label>
    <p class="meta">In-app notifications always continue regardless of this setting.</p>
    <button type="submit" class="button button-primary">Save preferences</button>
</form>

<hr class="sn-sub-sep" />

<!-- Role -->
<h3 class="sn-sub-title">Role</h3>
<section class="card form-card">
    <?php if ($user['role'] === 'customer'): ?>
        <p class="meta">You're registered as a customer. Want to offer services on AkuapemConnect?</p>
        <a href="become_worker.php" class="button button-primary">Become a worker</a>
    <?php elseif ($user['role'] === 'worker'): ?>
        <p class="meta">You're registered as a worker. Manage your skills, availability, and bio from your worker profile.</p>
        <a href="worker_profile.php" class="button button-secondary" style="margin-bottom:12px;">Manage worker profile</a>
        <p class="meta">Switching to customer mode hides your worker profile from job matching. Your profile and skills are kept — switch back any time.</p>
        <form method="post" action="switch_to_customer.php" onsubmit="return confirm('Switch to customer mode? Your worker profile will be kept and you can switch back later.');">
            <?php echo csrf_field(); ?>
            <button type="submit" class="button button-secondary">Switch to customer mode</button>
        </form>
    <?php endif; ?>
</section>

<?php elseif ($activeSection === 'worker'): ?>
<h2 class="sn-panel-title">🛠️ Worker Profile</h2>
<?php if (!$workerProfile): ?>
    <div class="alert alert-info">You don't have a worker profile yet. <a href="become_worker.php" style="color:var(--primary);">Become a worker</a> to create one.</div>
<?php else: ?>
    <form class="card form-card" method="post" action="settings.php?section=worker" style="margin-bottom:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="worker_bio" />
        <h3 style="font-size:.95rem;margin:0 0 10px;font-weight:600;">Bio &amp; Availability</h3>
        <label>About you <span class="meta">(optional)</span></label>
        <textarea name="bio" class="rich-editor" rows="3" placeholder="Briefly describe your experience and what makes you a great worker…"><?php echo $workerProfile['bio']; ?></textarea>
        <label>Availability</label>
        <select name="availability">
            <option value="available" <?php echo $workerProfile['availability']==='available'?'selected':''; ?>>Available for work</option>
            <option value="busy"      <?php echo $workerProfile['availability']==='busy'     ?'selected':''; ?>>Busy — not taking new jobs</option>
            <option value="on_leave"  <?php echo $workerProfile['availability']==='on_leave' ?'selected':''; ?>>On leave</option>
        </select>
        <button type="submit" class="button button-primary">Save</button>
    </form>

    <form class="card form-card" method="post" action="settings.php?section=worker" enctype="multipart/form-data" style="margin-bottom:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="worker_id" />
        <h3 style="font-size:.95rem;margin:0 0 10px;font-weight:600;">Identity Verification</h3>
        <p class="meta">Your ID is never shared publicly — used for trust &amp; safety only.</p>
        <label>ID type</label>
        <select name="id_type" required>
            <option value="">Select ID type</option>
            <option value="ghana_card" <?php echo $workerProfile['id_type']==='ghana_card'?'selected':''; ?>>Ghana Card</option>
            <option value="passport"   <?php echo $workerProfile['id_type']==='passport'  ?'selected':''; ?>>Passport</option>
        </select>
        <label>ID card number</label>
        <input type="text" name="id_number" value="<?php echo sanitize($workerProfile['id_number'] ?? ''); ?>" required placeholder="e.g. GHA-000000000-0" />
        <label>New ID photo <span class="meta">(leave blank to keep current)</span></label>
        <input type="file" name="id_document" accept="image/jpeg,image/png,image/webp" />
        <?php if (!empty($workerProfile['id_document_path'])): ?>
            <p class="meta" style="margin-top:4px;">Current document on file — <a href="<?php echo sanitize($workerProfile['id_document_path']); ?>" target="_blank" style="color:var(--primary);">View</a></p>
        <?php endif; ?>
        <button type="submit" class="button button-primary">Update ID</button>
    </form>

    <div class="card form-card" style="margin-bottom:14px;">
        <h3 style="font-size:.95rem;margin:0 0 10px;font-weight:600;">Your Skills</h3>
        <?php if ($workerSkills): ?>
            <p class="meta" style="margin-bottom:10px;">Tap × next to any skill to remove it.</p>
            <ul style="list-style:none;padding:0;margin:0 0 18px;display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($workerSkills as $sk): ?>
                <li class="badge" style="display:inline-flex;align-items:center;gap:5px;font-size:.88rem;">
                    <?php if ($sk['category_name']): ?><span style="font-size:.78rem;color:var(--text-muted);font-weight:normal;"><?php echo sanitize($sk['category_name']); ?>:</span><?php endif; ?>
                    <?php echo sanitize($sk['skill_name']); ?>
                    <form method="post" action="settings.php?section=worker" style="display:contents;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form" value="worker_remove_skill" />
                        <input type="hidden" name="skill_id" value="<?php echo $sk['id']; ?>" />
                        <button type="submit" onclick="return confirm('Remove <?php echo addslashes(sanitize($sk['skill_name'])); ?>?')" style="border:none;background:transparent;cursor:pointer;font-size:1.05rem;line-height:1;padding:0 0 0 2px;color:var(--text-muted);">×</button>
                    </form>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="meta" style="margin-bottom:12px;">No skills added yet.</p>
        <?php endif; ?>
        <form method="post" action="settings.php?section=worker" id="add-skill-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form" value="worker_add_skill" />
            <h4 style="font-size:.9rem;margin:0 0 6px;font-weight:600;">Add skills</h4>
            <label>Category</label>
            <select id="skill-category-select">
                <option value="">Select a category</option>
                <?php foreach ($skillCategories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo sanitize($cat['name']); ?></option>
                <?php endforeach; ?>
                <option value="__other__">Other (specify)</option>
            </select>
            <div id="other-category-wrap" style="display:none;">
                <label>Describe your category</label>
                <input type="text" id="other-category-input" placeholder="e.g. Welding, Pool cleaning, Borehole drilling" />
            </div>
            <label id="skill-label">Skill</label>
            <select id="skill-select" disabled><option value="">Select a category first</option></select>
            <div id="other-skill-wrap" style="display:none;">
                <label>Specify your skill</label>
                <input type="text" id="other-skill-input" placeholder="e.g. Arc welding, Filter cleaning" />
            </div>
            <button type="button" id="add-skill-button" class="button button-secondary button-small" style="margin-top:10px;">+ Add to list</button>
            <ul id="skill-list" style="list-style:none;padding:0;margin:10px 0 0;display:flex;flex-wrap:wrap;gap:8px;"></ul>
            <input type="hidden" name="skills_json" id="skills-json" value="[]" />
            <button type="submit" id="save-skills-button" class="button button-primary" style="display:none;margin-top:14px;">Save added skills</button>
        </form>
    </div>
<?php endif; ?>
<script>
(function() {
    var skillTaxonomy = <?php echo json_encode($skillCategories); ?>;
    var catSel=document.getElementById('skill-category-select'), skillSel=document.getElementById('skill-select'),
        otherWrap=document.getElementById('other-skill-wrap'), otherInput=document.getElementById('other-skill-input'),
        otherCatWrap=document.getElementById('other-category-wrap'), otherCatInput=document.getElementById('other-category-input'),
        skillLabel=document.getElementById('skill-label'), addBtn=document.getElementById('add-skill-button'),
        skillList=document.getElementById('skill-list'), skillsJson=document.getElementById('skills-json'),
        saveBtn=document.getElementById('save-skills-button'), pending=[];
    if (!catSel) return;
    function findCat(id) { for(var i=0;i<skillTaxonomy.length;i++) if(String(skillTaxonomy[i].id)===String(id)) return skillTaxonomy[i]; return null; }
    catSel.addEventListener('change', function() {
        otherCatWrap.style.display='none'; otherCatInput.value=''; otherWrap.style.display='none'; otherInput.value='';
        if(this.value==='__other__'){ otherCatWrap.style.display='block'; otherCatInput.focus(); skillLabel.style.display='none'; skillSel.style.display='none'; skillSel.disabled=true; otherWrap.style.display='block'; return; }
        skillLabel.style.display=''; skillSel.style.display=''; skillSel.innerHTML='';
        var cat=findCat(this.value);
        if(!cat){skillSel.disabled=true; skillSel.innerHTML='<option value="">Select a category first</option>'; return;}
        skillSel.disabled=false;
        var b=document.createElement('option'); b.value=''; b.textContent='Select a skill'; skillSel.appendChild(b);
        cat.skills.forEach(function(s){ var o=document.createElement('option'); o.value=s; o.textContent=s; skillSel.appendChild(o); });
        var oth=document.createElement('option'); oth.value='__other__'; oth.textContent='Other (specify)'; skillSel.appendChild(oth);
    });
    skillSel.addEventListener('change', function(){ var io=this.value==='__other__'; otherWrap.style.display=io?'block':'none'; if(io)otherInput.focus(); });
    function renderPending() {
        skillList.innerHTML=''; saveBtn.style.display=pending.length>0?'inline-flex':'none';
        pending.forEach(function(sk,idx){
            var li=document.createElement('li'); li.className='badge'; li.style.cssText='display:inline-flex;align-items:center;gap:6px;font-size:.88rem;';
            li.textContent=(sk.category_name?sk.category_name+': ':'')+sk.skill_name;
            var btn=document.createElement('button'); btn.type='button'; btn.textContent='×'; btn.style.cssText='border:none;background:transparent;cursor:pointer;font-size:1rem;padding:0 0 0 2px;color:var(--text-muted);';
            btn.addEventListener('click',function(){pending.splice(idx,1);renderPending();}); li.appendChild(btn); skillList.appendChild(li);
        }); skillsJson.value=JSON.stringify(pending);
    }
    addBtn.addEventListener('click', function() {
        var cid, cn, sn;
        if(catSel.value==='__other__'){cn=otherCatInput.value.trim();if(!cn){otherCatInput.focus();return;} sn=otherInput.value.trim();if(!sn){otherInput.focus();return;} cid=null;}
        else{var cat=findCat(catSel.value);if(!cat){catSel.focus();return;} cid=cat.id;cn=cat.name; sn=skillSel.value==='__other__'?otherInput.value.trim():skillSel.value;if(!sn){skillSel.focus();return;}}
        if(!pending.some(function(s){return s.category_name.toLowerCase()===cn.toLowerCase()&&s.skill_name.toLowerCase()===sn.toLowerCase();}))
            {pending.push({category_id:cid,category_name:cn,skill_name:sn});renderPending();}
        if(catSel.value==='__other__'){otherInput.value='';otherInput.focus();}else{otherInput.value='';otherWrap.style.display='none';skillSel.value='';}
    });
    renderPending();
})();
</script>

<?php elseif ($activeSection === 'payments'): ?>
<h2 class="sn-panel-title">💳 My Payments</h2>
<?php if (empty($myPendingPayments) && empty($myPaidPayments) && empty($myFailedPayments)): ?>
    <div class="empty-state">You have no platform payment history yet.</div>
<?php else: ?>
    <?php if (!empty($myPendingPayments)): ?>
        <h3 class="sn-sub-title" style="color:#f59e0b;">⏳ Pending</h3>
        <?php foreach ($myPendingPayments as $p): ?>
            <?php $isPs = ($p['gateway'] ?? 'manual') === 'paystack'; ?>
            <div style="padding:14px;border:2px solid #f59e0b;border-radius:10px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div>
                        <strong><?php echo sanitize($pyTypeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                        <?php if ($p['package_name'] && $p['package_name'] !== '—'): ?> — <?php echo sanitize($p['package_name']); ?><?php endif; ?>
                        <?php if ($p['job_title']): ?><br><span class="meta">📋 <?php echo sanitize(mb_strimwidth($p['job_title'],0,50,'…')); ?></span><?php endif; ?>
                    </div>
                    <strong style="color:var(--primary);white-space:nowrap;">GH₵ <?php echo number_format($p['amount'],2); ?></strong>
                </div>
                <div style="margin-top:10px;padding:10px;background:var(--surface);border-radius:8px;">
                    <?php if ($isPs): ?>
                        <p style="margin:0 0 4px;font-size:.85rem;color:#f59e0b;">🔒 Paystack payment initiated — awaiting confirmation</p>
                    <?php endif; ?>
                    <p class="meta" style="margin:0;font-size:.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo date('d M Y, H:i', strtotime($p['created_at'])); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="sn-sub-sep" style="margin:14px 0;"></div>
    <?php endif; ?>

    <?php if (!empty($myPaidPayments)): ?>
        <h3 class="sn-sub-title" style="color:#16a34a;">✓ Confirmed</h3>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
        <?php foreach ($myPaidPayments as $p): ?>
            <div class="py-row">
                <div>
                    <strong style="font-size:.93rem;"><?php echo sanitize($pyTypeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                    <?php if ($p['package_name'] && $p['package_name'] !== '—'): ?><span class="meta"> — <?php echo sanitize($p['package_name']); ?></span><?php endif; ?>
                    <?php if ($p['job_title']): ?><br><span class="meta">📋 <?php echo sanitize(mb_strimwidth($p['job_title'],0,40,'…')); ?></span><?php endif; ?>
                    <br><span class="meta" style="font-size:.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo date('d M Y', strtotime($p['paid_at'] ?: $p['created_at'])); ?></span>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <strong style="color:var(--primary);">GH₵ <?php echo number_format($p['amount'],2); ?></strong>
                    <br><span class="status status-open" style="font-size:.72rem;">PAID</span>
                    <?php if (!empty($p['paystack_reference'])): ?>
                        <br><a href="platform_receipt.php?ref=<?php echo urlencode($p['paystack_reference']); ?>" style="font-size:.75rem;color:var(--primary);">View receipt</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($myFailedPayments)): ?>
        <h3 class="sn-sub-title" style="color:#c0392b;">✗ Failed / Abandoned</h3>
        <p class="meta">These payments did not complete. Contact support if you believe this is an error.</p>
        <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($myFailedPayments as $p): ?>
            <div class="py-row" style="opacity:.75;">
                <div>
                    <strong style="font-size:.93rem;"><?php echo sanitize($pyTypeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                    <br><span class="meta" style="font-size:.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo date('d M Y', strtotime($p['created_at'])); ?></span>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <strong>GH₵ <?php echo number_format($p['amount'],2); ?></strong>
                    <br><span class="status status-cancelled" style="font-size:.72rem;"><?php echo strtoupper($p['status']); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php elseif ($activeSection === 'password'): ?>
<h2 class="sn-panel-title">🔑 Change Password</h2>
<form class="card form-card" method="post" action="settings.php?section=password">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="password" />
    <label>Current password</label>
    <input type="password" name="current_password" placeholder="Required to set a new password" />
    <label>New password</label>
    <input type="password" name="new_password" minlength="6" placeholder="At least 6 characters" />
    <button type="submit" class="button button-primary">Change password</button>
</form>

<?php elseif ($activeSection === 'privacy'): ?>
<h2 class="sn-panel-title">🔒 Privacy &amp; Security</h2>
<section class="card form-card">
    <?php if ($pendingDeletionRequest): ?>
        <p class="meta">Your account details are only shared with the customers and workers you transact with.</p>
        <div class="alert alert-info">
            <strong>Account closure request pending review.</strong><br>
            Submitted <?php echo sanitize(date('d M Y', strtotime($pendingDeletionRequest['created_at']))); ?>. An admin will review your request and reason — you'll be notified once it's processed.
        </div>
    <?php else: ?>
        <p class="meta">Your account details are only shared with the customers and workers you transact with. Closing your account submits a request for admin review — your account stays active until it's approved.</p>
        <form method="post" action="delete_account.php" onsubmit="return confirm('Submit a request to close your account? An admin will review it before anything happens to your account.');">
            <?php echo csrf_field(); ?>
            <label>Why do you want to close your account?</label>
            <textarea name="reason" required rows="3" placeholder="Let us know your reason — this helps admin review your request."></textarea>
            <label>Confirm your password</label>
            <input type="password" name="current_password" required placeholder="Enter your password to confirm" />
            <button type="submit" class="button button-secondary" style="color:#c0392b;border-color:#c0392b;">Request account closure</button>
        </form>
    <?php endif; ?>
</section>

<?php elseif ($activeSection === 'privacypolicy'): ?>
<h2 class="sn-panel-title">📄 Privacy Policy</h2>
<div class="card sn-scroll-box">
    <?php include "privacy_short.php"?>
</div>

<?php elseif ($activeSection === 'terms'): ?>
<h2 class="sn-panel-title">📋 Terms of Service</h2>
<div class="card sn-scroll-box">
    <?php include "terms_short.php"?>
</div>

<?php elseif ($activeSection === 'help'): ?>
<h2 class="sn-panel-title">❓ Help &amp; Support</h2>
<section class="card form-card">
    <?php include "support_short.php"?>
</section>
<!-- <section class="card" style="padding:16px 18px;margin-top:0;">
    <p style="margin:0 0 4px;font-size:.85rem;font-weight:600;color:var(--text-muted);">Quick links</p>
    <a href="jobs.php"   class="list-row" style="display:block;padding:9px 0;border-bottom:1px solid var(--border);">← Back to Dashboard</a>
    <a href="find_workers.php" class="list-row" style="display:block;padding:9px 0;border-bottom:1px solid var(--border);">Find Workers</a>
    <a href="notifications.php" class="list-row" style="display:block;padding:9px 0;">Notifications</a>
</section> -->

<?php elseif ($activeSection === 'contact'): ?>
<?php
    $ci = [
        'email'    => get_platform_setting('contact_email')    ?: MAIL_FROM,
        'phone'    => get_platform_setting('contact_phone')    ?: '',
        'whatsapp' => get_platform_setting('contact_whatsapp') ?: '',
        'address'  => get_platform_setting('contact_address')  ?: 'Akuapem Area, Eastern Region, Ghana',
        'hours'    => get_platform_setting('contact_hours')    ?: 'Monday – Saturday, 8:00 AM – 6:00 PM GMT',
        'tagline'  => get_platform_setting('contact_tagline')  ?: "We're here to help. Reach out and we'll respond as soon as possible.",
    ];
    $waLink = $ci['whatsapp'] ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $ci['whatsapp']) : '';
?>
<h2 class="sn-panel-title">📞 Contact Us</h2>

<div class="card" style="padding:18px 20px;margin-bottom:10px;">
    <p style="margin:0 0 16px;font-size:.92rem;color:var(--text-muted);"><?php echo sanitize($ci['tagline']); ?></p>

    <?php
    $contactItems = [
        ['📧', 'Email', '<a href="mailto:' . sanitize($ci['email']) . '">' . sanitize($ci['email']) . '</a>'],
    ];
    if ($ci['phone'])    $contactItems[] = ['📞', 'Phone',     '<a href="tel:' . sanitize(preg_replace('/[^0-9+]/', '', $ci['phone'])) . '">' . sanitize($ci['phone']) . '</a>'];
    if ($ci['whatsapp']) $contactItems[] = ['💬', 'WhatsApp',  '<a href="' . sanitize($waLink) . '" target="_blank" rel="noopener">' . sanitize($ci['whatsapp']) . '</a>'];
    $contactItems[] = ['📍', 'Address', nl2br(sanitize($ci['address']))];
    $contactItems[] = ['🕐', 'Hours',   sanitize($ci['hours'])];
    ?>

    <?php foreach ($contactItems as [$icon, $label, $value]): ?>
    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:14px;">
        <div style="width:36px;height:36px;border-radius:8px;background:var(--primary-soft,#e6f4f1);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;"><?php echo $icon; ?></div>
        <div>
            <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:2px;"><?php echo $label; ?></div>
            <div style="font-size:.9rem;"><?php echo $value; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card form-card">
    <h3 style="margin:0 0 14px;font-size:1rem;">Send us a message</h3>

    <?php if ($success && ($_POST['form'] ?? '') === 'contact_message'): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php else: ?>
        <?php if ($error && ($_POST['form'] ?? '') === 'contact_message'): ?>
            <div class="alert alert-error" style="margin-bottom:12px;"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <form method="post" action="settings.php?section=contact">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form" value="contact_message">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label for="sn-cf-name" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Your name</label>
                    <input type="text" id="sn-cf-name" name="cf_name" class="form-control"
                           value="<?php echo sanitize($_POST['cf_name'] ?? $user['name']); ?>" required>
                </div>
                <div>
                    <label for="sn-cf-email" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Email address</label>
                    <input type="email" id="sn-cf-email" name="cf_email" class="form-control"
                           value="<?php echo sanitize($_POST['cf_email'] ?? $user['email']); ?>" required>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label for="sn-cf-subj" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Subject</label>
                <select id="sn-cf-subj" name="cf_subject" class="form-control" required>
                    <option value="">— Select a topic —</option>
                    <?php foreach (['Payment issue','Account help','Worker verification','Job dispute','Report a user','General enquiry','Other'] as $opt): ?>
                        <option value="<?php echo sanitize($opt); ?>" <?php echo (($_POST['cf_subject'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo sanitize($opt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label for="sn-cf-body" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Message</label>
                <textarea id="sn-cf-body" name="cf_body" class="form-control" rows="5"
                          placeholder="Describe your question or issue in detail…" required><?php echo sanitize($_POST['cf_body'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="button button-primary" style="min-width:140px;">Send message</button>
        </form>
    <?php endif; ?>
</div>

<?php elseif ($activeSection === 'about'): ?>
 <h2 class="sn-panel-title">ℹ️ About AkuapemConnect</h2>

 <section class="card form-card">
    <?php include "about_short.php"?>
</section>
<!-- <div class="card" style="padding:20px 22px;margin-bottom:0;">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
        <span style="font-size:2.4rem;line-height:1;">🏔️</span>
        <div>
            <h3 style="margin:0 0 3px;font-size:1.1rem;">AkuapemConnect</h3>
            <span class="meta">Version 1.0 · Built for the Akuapem community</span>
        </div>
    </div>
    <p style="margin:0;font-size:.92rem;color:var(--text);line-height:1.7;">AkuapemConnect connects people across the Akuapem ridge with skilled workers and service providers. Whether you need a plumber, electrician, cleaner, caterer, delivery rider, or any other service — AkuapemConnect helps you find trusted help close to home.</p>
</div> -->
<!--

<?php elseif ($activeSection === 'help'): ?>
<h2 class="sn-panel-title">❓ Help &amp; Support</h2>
<section class="card form-card">
    <?php include "support_short.php"?>
</section>



<div class="card" style="margin-top:10px;padding:20px 22px;margin-bottom:0;">
    <h3 style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 12px;">What you can do</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div style="padding:12px;background:var(--surface);border-radius:8px;font-size:.88rem;">
            <div style="font-size:1.3rem;margin-bottom:4px;">📋</div>
            <strong>Post jobs</strong>
            <p class="meta" style="margin:2px 0 0;">Describe what you need and let workers apply</p>
        </div>
        <div style="padding:12px;background:var(--surface);border-radius:8px;font-size:.88rem;">
            <div style="font-size:1.3rem;margin-bottom:4px;">🔍</div>
            <strong>Find workers</strong>
            <p class="meta" style="margin:2px 0 0;">Browse verified local professionals by skill</p>
        </div>
        <div style="padding:12px;background:var(--surface);border-radius:8px;font-size:.88rem;">
            <div style="font-size:1.3rem;margin-bottom:4px;">💰</div>
            <strong>Secure escrow</strong>
            <p class="meta" style="margin:2px 0 0;">Pay safely — funds released on completion</p>
        </div>
        <div style="padding:12px;background:var(--surface);border-radius:8px;font-size:.88rem;">
            <div style="font-size:1.3rem;margin-bottom:4px;">✅</div>
            <strong>Verified workers</strong>
            <p class="meta" style="margin:2px 0 0;">ID-checked workers for extra peace of mind</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:10px;padding:16px 22px;margin-bottom:0;">
    <h3 style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px;">Platform info</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.88rem;">
        <div><span class="meta">Version</span><strong style="display:block;">1.0</strong></div>
        <div><span class="meta">Coverage</span><strong style="display:block;">Akuapem Ridge, Ghana</strong></div>
        <div><span class="meta">Currency</span><strong style="display:block;">Ghana Cedi (GH₵)</strong></div>
        <div><span class="meta">Payments</span><strong style="display:block;">Paystack</strong></div>
    </div>
</div>

<div class="card" style="margin-top:10px;padding:14px 18px;">
    <a href="contact.php"  class="list-row" style="display:block;padding:9px 0;border-bottom:1px solid var(--border);">Contact us</a>
    <a href="privacy.php"  class="list-row" style="display:block;padding:9px 0;border-bottom:1px solid var(--border);">Privacy Policy</a>
    <a href="terms.php"    class="list-row" style="display:block;padding:9px 0;">Terms of Service</a>
</div> -->

<?php else: ?>
<div class="sn-welcome">
    <span class="sn-welcome-icon">⚙️</span>
    <strong style="font-size:1rem;">Settings</strong>
    <p style="margin:0;font-size:.88rem;">Select a category from the left to get started.</p>
</div>
<?php endif; // activeSection ?>

<?php if (!$isAjax): ?>
        </div><!-- #sn-content -->
    </div><!-- .settings-shell -->

    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <script src="assets/js/image-compress.js"></script>
    <script>
    (function() {
        function runInjectedScripts(container) {
            container.querySelectorAll('script').forEach(function(old) {
                var s = document.createElement('script'); s.textContent = old.textContent;
                document.head.appendChild(s); document.head.removeChild(s);
            });
        }
        function initImageCompress(container) {
            var input = (container || document).querySelector('input[name="profile_photo"]');
            if (input && typeof setupImageInput === 'function') setupImageInput(input, 800, 800, 0.82);
        }
        function loadPanel(section) {
            var content = document.getElementById('sn-content');
            content.innerHTML = '<div class="sn-loading">Loading…</div>';
            document.querySelectorAll('.sn-nav .sn-link[data-section]').forEach(function(a) { a.classList.remove('sn-active'); });
            var active = document.querySelector('.sn-nav a[data-section="' + section + '"]');
            if (active) active.classList.add('sn-active');
            fetch('settings.php?section=' + encodeURIComponent(section) + '&ajax=1')
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    content.innerHTML = html;
                    runInjectedScripts(content);
                    initImageCompress(content);
                    history.replaceState(null, '', 'settings.php?section=' + encodeURIComponent(section));
                })
                .catch(function() {
                    content.innerHTML = '<div class="alert alert-error">Failed to load. Please try again.</div>';
                });
        }
        document.querySelectorAll('.sn-nav a[data-section]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (window.innerWidth < 760) return;
                e.preventDefault();
                loadPanel(this.dataset.section);
            });
        });
        if (window.innerWidth >= 760 && !<?php echo json_encode((bool)$activeSection); ?>) {
            loadPanel('profile');
        }
        if (<?php echo json_encode((bool)$activeSection); ?>) {
            initImageCompress(document.getElementById('sn-content'));
        }
    })();
    </script>
</body>
</html>
<?php endif; // !$isAjax ?>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$towns = get_towns();
$error = '';
$success = '';
$section = $_GET['section'] ?? '';
$skillCategories = get_skill_categories_with_skills();
$workerProfile = null;
if ($user['role'] === 'worker') {
    $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
    $wpStmt->execute([$user['id']]);
    $workerProfile = $wpStmt->fetch() ?: null;
}

function settings_refresh_user(PDO $pdo, $userId) {
    $stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $fresh = $stmt->fetch();
    $_SESSION['user'] = $fresh;
    return $fresh;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_profile') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

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
        $stmtUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
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
            $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, phone = ? WHERE id = ?')->execute([$name, $username, $email, $phone, $user['id']]);
            if (!empty($_FILES['profile_photo']['name'])) {
                $profilePhotoPath = save_uploaded_image($_FILES['profile_photo'], 'uploads/profiles/' . $user['id']);
                $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$profilePhotoPath, $user['id']]);
                // Points: first time a profile photo is uploaded
                require_once __DIR__ . '/modules/referrals/service.php';
                award_points((int)$user['id'], 'profile_photo');
            }
            $user = settings_refresh_user($pdo, $user['id']);
            $success = 'Profile updated.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'location') {
    $townId = intval($_POST['town_id'] ?? 0) ?: null;
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : ($user['latitude'] ?? null);
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : ($user['longitude'] ?? null);

    if (!$townId) {
        $error = 'Please select your town.';
    } else {
        $pdo->prepare('UPDATE users SET town_id = ?, latitude = ?, longitude = ? WHERE id = ?')->execute([$townId, $latitude, $longitude, $user['id']]);
        $user = settings_refresh_user($pdo, $user['id']);
        $success = 'Location updated.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($newPassword === '' || strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $user['id']]);
            $success = 'Password updated.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'notifications') {
    $emailNotifications = isset($_POST['email_notifications_enabled']) ? 1 : 0;
    $pdo->prepare('UPDATE users SET email_notifications_enabled = ? WHERE id = ?')->execute([$emailNotifications, $user['id']]);
    $user = settings_refresh_user($pdo, $user['id']);
    $success = 'Notification preferences updated.';

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_bio') {
    if ($workerProfile) {
        $bio = trim($_POST['bio'] ?? '');
        $avail = in_array($_POST['availability'] ?? '', ['available', 'busy', 'on_leave'], true) ? $_POST['availability'] : 'available';
        $pdo->prepare('UPDATE worker_profiles SET bio = ?, availability = ? WHERE user_id = ?')->execute([$bio, $avail, $user['id']]);
        $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
        $wpStmt->execute([$user['id']]);
        $workerProfile = $wpStmt->fetch() ?: null;
        $success = 'Bio and availability updated.';
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_id') {
    if ($workerProfile) {
        $idType   = $_POST['id_type'] ?? '';
        $idNumber = trim($_POST['id_number'] ?? '');
        if (!in_array($idType, ['ghana_card', 'passport'], true) || $idNumber === '') {
            $error = 'Select an ID type and enter your ID card number.';
        } elseif (!empty($_FILES['id_document']['name']) && !is_valid_image_upload($_FILES['id_document'])) {
            $error = 'ID photo must be a JPEG, PNG, or WEBP image under 5MB.';
        } else {
            $idDocPath = $workerProfile['id_document_path'];
            if (!empty($_FILES['id_document']['name'])) {
                $uploaded = save_uploaded_image($_FILES['id_document'], 'uploads/worker_ids/' . $user['id']);
                if ($uploaded) $idDocPath = $uploaded;
            }
            $pdo->prepare('UPDATE worker_profiles SET id_type = ?, id_number = ?, id_document_path = ? WHERE user_id = ?')->execute([$idType, $idNumber, $idDocPath, $user['id']]);
            $wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
            $wpStmt->execute([$user['id']]);
            $workerProfile = $wpStmt->fetch() ?: null;
            $success = 'Identity information updated.';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_remove_skill') {
    if ($workerProfile) {
        $skillId = intval($_POST['skill_id'] ?? 0);
        if ($skillId > 0) {
            $pdo->prepare('DELETE FROM worker_skills WHERE id = ? AND worker_profile_id = ?')->execute([$skillId, $workerProfile['id']]);
        }
        $success = 'Skill removed.';
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'worker_add_skill') {
    if ($workerProfile) {
        $newSkills = json_decode($_POST['skills_json'] ?? '', true);
        if (is_array($newSkills) && $newSkills) {
            $skillStmt = $pdo->prepare('INSERT INTO worker_skills (worker_profile_id, category_id, skill_name) VALUES (?, ?, ?)');
            foreach ($newSkills as $entry) {
                if (!is_array($entry)) continue;
                $sn  = trim((string)($entry['skill_name'] ?? ''));
                $cid = intval($entry['category_id'] ?? 0) ?: null;
                $customCatName = trim((string)($entry['category_name'] ?? ''));
                if ($cid === null && $customCatName !== '') {
                    $catCheck = $pdo->prepare('SELECT id FROM skill_categories WHERE LOWER(name) = LOWER(?)');
                    $catCheck->execute([$customCatName]);
                    if ($catRow = $catCheck->fetch()) {
                        $cid = (int)$catRow['id'];
                    } else {
                        $pdo->prepare('INSERT INTO skill_categories (name) VALUES (?)')->execute([$customCatName]);
                        $cid = (int)$pdo->lastInsertId();
                    }
                }
                if ($sn !== '') $skillStmt->execute([$workerProfile['id'], $cid, $sn]);
            }
            $success = 'Skills added.';
        }
    }
}

$workerSkills = [];
if ($workerProfile) {
    $wsStmt = $pdo->prepare(
        'SELECT ws.id, ws.skill_name, ws.category_id, sc.name AS category_name
         FROM worker_skills ws
         LEFT JOIN skill_categories sc ON ws.category_id = sc.id
         WHERE ws.worker_profile_id = ?
         ORDER BY sc.name, ws.skill_name'
    );
    $wsStmt->execute([$workerProfile['id']]);
    $workerSkills = $wsStmt->fetchAll();
}

$sectionMeta = [
    'account' => ['icon' => '✏️', 'title' => 'Edit Profile'],
    'location' => ['icon' => '📍', 'title' => 'Location'],
    'password' => ['icon' => '🔑', 'title' => 'Account Settings'],
    'notifications' => ['icon' => '🔔', 'title' => 'Notifications'],
    'worker' => ['icon' => '🛠️', 'title' => 'Worker Profile'],
    'role' => ['icon' => '🧰', 'title' => 'Role'],
    'privacy' => ['icon' => '🔒', 'title' => 'Privacy & Security'],
    'help' => ['icon' => '❓', 'title' => 'Help & Support'],
    'about' => ['icon' => 'ℹ️', 'title' => 'About AkuapemHub'],
];
$activeSection = isset($sectionMeta[$section]) ? $section : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $activeSection ? sanitize($sectionMeta[$activeSection]['title']) . ' — ' : ''; ?>Settings — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <?php if ($activeSection): ?>
            <a href="settings.php" class="brand" style="text-decoration: none;">
                <span class="brand-icon">‹</span> <?php echo sanitize($sectionMeta[$activeSection]['icon'] . ' ' . $sectionMeta[$activeSection]['title']); ?>
            </a>
        <?php else: ?>
            <span class="brand"><span class="brand-icon">⚙️</span> Settings</span>
            <a href="logout.php" class="button button-secondary button-small">Logout</a>
        <?php endif; ?>
    </header>
    <main class="page-shell small-shell">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <?php if ($activeSection === ''): ?>
            <section class="card" style="display: flex; align-items: center; gap: 14px;">
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile photo" class="avatar avatar-lg" />
                <?php else: ?>
                    <span class="avatar avatar-lg"><?php echo sanitize(strtoupper(substr(display_name($user), 0, 1))); ?></span>
                <?php endif; ?>
                <div>
                    <strong style="font-size: 1.05rem;"><?php echo sanitize(display_name($user)); ?></strong>
                    <?php if (!empty($user['username']) && $user['username'] !== $user['name']): ?>
                        <p class="meta" style="margin: 1px 0 0; font-size: 0.82rem;">@<?php echo sanitize($user['username']); ?></p>
                    <?php endif; ?>
                    <p class="meta" style="margin: 2px 0 0;"><?php echo sanitize($user['phone'] ?? 'No phone on file'); ?></p>
                </div>
            </section>

            <section class="card" style="padding: 6px 18px;">
                <a href="?section=account" class="list-row">
                    <span class="menu-icon">✏️</span>
                    <span class="list-row-body"><strong>Edit Profile</strong><p>Name, email, phone, photo</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=location" class="list-row">
                    <span class="menu-icon">📍</span>
                    <span class="list-row-body"><strong>Location</strong><p><?php echo sanitize(get_town_name((int)($user['town_id'] ?? 0)) ?: 'Not set'); ?></p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=password" class="list-row">
                    <span class="menu-icon">🔑</span>
                    <span class="list-row-body"><strong>Account Settings</strong><p>Change your password</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=notifications" class="list-row">
                    <span class="menu-icon">🔔</span>
                    <span class="list-row-body"><strong>Notifications</strong><p>Email alerts for job activity</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="my_payments.php" class="list-row">
                    <span class="menu-icon">💳</span>
                    <span class="list-row-body"><strong>My Payments</strong><p>Featured jobs, profiles &amp; verification history</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <?php if ($user['role'] === 'worker'): ?>
                <a href="?section=worker" class="list-row">
                    <span class="menu-icon">🛠️</span>
                    <span class="list-row-body"><strong>Worker Profile</strong><p>Bio, availability, identity &amp; skills</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <?php endif; ?>
                <a href="?section=role" class="list-row">
                    <span class="menu-icon">🧰</span>
                    <span class="list-row-body"><strong>Role</strong><p><?php echo $user['role'] === 'worker' ? 'Worker — manage your offering' : 'Customer — start offering services'; ?></p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=privacy" class="list-row">
                    <span class="menu-icon">🔒</span>
                    <span class="list-row-body"><strong>Privacy &amp; Security</strong><p>Close or deactivate your account</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=help" class="list-row">
                    <span class="menu-icon">❓</span>
                    <span class="list-row-body"><strong>Help &amp; Support</strong><p>Get in touch with our team</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="?section=about" class="list-row">
                    <span class="menu-icon">ℹ️</span>
                    <span class="list-row-body"><strong>About AkuapemHub</strong><p>Version, mission &amp; legal</p></span>
                    <span class="list-row-meta">›</span>
                </a>
                <a href="logout.php" class="list-row">
                    <span class="menu-icon">🚪</span>
                    <span class="list-row-body"><strong style="color: #c0392b;">Logout</strong><p>Sign out of your account</p></span>
                    <span class="list-row-meta">›</span>
                </a>
            </section>

        <?php elseif ($activeSection === 'account'): ?>
            <form class="card form-card" method="post" action="settings.php?section=account" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="edit_profile" />
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile photo" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; margin-bottom: 8px;" />
                <?php endif; ?>
                <label>Profile picture</label>
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" />
                <p class="meta">JPEG, PNG, or WEBP, up to 5MB. Leave blank to keep your current picture.</p>
                <label>Full name</label>
                <input type="text" name="name" value="<?php echo sanitize($user['name']); ?>" required />
                <label>Username</label>
                <input type="text" name="username" value="<?php echo sanitize($user['username'] ?? ''); ?>" required pattern="[a-zA-Z0-9_]{3,30}" title="3–30 characters: letters, numbers, underscores" placeholder="e.g. kwame_builds" />
                <p class="meta" style="margin-top: 4px;">Shown to other users instead of your real name.</p>
                <label>Email</label>
                <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required />
                <label>Phone number</label>
                <input type="text" name="phone" value="<?php echo sanitize($user['phone'] ?? ''); ?>" required placeholder="e.g. 0244000000" />
                <p class="meta">This number is used as your contact info across the app — requests, worker contact, and notifications all use it.</p>
                <button type="submit" class="button button-primary">Save profile</button>
            </form>

        <?php elseif ($activeSection === 'location'): ?>
            <form class="card form-card" method="post" action="settings.php?section=location">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="location" />
                <label>Town</label>
                <select name="town_id" required>
                    <option value="">Select your town</option>
                    <?php $currentDistrict = null; ?>
                    <?php foreach ($towns as $town): ?>
                        <?php if ($town['district'] !== $currentDistrict): ?>
                            <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                            <optgroup label="<?php echo sanitize($town['district']); ?>">
                            <?php $currentDistrict = $town['district']; ?>
                        <?php endif; ?>
                        <option value="<?php echo $town['id']; ?>" <?php echo ((int)($user['town_id'] ?? 0) === (int)$town['id']) ? 'selected' : ''; ?>><?php echo sanitize($town['name']); ?></option>
                    <?php endforeach; ?>
                    <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                </select>
                <input type="hidden" name="latitude" id="latitude" value="<?php echo $user['latitude'] !== null ? sanitize($user['latitude']) : ''; ?>" />
                <input type="hidden" name="longitude" id="longitude" value="<?php echo $user['longitude'] !== null ? sanitize($user['longitude']) : ''; ?>" />
                <button type="button" id="use-my-location" class="button button-secondary button-small">Update my GPS location</button>
                <p class="meta" id="location-status"><?php echo $user['latitude'] !== null ? 'Saved coordinates: ' . sanitize($user['latitude']) . ', ' . sanitize($user['longitude']) : 'No coordinates saved yet — sharing your location helps with nearby matching.'; ?></p>
                <button type="submit" class="button button-primary">Save location</button>
            </form>
            <script>
                document.getElementById('use-my-location').addEventListener('click', function () {
                    var status = document.getElementById('location-status');
                    if (!navigator.geolocation) {
                        status.textContent = 'Geolocation is not supported by your browser.';
                        return;
                    }
                    status.textContent = 'Locating…';
                    navigator.geolocation.getCurrentPosition(function (position) {
                        document.getElementById('latitude').value = position.coords.latitude;
                        document.getElementById('longitude').value = position.coords.longitude;
                        status.textContent = 'Location captured: ' + position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5) + '. Click "Save location" to store it.';
                    }, function () {
                        status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
                    });
                });
            </script>

        <?php elseif ($activeSection === 'password'): ?>
            <form class="card form-card" method="post" action="settings.php?section=password">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="password" />
                <p class="meta">Leave blank to keep your current password.</p>
                <label>Current password</label>
                <input type="password" name="current_password" placeholder="Required to set a new password" />
                <label>New password</label>
                <input type="password" name="new_password" minlength="6" placeholder="At least 6 characters" />
                <button type="submit" class="button button-primary">Change password</button>
            </form>

        <?php elseif ($activeSection === 'notifications'): ?>
            <form class="card form-card" method="post" action="settings.php?section=notifications">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="notifications" />
                <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; font-weight: normal;">
                    <input type="checkbox" name="email_notifications_enabled" <?php echo !empty($user['email_notifications_enabled']) ? 'checked' : ''; ?> />
                    Email me about job updates, requests, and account activity
                </label>
                <p class="meta">In-app notifications always continue regardless of this setting.</p>
                <button type="submit" class="button button-primary">Save preferences</button>
            </form>

        <?php elseif ($activeSection === 'role'): ?>
            <section class="card form-card">
                <?php if ($user['role'] === 'customer'): ?>
                    <p class="meta">You're registered as a customer. Want to offer services on AkuapemHub?</p>
                    <a href="become_worker.php" class="button button-primary">Become a worker</a>
                <?php elseif ($user['role'] === 'worker'): ?>
                    <p class="meta">You're registered as a worker. Manage your skills, availability, and bio from your worker profile.</p>
                    <a href="worker_profile.php" class="button button-secondary" style="margin-bottom: 12px;">Manage worker profile</a>
                    <p class="meta">Switching to customer mode hides your worker profile from job matching. Your worker profile and skills are kept, so you can switch back any time.</p>
                    <form method="post" action="switch_to_customer.php" onsubmit="return confirm('Switch to customer mode? Your worker profile will be kept and you can switch back later.');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="button button-secondary">Switch to customer mode</button>
                    </form>
                <?php endif; ?>
            </section>

        <?php elseif ($activeSection === 'worker'): ?>
            <?php if (!$workerProfile): ?>
                <div class="alert alert-info">You don't have a worker profile yet. <a href="become_worker.php" style="color:var(--primary);">Become a worker</a> to create one.</div>
            <?php else: ?>
                <!-- Bio & Availability -->
                <form class="card form-card" method="post" action="settings.php?section=worker" style="margin-bottom:14px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form" value="worker_bio" />
                    <h2>Bio &amp; Availability</h2>
                    <label>About you <span class="meta">(optional)</span></label>
                    <textarea name="bio" rows="3" placeholder="Briefly describe your experience and what makes you a great worker..."><?php echo sanitize($workerProfile['bio']); ?></textarea>
                    <label>Availability</label>
                    <select name="availability">
                        <option value="available" <?php echo $workerProfile['availability'] === 'available' ? 'selected' : ''; ?>>Available for work</option>
                        <option value="busy" <?php echo $workerProfile['availability'] === 'busy' ? 'selected' : ''; ?>>Busy — not taking new jobs</option>
                        <option value="on_leave" <?php echo $workerProfile['availability'] === 'on_leave' ? 'selected' : ''; ?>>On leave</option>
                    </select>
                    <button type="submit" class="button button-primary">Save</button>
                </form>

                <!-- Identity Verification -->
                <form class="card form-card" method="post" action="settings.php?section=worker" enctype="multipart/form-data" style="margin-bottom:14px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form" value="worker_id" />
                    <h2>Identity Verification</h2>
                    <p class="meta">Your ID is never shared publicly — it is used for trust &amp; safety only.</p>
                    <label>ID type</label>
                    <select name="id_type" required>
                        <option value="">Select ID type</option>
                        <option value="ghana_card" <?php echo $workerProfile['id_type'] === 'ghana_card' ? 'selected' : ''; ?>>Ghana Card</option>
                        <option value="passport" <?php echo $workerProfile['id_type'] === 'passport' ? 'selected' : ''; ?>>Passport</option>
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

                <!-- Skills -->
                <div class="card form-card" style="margin-bottom:14px;">
                    <h2>Your Skills</h2>
                    <?php if ($workerSkills): ?>
                        <p class="meta" style="margin-bottom:10px;">Tap × next to any skill to remove it.</p>
                        <ul style="list-style:none;padding:0;margin:0 0 18px;display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($workerSkills as $sk): ?>
                            <li class="badge" style="display:inline-flex;align-items:center;gap:5px;font-size:0.88rem;">
                                <?php if ($sk['category_name']): ?><span style="font-size:0.78rem;color:var(--text-muted);font-weight:normal;"><?php echo sanitize($sk['category_name']); ?>:</span><?php endif; ?>
                                <?php echo sanitize($sk['skill_name']); ?>
                                <form method="post" action="settings.php?section=worker" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="form" value="worker_remove_skill" />
                                    <input type="hidden" name="skill_id" value="<?php echo $sk['id']; ?>" />
                                    <button type="submit" title="Remove skill" onclick="return confirm('Remove <?php echo addslashes(sanitize($sk['skill_name'])); ?>?')" style="border:none;background:transparent;cursor:pointer;font-size:1.05rem;line-height:1;padding:0 0 0 2px;color:var(--text-muted);">×</button>
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
                        <h3 style="font-size:0.95rem;margin:0 0 6px;font-weight:600;">Add skills</h3>
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
                        <select id="skill-select" disabled>
                            <option value="">Select a category first</option>
                        </select>
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
                var skillTaxonomy = <?php echo json_encode($skillCategories); ?>;
                var catSel = document.getElementById('skill-category-select');
                var skillSel = document.getElementById('skill-select');
                var otherWrap = document.getElementById('other-skill-wrap');
                var otherInput = document.getElementById('other-skill-input');
                var otherCatWrap = document.getElementById('other-category-wrap');
                var otherCatInput = document.getElementById('other-category-input');
                var skillLabel = document.getElementById('skill-label');
                var addBtn = document.getElementById('add-skill-button');
                var skillList = document.getElementById('skill-list');
                var skillsJson = document.getElementById('skills-json');
                var saveBtn = document.getElementById('save-skills-button');
                var pending = [];

                if (catSel) {
                    function findCat(id) {
                        for (var i = 0; i < skillTaxonomy.length; i++) {
                            if (String(skillTaxonomy[i].id) === String(id)) return skillTaxonomy[i];
                        }
                        return null;
                    }

                    catSel.addEventListener('change', function () {
                        otherCatWrap.style.display = 'none';
                        otherCatInput.value = '';
                        otherWrap.style.display = 'none';
                        otherInput.value = '';

                        if (this.value === '__other__') {
                            otherCatWrap.style.display = 'block';
                            otherCatInput.focus();
                            skillLabel.style.display = 'none';
                            skillSel.style.display = 'none';
                            skillSel.disabled = true;
                            otherWrap.style.display = 'block';
                            return;
                        }

                        skillLabel.style.display = '';
                        skillSel.style.display = '';
                        skillSel.innerHTML = '';

                        var cat = findCat(this.value);
                        if (!cat) {
                            skillSel.disabled = true;
                            skillSel.innerHTML = '<option value="">Select a category first</option>';
                            return;
                        }
                        skillSel.disabled = false;
                        var blank = document.createElement('option');
                        blank.value = ''; blank.textContent = 'Select a skill';
                        skillSel.appendChild(blank);
                        cat.skills.forEach(function (s) {
                            var o = document.createElement('option');
                            o.value = s; o.textContent = s;
                            skillSel.appendChild(o);
                        });
                        var oth = document.createElement('option');
                        oth.value = '__other__'; oth.textContent = 'Other (specify)';
                        skillSel.appendChild(oth);
                    });

                    skillSel.addEventListener('change', function () {
                        var isOther = this.value === '__other__';
                        otherWrap.style.display = isOther ? 'block' : 'none';
                        if (isOther) otherInput.focus();
                    });

                    function renderPending() {
                        skillList.innerHTML = '';
                        saveBtn.style.display = pending.length > 0 ? 'inline-flex' : 'none';
                        pending.forEach(function (sk, idx) {
                            var li = document.createElement('li');
                            li.className = 'badge';
                            li.style.cssText = 'display:inline-flex;align-items:center;gap:6px;font-size:0.88rem;';
                            li.textContent = (sk.category_name ? sk.category_name + ': ' : '') + sk.skill_name;
                            var btn = document.createElement('button');
                            btn.type = 'button'; btn.textContent = '×';
                            btn.style.cssText = 'border:none;background:transparent;cursor:pointer;font-size:1rem;padding:0 0 0 2px;color:var(--text-muted);';
                            btn.addEventListener('click', function () {
                                pending.splice(idx, 1);
                                renderPending();
                            });
                            li.appendChild(btn);
                            skillList.appendChild(li);
                        });
                        skillsJson.value = JSON.stringify(pending);
                    }

                    addBtn.addEventListener('click', function () {
                        var categoryId, categoryName, skillName;

                        if (catSel.value === '__other__') {
                            categoryName = otherCatInput.value.trim();
                            if (!categoryName) { otherCatInput.focus(); return; }
                            skillName = otherInput.value.trim();
                            if (!skillName) { otherInput.focus(); return; }
                            categoryId = null;
                        } else {
                            var cat = findCat(catSel.value);
                            if (!cat) { catSel.focus(); return; }
                            categoryId = cat.id;
                            categoryName = cat.name;
                            skillName = skillSel.value === '__other__' ? otherInput.value.trim() : skillSel.value;
                            if (!skillName) { skillSel.focus(); return; }
                        }

                        var exists = pending.some(function (s) {
                            return s.category_name.toLowerCase() === categoryName.toLowerCase()
                                && s.skill_name.toLowerCase() === skillName.toLowerCase();
                        });
                        if (!exists) {
                            pending.push({ category_id: categoryId, category_name: categoryName, skill_name: skillName });
                            renderPending();
                        }

                        if (catSel.value === '__other__') {
                            // keep category name; only clear skill and re-focus
                            otherInput.value = '';
                            otherInput.focus();
                        } else {
                            otherInput.value = '';
                            otherWrap.style.display = 'none';
                            skillSel.value = '';
                        }
                    });

                    renderPending();
                }
            </script>

        <?php elseif ($activeSection === 'privacy'): ?>
            <section class="card form-card">
                <p class="meta">Your account details are only shared with the customers and workers you transact with. Closing your account deactivates it and signs you out immediately.</p>
                <form method="post" action="delete_account.php" onsubmit="return confirm('Are you sure you want to close your account? You will be signed out immediately.');">
                    <?php echo csrf_field(); ?>
                    <label>Confirm your password</label>
                    <input type="password" name="current_password" required placeholder="Enter your password to confirm" />
                    <button type="submit" class="button button-secondary" style="color: #c0392b; border-color: #c0392b;">Close my account</button>
                </form>
            </section>

        <?php elseif ($activeSection === 'help'): ?>
            <section class="card form-card">
                <p class="meta">Have a question, found a bug, or need help with a job or payment? Reach out and our team will get back to you.</p>
                <a href="mailto:<?php echo sanitize(ADMIN_EMAIL); ?>" class="button button-secondary">Email support</a>
            </section>

        <?php elseif ($activeSection === 'about'): ?>
            <section class="card form-card">
                <p class="meta">AkuapemHub connects people in Akuapem with trusted workers and services — post errands, skilled work, and micro jobs, or find verified workers nearby.</p>
                <p class="meta">Version 1.0 · Made for the Akuapem community.</p>
            </section>
        <?php endif; ?>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

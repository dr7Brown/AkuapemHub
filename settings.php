<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$towns = get_towns();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'notifications') {
    $emailNotifications = isset($_POST['email_notifications_enabled']) ? 1 : 0;
    $pdo->prepare('UPDATE users SET email_notifications_enabled = ? WHERE id = ?')->execute([$emailNotifications, $user['id']]);

    $stmt = $pdo->prepare('SELECT id, name, email, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch();
    $_SESSION['user'] = $user;
    $success = 'Notification preferences updated.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $townId = intval($_POST['town_id'] ?? 0) ?: null;
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : ($user['latitude'] ?? null);
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : ($user['longitude'] ?? null);
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || !$townId) {
        $error = 'Name, email, phone and town are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } elseif (!empty($_FILES['profile_photo']['name']) && !is_valid_image_upload($_FILES['profile_photo'])) {
        $error = 'Profile picture must be a JPEG, PNG, or WEBP image under 5MB.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $error = 'Another account already uses this email address.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            $passwordHash = null;
            if ($newPassword !== '') {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$user['id']]);
                $hash = $stmt->fetchColumn();
                if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
                    $error = 'Current password is incorrect.';
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                }
            }

            if ($error === '') {
                $profilePhotoPath = null;
                if (!empty($_FILES['profile_photo']['name'])) {
                    $profilePhotoPath = save_uploaded_image($_FILES['profile_photo'], 'uploads/profiles/' . $user['id']);
                }

                if ($passwordHash !== null) {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, town_id = ?, latitude = ?, longitude = ?, password_hash = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $phone, $townId, $latitude, $longitude, $passwordHash, $user['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, town_id = ?, latitude = ?, longitude = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $phone, $townId, $latitude, $longitude, $user['id']]);
                }

                if ($profilePhotoPath !== null) {
                    $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$profilePhotoPath, $user['id']]);
                }

                $stmt = $pdo->prepare('SELECT id, name, email, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
                $_SESSION['user'] = $user;
                $success = 'Settings updated.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Settings — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">⚙️</span> Settings</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <section class="card" style="display: flex; align-items: center; gap: 14px;">
            <?php if (!empty($user['profile_photo'])): ?>
                <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile photo" class="avatar avatar-lg" />
            <?php else: ?>
                <span class="avatar avatar-lg"><?php echo sanitize(strtoupper(substr($user['name'], 0, 1))); ?></span>
            <?php endif; ?>
            <div>
                <strong style="font-size: 1.05rem;"><?php echo sanitize($user['name']); ?></strong>
                <p class="meta" style="margin: 2px 0 0;"><?php echo sanitize($user['phone'] ?? 'No phone on file'); ?></p>
            </div>
        </section>

        <section class="card" style="padding: 6px 18px;">
            <a href="#account" class="list-row">
                <span class="menu-icon">✏️</span>
                <span class="list-row-body"><strong>Edit Profile</strong><p>Name, email, phone, photo</p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#location" class="list-row">
                <span class="menu-icon">📍</span>
                <span class="list-row-body"><strong>Location</strong><p><?php echo sanitize(get_town_name((int)($user['town_id'] ?? 0)) ?: 'Not set'); ?></p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#password" class="list-row">
                <span class="menu-icon">🔑</span>
                <span class="list-row-body"><strong>Account Settings</strong><p>Change your password</p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#notifications" class="list-row">
                <span class="menu-icon">🔔</span>
                <span class="list-row-body"><strong>Notifications</strong><p>Email alerts for job activity</p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#role" class="list-row">
                <span class="menu-icon">🧰</span>
                <span class="list-row-body"><strong>Role</strong><p><?php echo $user['role'] === 'worker' ? 'Worker — manage your offering' : 'Customer — start offering services'; ?></p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#privacy" class="list-row">
                <span class="menu-icon">🔒</span>
                <span class="list-row-body"><strong>Privacy &amp; Security</strong><p>Close or deactivate your account</p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#help" class="list-row">
                <span class="menu-icon">❓</span>
                <span class="list-row-body"><strong>Help &amp; Support</strong><p>Get in touch with our team</p></span>
                <span class="list-row-meta">›</span>
            </a>
            <a href="#about" class="list-row">
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

        <form class="card form-card" method="post" action="settings.php#account" enctype="multipart/form-data">
            <input type="hidden" name="form" value="profile" />

            <h2 id="account">Edit profile</h2>
            <?php if (!empty($user['profile_photo'])): ?>
                <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile photo" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; margin-bottom: 8px;" />
            <?php endif; ?>
            <label>Profile picture</label>
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" />
            <p class="meta">JPEG, PNG, or WEBP, up to 5MB. Leave blank to keep your current picture.</p>
            <label>Name</label>
            <input type="text" name="name" value="<?php echo sanitize($user['name']); ?>" required />
            <label>Email</label>
            <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required />
            <label>Phone number</label>
            <input type="text" name="phone" value="<?php echo sanitize($user['phone'] ?? ''); ?>" required placeholder="e.g. 0244000000" />
            <p class="meta">This number is used as your contact info across the app — requests, worker contact, and notifications all use it.</p>

            <h2 id="location">Location</h2>
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

            <h2 id="password">Account settings — change password</h2>
            <p class="meta">Leave blank to keep your current password.</p>
            <label>Current password</label>
            <input type="password" name="current_password" placeholder="Required only if setting a new password" />
            <label>New password</label>
            <input type="password" name="new_password" minlength="6" placeholder="At least 6 characters" />

            <button type="submit" class="button button-primary">Save settings</button>
        </form>

        <form class="card form-card" method="post" action="settings.php#notifications">
            <input type="hidden" name="form" value="notifications" />
            <h2 id="notifications">Notifications</h2>
            <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; font-weight: normal;">
                <input type="checkbox" name="email_notifications_enabled" <?php echo !empty($user['email_notifications_enabled']) ? 'checked' : ''; ?> />
                Email me about job updates, requests, and account activity
            </label>
            <p class="meta">In-app notifications always continue regardless of this setting.</p>
            <button type="submit" class="button button-primary">Save preferences</button>
        </form>

        <section class="card form-card">
            <h2 id="role">Role</h2>
            <?php if ($user['role'] === 'customer'): ?>
                <p class="meta">You're registered as a customer. Want to offer services on AkuapemHub?</p>
                <a href="become_worker.php" class="button button-primary">Become a worker</a>
            <?php elseif ($user['role'] === 'worker'): ?>
                <p class="meta">You're registered as a worker. Manage your skills, availability, and bio from your worker profile.</p>
                <a href="worker_profile.php" class="button button-secondary" style="margin-bottom: 12px;">Manage worker profile</a>
                <p class="meta">Switching to customer mode hides your worker profile from job matching. Your worker profile and skills are kept, so you can switch back any time.</p>
                <form method="post" action="switch_to_customer.php" onsubmit="return confirm('Switch to customer mode? Your worker profile will be kept and you can switch back later.');">
                    <button type="submit" class="button button-secondary">Switch to customer mode</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card form-card">
            <h2 id="privacy">Privacy &amp; Security</h2>
            <p class="meta">Your account details are only shared with the customers and workers you transact with. Closing your account deactivates it and signs you out immediately.</p>
            <form method="post" action="delete_account.php" onsubmit="return confirm('Are you sure you want to close your account? You will be signed out immediately.');">
                <label>Confirm your password</label>
                <input type="password" name="current_password" required placeholder="Enter your password to confirm" />
                <button type="submit" class="button button-secondary" style="color: #c0392b; border-color: #c0392b;">Close my account</button>
            </form>
        </section>

        <section class="card form-card">
            <h2 id="help">Help &amp; Support</h2>
            <p class="meta">Have a question, found a bug, or need help with a job or payment? Reach out and our team will get back to you.</p>
            <a href="mailto:<?php echo sanitize(ADMIN_EMAIL); ?>" class="button button-secondary">Email support</a>
        </section>

        <section class="card form-card">
            <h2 id="about">About <?php echo sanitize(APP_NAME); ?></h2>
            <p class="meta">AkuapemHub connects people in Akuapem with trusted workers and services — post errands, skilled work, and micro jobs, or find verified workers nearby.</p>
            <p class="meta">Version 1.0 · Made for the Akuapem community.</p>
        </section>
    </main>
    <?php $activeNav = 'profile'; require __DIR__ . '/partials/bottom_nav.php'; ?>
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
                status.textContent = 'Location captured: ' + position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5) + '. Click "Save settings" to store it.';
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
            });
        });
    </script>
</body>
</html>

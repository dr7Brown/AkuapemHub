<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
require_role('worker');

$stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bio = trim($_POST['bio'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $contact = trim($user['phone'] ?? '');
    $availability = $_POST['availability'] ?? 'available';
    $skills = trim($_POST['skills'] ?? '');
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;

    if ($location === '') {
        $error = 'Location is required.';
    } elseif ($contact === '') {
        $error = 'Add a phone number to your account before updating your profile.';
    } else {
        $stmt = $pdo->prepare('UPDATE worker_profiles SET bio = ?, location = ?, latitude = ?, longitude = ?, contact_phone = ?, availability = ?, updated_at = NOW() WHERE user_id = ?');
        $stmt->execute([$bio, $location, $latitude, $longitude, $contact, $availability, $user['id']]);

        $pdo->prepare('DELETE FROM worker_skills WHERE worker_profile_id = ?')->execute([$profile['id']]);
        $skillList = array_filter(array_map('trim', explode(',', $skills)));
        $skillStmt = $pdo->prepare('INSERT INTO worker_skills (worker_profile_id, skill_name) VALUES (?, ?)');
        foreach ($skillList as $skill) {
            $skillStmt->execute([$profile['id'], $skill]);
        }

        save_worker_schedule($profile['id'], $_POST['schedule_day'] ?? [], $_POST['schedule_start'] ?? [], $_POST['schedule_end'] ?? []);

        $success = 'Profile updated.';
        $stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
    }
}

$skillRows = $pdo->prepare('SELECT skill_name FROM worker_skills WHERE worker_profile_id = ?');
$skillRows->execute([$profile['id']]);
$skills = implode(', ', array_column($skillRows->fetchAll(), 'skill_name'));

$schedule = get_worker_schedule($profile['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Worker Profile — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">👤</span> Worker Profile</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>
        <div class="panel" style="margin-bottom:16px;">
            <p><strong>Subscription:</strong> <?php echo sanitize(ucfirst($profile['subscription_status'])); ?></p>
            <?php if ($profile['subscription_status'] === 'free'): ?>
                <a href="toggle_subscription.php" class="button button-primary">Upgrade to Premium</a>
            <?php else: ?>
                <a href="toggle_subscription.php" class="button button-secondary">Switch to Free</a>
            <?php endif; ?>
            <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <?php if ($profile['is_featured'] && (!empty($profile['featured_end_date']) && $profile['featured_end_date'] >= date('Y-m-d'))): ?>
                    <span class="badge" style="background:var(--primary);color:#fff;">Featured until <?php echo sanitize($profile['featured_end_date']); ?></span>
                <?php else: ?>
                    <a href="feature_worker.php" class="button button-secondary button-small">Feature my profile</a>
                <?php endif; ?>
                <?php if ($profile['is_verified']): ?>
                    <span class="badge" style="background:#22a06b;color:#fff;">Verified ✓</span>
                    <?php if ($profile['verification_expiry']): ?>
                        <span class="meta">expires <?php echo sanitize($profile['verification_expiry']); ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    // Check if worker already has a pending verification payment
                    $pendingVerifStmt = $pdo->prepare("SELECT id FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'pending'");
                    $pendingVerifStmt->execute([$user['id']]);
                    $hasPendingVerif = (bool) $pendingVerifStmt->fetch();
                    ?>
                    <?php if ($hasPendingVerif): ?>
                        <span class="badge" style="background:#f59e0b;color:#fff;">Verification payment pending</span>
                        <span class="meta" style="font-size:0.9rem;">Awaiting admin confirmation.</span>
                    <?php elseif (is_feature_paid('enable_paid_verification_badges')): ?>
                        <a href="request_verification.php" class="button button-secondary button-small">Request Verification</a>
                    <?php else: ?>
                        <span class="meta" style="font-size:0.9rem;">Not verified — admin can grant verification from the admin panel.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <form class="card form-card" method="post" action="worker_profile.php">
            <label>Bio</label>
            <textarea name="bio" rows="4"><?php echo sanitize($profile['bio']); ?></textarea>
            <label>Location</label>
            <input type="text" name="location" value="<?php echo sanitize($profile['location']); ?>" required />
            <input type="hidden" name="latitude" id="latitude" value="<?php echo $profile['latitude'] !== null ? sanitize($profile['latitude']) : ''; ?>" />
            <input type="hidden" name="longitude" id="longitude" value="<?php echo $profile['longitude'] !== null ? sanitize($profile['longitude']) : ''; ?>" />
            <button type="button" id="use-my-location" class="button button-secondary button-small">Use my current location</button>
            <p class="meta" id="location-status"><?php echo $profile['latitude'] !== null ? 'Saved coordinates: ' . sanitize($profile['latitude']) . ', ' . sanitize($profile['longitude']) : 'No coordinates saved yet — sharing your location helps customers find you nearby.'; ?></p>
            <label>Contact phone</label>
            <?php if (!empty($user['phone'])): ?>
                <p class="meta">📱 Customers will reach you on your registered number: <strong><?php echo sanitize($user['phone']); ?></strong></p>
            <?php else: ?>
                <p class="alert alert-error">No phone number on file. Please contact support to add one before saving your profile.</p>
            <?php endif; ?>
            <label>Skills</label>
            <input type="text" name="skills" value="<?php echo sanitize($skills); ?>" placeholder="e.g. electrician, plumber, welder" />
            <label>Availability</label>
            <select name="availability">
                <?php foreach (get_availability_options() as $key => $label): ?>
                    <option value="<?php echo sanitize($key); ?>" <?php echo $profile['availability'] === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Weekly availability schedule</label>
            <div class="schedule-list">
                <?php foreach (get_weekday_names() as $dayNum => $dayName): ?>
                    <?php $daySlot = $schedule[$dayNum][0] ?? null; ?>
                    <div class="schedule-row<?php echo $daySlot ? ' is-active' : ''; ?>" data-schedule-row>
                        <span class="schedule-day">
                            <span class="day-switch">
                                <input type="checkbox" name="schedule_day[<?php echo $dayNum; ?>]" value="1" <?php echo $daySlot ? 'checked' : ''; ?> data-schedule-toggle />
                                <span class="switch-track"></span>
                            </span>
                            <?php echo sanitize($dayName); ?>
                        </span>
                        <span class="schedule-time-range">
                            <input type="time" name="schedule_start[<?php echo $dayNum; ?>]" value="<?php echo $daySlot ? sanitize(substr($daySlot['start_time'], 0, 5)) : '08:00'; ?>" />
                            <span>to</span>
                            <input type="time" name="schedule_end[<?php echo $dayNum; ?>]" value="<?php echo $daySlot ? sanitize(substr($daySlot['end_time'], 0, 5)) : '17:00'; ?>" />
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="meta">Toggle the days you're available and set your working hours. Customers will see this on your profile.</p>
            <button type="submit" class="button button-primary">Save profile</button>
        </form>
    </main>
    <script>
        document.querySelectorAll('[data-schedule-toggle]').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                toggle.closest('[data-schedule-row]').classList.toggle('is-active', toggle.checked);
            });
        });

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
                status.textContent = 'Location captured: ' + position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5) + '. Click "Save profile" to store it.';
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
            });
        });
    </script>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

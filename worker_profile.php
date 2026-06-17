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
$success = ($_GET['msg'] ?? '') === 'updated' ? 'Profile updated.' : '';

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

        header('Location: worker_profile.php?msg=updated'); exit;
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
            <?php
                $wpFeatEnd    = $profile['featured_end_date'] ?? null;
                $wpFeatActive = !empty($profile['is_featured']) && (empty($wpFeatEnd) || $wpFeatEnd >= date('Y-m-d'));
                $wpRenewSoon  = !empty($profile['is_featured']) && !empty($wpFeatEnd) && $wpFeatEnd < date('Y-m-d', strtotime('+7 days'));
            ?>
            <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <?php if ($wpFeatActive && !$wpRenewSoon): ?>
                    <span class="badge" style="background:var(--primary);color:#fff;">⭐ Featured<?php echo $wpFeatEnd ? ' until ' . sanitize($wpFeatEnd) : ''; ?></span>
                <?php elseif ($wpRenewSoon): ?>
                    <a href="feature_worker.php" class="button button-secondary button-small">⭐ Renew feature (expires <?php echo sanitize($wpFeatEnd); ?>)</a>
                <?php else: ?>
                    <a href="feature_worker.php" class="button button-secondary button-small">⭐ Feature my profile</a>
                <?php endif; ?>
                <?php if ($profile['is_verified']): ?>
                    <span class="badge" style="background:#22a06b;color:#fff;font-size:0.95rem;letter-spacing:0.01em;"><strong style="font-size:1.05em;">✓</strong>erified</span>
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
            <?php
            $svcFeeStatus = $profile['service_fee_status'] ?? 'free';
            $svcFeeExpiry = $profile['service_fee_expiry'] ?? null;
            ?>
            <?php if ($svcFeeStatus === 'pending'): ?>
                <div class="alert alert-warning" style="margin-top:12px;">
                    💳 <strong>Service listing payment pending</strong> — you are not yet visible in worker search results.
                    <a href="my_payments.php" style="color:var(--primary);margin-left:4px;">Track payment →</a>
                    &nbsp;
                    <a href="pay_worker_service.php" style="color:var(--primary);">View packages →</a>
                </div>
            <?php elseif ($svcFeeStatus === 'paid' && $svcFeeExpiry): ?>
                <p class="meta" style="margin-top:8px;">Service listing active until <strong><?php echo sanitize($svcFeeExpiry); ?></strong>.
                    <?php if (is_feature_paid('enable_paid_worker_service')): ?>
                        <a href="pay_worker_service.php" style="color:var(--primary);margin-left:4px;">Renew →</a>
                    <?php endif; ?>
                </p>
            <?php elseif ($svcFeeStatus === 'free' && is_feature_paid('enable_paid_worker_service')): ?>
                <div class="alert alert-info" style="margin-top:12px;">
                    A service listing fee is now required to appear in search results.
                    <a href="pay_worker_service.php" style="color:var(--primary);margin-left:4px;">Pay now →</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="card" style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="margin:0;font-weight:600;white-space:nowrap;">Availability</label>
            <select id="avail-quick" style="flex:1;min-width:140px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;">
                <?php foreach (get_availability_options() as $key => $label): ?>
                    <option value="<?php echo sanitize($key); ?>" <?php echo $profile['availability'] === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                <?php endforeach; ?>
            </select>
            <span id="avail-status" style="font-size:0.82rem;color:var(--text-muted);"></span>
        </div>
        <form class="card form-card" method="post" action="worker_profile.php">
            <label>Bio</label>
            <textarea name="bio" class="rich-editor" rows="4" placeholder="Describe your experience, skills, and what makes you a great worker…"><?php echo $profile['bio']; ?></textarea>
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
        // Availability quick-toggle
        (function () {
            var sel    = document.getElementById('avail-quick');
            var status = document.getElementById('avail-status');
            if (!sel) return;
            sel.addEventListener('change', function () {
                status.textContent = 'Saving…';
                fetch('ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=update_availability&availability=' + encodeURIComponent(sel.value) + '&csrf_token=' + encodeURIComponent(CSRF)
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    status.textContent = d.ok ? 'Saved ✓' : (d.error || 'Failed');
                    setTimeout(function () { status.textContent = ''; }, 2000);
                })
                .catch(function () { status.textContent = 'Failed'; });
            });
        })();

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

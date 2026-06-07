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
    $contact = trim($_POST['contact_phone'] ?? '');
    $availability = $_POST['availability'] ?? 'available';
    $skills = trim($_POST['skills'] ?? '');

    if ($location === '' || $contact === '') {
        $error = 'Location and contact phone are required.';
    } else {
        $stmt = $pdo->prepare('UPDATE worker_profiles SET bio = ?, location = ?, contact_phone = ?, availability = ?, updated_at = NOW() WHERE user_id = ?');
        $stmt->execute([$bio, $location, $contact, $availability, $user['id']]);

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
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>Worker profile</h1>
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
        </div>
        <form class="card form-card" method="post" action="worker_profile.php">
            <label>Bio</label>
            <textarea name="bio" rows="4"><?php echo sanitize($profile['bio']); ?></textarea>
            <label>Location</label>
            <input type="text" name="location" value="<?php echo sanitize($profile['location']); ?>" required />
            <label>Contact phone</label>
            <input type="text" name="contact_phone" value="<?php echo sanitize($profile['contact_phone']); ?>" required placeholder="WhatsApp or phone" />
            <label>Skills</label>
            <input type="text" name="skills" value="<?php echo sanitize($skills); ?>" placeholder="e.g. electrician, plumber, welder" />
            <label>Availability</label>
            <select name="availability">
                <?php foreach (get_availability_options() as $key => $label): ?>
                    <option value="<?php echo sanitize($key); ?>" <?php echo $profile['availability'] === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Weekly availability schedule</label>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach (get_weekday_names() as $dayNum => $dayName): ?>
                    <?php $daySlot = $schedule[$dayNum][0] ?? null; ?>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 6px; min-width: 110px;">
                            <input type="checkbox" name="schedule_day[<?php echo $dayNum; ?>]" value="1" <?php echo $daySlot ? 'checked' : ''; ?> />
                            <?php echo sanitize($dayName); ?>
                        </label>
                        <input type="time" name="schedule_start[<?php echo $dayNum; ?>]" value="<?php echo $daySlot ? sanitize(substr($daySlot['start_time'], 0, 5)) : ''; ?>" />
                        <span>to</span>
                        <input type="time" name="schedule_end[<?php echo $dayNum; ?>]" value="<?php echo $daySlot ? sanitize(substr($daySlot['end_time'], 0, 5)) : ''; ?>" />
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="meta">Tick the days you're available and set your working hours. Customers will see this on your profile.</p>
            <button type="submit" class="button button-primary">Save profile</button>
        </form>
    </main>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$towns = get_towns();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                if ($passwordHash !== null) {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, town_id = ?, latitude = ?, longitude = ?, password_hash = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $phone, $townId, $latitude, $longitude, $passwordHash, $user['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, town_id = ?, latitude = ?, longitude = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $phone, $townId, $latitude, $longitude, $user['id']]);
                }

                $stmt = $pdo->prepare('SELECT id, name, email, role, phone, town_id, latitude, longitude, banned FROM users WHERE id = ?');
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
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>Account settings</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>
        <form class="card form-card" method="post" action="settings.php">
            <label>Name</label>
            <input type="text" name="name" value="<?php echo sanitize($user['name']); ?>" required />
            <label>Email</label>
            <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required />
            <label>Phone number</label>
            <input type="text" name="phone" value="<?php echo sanitize($user['phone'] ?? ''); ?>" required placeholder="e.g. 0244000000" />
            <p class="meta">This number is used as your contact info across the app — requests, worker contact, and notifications all use it.</p>
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

            <h2>Change password</h2>
            <p class="meta">Leave blank to keep your current password.</p>
            <label>Current password</label>
            <input type="password" name="current_password" placeholder="Required only if setting a new password" />
            <label>New password</label>
            <input type="password" name="new_password" minlength="6" placeholder="At least 6 characters" />

            <button type="submit" class="button button-primary">Save settings</button>
        </form>
    </main>
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

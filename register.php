<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$towns = get_towns();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] === 'worker' ? 'worker' : 'customer';
    $phone = trim($_POST['phone'] ?? '');
    $townId = intval($_POST['town_id'] ?? 0) ?: null;
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;

    if ($name === '' || $email === '' || $password === '' || $phone === '' || !$townId) {
        $error = 'All fields are required, including phone number and town.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone, town_id, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $email, $passwordHash, $role, $phone, $townId, $latitude, $longitude]);
            $userId = $pdo->lastInsertId();

            $townName = get_town_name($townId) ?: '';
            if ($role === 'worker') {
                $stmt = $pdo->prepare('INSERT INTO worker_profiles (user_id, bio, location, latitude, longitude, contact_phone, availability, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$userId, '', $townName, $latitude, $longitude, $phone, 'available']);
            }

            $stmt = $pdo->prepare('SELECT id, name, email, role, phone, town_id, latitude, longitude, banned FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            login_user($user);
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="register.php">
            <h1>Create account</h1>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Name</label>
            <input type="text" name="name" required />
            <label>Email</label>
            <input type="email" name="email" required />
            <label>Password</label>
            <input type="password" name="password" required minlength="6" />
            <label>Phone number</label>
            <input type="text" name="phone" required placeholder="e.g. 0244000000" value="<?php echo sanitize($_POST['phone'] ?? ''); ?>" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">We'll use this number for WhatsApp/SMS updates and as your contact info across the app — no need to retype it later.</p>
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
                    <option value="<?php echo $town['id']; ?>" <?php echo (isset($_POST['town_id']) && $_POST['town_id'] == $town['id']) ? 'selected' : ''; ?>><?php echo sanitize($town['name']); ?></option>
                <?php endforeach; ?>
                <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
            </select>
            <label>Role</label>
            <select name="role" required>
                <option value="customer">Customer</option>
                <option value="worker">Worker</option>
            </select>
            <input type="hidden" name="latitude" id="latitude" />
            <input type="hidden" name="longitude" id="longitude" />
            <button type="button" id="use-my-location" class="button button-secondary button-small">Share my GPS location</button>
            <p class="meta" id="location-status">Sharing your location helps us match you with nearby jobs and workers.</p>
            <button type="submit" class="button button-primary">Register</button>
            <p class="small-note">Already registered? <a href="login.php">Sign in</a></p>
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
                status.textContent = 'Location captured: ' + position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5) + '.';
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
            });
        });
    </script>
</body>
</html>

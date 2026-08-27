<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';

if (current_user()) {
    header('Location: community.php');
    exit;
}

// Capture referral code from URL into session (survives the POST)
if (!isset($_SESSION['ref_code']) && !empty($_GET['ref'])) {
    $rc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['ref']));
    if (strlen($rc) >= 6 && strlen($rc) <= 16) {
        $_SESSION['ref_code'] = $rc;
        record_referral_visit($rc, client_ip());
    }
}

$towns = get_towns();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $townIdRaw = $_POST['town_id'] ?? '';
    $customTown = null;
    if ($townIdRaw === '__other__') {
        $customTown = trim($_POST['custom_town'] ?? '');
        $townId = $customTown !== '' ? get_other_town_id() : null;
    } else {
        $townId = intval($townIdRaw) ?: null;
    }

    if ($name === '' || $username === '' || $email === '' || $password === '' || $phone === '' || !$townId) {
        $error = ($townIdRaw === '__other__')
            ? 'Please specify your location.'
            : 'All fields are required, including username, phone number and town.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters and can only contain letters, numbers, and underscores.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } elseif (!empty($_FILES['profile_photo']['name']) && !is_valid_image_upload($_FILES['profile_photo'])) {
        $error = 'Profile picture must be a JPEG, PNG, or WEBP image under 5MB.';
    } else {
        $stmtEmail = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmtEmail->execute([$email]);
        $stmtUser = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmtUser->execute([$username]);
        $stmtPhone = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
        $stmtPhone->execute([$phone]);
        if ($stmtEmail->fetch()) {
            $error = 'This email is already registered.';
        } elseif ($stmtUser->fetch()) {
            $error = 'This username is already taken. Please choose another.';
        } elseif ($stmtPhone->fetch()) {
            $error = 'This phone number is already registered to another account.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role, phone, town_id, custom_town, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $username, $email, $passwordHash, 'customer', $phone, $townId, $customTown]);
            $userId = $pdo->lastInsertId();

            if (!empty($_FILES['profile_photo']['name'])) {
                $profilePhotoPath = process_profile_image($_FILES['profile_photo'], 'uploads/profiles/' . $userId);
                if ($profilePhotoPath) {
                    $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$profilePhotoPath, $userId]);
                }
            }

            // Generate email verification token and send
            $verifyToken = bin2hex(random_bytes(32));
            $pdo->prepare(
                'UPDATE users SET email_verified = 0, email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ?'
            )->execute([$verifyToken, $userId]);
            require_once __DIR__ . '/services/EmailService.php';
            EmailService::sendVerificationEmail($email, $name, $verifyToken);

            $stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, role, phone, town_id, custom_town, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            login_user($user);

            // Points & referral hooks
            award_points((int)$userId, 'registration');
            if (isset($profilePhotoPath) && $profilePhotoPath) {
                award_points((int)$userId, 'profile_photo');
            }
            // A clicked referral link (captured to session on page load) takes
            // priority since it's already tracked with a visit record; if the
            // user never clicked a link at all, fall back to whatever code
            // they typed in manually — referrals still benefit either way.
            $refCode = $_SESSION['ref_code'] ?? '';
            if ($refCode === '' && trim($_POST['referral_code'] ?? '') !== '') {
                $refCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['referral_code'])));
            }
            if ($refCode !== '') {
                $referrerId = referral_code_owner($refCode);
                if ($referrerId && $referrerId !== (int)$userId) {
                    record_referral($referrerId, (int)$userId, $refCode);
                }
            }
            unset($_SESSION['ref_code']);

            header('Location: community.php');
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
    <title>Register — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <div style="text-align: center; margin-bottom: var(--space-4);">
            <img src="assets/images/ac%20logo%20removedbg.png" alt="AkuapemConnect" style="height:72px;width:auto;margin-bottom:12px;">
            <h1 style="margin: 0;">Create your account</h1>
            <p class="meta">Join AkuapemConnect to find work or get jobs done</p>
        </div>
        <form class="card form-card" method="post" action="register.php" enctype="multipart/form-data" id="register-form">
            <?php echo csrf_field(); ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>

            <a href="google_auth.php" class="button button-secondary" style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;box-sizing:border-box;margin-bottom:14px;">
                <?php require __DIR__ . '/partials/google_icon.php'; ?>
                Continue with Google
            </a>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;color:var(--text-muted,#6b7280);font-size:.8rem;">
                <span style="flex:1;height:1px;background:var(--border,#e5e7eb);"></span>
                or create an account with email
                <span style="flex:1;height:1px;background:var(--border,#e5e7eb);"></span>
            </div>

            <label>Full name</label>
            <input type="text" name="name" required value="<?php echo sanitize($_POST['name'] ?? ''); ?>" />
            <label>Username</label>
            <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,30}" title="3–30 characters: letters, numbers, underscores only" value="<?php echo sanitize($_POST['username'] ?? ''); ?>" placeholder="e.g. kwame_builds" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">This is what other users see instead of your real name. No spaces are allowed.3–30 characters, letters/numbers/underscores.</p>
            <label>Email</label>
            <input type="email" name="email" required value="<?php echo sanitize($_POST['email'] ?? ''); ?>" />
            <label>Password</label>
            <input type="password" name="password" id="register-password" required minlength="6" />
            <label class="pw-show-label">
                <input type="checkbox" onchange="togglePasswordField('register-password', this.checked)" />
                Show password
            </label>
            <label>Phone number</label>
            <input type="text" name="phone" required placeholder="e.g. 0244000000" value="<?php echo sanitize($_POST['phone'] ?? ''); ?>" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">We'll use this number for WhatsApp/SMS updates and as your contact info across the app — no need to retype it later.</p>
            <label>Town</label>
            <select name="town_id" id="town-select" required onchange="document.getElementById('custom-town-row').style.display=this.value==='__other__'?'block':'none';">
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
                <option value="__other__" <?php echo (($_POST['town_id'] ?? '') === '__other__') ? 'selected' : ''; ?>>Other (outside Akuapem — specify)</option>
            </select>
            <div id="custom-town-row" style="display:<?php echo (($_POST['town_id'] ?? '') === '__other__') ? 'block' : 'none'; ?>;margin-top:8px;">
                <input type="text" name="custom_town" placeholder="Enter your town/city" value="<?php echo sanitize($_POST['custom_town'] ?? ''); ?>" />
            </div>
            <label>Profile picture <span class="meta">(optional)</span></label>
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">Shown on your dashboard. JPEG, PNG, or WEBP, up to 5MB.</p>

            <label>Referral code <span class="meta">(optional)</span></label>
            <input type="text" name="referral_code" value="<?php echo sanitize($_POST['referral_code'] ?? ($_SESSION['ref_code'] ?? '')); ?>" placeholder="e.g. ABC123XY" style="text-transform:uppercase;" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">Were you invited by a friend? Enter their referral code so they get credit — even if you didn't use their link.</p>

            <div style="margin-top: 12px;">
                <button type="submit" class="button button-primary">Create account</button>
            </div>
            <p class="small-note">Already registered? <a href="login.php">Sign in</a></p>
        </form>
    </main>
    <script src="assets/js/image-compress.js"></script>
    <script src="assets/js/password-toggle.js"></script>
    <script src="assets/js/username-input.js"></script>
    <script>
        setupImageInput(document.querySelector('input[name="profile_photo"]'), 800, 800, 0.82);
    </script>
</body>
</html>

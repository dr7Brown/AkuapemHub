<?php
/**
 * Shown once, right after a first-time Google sign-up (google_callback.php
 * redirects here for is_new accounts only — not a global gate). Collects
 * username/phone/town using the exact same validation register.php runs,
 * since those fields are used meaningfully across the app (Call buttons,
 * delivery contact info, etc.) but Google's OAuth response can't provide them.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';

require_login();
$user = current_user();

// Already complete (e.g. reached this page a second time via back button) —
// nothing left to collect.
if (!needs_profile_completion($user)) {
    header('Location: community.php');
    exit;
}

$towns = get_towns_grouped_by_district();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username   = trim($_POST['username'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $townIdRaw  = $_POST['town_id'] ?? '';
    $customTown = null;
    if ($townIdRaw === '__other__') {
        $customTown = trim($_POST['custom_town'] ?? '');
        $townId = $customTown !== '' ? get_other_town_id() : null;
    } else {
        $townId = intval($townIdRaw) ?: null;
    }

    if ($username === '' || $phone === '' || !$townId) {
        $error = ($townIdRaw === '__other__')
            ? 'Please specify your location.'
            : 'Username, phone number and town are all required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters and can only contain letters, numbers, and underscores.';
    } else {
        $stmtUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $stmtUser->execute([$username, $user['id']]);
        $stmtPhone = $pdo->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
        $stmtPhone->execute([$phone, $user['id']]);
        if ($stmtUser->fetch()) {
            $error = 'This username is already taken. Please choose another.';
        } elseif ($stmtPhone->fetch()) {
            $error = 'This phone number is already registered to another account.';
        } else {
            $pdo->prepare('UPDATE users SET username = ?, phone = ?, town_id = ?, custom_town = ? WHERE id = ?')
                ->execute([$username, $phone, $townId, $customTown, $user['id']]);

            // Refresh the session snapshot so the rest of the app sees the
            // completed profile immediately, without requiring a re-login.
            $_SESSION['user']['username']    = $username;
            $_SESSION['user']['phone']       = $phone;
            $_SESSION['user']['town_id']     = $townId;
            $_SESSION['user']['custom_town'] = $customTown;

            // Points & referral hooks — a Google sign-up skips register.php's
            // form entirely (and never visits verify_email.php, since Google
            // already verified the address), so this is the one place those
            // two one-time bonuses and any referral credit get recorded.
            award_points((int)$user['id'], 'registration');
            award_points((int)$user['id'], 'email_verification');
            $refCode = $_SESSION['ref_code'] ?? '';
            if ($refCode === '' && trim($_POST['referral_code'] ?? '') !== '') {
                $refCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['referral_code'])));
            }
            if ($refCode !== '') {
                $referrerId = referral_code_owner($refCode);
                if ($referrerId && $referrerId !== (int)$user['id']) {
                    record_referral($referrerId, (int)$user['id'], $refCode);
                }
            }
            unset($_SESSION['ref_code']);

            flash('Welcome to ' . APP_NAME . '!', 'success');
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
    <title>Complete your profile — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <div style="text-align: center; margin-bottom: var(--space-4);">
            <img src="assets/images/ac%20logo%20removedbg.png" alt="<?php echo APP_NAME; ?>" style="height:72px;width:auto;margin-bottom:12px;">
            <h1 style="margin: 0;">Almost there, <?php echo sanitize($user['name']); ?>!</h1>
            <p class="meta">Just a few more details to finish setting up your account</p>
        </div>
        <form class="card form-card" method="post" action="complete_profile.php">
            <?php echo csrf_field(); ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>

            <label>Username</label>
            <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,30}" title="3–30 characters: letters, numbers, underscores only" value="<?php echo sanitize($_POST['username'] ?? ''); ?>" placeholder="e.g. kwame_builds" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">This is what other users see instead of your real name.</p>

            <label>Phone number</label>
            <input type="text" name="phone" required placeholder="e.g. 0244000000" value="<?php echo sanitize($_POST['phone'] ?? ''); ?>" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">We'll use this for WhatsApp/SMS updates and as your contact info across the app.</p>

            <label>Town</label>
            <select name="town_id" id="town-select" required onchange="document.getElementById('custom-town-row').style.display=this.value==='__other__'?'block':'none';">
                <option value="">Select your town</option>
                <?php foreach ($towns as $district => $ts): ?>
                <optgroup label="<?php echo sanitize($district); ?>">
                    <?php foreach ($ts as $town): ?>
                    <option value="<?php echo $town['id']; ?>" <?php echo (isset($_POST['town_id']) && $_POST['town_id'] == $town['id']) ? 'selected' : ''; ?>><?php echo sanitize($town['name']); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
                <option value="__other__" <?php echo (($_POST['town_id'] ?? '') === '__other__') ? 'selected' : ''; ?>>Other (outside Akuapem — specify)</option>
            </select>
            <div id="custom-town-row" style="display:<?php echo (($_POST['town_id'] ?? '') === '__other__') ? 'block' : 'none'; ?>;margin-top:8px;">
                <input type="text" name="custom_town" placeholder="Enter your town/city" value="<?php echo sanitize($_POST['custom_town'] ?? ''); ?>" />
            </div>

            <label>Referral code <span class="meta">(optional)</span></label>
            <input type="text" name="referral_code" value="<?php echo sanitize($_POST['referral_code'] ?? ($_SESSION['ref_code'] ?? '')); ?>" placeholder="e.g. ABC123XY" style="text-transform:uppercase;" />
            <p class="small-note" style="text-align: left; margin-top: 4px;">Were you invited by a friend? Enter their referral code so they get credit — even if you didn't use their link.</p>

            <div style="margin-top: 12px;">
                <button type="submit" class="button button-primary">Continue</button>
            </div>
        </form>
    </main>
    <script src="assets/js/username-input.js"></script>
</body>
</html>

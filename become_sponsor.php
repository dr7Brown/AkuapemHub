<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user = current_user();

$packages = get_active_packages('sponsor_packages');
$error = '';

// A pending (unpaid) sponsor submission already in progress — let them
// resume instead of creating a duplicate row + duplicate charge.
$existingStmt = $pdo->prepare("SELECT s.id, s.name, pp.reference_code FROM sponsors s
    LEFT JOIN platform_payments pp ON pp.reference_id = s.id AND pp.payment_type = 'sponsor' AND pp.status = 'pending'
    WHERE s.user_id = ? AND s.status = 'pending_payment' ORDER BY s.id DESC LIMIT 1");
$existingStmt->execute([$user['id']]);
$existingPending = $existingStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name'] ?? '');
    $packageId   = (int)($_POST['package_id'] ?? 0);
    $websiteUrl  = trim($_POST['website_url'] ?? '') ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;
    $contactEmail = trim($_POST['contact_email'] ?? '') ?: null;
    $contactPhone = trim($_POST['contact_phone'] ?? '') ?: null;

    $package = null;
    foreach ($packages as $pkg) {
        if ((int)$pkg['id'] === $packageId) { $package = $pkg; break; }
    }

    if ($name === '') {
        $error = 'Sponsor / business name is required.';
    } elseif (!$package) {
        $error = 'Please select a sponsorship package.';
    } elseif ($websiteUrl && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid website URL.';
    } elseif ($contactEmail && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid contact email.';
    } elseif (empty($_FILES['logo']['name'])) {
        $error = 'Please upload your logo.';
    } elseif (!is_valid_image_upload($_FILES['logo'])) {
        $error = 'Logo must be a JPEG, PNG, or WEBP image under 5MB.';
    } else {
        $pdo->prepare("INSERT INTO sponsors (user_id, package_id, name, logo_path, website_url, description, contact_email, contact_phone, status, created_at)
            VALUES (?,?,?,?,?,?,?,?,'pending_payment',NOW())")
            ->execute([$user['id'], $package['id'], $name, '', $websiteUrl, $description, $contactEmail, $contactPhone]);
        $sponsorId = (int)$pdo->lastInsertId();

        $logoPath = save_uploaded_image($_FILES['logo'], 'uploads/sponsors/' . $sponsorId, 600);
        if (!$logoPath) {
            $pdo->prepare("DELETE FROM sponsors WHERE id = ?")->execute([$sponsorId]);
            $error = 'Could not save your logo. Please try a different image.';
        }
    }

    if (!$error && isset($logoPath) && $logoPath) {
        $pdo->prepare("UPDATE sponsors SET logo_path = ? WHERE id = ?")->execute([$logoPath, $sponsorId]);

        $result = initializePayment($user['id'], $user['email'], 'sponsor', $sponsorId, $package['id'], (float)$package['price'], ['sponsor_name' => $name]);
        if (isset($result['error'])) {
            $pdo->prepare("DELETE FROM sponsors WHERE id = ?")->execute([$sponsorId]);
            $error = $result['error'];
        } else {
            header('Location: ' . $result['checkout_url']);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Become a Sponsor — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bs-shell { max-width:600px; margin:0 auto; padding:20px 16px 60px; }
        .bs-pkg { display:flex; align-items:center; gap:12px; padding:14px; border:2px solid var(--border,#e5e7eb); border-radius:10px; margin-bottom:10px; cursor:pointer; }
        .bs-pkg input:checked ~ span { color: var(--primary,#0f766e); }
        label.bs-pkg:has(input:checked) { border-color: var(--primary,#0f766e); background: var(--primary-soft,#d1fae5); }
        .form-group { margin-bottom:14px; }
        label:not(.bs-pkg) { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="index.php" class="button button-secondary button-small">← Home</a>
        <span class="brand">🤝 Become a Sponsor</span>
    </header>
    <main class="bs-shell">
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <?php if ($existingPending): ?>
        <div class="card">
            <h2 style="margin-top:0;">Payment pending</h2>
            <p style="color:var(--muted);font-size:.9rem;">
                You already have a sponsor submission ("<?php echo sanitize($existingPending['name']); ?>") awaiting payment
                <?php if ($existingPending['reference_code']): ?>(ref: <?php echo sanitize($existingPending['reference_code']); ?>)<?php endif; ?>.
            </p>
            <a href="my_payments.php" class="button button-primary">Track your payment →</a>
        </div>
        <?php else: ?>

        <div class="card">
            <h2 style="margin-top:0;">Sponsor the Community</h2>
            <p style="color:var(--muted);font-size:.9rem;">Get your business logo featured on the AkuapemConnect homepage, seen by every visitor. Pick a package, tell us about your business, and go live once approved.</p>
        </div>

        <?php if (empty($packages)): ?>
        <div class="alert alert-info">Sponsorship packages aren't available right now. Check back later.</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="card form-card">
            <?php echo csrf_field(); ?>

            <p class="pf-section-title" style="text-transform:uppercase;font-size:.74rem;font-weight:800;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 10px;">Choose a package</p>
            <?php foreach ($packages as $pkg): ?>
            <label class="bs-pkg">
                <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required <?php echo (($_POST['package_id'] ?? '') == $pkg['id']) ? 'checked' : ''; ?> />
                <span>
                    <strong><?php echo sanitize($pkg['name']); ?></strong>
                    <span style="color:var(--muted,#6b7280);font-size:.82rem;"> — <?php echo (int)$pkg['duration_days']; ?> days</span><br>
                    <strong style="color:var(--primary,#0f766e);">GH₵ <?php echo number_format($pkg['price'], 2); ?></strong>
                </span>
            </label>
            <?php endforeach; ?>

            <div class="form-group" style="margin-top:18px;">
                <label>Sponsor / business name</label>
                <input type="text" name="name" required value="<?php echo sanitize($_POST['name'] ?? ''); ?>" />
            </div>
            <div class="form-group">
                <label>Logo</label>
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required />
                <p class="small-note" style="margin-top:4px;">JPEG, PNG, or WEBP, up to 5MB. Shown on the homepage.</p>
            </div>
            <div class="form-group">
                <label>Website URL <span class="meta">(optional)</span></label>
                <input type="url" name="website_url" placeholder="https://" value="<?php echo sanitize($_POST['website_url'] ?? ''); ?>" />
            </div>
            <div class="form-group">
                <label>Short description <span class="meta">(optional)</span></label>
                <textarea name="description" rows="3" maxlength="500"><?php echo sanitize($_POST['description'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label>Contact email <span class="meta">(optional)</span></label>
                <input type="email" name="contact_email" value="<?php echo sanitize($_POST['contact_email'] ?? ''); ?>" />
            </div>
            <div class="form-group">
                <label>Contact phone <span class="meta">(optional)</span></label>
                <input type="text" name="contact_phone" value="<?php echo sanitize($_POST['contact_phone'] ?? ''); ?>" />
            </div>

            <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">🔒 Continue to Payment</button>
            <p style="font-size:.76rem;color:var(--muted);text-align:center;margin-top:10px;">Your logo goes live once payment is confirmed and an admin reviews your submission.</p>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <script src="assets/js/image-compress.js"></script>
    <script>
        setupImageInput(document.querySelector('input[name="logo"]'), 800, 800, 0.85);
    </script>
    <?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

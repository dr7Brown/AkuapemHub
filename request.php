<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$categories = get_categories();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId = intval($_POST['category_id'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $budget = trim($_POST['budget'] ?? '');
    $contactInfo = trim($_POST['contact_info'] ?? '');

    if ($title === '' || $description === '' || $categoryId === 0 || $location === '' || $budget === '' || $contactInfo === '') {
        $error = 'All fields are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO service_requests (customer_id, title, description, category_id, location, budget, contact_info, status, payment_status, commission_percent, featured, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$user['id'], $title, $description, $categoryId, $location, $budget, $contactInfo, 'pending', 'unpaid', DEFAULT_COMMISSION, 0]);

        $categoryName = 'Unknown';
        foreach ($categories as $category) {
            if ($category['id'] === $categoryId) {
                $categoryName = $category['name'];
                break;
            }
        }

        $adminMessage = "New service request created by {$user['name']} ({$user['email']}):\n\n" .
                        "Title: {$title}\n" .
                        "Category: {$categoryName}\n" .
                        "Location: {$location}\n" .
                        "Budget: GH₵ {$budget}\n" .
                        "Contact: {$contactInfo}\n\n" .
                        "Please review and approve the request in the admin panel.";
        send_email_notification(ADMIN_EMAIL, 'New AkuapemHub service request', $adminMessage);

        flash('Service request created successfully. Admin will approve it before workers can accept.');
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>New Request — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>New request</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="request.php">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Title</label>
            <input type="text" name="title" required />
            <label>Description</label>
            <textarea name="description" rows="4" required></textarea>
            <label>Category</label>
            <select name="category_id" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['id']; ?>"><?php echo sanitize($category['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Location</label>
            <input type="text" name="location" required placeholder="City, neighbourhood" />
            <label>Budget</label>
            <input type="text" name="budget" required placeholder="GH₵ 100 or Negotiable" />
            <label>Contact info</label>
            <input type="text" name="contact_info" required placeholder="Phone or WhatsApp" />
            <button type="submit" class="button button-primary">Publish request</button>
        </form>
    </main>
</body>
</html>

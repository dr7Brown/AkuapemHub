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
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;

    if ($title === '' || $description === '' || $categoryId === 0 || $location === '' || $budget === '' || $contactInfo === '') {
        $error = 'All fields are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO service_requests (customer_id, title, description, category_id, location, latitude, longitude, budget, contact_info, status, payment_status, commission_percent, featured, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$user['id'], $title, $description, $categoryId, $location, $latitude, $longitude, $budget, $contactInfo, 'pending', 'unpaid', DEFAULT_COMMISSION, 0]);

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
            <input type="hidden" name="latitude" id="latitude" />
            <input type="hidden" name="longitude" id="longitude" />
            <button type="button" id="use-my-location" class="button button-secondary button-small">Use my current location</button>
            <p class="meta" id="location-status">Sharing your location helps nearby workers find your job faster.</p>
            <label>Budget</label>
            <input type="text" name="budget" required placeholder="GH₵ 100 or Negotiable" />
            <p class="meta" id="budget-suggestion"></p>
            <label>Contact info</label>
            <input type="text" name="contact_info" required placeholder="Phone or WhatsApp" />
            <button type="submit" class="button button-primary">Publish request</button>
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

        var categorySelect = document.querySelector('select[name="category_id"]');
        var locationInput = document.querySelector('input[name="location"]');
        var budgetSuggestion = document.getElementById('budget-suggestion');
        var suggestionTimer = null;

        function fetchBudgetSuggestion() {
            var categoryId = categorySelect.value;
            if (!categoryId) {
                budgetSuggestion.textContent = '';
                return;
            }
            var url = 'suggest_budget.php?category_id=' + encodeURIComponent(categoryId) + '&location=' + encodeURIComponent(locationInput.value);
            fetch(url)
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.suggestion) {
                        budgetSuggestion.textContent = '';
                        return;
                    }
                    var s = data.suggestion;
                    var scopeText = s.scope === 'nearby' ? 'Similar jobs near this location' : 'Similar jobs across AkuapemHub';
                    budgetSuggestion.textContent = '💡 ' + scopeText + ' typically went for GH₵ ' + Math.round(s.min) + '–' + Math.round(s.max) +
                        ' (avg GH₵ ' + Math.round(s.avg) + ') based on ' + s.count + ' job' + (s.count === 1 ? '' : 's') + '.';
                })
                .catch(function () { budgetSuggestion.textContent = ''; });
        }

        categorySelect.addEventListener('change', fetchBudgetSuggestion);
        locationInput.addEventListener('input', function () {
            clearTimeout(suggestionTimer);
            suggestionTimer = setTimeout(fetchBudgetSuggestion, 500);
        });
    </script>
</body>
</html>

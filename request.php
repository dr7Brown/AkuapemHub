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
    $contactInfo = trim($user['phone'] ?? '');
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
    $skillsNeeded = trim($_POST['skills_needed'] ?? '');

    if ($title === '' || $description === '' || $categoryId === 0 || $location === '' || $budget === '') {
        $error = 'All fields are required.';
    } elseif ($contactInfo === '') {
        $error = 'Add a phone number to your account on your profile before posting a request.';
    } else {
        $postingFeePaid = is_feature_paid('enable_paid_job_posting');
        $postingFeeStatus = $postingFeePaid ? 'pending' : 'free';

        $stmt = $pdo->prepare('INSERT INTO service_requests (customer_id, title, description, category_id, location, latitude, longitude, budget, contact_info, skills_needed, status, payment_status, commission_percent, featured, posting_fee_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$user['id'], $title, $description, $categoryId, $location, $latitude, $longitude, $budget, $contactInfo, $skillsNeeded !== '' ? $skillsNeeded : null, 'pending', 'unpaid', DEFAULT_COMMISSION, 0, $postingFeeStatus]);
        $newJobId = (int)$pdo->lastInsertId();

        $categoryName = 'Unknown';
        foreach ($categories as $category) {
            if ($category['id'] === $categoryId) {
                $categoryName = $category['name'];
                break;
            }
        }

        if ($postingFeePaid) {
            notify_admins_and_managers(
                'New job — posting fee required',
                display_name($user) . ' posted "' . $title . '" but it requires a posting fee before approval. See Monetization → Pending Payments.',
                'info'
            );
            flash('Job saved. Please complete the posting fee payment to submit it for review.', 'info');
            header('Location: pay_job_post.php?id=' . $newJobId);
            exit;
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
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">📝</span> Post a Job</span>
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
            <p class="meta" id="category-suggestion-note"></p>

            <label>Skills needed <span class="meta">(optional — helps us match the right workers)</span></label>
            <select id="skill-name-select" disabled>
                <option value="">Select a category first</option>
            </select>
            <button type="button" id="add-skill-button" class="button button-secondary button-small">+ Add skill</button>
            <ul id="skill-list" style="list-style: none; padding: 0; margin: 8px 0; display: flex; flex-wrap: wrap; gap: 8px;"></ul>
            <input type="hidden" name="skills_needed" id="skills-needed-input" />

            <label>Location</label>
            <select id="town-select">
                <option value="">Select your town</option>
                <?php $currentDistrict = null; ?>
                <?php foreach (get_towns() as $town): ?>
                    <?php if ($town['district'] !== $currentDistrict): ?>
                        <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                        <optgroup label="<?php echo sanitize($town['district']); ?>">
                        <?php $currentDistrict = $town['district']; ?>
                    <?php endif; ?>
                    <option value="<?php echo sanitize($town['name']); ?>"><?php echo sanitize($town['name']); ?></option>
                <?php endforeach; ?>
                <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                <option value="__other__">Other (specify)</option>
            </select>
            <input type="text" name="location" id="location-input" required placeholder="City, neighbourhood" readonly />
            <input type="hidden" name="latitude" id="latitude" />
            <input type="hidden" name="longitude" id="longitude" />
            <button type="button" id="use-my-location" class="button button-secondary button-small">Use my current location</button>
            <p class="meta" id="location-status">Sharing your location helps nearby workers find your job faster.</p>
            <label>Budget</label>
            <input type="text" name="budget" required placeholder="GH₵ 100 or Negotiable" />
            <p class="meta" id="budget-suggestion"></p>
            <label>Contact info</label>
            <?php if (!empty($user['phone'])): ?>
                <p class="meta">📱 Workers will reach you on your registered number: <strong><?php echo sanitize($user['phone']); ?></strong></p>
            <?php else: ?>
                <p class="alert alert-error">No phone number on file. Please add one to your account before posting — contact support to update it.</p>
            <?php endif; ?>
            <button type="submit" class="button button-primary" <?php echo empty($user['phone']) ? 'disabled' : ''; ?>>Publish request</button>
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

        var townSelect = document.getElementById('town-select');
        var locationInputEl = document.getElementById('location-input');
        townSelect.addEventListener('change', function () {
            if (this.value === '__other__') {
                locationInputEl.value = '';
                locationInputEl.readOnly = false;
                locationInputEl.placeholder = 'Enter your location';
                locationInputEl.focus();
            } else if (this.value) {
                locationInputEl.value = this.value;
                locationInputEl.readOnly = true;
            } else {
                locationInputEl.value = '';
                locationInputEl.readOnly = true;
                locationInputEl.placeholder = 'City, neighbourhood';
            }
            locationInputEl.dispatchEvent(new Event('input'));
        });

        var categorySelect = document.querySelector('select[name="category_id"]');
        var locationInput = document.querySelector('input[name="location"]');
        var budgetSuggestion = document.getElementById('budget-suggestion');
        var suggestionTimer = null;

        var titleInput = document.querySelector('input[name="title"]');
        var descriptionInput = document.querySelector('textarea[name="description"]');
        var categoryNote = document.getElementById('category-suggestion-note');
        var categoryManuallyChanged = false;
        var categoryTimer = null;

        categorySelect.addEventListener('change', function () {
            categoryManuallyChanged = true;
            categoryNote.textContent = '';
        });

        function fetchCategorySuggestion() {
            if (categoryManuallyChanged) {
                return;
            }
            var title = titleInput.value.trim();
            var description = descriptionInput.value.trim();
            if (title === '' && description === '') {
                categoryNote.textContent = '';
                return;
            }
            var url = 'suggest_category.php?title=' + encodeURIComponent(title) + '&description=' + encodeURIComponent(description);
            fetch(url)
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (categoryManuallyChanged || !data.suggestion) {
                        return;
                    }
                    var s = data.suggestion;
                    categorySelect.value = String(s.category_id);
                    categoryNote.textContent = '✨ We pre-selected "' + s.category_name + '" based on your description. Change it if that\'s not right.';
                })
                .catch(function () {});
        }

        titleInput.addEventListener('input', function () {
            clearTimeout(categoryTimer);
            categoryTimer = setTimeout(fetchCategorySuggestion, 600);
        });
        descriptionInput.addEventListener('input', function () {
            clearTimeout(categoryTimer);
            categoryTimer = setTimeout(fetchCategorySuggestion, 600);
        });

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

        var categorySkillMap = <?php
            $skillTaxonomy = get_skill_taxonomy();
            $categoryTaxonomyOverrides = ['Errand' => 'Errand & Support Services'];
            $categorySkillMap = [];
            foreach ($categories as $category) {
                $taxonomyKey = $categoryTaxonomyOverrides[$category['name']] ?? $category['name'];
                $categorySkillMap[$category['id']] = $skillTaxonomy[$taxonomyKey] ?? [];
            }
            echo json_encode($categorySkillMap);
        ?>;
        var skillNameSelect = document.getElementById('skill-name-select');
        var addSkillButton = document.getElementById('add-skill-button');
        var skillList = document.getElementById('skill-list');
        var skillsNeededInput = document.getElementById('skills-needed-input');
        var selectedSkills = [];

        function refreshSkillOptions() {
            var skills = categorySkillMap[categorySelect.value] || [];
            skillNameSelect.innerHTML = '';
            selectedSkills = [];
            renderSkillList();
            if (!skills.length) {
                skillNameSelect.innerHTML = '<option value="">No specific skills for this category</option>';
                skillNameSelect.disabled = true;
                return;
            }
            skillNameSelect.disabled = false;
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select a skill';
            skillNameSelect.appendChild(placeholder);
            skills.forEach(function (skillName) {
                var option = document.createElement('option');
                option.value = skillName;
                option.textContent = skillName;
                skillNameSelect.appendChild(option);
            });
        }

        categorySelect.addEventListener('change', refreshSkillOptions);

        function renderSkillList() {
            skillList.innerHTML = '';
            selectedSkills.forEach(function (skillName, index) {
                var li = document.createElement('li');
                li.className = 'badge';
                li.style.display = 'inline-flex';
                li.style.alignItems = 'center';
                li.style.gap = '6px';
                li.textContent = skillName;
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '×';
                removeBtn.setAttribute('aria-label', 'Remove ' + skillName);
                removeBtn.style.border = 'none';
                removeBtn.style.background = 'transparent';
                removeBtn.style.cursor = 'pointer';
                removeBtn.addEventListener('click', function () {
                    selectedSkills.splice(index, 1);
                    renderSkillList();
                });
                li.appendChild(removeBtn);
                skillList.appendChild(li);
            });
            skillsNeededInput.value = selectedSkills.join(', ');
        }

        addSkillButton.addEventListener('click', function () {
            var skillName = skillNameSelect.value;
            if (!skillName || selectedSkills.indexOf(skillName) !== -1) {
                return;
            }
            selectedSkills.push(skillName);
            renderSkillList();
            skillNameSelect.value = '';
        });

        categorySelect.addEventListener('change', fetchBudgetSuggestion);
        locationInput.addEventListener('input', function () {
            clearTimeout(suggestionTimer);
            suggestionTimer = setTimeout(fetchBudgetSuggestion, 500);
        });
    </script>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

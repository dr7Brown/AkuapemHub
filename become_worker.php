<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('customer');
$user = current_user();

$stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$existingProfile = $stmt->fetch();

function refresh_session_user($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT id, name, email, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $_SESSION['user'] = $stmt->fetch();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($existingProfile) {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['worker', $user['id']]);
        refresh_session_user($pdo, $user['id']);
        notify_user($user['id'], 'Welcome back as a worker', 'You have switched back to worker mode. Your existing worker profile and skills are active again.', 'success');
        flash('You are now in worker mode again. Your existing worker profile is active.');
        header('Location: worker_profile.php');
        exit;
    }

    $idType = $_POST['id_type'] ?? '';
    $idNumber = trim($_POST['id_number'] ?? '');

    $skills = [];
    $decodedSkills = json_decode($_POST['skills_json'] ?? '', true);
    if (is_array($decodedSkills)) {
        foreach ($decodedSkills as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $skillName = trim((string)($entry['skill_name'] ?? ''));
            $categoryId = intval($entry['category_id'] ?? 0) ?: null;
            if ($skillName !== '') {
                $skills[] = ['category_id' => $categoryId, 'skill_name' => $skillName];
            }
        }
    }

    if (!in_array($idType, ['ghana_card', 'passport'], true) || $idNumber === '') {
        $error = 'Select an ID type (Ghana Card or Passport) and enter your ID card number.';
    } elseif (empty($_FILES['id_document']['name'])) {
        $error = 'Upload a clear photo of your Ghana Card or Passport.';
    } elseif (!is_valid_image_upload($_FILES['id_document'])) {
        $error = 'ID card photo must be a JPEG, PNG, or WEBP image under 5MB.';
    } elseif (empty($skills)) {
        $error = 'Select at least one skill you offer.';
    } elseif (trim($user['phone'] ?? '') === '') {
        $error = 'Add a phone number to your account in Settings before becoming a worker.';
    } else {
        $idDocumentPath = save_uploaded_image($_FILES['id_document'], 'uploads/worker_ids/' . $user['id']);
        $townName = get_town_name($user['town_id']) ?: '';

        $pdo->beginTransaction();
        try {
            $serviceFeeStatus = is_feature_paid('enable_paid_worker_service') ? 'pending' : 'free';
            $stmt = $pdo->prepare('INSERT INTO worker_profiles (user_id, bio, location, latitude, longitude, contact_phone, id_type, id_number, id_document_path, availability, service_fee_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$user['id'], '', $townName, $user['latitude'], $user['longitude'], $user['phone'], $idType, $idNumber, $idDocumentPath, 'available', $serviceFeeStatus]);
            $profileId = $pdo->lastInsertId();

            $skillStmt = $pdo->prepare('INSERT INTO worker_skills (worker_profile_id, category_id, skill_name) VALUES (?, ?, ?)');
            foreach ($skills as $skill) {
                $skillStmt->execute([$profileId, $skill['category_id'], $skill['skill_name']]);
            }

            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['worker', $user['id']]);
            $pdo->commit();

            refresh_session_user($pdo, $user['id']);
            notify_user($user['id'], 'Welcome to working on AkuapemHub', 'Your worker profile has been created. You can now browse and accept jobs.', 'success');
            if ($serviceFeeStatus === 'pending') {
                flash('Worker profile created! Complete your service listing payment to appear in search results.', 'info');
                header('Location: pay_worker_service.php');
            } else {
                flash('You are now registered as a worker. Welcome aboard!');
                header('Location: worker_profile.php');
            }
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Unable to set up your worker profile. Please try again.';
        }
    }
}

$skillCategories = get_skill_categories_with_skills();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Become a worker — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🧰</span> Become a Worker</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <?php if ($existingProfile): ?>
            <form class="card form-card" method="post" action="become_worker.php">
                <?php echo csrf_field(); ?>
                <h2>Welcome back</h2>
                <p class="meta">You already have a worker profile from a previous registration, including your verified ID and skills. Switch back to worker mode to start accepting jobs again — no need to redo verification.</p>
                <button type="submit" class="button button-primary">Switch to worker mode</button>
            </form>
        <?php else: ?>
            <form class="card form-card" method="post" action="become_worker.php" enctype="multipart/form-data" id="become-worker-form">
                <?php echo csrf_field(); ?>
                <p class="meta" id="step-indicator">Step 1 of 2</p>

                <div class="wizard-step" data-step="1">
                    <h2>Identity verification</h2>
                    <p class="meta">Workers must verify their identity with a Ghana Card or Passport before they can accept jobs. This is for trust &amp; safety only — it is not shared publicly.</p>
                    <label>ID type</label>
                    <select name="id_type" id="id-type-select" required>
                        <option value="">Select ID type</option>
                        <option value="ghana_card">Ghana Card</option>
                        <option value="passport">Passport</option>
                    </select>
                    <label>ID card number</label>
                    <input type="text" name="id_number" id="id-number-input" required placeholder="e.g. GHA-000000000-0" />
                    <label>Photo of ID card</label>
                    <input type="file" name="id_document" id="id-document-input" required accept="image/jpeg,image/png,image/webp" />
                    <p class="small-note" style="text-align: left; margin-top: 4px;">Ghana Card or Passport — a clear photo of the card, JPEG/PNG/WEBP up to 5MB.</p>
                </div>

                <div class="wizard-step" data-step="2" style="display: none;">
                    <h2>Your skills</h2>
                    <p class="meta">Pick the skills you offer so customers and our matching system can find you. Choose a category, then a skill, and add it to your list. You can add as many as you like.</p>
                    <label>Skill category</label>
                    <select id="skill-category-select">
                        <option value="">Select a category</option>
                        <?php foreach ($skillCategories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo sanitize($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Skill</label>
                    <select id="skill-select" disabled>
                        <option value="">Select a category first</option>
                    </select>
                    <div id="other-skill-wrap" style="display: none;">
                        <label>Specify your skill</label>
                        <input type="text" id="other-skill-input" placeholder="e.g. Borehole drilling" />
                    </div>
                    <button type="button" id="add-skill-button" class="button button-secondary button-small">+ Add skill</button>
                    <p class="meta" id="skill-list-empty">No skills added yet — add at least one to continue.</p>
                    <ul id="skill-list" style="list-style: none; padding: 0; margin: 8px 0; display: flex; flex-wrap: wrap; gap: 8px;"></ul>
                    <input type="hidden" name="skills_json" id="skills-json" value="[]" />
                </div>

                <div class="wizard-nav" style="display: flex; justify-content: space-between; gap: 10px; margin-top: 12px;">
                    <button type="button" id="wizard-back" class="button button-secondary" style="display: none;">Back</button>
                    <button type="button" id="wizard-next" class="button button-primary">Next</button>
                    <button type="submit" id="wizard-submit" class="button button-primary" style="display: none;">Create worker profile</button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <?php if (!$existingProfile): ?>
    <script>
        var skillTaxonomy = <?php echo json_encode($skillCategories); ?>;

        var stepIndicator = document.getElementById('step-indicator');
        var backButton = document.getElementById('wizard-back');
        var nextButton = document.getElementById('wizard-next');
        var submitButton = document.getElementById('wizard-submit');
        var stepEls = {};
        document.querySelectorAll('.wizard-step').forEach(function (el) {
            stepEls[el.getAttribute('data-step')] = el;
        });
        var steps = ['1', '2'];
        var currentStepIndex = 0;

        function showStep(index) {
            currentStepIndex = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach(function (stepKey, i) {
                stepEls[stepKey].style.display = (i === currentStepIndex) ? 'block' : 'none';
            });
            stepIndicator.textContent = 'Step ' + (currentStepIndex + 1) + ' of ' + steps.length;
            backButton.style.display = currentStepIndex > 0 ? 'inline-flex' : 'none';
            var isLastStep = currentStepIndex === steps.length - 1;
            nextButton.style.display = isLastStep ? 'none' : 'inline-flex';
            submitButton.style.display = isLastStep ? 'inline-flex' : 'none';
        }

        backButton.addEventListener('click', function () {
            showStep(currentStepIndex - 1);
        });

        nextButton.addEventListener('click', function () {
            var currentEl = stepEls[steps[currentStepIndex]];
            var fields = currentEl.querySelectorAll('input, select, textarea');
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].reportValidity()) {
                    return;
                }
            }
            showStep(currentStepIndex + 1);
        });

        showStep(0);

        // Skills picker (step 2)
        var categorySelect = document.getElementById('skill-category-select');
        var skillSelect = document.getElementById('skill-select');
        var otherWrap = document.getElementById('other-skill-wrap');
        var otherInput = document.getElementById('other-skill-input');
        var addSkillButton = document.getElementById('add-skill-button');
        var skillList = document.getElementById('skill-list');
        var skillListEmpty = document.getElementById('skill-list-empty');
        var skillsJsonInput = document.getElementById('skills-json');
        var selectedSkills = [];

        function findCategory(categoryId) {
            for (var i = 0; i < skillTaxonomy.length; i++) {
                if (String(skillTaxonomy[i].id) === String(categoryId)) {
                    return skillTaxonomy[i];
                }
            }
            return null;
        }

        categorySelect.addEventListener('change', function () {
            var category = findCategory(categorySelect.value);
            skillSelect.innerHTML = '';
            otherWrap.style.display = 'none';
            otherInput.value = '';
            if (!category) {
                skillSelect.disabled = true;
                skillSelect.innerHTML = '<option value="">Select a category first</option>';
                return;
            }
            skillSelect.disabled = false;
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = 'Select a skill';
            skillSelect.appendChild(blank);
            category.skills.forEach(function (skillName) {
                var opt = document.createElement('option');
                opt.value = skillName;
                opt.textContent = skillName;
                skillSelect.appendChild(opt);
            });
            var otherOpt = document.createElement('option');
            otherOpt.value = '__other__';
            otherOpt.textContent = 'Other (specify)';
            skillSelect.appendChild(otherOpt);
        });

        skillSelect.addEventListener('change', function () {
            otherWrap.style.display = skillSelect.value === '__other__' ? 'block' : 'none';
        });

        function renderSkillList() {
            skillList.innerHTML = '';
            skillListEmpty.style.display = selectedSkills.length === 0 ? 'block' : 'none';
            selectedSkills.forEach(function (skill, index) {
                var li = document.createElement('li');
                li.className = 'badge';
                li.style.display = 'inline-flex';
                li.style.alignItems = 'center';
                li.style.gap = '6px';
                li.textContent = skill.category_name + ': ' + skill.skill_name;
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '×';
                removeBtn.setAttribute('aria-label', 'Remove ' + skill.skill_name);
                removeBtn.style.border = 'none';
                removeBtn.style.background = 'transparent';
                removeBtn.style.cursor = 'pointer';
                removeBtn.style.fontWeight = 'bold';
                removeBtn.addEventListener('click', function () {
                    selectedSkills.splice(index, 1);
                    renderSkillList();
                });
                li.appendChild(removeBtn);
                skillList.appendChild(li);
            });
            skillsJsonInput.value = JSON.stringify(selectedSkills);
        }

        addSkillButton.addEventListener('click', function () {
            var category = findCategory(categorySelect.value);
            if (!category) {
                categorySelect.focus();
                return;
            }
            var skillName = '';
            if (skillSelect.value === '__other__') {
                skillName = otherInput.value.trim();
                if (skillName === '') {
                    otherInput.focus();
                    return;
                }
            } else {
                skillName = skillSelect.value;
                if (skillName === '') {
                    skillSelect.focus();
                    return;
                }
            }
            var exists = selectedSkills.some(function (s) {
                return s.category_id === category.id && s.skill_name.toLowerCase() === skillName.toLowerCase();
            });
            if (exists) {
                return;
            }
            selectedSkills.push({ category_id: category.id, category_name: category.name, skill_name: skillName });
            renderSkillList();
            otherInput.value = '';
            otherWrap.style.display = 'none';
            skillSelect.value = '';
        });

        renderSkillList();

        document.getElementById('become-worker-form').addEventListener('submit', function (e) {
            if (selectedSkills.length === 0) {
                e.preventDefault();
                showStep(1);
                skillListEmpty.textContent = 'Add at least one skill before creating your worker profile.';
                skillListEmpty.style.display = 'block';
            }
        });
    </script>
    <?php endif; ?>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

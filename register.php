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
        record_referral_visit($rc, $_SERVER['REMOTE_ADDR'] ?? '');
    }
}

$towns = get_towns();
$skillCategories = get_skill_categories_with_skills();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] === 'worker' ? 'worker' : 'customer';
    $phone = trim($_POST['phone'] ?? '');
    $townId = intval($_POST['town_id'] ?? 0) ?: null;
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
    $idType = $_POST['id_type'] ?? '';
    $idNumber = trim($_POST['id_number'] ?? '');
    $idTypeCustom = trim($_POST['id_type_custom'] ?? '');

    $skills = [];
    if ($role === 'worker') {
        $decodedSkills = json_decode($_POST['skills_json'] ?? '', true);
        if (is_array($decodedSkills)) {
            foreach ($decodedSkills as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $skillName = trim((string)($entry['skill_name'] ?? ''));
                $categoryId = intval($entry['category_id'] ?? 0) ?: null;
                $customCatName = trim((string)($entry['category_name'] ?? ''));
                if ($categoryId === null && $customCatName !== '') {
                    $catCheck = $pdo->prepare('SELECT id FROM skill_categories WHERE LOWER(name) = LOWER(?)');
                    $catCheck->execute([$customCatName]);
                    if ($catRow = $catCheck->fetch()) {
                        $categoryId = (int)$catRow['id'];
                    } else {
                        $pdo->prepare('INSERT INTO skill_categories (name) VALUES (?)')->execute([$customCatName]);
                        $categoryId = (int)$pdo->lastInsertId();
                    }
                }
                if ($skillName !== '') {
                    $skills[] = ['category_id' => $categoryId, 'skill_name' => $skillName];
                }
            }
        }
    }

    $workerError = '';
    if ($role === 'worker') {
        if (!in_array($idType, ['ghana_card', 'passport', 'other'], true) || $idNumber === '') {
            $workerError = 'Select an ID type and enter your ID card number.';
        } elseif ($idType === 'other' && $idTypeCustom === '') {
            $workerError = 'Please specify the type of ID document you are using.';
        } elseif ($idType === 'ghana_card' && !validate_ghana_card($idNumber)) {
            $workerError = 'Ghana Card number must be in the format GHA-123456789-0.';
        } elseif ($idType === 'ghana_card' && is_ghana_card_duplicate($idNumber)) {
            $workerError = 'This Ghana Card number is already registered to another account.';
        } elseif (empty($_FILES['id_document']['name'])) {
            $workerError = 'Upload a clear photo of your ID document.';
        } elseif (!is_valid_image_upload($_FILES['id_document'])) {
            $workerError = 'ID card photo must be a JPEG, PNG, or WEBP image under 5MB.';
        } elseif (empty($skills)) {
            $workerError = 'Select at least one skill you offer.';
        }
    }

    if ($name === '' || $username === '' || $email === '' || $password === '' || $phone === '' || !$townId) {
        $error = 'All fields are required, including username, phone number and town.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters and can only contain letters, numbers, and underscores.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } elseif ($workerError !== '') {
        $error = $workerError;
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
            $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role, phone, town_id, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $username, $email, $passwordHash, $role, $phone, $townId, $latitude, $longitude]);
            $userId = $pdo->lastInsertId();

            if (!empty($_FILES['profile_photo']['name'])) {
                $profilePhotoPath = process_profile_image($_FILES['profile_photo'], 'uploads/profiles/' . $userId);
                if ($profilePhotoPath) {
                    $pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$profilePhotoPath, $userId]);
                }
            }

            $townName = get_town_name($townId) ?: '';
            $workerNeedsServiceFee = false;
            if ($role === 'worker') {
                $workerNeedsServiceFee = is_feature_paid('enable_paid_worker_service');
                $serviceFeeStatus = $workerNeedsServiceFee ? 'pending' : 'free';
                $idDocumentPath = save_uploaded_image($_FILES['id_document'], 'uploads/worker_ids/' . $userId);
                $stmt = $pdo->prepare('INSERT INTO worker_profiles (user_id, bio, location, latitude, longitude, contact_phone, id_type, id_type_custom, id_number, id_document_path, availability, service_fee_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$userId, '', $townName, $latitude, $longitude, $phone, $idType, ($idType === 'other' ? $idTypeCustom : null), $idNumber, $idDocumentPath, 'available', $serviceFeeStatus]);
                $profileId = $pdo->lastInsertId();

                $skillStmt = $pdo->prepare('INSERT INTO worker_skills (worker_profile_id, category_id, skill_name) VALUES (?, ?, ?)');
                foreach ($skills as $skill) {
                    $skillStmt->execute([$profileId, $skill['category_id'], $skill['skill_name']]);
                }
            }

            // Generate email verification token and send
            $verifyToken = bin2hex(random_bytes(32));
            $pdo->prepare(
                'UPDATE users SET email_verified = 0, email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ?'
            )->execute([$verifyToken, $userId]);
            require_once __DIR__ . '/services/EmailService.php';
            EmailService::sendVerificationEmail($email, $name, $verifyToken);

            $stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            login_user($user);

            // Points & referral hooks
            award_points((int)$userId, 'registration');
            if (isset($profilePhotoPath) && $profilePhotoPath) {
                award_points((int)$userId, 'profile_photo');
            }
            if (!empty($_SESSION['ref_code'])) {
                $referrerId = referral_code_owner($_SESSION['ref_code']);
                if ($referrerId && $referrerId !== (int)$userId) {
                    record_referral($referrerId, (int)$userId, $_SESSION['ref_code']);
                }
                unset($_SESSION['ref_code']);
            }

            if ($workerNeedsServiceFee) {
                flash('Account created! Please complete your service listing payment to appear in search results.', 'info');
                header('Location: pay_worker_service.php');
            } else {
                header('Location: community.php');
            }
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
            <p class="meta" id="step-indicator">Step 1 of 1</p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>

            <div class="wizard-step" data-step="1">
                <label>Full name</label>
                <input type="text" name="name" required value="<?php echo sanitize($_POST['name'] ?? ''); ?>" />
                <label>Username</label>
                <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,30}" title="3–30 characters: letters, numbers, underscores only" value="<?php echo sanitize($_POST['username'] ?? ''); ?>" placeholder="e.g. kwame_builds" />
                <p class="small-note" style="text-align: left; margin-top: 4px;">This is what other users see instead of your real name. 3–30 characters, letters/numbers/underscores.</p>
                <label>Email</label>
                <input type="email" name="email" required value="<?php echo sanitize($_POST['email'] ?? ''); ?>" />
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
                <label>Profile picture <span class="meta">(optional)</span></label>
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" />
                <p class="small-note" style="text-align: left; margin-top: 4px;">Shown on your dashboard. JPEG, PNG, or WEBP, up to 5MB.</p>
                <label>I am registering as a</label>
                <select name="role" id="role-select" required>
                    <option value="customer">Customer</option>
                    <option value="worker">Worker</option>
                </select>
                <input type="hidden" name="latitude" id="latitude" />
                <input type="hidden" name="longitude" id="longitude" />
                <button type="button" id="use-my-location" class="button button-secondary button-small">Share my GPS location</button>
                <p class="meta" id="location-status">Sharing your location helps us match you with nearby jobs and workers.</p>
            </div>

            <div class="wizard-step" data-step="2" style="display: none;">
                <h2>Identity verification</h2>
                <p class="meta">Workers must verify their identity with a Ghana Card or Passport before they can accept jobs. This is for trust &amp; safety only — it is not shared publicly.</p>
                <label>ID type</label>
                <select name="id_type" id="id-type-select">
                    <option value="">Select ID type</option>
                    <option value="ghana_card" <?php echo (($_POST['id_type'] ?? '') === 'ghana_card') ? 'selected' : ''; ?>>Ghana Card</option>
                    <option value="passport" <?php echo (($_POST['id_type'] ?? '') === 'passport') ? 'selected' : ''; ?>>Passport</option>
                    <option value="other" <?php echo (($_POST['id_type'] ?? '') === 'other') ? 'selected' : ''; ?>>Other (specify)</option>
                </select>
                <div id="id-type-custom-wrap" style="display:none;">
                    <label>Specify ID type</label>
                    <input type="text" name="id_type_custom" id="id-type-custom-input" placeholder="e.g. National ID, Driver's Licence, NHIS" maxlength="100" value="<?php echo sanitize($_POST['id_type_custom'] ?? ''); ?>" />
                </div>
                <label>ID card number</label>
                <input type="text" name="id_number" id="id-number-input" placeholder="ID card number" autocomplete="off" value="<?php echo sanitize($_POST['id_number'] ?? ''); ?>" />
                <small id="id_number_hint" style="display:block;margin-top:4px;font-size:0.82rem;"></small>
                <label>Photo of ID card</label>
                <input type="file" name="id_document" id="id-document-input" accept="image/jpeg,image/png,image/webp" />
                <p class="small-note" style="text-align: left; margin-top: 4px;">A clear photo of your ID document — JPEG/PNG/WEBP up to 5MB.</p>
            </div>

            <div class="wizard-step" data-step="3" style="display: none;">
                <h2>Your skills</h2>
                <p class="meta">Pick the skills you offer so customers and our matching system can find you. Choose a category, then a skill, and add it to your list. You can add as many as you like.</p>
                <label>Skill category</label>
                <select id="skill-category-select">
                    <option value="">Select a category</option>
                    <?php foreach ($skillCategories as $category): ?>
                        <option value="<?php echo $category['id']; ?>"><?php echo sanitize($category['name']); ?></option>
                    <?php endforeach; ?>
                    <option value="__other__">Other (specify)</option>
                </select>
                <div id="other-category-wrap" style="display: none;">
                    <label>Describe your category</label>
                    <input type="text" id="other-category-input" placeholder="e.g. Welding, Pool cleaning, Borehole drilling" />
                </div>
                <label id="skill-label">Skill</label>
                <select id="skill-select" disabled>
                    <option value="">Select a category first</option>
                </select>
                <div id="other-skill-wrap" style="display: none;">
                    <label>Specify your skill</label>
                    <input type="text" id="other-skill-input" placeholder="e.g. Arc welding, Filter cleaning" />
                </div>
                <button type="button" id="add-skill-button" class="button button-secondary button-small">+ Add skill</button>
                <p class="meta" id="skill-list-empty">No skills added yet — add at least one to continue.</p>
                <ul id="skill-list" style="list-style: none; padding: 0; margin: 8px 0; display: flex; flex-wrap: wrap; gap: 8px;"></ul>
                <input type="hidden" name="skills_json" id="skills-json" value="[]" />
            </div>

            <div class="wizard-nav" style="display: flex; justify-content: space-between; gap: 10px; margin-top: 12px;">
                <button type="button" id="wizard-back" class="button button-secondary" style="display: none;">Back</button>
                <button type="button" id="wizard-next" class="button button-primary" style="display: none;">Next</button>
                <button type="submit" id="wizard-submit" class="button button-primary">Create account</button>
            </div>
            <p class="small-note">Already registered? <a href="login.php">Sign in</a></p>
        </form>
    </main>
    <script>
        var skillTaxonomy = <?php echo json_encode($skillCategories); ?>;

        var roleSelect = document.getElementById('role-select');
        var idTypeSelect = document.getElementById('id-type-select');
        var idNumberInput = document.getElementById('id-number-input');
        var idDocumentInput = document.getElementById('id-document-input');

        var stepIndicator = document.getElementById('step-indicator');
        var backButton = document.getElementById('wizard-back');
        var nextButton = document.getElementById('wizard-next');
        var submitButton = document.getElementById('wizard-submit');
        var stepEls = {};
        document.querySelectorAll('.wizard-step').forEach(function (el) {
            stepEls[el.getAttribute('data-step')] = el;
        });
        var currentStepIndex = 0;

        function visibleSteps() {
            return roleSelect.value === 'worker' ? ['1', '2', '3'] : ['1'];
        }

        function setRequiredForStep(step, required) {
            if (step === '2') {
                idTypeSelect.required = required;
                idNumberInput.required = required;
                idDocumentInput.required = required;
            }
        }

        function showStep(index) {
            var steps = visibleSteps();
            currentStepIndex = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach(function (stepKey, i) {
                var isVisible = i === currentStepIndex;
                stepEls[stepKey].style.display = isVisible ? 'block' : 'none';
                if (stepKey !== '1') {
                    setRequiredForStep(stepKey, isVisible);
                }
            });
            stepIndicator.textContent = 'Step ' + (currentStepIndex + 1) + ' of ' + steps.length;
            backButton.style.display = currentStepIndex > 0 ? 'inline-flex' : 'none';
            var isLastStep = currentStepIndex === steps.length - 1;
            nextButton.style.display = isLastStep ? 'none' : 'inline-flex';
            submitButton.style.display = isLastStep ? 'inline-flex' : 'none';
        }

        roleSelect.addEventListener('change', function () {
            showStep(0);
        });

        backButton.addEventListener('click', function () {
            showStep(currentStepIndex - 1);
        });

        nextButton.addEventListener('click', function () {
            var steps = visibleSteps();
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

        // Skills picker (step 3)
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

        var otherCategoryWrap = document.getElementById('other-category-wrap');
        var otherCategoryInput = document.getElementById('other-category-input');
        var skillLabel = document.getElementById('skill-label');

        categorySelect.addEventListener('change', function () {
            otherCategoryWrap.style.display = 'none';
            otherCategoryInput.value = '';
            otherWrap.style.display = 'none';
            otherInput.value = '';

            if (this.value === '__other__') {
                otherCategoryWrap.style.display = 'block';
                otherCategoryInput.focus();
                skillLabel.style.display = 'none';
                skillSelect.style.display = 'none';
                skillSelect.disabled = true;
                otherWrap.style.display = 'block';
                return;
            }

            skillLabel.style.display = '';
            skillSelect.style.display = '';

            var category = findCategory(categorySelect.value);
            skillSelect.innerHTML = '';
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
            var isOther = skillSelect.value === '__other__';
            otherWrap.style.display = isOther ? 'block' : 'none';
            if (isOther) otherInput.focus();
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
            var categoryId, categoryName, skillName;

            if (categorySelect.value === '__other__') {
                categoryName = otherCategoryInput.value.trim();
                if (!categoryName) { otherCategoryInput.focus(); return; }
                skillName = otherInput.value.trim();
                if (!skillName) { otherInput.focus(); return; }
                categoryId = null;
            } else {
                var category = findCategory(categorySelect.value);
                if (!category) { categorySelect.focus(); return; }
                categoryId = category.id;
                categoryName = category.name;
                if (skillSelect.value === '__other__') {
                    skillName = otherInput.value.trim();
                    if (!skillName) { otherInput.focus(); return; }
                } else {
                    skillName = skillSelect.value;
                    if (!skillName) { skillSelect.focus(); return; }
                }
            }

            var exists = selectedSkills.some(function (s) {
                return s.category_name.toLowerCase() === categoryName.toLowerCase()
                    && s.skill_name.toLowerCase() === skillName.toLowerCase();
            });
            if (exists) return;

            selectedSkills.push({ category_id: categoryId, category_name: categoryName, skill_name: skillName });
            renderSkillList();
            if (categorySelect.value === '__other__') {
                // keep category name locked; only clear the skill field and re-focus it
                otherInput.value = '';
                otherInput.focus();
            } else {
                otherInput.value = '';
                otherWrap.style.display = 'none';
                skillSelect.value = '';
            }
        });

        renderSkillList();

        document.getElementById('register-form').addEventListener('submit', function (e) {
            if (roleSelect.value === 'worker' && selectedSkills.length === 0) {
                e.preventDefault();
                showStep(visibleSteps().indexOf('3'));
                skillListEmpty.textContent = 'Add at least one skill before creating your account.';
                skillListEmpty.style.display = 'block';
            }
        });

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
    <script src="assets/js/image-compress.js"></script>
    <script src="assets/js/ghana-card-input.js"></script>
    <script>
        setupImageInput(document.querySelector('input[name="profile_photo"]'), 800, 800, 0.82);

        // ID type → show/hide custom field + configure Ghana Card input
        var idTypeCustomWrap = document.getElementById('id-type-custom-wrap');
        var idTypeCustomInput = document.getElementById('id-type-custom-input');
        var ghanaCtrl = initGhanaCardInput(document.getElementById('id-number-input'));

        function onIdTypeChange() {
            var v = idTypeSelect.value;
            idTypeCustomWrap.style.display = v === 'other' ? 'block' : 'none';
            if (idTypeCustomInput) idTypeCustomInput.required = (v === 'other');
            if (v === 'ghana_card') {
                ghanaCtrl.activate();
            } else {
                ghanaCtrl.deactivate();
                idNumberInput.placeholder = v === 'passport' ? 'e.g. G0000000' : 'ID card number';
            }
        }
        idTypeSelect.addEventListener('change', onIdTypeChange);
        onIdTypeChange();
    </script>
</body>
</html>

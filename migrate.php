<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$migrated = false;
$errors = [];
$status = [];

$requiredTables = [
    'towns',
    'users',
    'worker_profiles',
    'service_categories',
    'service_requests',
    'applications',
    'payments',
    'ratings',
    'notifications',
    'worker_skills',
    'messages',
    'disputes',
    'worker_availability_slots',
    'completion_photos',
    'business_messages',
];

function table_exists($tableName) {
    global $pdo;
    try {
        $result = $pdo->query("SELECT 1 FROM $tableName LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function get_table_status() {
    global $requiredTables;
    $status = [];
    foreach ($requiredTables as $table) {
        $status[$table] = table_exists($table);
    }
    return $status;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'migrate') {
    $migrations = [
        'towns' => "CREATE TABLE IF NOT EXISTS towns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            district VARCHAR(80) NOT NULL,
            UNIQUE KEY uniq_towns_name_district (name, district)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('customer','worker','admin') NOT NULL DEFAULT 'customer',
            phone VARCHAR(20) DEFAULT NULL,
            town_id INT UNSIGNED DEFAULT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            profile_photo VARCHAR(255) DEFAULT NULL,
            banned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users_town_id (town_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'worker_profiles' => "CREATE TABLE IF NOT EXISTS worker_profiles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            bio TEXT,
            location VARCHAR(140) NOT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            contact_phone VARCHAR(80) NOT NULL,
            id_type ENUM('ghana_card','passport') DEFAULT NULL,
            id_number VARCHAR(50) DEFAULT NULL,
            id_document_path VARCHAR(255) DEFAULT NULL,
            availability ENUM('available','busy','offline') NOT NULL DEFAULT 'available',
            subscription_status ENUM('free','premium') NOT NULL DEFAULT 'free',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_worker_profiles_availability (availability),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'service_categories' => "CREATE TABLE IF NOT EXISTS service_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'service_requests' => "CREATE TABLE IF NOT EXISTS service_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            assigned_worker_id INT UNSIGNED NULL,
            category_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NOT NULL,
            location VARCHAR(140) NOT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            budget VARCHAR(80) NOT NULL,
            contact_info VARCHAR(180) NOT NULL,
            completion_notes TEXT DEFAULT NULL,
            status ENUM('pending','open','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
            payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
            commission_percent INT UNSIGNED NOT NULL DEFAULT 10,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_service_requests_customer_id (customer_id),
            INDEX idx_service_requests_assigned_worker_id (assigned_worker_id),
            INDEX idx_service_requests_category_id (category_id),
            INDEX idx_service_requests_status (status),
            INDEX idx_service_requests_location (location),
            FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_worker_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'applications' => "CREATE TABLE IF NOT EXISTS applications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            worker_id INT UNSIGNED NOT NULL,
            status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (worker_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'payments' => "CREATE TABLE IF NOT EXISTS payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            amount VARCHAR(80) NOT NULL,
            status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'ratings' => "CREATE TABLE IF NOT EXISTS ratings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            worker_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            comment TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (worker_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'notifications' => "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            type ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'worker_skills' => "CREATE TABLE IF NOT EXISTS worker_skills (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_profile_id INT UNSIGNED NOT NULL,
            skill_name VARCHAR(120) NOT NULL,
            INDEX idx_worker_skills_skill_name (skill_name),
            FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'messages' => "CREATE TABLE IF NOT EXISTS messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_messages_request_id (request_id),
            INDEX idx_messages_sender_id (sender_id),
            INDEX idx_messages_recipient_id (recipient_id),
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        'disputes' => "CREATE TABLE IF NOT EXISTS disputes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            reported_by INT UNSIGNED NOT NULL,
            reported_user_id INT UNSIGNED NOT NULL,
            dispute_type ENUM('quality','payment','communication','no_show','other') NOT NULL,
            description TEXT NOT NULL,
            status ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
            resolution_notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_disputes_request_id (request_id),
            INDEX idx_disputes_reported_by (reported_by),
            INDEX idx_disputes_reported_user_id (reported_user_id),
            INDEX idx_disputes_status (status),
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'worker_availability_slots' => "CREATE TABLE IF NOT EXISTS worker_availability_slots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_profile_id INT UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            INDEX idx_worker_availability_worker_profile_id (worker_profile_id),
            FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'completion_photos' => "CREATE TABLE IF NOT EXISTS completion_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_completion_photos_request_id (request_id),
            FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'business_messages' => "CREATE TABLE IF NOT EXISTS business_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            phone VARCHAR(40) NOT NULL,
            channel ENUM('sms','whatsapp') NOT NULL DEFAULT 'whatsapp',
            message TEXT NOT NULL,
            status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
            response_excerpt VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_messages_user_id (user_id),
            INDEX idx_business_messages_status (status),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    $initData = "INSERT IGNORE INTO service_categories (name) VALUES
        ('Errand'),
        ('Skilled Work'),
        ('Micro Job')";

    $townsData = "INSERT IGNORE INTO towns (name, district) VALUES
        ('Akropong', 'Akuapem North'),
        ('Mamfe', 'Akuapem North'),
        ('Mampong', 'Akuapem North'),
        ('Larteh', 'Akuapem North'),
        ('Tutu', 'Akuapem North'),
        ('Obosomase', 'Akuapem North'),
        ('Amanokrom', 'Akuapem North'),
        ('Adawso', 'Akuapem North'),
        ('Kwamoso', 'Akuapem North'),
        ('Tinkong', 'Akuapem North'),
        ('Okorase', 'Akuapem North'),
        ('New Mangoase', 'Akuapem North'),
        ('Larteh Ahenease', 'Akuapem North'),
        ('Larteh Kubease', 'Akuapem North'),
        ('Okra Kwadwo', 'Akuapem North'),
        ('Aburi', 'Akuapem South'),
        ('Ahwerase', 'Akuapem South'),
        ('Berekuso', 'Akuapem South'),
        ('Atweaase', 'Akuapem South'),
        ('Adukrom', 'Okere District'),
        ('Abiriw', 'Okere District'),
        ('Awukugua', 'Okere District'),
        ('Dawu', 'Okere District'),
        ('Apirede', 'Okere District'),
        ('Aseseeso', 'Okere District'),
        ('Abonse', 'Okere District'),
        ('Asenema', 'Okere District'),
        ('Amanfro', 'Okere District'),
        ('Nsutam', 'Okere District'),
        ('Kobokobo', 'Okere District'),
        ('Nyamebekyere', 'Okere District'),
        ('Okrakwadjo', 'Okere District'),
        ('Mile 14', 'Okere District'),
        ('Sanfo', 'Okere District'),
        ('Kwadako', 'Okere District'),
        ('Nkyenoa', 'Okere District')";

    try {
        foreach ($migrations as $tableName => $sql) {
            $pdo->exec($sql);
            $status[$tableName] = true;
        }
        $pdo->exec($initData);
        $pdo->exec($townsData);
        if (table_exists('users')) {
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'town_id'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN town_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_users_town_id (town_id)');
            }
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'latitude'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'longitude'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL');
            }
        }
        if (table_exists('service_requests')) {
            $completionColumn = $pdo->query("SHOW COLUMNS FROM service_requests LIKE 'completion_notes'")->fetch();
            if (!$completionColumn) {
                $pdo->exec('ALTER TABLE service_requests ADD COLUMN completion_notes TEXT DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM service_requests LIKE 'latitude'")->fetch()) {
                $pdo->exec('ALTER TABLE service_requests ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM service_requests LIKE 'longitude'")->fetch()) {
                $pdo->exec('ALTER TABLE service_requests ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL');
            }
        }
        if (table_exists('worker_profiles')) {
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'latitude'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'longitude'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'id_type'")->fetch()) {
                $pdo->exec("ALTER TABLE worker_profiles ADD COLUMN id_type ENUM('ghana_card','passport') DEFAULT NULL");
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'id_number'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN id_number VARCHAR(50) DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'id_document_path'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN id_document_path VARCHAR(255) DEFAULT NULL');
            }
        }
        $migrated = true;
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

$tableStatus = get_table_status();
$allTablesExist = !in_array(false, array_values($tableStatus));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Database Migration — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <h1>Database Migration</h1>
    </header>
    <main class="page-shell small-shell">
        <section class="card">
            <h2>Status</h2>
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStatus as $table => $exists): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($table); ?></td>
                            <td>
                                <?php if ($exists): ?>
                                    <span class="status status-success">✓ Exists</span>
                                <?php else: ?>
                                    <span class="status status-error">✗ Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($migrated): ?>
                <div class="alert alert-success">✓ Migration completed successfully!</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Errors:</strong><br>
                    <?php foreach ($errors as $error): ?>
                        <?php echo htmlspecialchars($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$allTablesExist && !$migrated): ?>
                <p style="margin-top: 16px; color: #666;">Some tables are missing. Click the button below to create them.</p>
                <form method="post">
                    <input type="hidden" name="action" value="migrate" />
                    <button type="submit" class="button button-primary" style="width: 100%; margin-top: 16px;">Run Migration</button>
                </form>
            <?php elseif ($allTablesExist && !$migrated): ?>
                <div class="alert alert-success">✓ All tables exist. Your database is ready to go!</div>
                <a href="index.php" class="button button-primary" style="display: block; width: 100%; text-align: center; margin-top: 16px;">Back to home</a>
            <?php elseif ($migrated): ?>
                <a href="index.php" class="button button-primary" style="display: block; width: 100%; text-align: center; margin-top: 16px;">Back to home</a>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

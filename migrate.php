<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$migrated = false;
$errors = [];
$status = [];

$requiredTables = [
    'towns',
    'skill_categories',
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
    'platform_settings',
    'featured_job_packages',
    'worker_promotion_packages',
    'verification_packages',
    'job_posting_packages',
    'worker_service_packages',
    'platform_payments',
    'audit_logs',
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

        'skill_categories' => "CREATE TABLE IF NOT EXISTS skill_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('customer','worker','admin','manager') NOT NULL DEFAULT 'customer',
            phone VARCHAR(20) DEFAULT NULL,
            town_id INT UNSIGNED DEFAULT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            profile_photo VARCHAR(255) DEFAULT NULL,
            email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
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
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_status ENUM('none','pending','approved','rejected','resubmission_requested','expired') NOT NULL DEFAULT 'none',
            verification_date DATE DEFAULT NULL,
            verification_expiry DATE DEFAULT NULL,
            verification_rejection_reason TEXT DEFAULT NULL,
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
            skills_needed VARCHAR(255) DEFAULT NULL,
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
            category_id INT UNSIGNED DEFAULT NULL,
            skill_name VARCHAR(120) NOT NULL,
            INDEX idx_worker_skills_skill_name (skill_name),
            INDEX idx_worker_skills_category_id (category_id),
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

        'platform_settings' => "CREATE TABLE IF NOT EXISTS platform_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(80) NOT NULL UNIQUE,
            setting_value VARCHAR(255) NOT NULL DEFAULT '',
            description VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'featured_job_packages' => "CREATE TABLE IF NOT EXISTS featured_job_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            duration_days TINYINT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'worker_promotion_packages' => "CREATE TABLE IF NOT EXISTS worker_promotion_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            duration_days TINYINT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'verification_packages' => "CREATE TABLE IF NOT EXISTS verification_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'job_posting_packages' => "CREATE TABLE IF NOT EXISTS job_posting_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            post_count INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'worker_service_packages' => "CREATE TABLE IF NOT EXISTS worker_service_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'platform_payments' => "CREATE TABLE IF NOT EXISTS platform_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            payment_type ENUM('featured_job','featured_worker','verification','job_post','worker_service') NOT NULL,
            reference_id INT UNSIGNED DEFAULT NULL,
            package_id INT UNSIGNED DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
            reference_code VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            INDEX idx_platform_payments_user_id (user_id),
            INDEX idx_platform_payments_status (status),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_logs_admin_id (admin_id),
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
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
        ('Electrical & Technical Skills'),
        ('Plumbing Skills'),
        ('Construction & Building Skills'),
        ('Welding & Metal Works'),
        ('Vehicle & Mechanical Skills'),
        ('Cleaning & Domestic Services'),
        ('Personal Care Services'),
        ('Education & Tutoring'),
        ('Digital & Tech Skills'),
        ('Event Services'),
        ('Agriculture & Local Work'),
        ('Micro Job')";

    $skilledWorkSubcategories = [
        'Electrical & Technical Skills' => ['electrician', 'electrical', 'wiring', 'ac repair', 'air conditioner', 'fridge repair', 'refrigerator repair', 'generator', 'appliance repair', 'solar installation'],
        'Plumbing Skills' => ['plumber', 'plumbing', 'pipe fitting', 'pipe', 'tap fixing', 'tap repair', 'toilet repair', 'water pump', 'leak repair', 'bathroom installation'],
        'Construction & Building Skills' => ['carpenter', 'carpentry', 'mason', 'masonry', 'bricklaying', 'plastering', 'painter', 'painting', 'tiling', 'roofing', 'renovation', 'construction', 'concrete', 'building'],
        'Welding & Metal Works' => ['welding', 'welder', 'gate fabrication', 'metal fabrication', 'burglar proof', 'aluminium works', 'aluminum works'],
        'Vehicle & Mechanical Skills' => ['mechanic', 'auto repair', 'car repair', 'motorcycle repair', 'tyre fixing', 'tire repair', 'battery servicing', 'car electrical', 'auto diagnostics', 'vehicle repair'],
        'Cleaning & Domestic Services' => ['cleaner', 'cleaning service', 'house cleaning', 'compound cleaning', 'laundry', 'deep cleaning', 'gardener', 'landscaping', 'post-construction cleaning'],
        'Personal Care Services' => ['hairdresser', 'barber', 'barbering', 'braiding', 'makeup', 'nail care', 'tailor', 'sewing'],
    ];
    $skilledWorkFallback = 'Construction & Building Skills';

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

    $skillCategoriesData = "INSERT IGNORE INTO skill_categories (name) VALUES
        ('Electrical & Technical Skills'),
        ('Plumbing Skills'),
        ('Construction & Building Skills'),
        ('Welding & Metal Works'),
        ('Vehicle & Mechanical Skills'),
        ('Cleaning & Domestic Services'),
        ('Errand & Support Services'),
        ('Personal Care Services'),
        ('Education & Tutoring'),
        ('Digital & Tech Skills'),
        ('Event Services'),
        ('Agriculture & Local Work')";

    try {
        foreach ($migrations as $tableName => $sql) {
            $pdo->exec($sql);
            $status[$tableName] = true;
        }
        $pdo->exec($initData);

        $skilledWorkId = $pdo->query("SELECT id FROM service_categories WHERE name = 'Skilled Work'")->fetchColumn();
        if ($skilledWorkId) {
            $subCategoryIds = [];
            foreach (array_keys($skilledWorkSubcategories) as $subName) {
                $stmt = $pdo->prepare('SELECT id FROM service_categories WHERE name = ?');
                $stmt->execute([$subName]);
                $subCategoryIds[$subName] = (int)$stmt->fetchColumn();
            }

            $stmt = $pdo->prepare('SELECT id, title, description FROM service_requests WHERE category_id = ?');
            $stmt->execute([$skilledWorkId]);
            foreach ($stmt->fetchAll() as $request) {
                $haystack = strtolower($request['title'] . ' ' . $request['description']);
                $bestName = $skilledWorkFallback;
                $bestMatches = 0;
                foreach ($skilledWorkSubcategories as $subName => $keywords) {
                    $matches = 0;
                    foreach ($keywords as $keyword) {
                        if (strpos($haystack, $keyword) !== false) {
                            $matches++;
                        }
                    }
                    if ($matches > $bestMatches) {
                        $bestMatches = $matches;
                        $bestName = $subName;
                    }
                }
                $pdo->prepare('UPDATE service_requests SET category_id = ? WHERE id = ?')->execute([$subCategoryIds[$bestName], $request['id']]);
            }

            $pdo->prepare('DELETE FROM service_categories WHERE id = ?')->execute([$skilledWorkId]);
        }

        $pdo->exec($townsData);
        $pdo->exec($skillCategoriesData);
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
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'email_notifications_enabled'")->fetch()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1');
            }
            $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            if ($roleColumn && strpos($roleColumn['Type'], "'manager'") === false) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('customer','worker','admin','manager') NOT NULL DEFAULT 'customer'");
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
            if (!$pdo->query("SHOW COLUMNS FROM service_requests LIKE 'skills_needed'")->fetch()) {
                $pdo->exec('ALTER TABLE service_requests ADD COLUMN skills_needed VARCHAR(255) DEFAULT NULL');
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
        if (table_exists('worker_skills')) {
            if (!$pdo->query("SHOW COLUMNS FROM worker_skills LIKE 'category_id'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_skills ADD COLUMN category_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_worker_skills_category_id (category_id)');
            }
        }
        if (table_exists('users')) {
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch()) {
                $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(30) DEFAULT NULL AFTER name, ADD UNIQUE KEY uniq_users_username (username)");
            }
        }
        if (table_exists('service_requests')) {
            if (!$pdo->query("SHOW COLUMNS FROM service_requests LIKE 'featured_start_date'")->fetch()) {
                $pdo->exec('ALTER TABLE service_requests ADD COLUMN featured_start_date DATE DEFAULT NULL, ADD COLUMN featured_end_date DATE DEFAULT NULL');
            }
        }
        if (table_exists('worker_profiles')) {
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'is_featured'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN featured_start_date DATE DEFAULT NULL, ADD COLUMN featured_end_date DATE DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'is_verified'")->fetch()) {
                $pdo->exec('ALTER TABLE worker_profiles ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN verification_date DATE DEFAULT NULL, ADD COLUMN verification_expiry DATE DEFAULT NULL');
            }
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'verification_status'")->fetch()) {
                $pdo->exec("ALTER TABLE worker_profiles ADD COLUMN verification_status ENUM('none','pending','approved','rejected','resubmission_requested','expired') NOT NULL DEFAULT 'none', ADD COLUMN verification_rejection_reason TEXT DEFAULT NULL");
                // Back-fill status for rows already verified
                $pdo->exec("UPDATE worker_profiles SET verification_status = 'approved' WHERE is_verified = 1");
            }
        }
        // New columns for paid posting/service features
        if (table_exists('service_requests')) {
            if (!$pdo->query("SHOW COLUMNS FROM service_requests LIKE 'posting_fee_status'")->fetch()) {
                $pdo->exec("ALTER TABLE service_requests ADD COLUMN posting_fee_status ENUM('free','pending','paid') NOT NULL DEFAULT 'free'");
            }
        }
        if (table_exists('worker_profiles')) {
            if (!$pdo->query("SHOW COLUMNS FROM worker_profiles LIKE 'service_fee_status'")->fetch()) {
                $pdo->exec("ALTER TABLE worker_profiles ADD COLUMN service_fee_status ENUM('free','pending','paid') NOT NULL DEFAULT 'free', ADD COLUMN service_fee_expiry DATE DEFAULT NULL");
            }
        }
        // Expand platform_payments.payment_type ENUM for existing databases
        if (table_exists('platform_payments')) {
            $colInfo = $pdo->query("SHOW COLUMNS FROM platform_payments LIKE 'payment_type'")->fetch();
            if ($colInfo && strpos($colInfo['Type'], "'job_post'") === false) {
                $pdo->exec("ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM('featured_job','featured_worker','verification','job_post','worker_service') NOT NULL");
            }
        }
        // Seed platform_settings defaults
        $pdo->exec("INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
            ('monetization_mode', 'free', 'Global monetization mode: free, hybrid, or paid'),
            ('enable_paid_featured_jobs', '0', 'Charge for featured job posts (0=free, 1=paid)'),
            ('enable_paid_featured_workers', '0', 'Charge workers to feature profiles (0=free, 1=paid)'),
            ('enable_paid_verification_badges', '0', 'Charge for verification badges (0=free, 1=paid)'),
            ('enable_paid_job_posting', '0', 'Require a posting fee before jobs go live (0=free, 1=paid)'),
            ('enable_paid_worker_service', '0', 'Require workers to pay to be listed (0=free, 1=paid)')");
        // Seed default packages
        $pdo->exec("INSERT IGNORE INTO featured_job_packages (name, duration_days, price, status) VALUES
            ('7 Days', 7, 0.00, 'active'),
            ('14 Days', 14, 0.00, 'active'),
            ('30 Days', 30, 0.00, 'active')");
        $pdo->exec("INSERT IGNORE INTO worker_promotion_packages (name, duration_days, price, status) VALUES
            ('7 Days', 7, 0.00, 'active'),
            ('30 Days', 30, 0.00, 'active'),
            ('90 Days', 90, 0.00, 'active')");
        $pdo->exec("INSERT IGNORE INTO verification_packages (name, price, status) VALUES
            ('Verified Worker Badge', 0.00, 'active')");
        $pdo->exec("INSERT IGNORE INTO job_posting_packages (name, post_count, price, status) VALUES
            ('Single Post', 1, 0.00, 'active'),
            ('5 Post Bundle', 5, 0.00, 'active'),
            ('Monthly Unlimited', -1, 0.00, 'active')");
        $pdo->exec("INSERT IGNORE INTO worker_service_packages (name, duration_days, price, status) VALUES
            ('Monthly Listing', 30, 0.00, 'active'),
            ('3 Month Listing', 90, 0.00, 'active'),
            ('Annual Listing', 365, 0.00, 'active')");
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

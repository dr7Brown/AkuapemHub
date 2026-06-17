-- ─────────────────────────────────────────────────────────────────────────────
-- AkuapemHub — Master Installation SQL
-- ─────────────────────────────────────────────────────────────────────────────
--
-- HOW TO RUN (pick one):
--   mysql -u root -p your_database_name < install.sql
--   OR paste into phpMyAdmin → SQL tab.
--
-- Safe to run on:
--   • Fresh database: creates everything from scratch.
--   • Existing database: all statements use IF NOT EXISTS / IF NOT EXISTS
--     guards so re-running adds only what is missing.
--
-- VERSIONS (run order):
--   v001  Core tables + seed data                    (migrate.php)
--   v002  Password-reset system                      (migrate_password_reset.php)
--   v003  Email verification                         (migrate_email_verification.php)
--   v004  Escrow payments                            (migrate_escrow.php)
--   v005  Platform payments admin columns            (migrate_payments_admin.php)
--   v006  Referrals & points system                  (modules/referrals/migrate.php)
--   v007  Community tables                           (no prior migration file — added manually)
--   v008  Content publishing fee ENUM extensions     (migrate_content_fees.php)
--   v009  Location mapping                           (migrate_locations.php)
--   v010  Rejection-reason columns + status values   (temp script — run & deleted)
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ═══════════════════════════════════════════════════════════════════════════
-- v001  Core tables + seed data
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS towns (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(80) NOT NULL,
    district VARCHAR(80) NOT NULL,
    UNIQUE KEY uniq_towns_name_district (name, district)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skill_categories (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                         VARCHAR(120) NOT NULL,
    username                     VARCHAR(30) DEFAULT NULL,
    email                        VARCHAR(180) NOT NULL UNIQUE,
    -- v003 columns included here so fresh installs are complete
    email_verified               TINYINT(1) NOT NULL DEFAULT 0,
    email_verification_token     VARCHAR(64) NULL,
    email_verification_sent_at   DATETIME NULL,
    password_hash                VARCHAR(255) NOT NULL,
    -- v002 column included here
    password_changed_at          DATETIME NULL,
    role                         ENUM('customer','worker','admin','manager') NOT NULL DEFAULT 'customer',
    phone                        VARCHAR(20) DEFAULT NULL,
    town_id                      INT UNSIGNED DEFAULT NULL,
    latitude                     DECIMAL(10,7) DEFAULT NULL,
    longitude                    DECIMAL(10,7) DEFAULT NULL,
    profile_photo                VARCHAR(255) DEFAULT NULL,
    email_notifications_enabled  TINYINT(1) NOT NULL DEFAULT 1,
    -- chat restriction columns (added via migrate.php ALTER TABLE)
    can_send_messages            TINYINT(1) NOT NULL DEFAULT 1,
    can_receive_messages         TINYINT(1) NOT NULL DEFAULT 1,
    chat_ban_until               DATETIME NULL DEFAULT NULL,
    banned                       TINYINT(1) NOT NULL DEFAULT 0,
    created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_username (username),
    INDEX idx_users_town_id (town_id),
    INDEX idx_evt (email_verification_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_profiles (
    id                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                      INT UNSIGNED NOT NULL,
    bio                          TEXT,
    location                     VARCHAR(140) NOT NULL,
    latitude                     DECIMAL(10,7) DEFAULT NULL,
    longitude                    DECIMAL(10,7) DEFAULT NULL,
    contact_phone                VARCHAR(80) NOT NULL,
    id_type                      ENUM('ghana_card','passport','other') DEFAULT NULL,
    id_number                    VARCHAR(50) DEFAULT NULL,
    id_document_path             VARCHAR(255) DEFAULT NULL,
    availability                 ENUM('available','busy','offline') NOT NULL DEFAULT 'available',
    subscription_status          ENUM('free','premium') NOT NULL DEFAULT 'free',
    is_verified                  TINYINT(1) NOT NULL DEFAULT 0,
    verification_status          ENUM('none','pending','approved','rejected','resubmission_requested','expired') NOT NULL DEFAULT 'none',
    verification_date            DATE DEFAULT NULL,
    verification_expiry          DATE DEFAULT NULL,
    verification_rejection_reason TEXT DEFAULT NULL,
    is_featured                  TINYINT(1) NOT NULL DEFAULT 0,
    featured_start_date          DATE DEFAULT NULL,
    featured_end_date            DATE DEFAULT NULL,
    service_fee_status           ENUM('free','pending','paid') NOT NULL DEFAULT 'free',
    service_fee_expiry           DATE DEFAULT NULL,
    service_renewal_notice_sent  TINYINT(1) NOT NULL DEFAULT 0,
    created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME NULL,
    INDEX idx_worker_profiles_availability (availability),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_categories (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- service_requests includes all columns added across v001–v010
CREATE TABLE IF NOT EXISTS service_requests (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id        INT UNSIGNED NOT NULL,
    assigned_worker_id INT UNSIGNED NULL,
    category_id        INT UNSIGNED NOT NULL,
    title              VARCHAR(180) NOT NULL,
    description        TEXT NOT NULL,
    location           VARCHAR(140) NOT NULL,
    latitude           DECIMAL(10,7) DEFAULT NULL,
    longitude          DECIMAL(10,7) DEFAULT NULL,
    google_maps_link   VARCHAR(512) DEFAULT NULL,
    budget             VARCHAR(80) NOT NULL,
    budget_amount      DECIMAL(10,2) NULL,
    contact_info       VARCHAR(180) NOT NULL,
    skills_needed      VARCHAR(255) DEFAULT NULL,
    workers_needed     INT UNSIGNED NOT NULL DEFAULT 1,
    workers_approved   INT UNSIGNED NOT NULL DEFAULT 0,
    job_type           ENUM('on_site','remote','hybrid') NOT NULL DEFAULT 'on_site',
    completion_notes   TEXT DEFAULT NULL,
    -- Final ENUM includes all values added by later migrations + temp script (v004, v010)
    status             ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected') NOT NULL DEFAULT 'draft',
    payment_status     ENUM('unpaid','escrowed','paid') NOT NULL DEFAULT 'unpaid',
    payment_mode       ENUM('direct','escrow') NOT NULL DEFAULT 'direct',
    posting_fee_status ENUM('free','pending','paid') NOT NULL DEFAULT 'free',
    deadline_value     SMALLINT UNSIGNED NULL,
    deadline_unit      ENUM('hours','days','months') NULL,
    deadline_date      DATETIME NULL,
    commission_percent INT UNSIGNED NOT NULL DEFAULT 10,
    featured           TINYINT(1) NOT NULL DEFAULT 0,
    featured_start_date DATE DEFAULT NULL,
    featured_end_date   DATE DEFAULT NULL,
    location_id        INT UNSIGNED NULL DEFAULT NULL,  -- v009
    rejection_reason   VARCHAR(500) NULL DEFAULT NULL,  -- v010
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_requests_customer_id (customer_id),
    INDEX idx_service_requests_assigned_worker_id (assigned_worker_id),
    INDEX idx_service_requests_category_id (category_id),
    INDEX idx_service_requests_status (status),
    INDEX idx_service_requests_location (location),
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_worker_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    worker_id  INT UNSIGNED NOT NULL,
    -- Final ENUM includes all values added via migrate.php ALTER TABLE
    status     ENUM('pending','approved','rejected','withdrawn','completed','accepted','declined') NOT NULL DEFAULT 'pending',
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (worker_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    amount     VARCHAR(80) NOT NULL,
    status     ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    note       VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id  INT UNSIGNED NOT NULL,
    worker_id   INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    score       TINYINT UNSIGNED NOT NULL,
    comment     TEXT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id)  REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (worker_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(160) NOT NULL,
    body       TEXT NOT NULL,
    type       ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_skills (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT UNSIGNED NOT NULL,
    category_id       INT UNSIGNED DEFAULT NULL,
    skill_name        VARCHAR(120) NOT NULL,
    INDEX idx_worker_skills_skill_name (skill_name),
    INDEX idx_worker_skills_category_id (category_id),
    FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id   INT UNSIGNED NOT NULL,
    sender_id    INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    content      TEXT NOT NULL,
    is_read      TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_request_id (request_id),
    INDEX idx_messages_sender_id (sender_id),
    INDEX idx_messages_recipient_id (recipient_id),
    FOREIGN KEY (request_id)   REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disputes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id       INT UNSIGNED NOT NULL,
    reported_by      INT UNSIGNED NOT NULL,
    reported_user_id INT UNSIGNED NOT NULL,
    dispute_type     ENUM('quality','payment','communication','no_show','other') NOT NULL,
    description      TEXT NOT NULL,
    status           ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
    resolution_notes TEXT,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL,
    INDEX idx_disputes_request_id (request_id),
    INDEX idx_disputes_reported_by (reported_by),
    INDEX idx_disputes_reported_user_id (reported_user_id),
    INDEX idx_disputes_status (status),
    FOREIGN KEY (request_id)       REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_availability_slots (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT UNSIGNED NOT NULL,
    day_of_week       TINYINT UNSIGNED NOT NULL,
    start_time        TIME NOT NULL,
    end_time          TIME NOT NULL,
    INDEX idx_worker_availability_worker_profile_id (worker_profile_id),
    FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS completion_photos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id  INT UNSIGNED NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_completion_photos_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(80) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL DEFAULT '',
    description   VARCHAR(255) DEFAULT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS featured_job_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL,
    duration_days TINYINT UNSIGNED NOT NULL,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_promotion_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL,
    duration_days TINYINT UNSIGNED NOT NULL,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_packages (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(80) NOT NULL,
    price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_posting_packages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    description TEXT NULL DEFAULT NULL,
    post_count  INT NOT NULL DEFAULT 1,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_service_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL,
    description   TEXT NULL DEFAULT NULL,
    duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- platform_payments: final ENUM includes all values added across v001, v004, v005, v007, v008
CREATE TABLE IF NOT EXISTS platform_payments (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    payment_type         ENUM('featured_job','featured_worker','verification','job_post','worker_service',
                              'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post') NOT NULL,
    reference_id         INT UNSIGNED DEFAULT NULL,
    package_id           INT UNSIGNED DEFAULT NULL,
    amount               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status               ENUM('pending','paid','failed','abandoned','refunded') NOT NULL DEFAULT 'pending',
    reference_code       VARCHAR(64) DEFAULT NULL,
    paystack_reference   VARCHAR(100) DEFAULT NULL,
    paystack_transaction_id BIGINT UNSIGNED DEFAULT NULL,
    currency             CHAR(3) NOT NULL DEFAULT 'GHS',
    gateway              VARCHAR(20) NOT NULL DEFAULT 'manual',
    confirmed_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    payment_method       VARCHAR(30) NULL DEFAULT NULL,
    admin_notes          TEXT NULL,      -- v005
    flagged              TINYINT(1) NOT NULL DEFAULT 0,  -- v005
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at              DATETIME DEFAULT NULL,
    INDEX idx_platform_payments_user_id (user_id),
    INDEX idx_platform_payments_status (status),
    INDEX idx_platform_payments_paystack_ref (paystack_reference),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_post_credits (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    payment_id      INT UNSIGNED NOT NULL,
    posts_total     INT NOT NULL,
    posts_remaining INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_post_credits_user (user_id),
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES platform_payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED NULL DEFAULT NULL,
    action      VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_admin_id (admin_id),
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_messages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NULL,
    phone            VARCHAR(40) NOT NULL,
    channel          ENUM('sms','whatsapp') NOT NULL DEFAULT 'whatsapp',
    message          TEXT NOT NULL,
    status           ENUM('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
    response_excerpt VARCHAR(255) DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_business_messages_user_id (user_id),
    INDEX idx_business_messages_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_type ENUM('job_application','job_hired','worker_direct','direct','admin_granted') NOT NULL DEFAULT 'direct',
    job_id            INT UNSIGNED DEFAULT NULL,
    created_by        INT UNSIGNED NOT NULL,
    status            ENUM('active','blocked','closed') NOT NULL DEFAULT 'active',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conv_status (status),
    INDEX idx_conv_job_id (job_id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_participants (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_conv_user (conversation_id, user_id),
    INDEX idx_cp_user_id (user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)         REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id   INT UNSIGNED NOT NULL,
    sender_id         INT UNSIGNED NOT NULL,
    message           TEXT NOT NULL,
    message_type      ENUM('text','image','file') NOT NULL DEFAULT 'text',
    file_path         VARCHAR(255) DEFAULT NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    is_flagged        TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason       VARCHAR(120) DEFAULT NULL,
    deleted_by_sender   TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by_receiver TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cm_conversation_id (conversation_id),
    INDEX idx_cm_sender_id (sender_id),
    INDEX idx_cm_created_at (created_at),
    INDEX idx_cm_is_read (is_read),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    status      ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mr_status (status),
    FOREIGN KEY (message_id)  REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_audit_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT UNSIGNED NULL DEFAULT NULL,
    action          VARCHAR(80) NOT NULL,
    target_user_id  INT UNSIGNED NULL DEFAULT NULL,
    conversation_id INT UNSIGNED NULL DEFAULT NULL,
    details         TEXT NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cal_admin_id (admin_id),
    INDEX idx_cal_target_user_id (target_user_id),
    INDEX idx_cal_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── v001 Seeds ───────────────────────────────────────────────────────────────

INSERT IGNORE INTO service_categories (name) VALUES
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
    ('Micro Job');

INSERT IGNORE INTO skill_categories (name) VALUES
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
    ('Agriculture & Local Work');

INSERT IGNORE INTO towns (name, district) VALUES
    ('Akropong',        'Akuapem North'),
    ('Mamfe',           'Akuapem North'),
    ('Mampong',         'Akuapem North'),
    ('Larteh',          'Akuapem North'),
    ('Tutu',            'Akuapem North'),
    ('Obosomase',       'Akuapem North'),
    ('Amanokrom',       'Akuapem North'),
    ('Adawso',          'Akuapem North'),
    ('Kwamoso',         'Akuapem North'),
    ('Tinkong',         'Akuapem North'),
    ('Okorase',         'Akuapem North'),
    ('New Mangoase',    'Akuapem North'),
    ('Larteh Ahenease', 'Akuapem North'),
    ('Larteh Kubease',  'Akuapem North'),
    ('Okra Kwadwo',     'Akuapem North'),
    ('Aburi',           'Akuapem South'),
    ('Ahwerase',        'Akuapem South'),
    ('Berekuso',        'Akuapem South'),
    ('Atweaase',        'Akuapem South'),
    ('Adukrom',         'Okere District'),
    ('Abiriw',          'Okere District'),
    ('Awukugua',        'Okere District'),
    ('Dawu',            'Okere District'),
    ('Apirede',         'Okere District'),
    ('Aseseeso',        'Okere District'),
    ('Abonse',          'Okere District'),
    ('Asenema',         'Okere District'),
    ('Amanfro',         'Okere District'),
    ('Nsutam',          'Okere District'),
    ('Kobokobo',        'Okere District'),
    ('Nyamebekyere',    'Okere District'),
    ('Okrakwadjo',      'Okere District'),
    ('Mile 14',         'Okere District'),
    ('Sanfo',           'Okere District'),
    ('Kwadako',         'Okere District'),
    ('Nkyenoa',         'Okere District');

-- Default packages
INSERT IGNORE INTO featured_job_packages (name, duration_days, price, status) VALUES
    ('7 Days',  7,  0.00, 'active'),
    ('14 Days', 14, 0.00, 'active'),
    ('30 Days', 30, 0.00, 'active');

INSERT IGNORE INTO worker_promotion_packages (name, duration_days, price, status) VALUES
    ('7 Days',  7,  0.00, 'active'),
    ('30 Days', 30, 0.00, 'active'),
    ('90 Days', 90, 0.00, 'active');

INSERT IGNORE INTO verification_packages (name, price, status) VALUES
    ('Verified Worker Badge', 0.00, 'active');

INSERT IGNORE INTO job_posting_packages (name, post_count, price, status) VALUES
    ('Single Post',      1,  0.00, 'active'),
    ('5 Post Bundle',    5,  0.00, 'active'),
    ('Monthly Unlimited', -1, 0.00, 'active');

INSERT IGNORE INTO worker_service_packages (name, duration_days, price, status) VALUES
    ('Monthly Listing', 30,  0.00, 'active'),
    ('3 Month Listing', 90,  0.00, 'active'),
    ('Annual Listing',  365, 0.00, 'active');

-- Core platform settings
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('monetization_mode',             'free', 'Global monetization mode: free, hybrid, or paid'),
    ('enable_paid_featured_jobs',     '0',    'Charge for featured job posts (0=free, 1=paid)'),
    ('enable_paid_featured_workers',  '0',    'Charge workers to feature profiles (0=free, 1=paid)'),
    ('enable_paid_verification_badges','0',   'Charge for verification badges (0=free, 1=paid)'),
    ('enable_paid_job_posting',       '0',    'Require a posting fee before jobs go live (0=free, 1=paid)'),
    ('enable_paid_worker_service',    '0',    'Require workers to pay to be listed (0=free, 1=paid)'),
    ('paystack_public_key',           '',     'Paystack publishable key (pk_test_... or pk_live_...)'),
    ('paystack_secret_key',           '',     'Paystack secret key (sk_test_... or sk_live_...)'),
    ('paystack_webhook_secret',       '',     'Paystack webhook signature secret'),
    ('paystack_mode',                 'test', 'Paystack environment: test or live'),
    ('chat_disabled',                 '0',    'Disable all in-platform messaging (1=disabled)'),
    ('chat_allow_applicant_chat',     '1',    'Allow job owner and applicant to chat'),
    ('chat_allow_hired_chat',         '1',    'Allow hired worker and job owner to chat'),
    ('chat_allow_worker_worker',      '0',    'Allow worker to worker direct messaging'),
    ('chat_allow_direct_all',         '0',    'Allow any user to message any other user freely');


-- ═══════════════════════════════════════════════════════════════════════════
-- v002  Password-reset system
-- ═══════════════════════════════════════════════════════════════════════════
-- New tables: password_reset_otps, security_logs
-- New column:  users.password_changed_at
-- (Column is already in the v001 CREATE TABLE above; the ALTER below is a
--  no-op on fresh installs and adds it on databases upgraded from an older
--  version of migrate.php that lacked it.)

CREATE TABLE IF NOT EXISTS password_reset_otps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    otp_hash   VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NULL,
    phone      VARCHAR(50)  NULL,
    status     ENUM('pending','used','expired') NOT NULL DEFAULT 'pending',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at    DATETIME NULL,
    INDEX idx_pro_user    (user_id),
    INDEX idx_pro_status  (status),
    INDEX idx_pro_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sl_user    (user_id),
    INDEX idx_sl_action  (action),
    INDEX idx_sl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER password_hash;


-- ═══════════════════════════════════════════════════════════════════════════
-- v003  Email verification
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds email_verified, email_verification_token, email_verification_sent_at
-- to users. (Already in v001 CREATE TABLE; ALTER TABLE is for upgrades.)

ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified             TINYINT(1) NOT NULL DEFAULT 0       AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_token   VARCHAR(64) NULL                    AFTER email_verified;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL                       AFTER email_verification_token;
ALTER TABLE users ADD INDEX IF NOT EXISTS  idx_evt (email_verification_token);

-- Mark all pre-existing accounts as already verified so they are not locked out.
-- (Safe to re-run — only touches rows where email_verified is still 0.)
UPDATE users SET email_verified = 1 WHERE email_verified = 0;


-- ═══════════════════════════════════════════════════════════════════════════
-- v004  Escrow payments
-- ═══════════════════════════════════════════════════════════════════════════
-- Extends service_requests ENUMs and adds escrow columns.
-- Adds escrow_payments table.

ALTER TABLE service_requests MODIFY COLUMN status
    ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected')
    NOT NULL DEFAULT 'draft';

ALTER TABLE service_requests MODIFY COLUMN payment_status
    ENUM('unpaid','escrowed','paid') NOT NULL DEFAULT 'unpaid';

ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS budget_amount  DECIMAL(10,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS payment_mode   ENUM('direct','escrow') NOT NULL DEFAULT 'direct';
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS deadline_value SMALLINT UNSIGNED NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS deadline_unit  ENUM('hours','days','months') NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS deadline_date  DATETIME NULL;

CREATE TABLE IF NOT EXISTS escrow_payments (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id               INT UNSIGNED NOT NULL,
    client_id            INT UNSIGNED NOT NULL,
    worker_id            INT UNSIGNED NULL,
    budget_amount        DECIMAL(10,2) NOT NULL,
    commission_rate      DECIMAL(5,2) NOT NULL,
    commission_amount    DECIMAL(10,2) NOT NULL,
    gross_amount         DECIMAL(10,2) NOT NULL,
    net_amount           DECIMAL(10,2) NOT NULL,
    status               ENUM('awaiting_payment','held','released','refunded','disputed') NOT NULL DEFAULT 'awaiting_payment',
    platform_payment_id  INT UNSIGNED NULL,
    paystack_reference   VARCHAR(100) NULL,
    paid_at              DATETIME NULL,
    auto_release_days    TINYINT UNSIGNED NOT NULL DEFAULT 7,
    auto_release_at      DATETIME NULL,
    released_at          DATETIME NULL,
    refunded_at          DATETIME NULL,
    release_initiated_by ENUM('client','admin','auto') NULL,
    admin_notes          TEXT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_escrow_job (job_id),
    INDEX idx_escrow_status      (status),
    INDEX idx_escrow_client      (client_id),
    INDEX idx_escrow_worker      (worker_id),
    INDEX idx_escrow_auto_release (auto_release_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════════════
-- v005  Platform payments admin columns
-- ═══════════════════════════════════════════════════════════════════════════
-- Extends platform_payments.payment_type to include escrow_payment.
-- Adds admin_notes and flagged columns.

ALTER TABLE platform_payments MODIFY COLUMN payment_type
    ENUM('featured_job','featured_worker','verification','job_post','worker_service',
         'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post') NOT NULL;

ALTER TABLE platform_payments ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL;
ALTER TABLE platform_payments ADD COLUMN IF NOT EXISTS flagged     TINYINT(1) NOT NULL DEFAULT 0;


-- ═══════════════════════════════════════════════════════════════════════════
-- v006  Referrals & points system
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS referral_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    code       VARCHAR(16) NOT NULL,
    clicks     INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_visits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(16) NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(512),
    converted  TINYINT(1) NOT NULL DEFAULT 0,
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code    (code),
    INDEX idx_visited (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    referrer_id       INT NOT NULL,
    referred_id       INT NOT NULL,
    code              VARCHAR(16) NOT NULL,
    registered_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email_verified_at DATETIME NULL,
    first_payment_at  DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_referred (referred_id),
    INDEX idx_referrer (referrer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS points_wallets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    balance      INT NOT NULL DEFAULT 0,
    total_earned INT NOT NULL DEFAULT 0,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS points_transactions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    event      VARCHAR(64) NOT NULL,
    points     INT NOT NULL,
    related_id INT NULL,
    note       VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_event (user_id, event),
    INDEX idx_user_date  (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v006 settings seeds
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
    ('referrals_enabled',              '1'),
    ('points_registration',            '10'),
    ('points_email_verification',      '5'),
    ('points_phone_verification',      '5'),
    ('points_profile_photo',           '5'),
    ('points_referral_registers',      '5'),
    ('points_referral_email_verified', '5'),
    ('points_referral_first_payment',  '10'),
    ('points_hire_worker',             '10'),
    ('points_hire_worker_cap',         '20'),
    ('points_mark_job_completed',      '5'),
    ('points_mark_job_completed_cap',  '10'),
    ('points_leave_review',            '5'),
    ('points_leave_review_cap',        '10'),
    ('points_complete_job',            '10'),
    ('points_complete_job_cap',        '20'),
    ('points_five_star_rating',        '5'),
    ('points_five_star_rating_cap',    '10');


-- ═══════════════════════════════════════════════════════════════════════════
-- v007  Community tables
-- ═══════════════════════════════════════════════════════════════════════════
-- These tables were created manually (no prior migration file).
-- They are included here for complete fresh-install coverage.

-- v008 extends news.status and events.status to add 'pending_payment';
-- the final ENUMs below already include that value.

CREATE TABLE IF NOT EXISTS news (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    title             VARCHAR(255) NOT NULL,
    slug              VARCHAR(255) NOT NULL,
    summary           TEXT,
    content           LONGTEXT,
    featured_image    VARCHAR(255) DEFAULT NULL,
    -- Final ENUM (v007 base + 'pending_payment' from v008 + 'rejected' from v010)
    status            ENUM('pending_payment','draft','published','rejected') NOT NULL DEFAULT 'draft',
    view_count        INT UNSIGNED NOT NULL DEFAULT 0,
    notification_sent TINYINT(1) NOT NULL DEFAULT 0,
    published_at      DATETIME DEFAULT NULL,
    rejection_reason  VARCHAR(500) NULL DEFAULT NULL,  -- v010
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_news_slug (slug),
    INDEX idx_news_user_id (user_id),
    INDEX idx_news_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    title             VARCHAR(255) NOT NULL,
    slug              VARCHAR(255) NOT NULL,
    featured_image    VARCHAR(255) DEFAULT NULL,
    description       TEXT,
    venue             VARCHAR(255) DEFAULT NULL,
    gps_address       VARCHAR(255) DEFAULT NULL,
    start_date        DATE NOT NULL,
    end_date          DATE DEFAULT NULL,
    start_time        TIME DEFAULT NULL,
    end_time          TIME DEFAULT NULL,
    organizer_name    VARCHAR(120) DEFAULT NULL,
    ticket_type       ENUM('free','paid','registration') NOT NULL DEFAULT 'free',
    ticket_price      DECIMAL(10,2) DEFAULT NULL,
    registration_link VARCHAR(512) DEFAULT NULL,
    google_maps_link  VARCHAR(512) DEFAULT NULL,
    featured          TINYINT(1) NOT NULL DEFAULT 0,
    -- Final ENUM (v007 base + 'pending_payment' from v008 + 'rejected' from v010)
    status            ENUM('pending_payment','draft','published','cancelled','rejected') NOT NULL DEFAULT 'draft',
    location_id       INT UNSIGNED NULL DEFAULT NULL,  -- v009
    rejection_reason  VARCHAR(500) NULL DEFAULT NULL,  -- v010
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_events_slug (slug),
    INDEX idx_events_user_id (user_id),
    INDEX idx_events_status (status),
    INDEX idx_events_start_date (start_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funeral_announcements (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    slug              VARCHAR(255) NOT NULL,
    deceased_name     VARCHAR(180) NOT NULL,
    gender            ENUM('male','female','other') DEFAULT NULL,
    age               TINYINT UNSIGNED DEFAULT NULL,
    photograph        VARCHAR(255) DEFAULT NULL,
    funeral_poster    VARCHAR(255) DEFAULT NULL,
    date_of_birth     DATE DEFAULT NULL,
    date_of_death     DATE DEFAULT NULL,
    biography         TEXT DEFAULT NULL,
    wake_keeping_date DATETIME DEFAULT NULL,
    burial_date       DATETIME DEFAULT NULL,
    thanksgiving_date DATETIME DEFAULT NULL,
    venue             VARCHAR(255) DEFAULT NULL,
    gps_address       VARCHAR(255) DEFAULT NULL,
    organizer_name    VARCHAR(120) DEFAULT NULL,
    organizer_phone   VARCHAR(30) DEFAULT NULL,
    organizer_email   VARCHAR(180) DEFAULT NULL,
    google_maps_link  VARCHAR(512) DEFAULT NULL,
    -- Final ENUM (includes pending_payment + rejected)
    status            ENUM('pending_payment','pending','approved','rejected') NOT NULL DEFAULT 'pending',
    location_id       INT UNSIGNED NULL DEFAULT NULL,  -- v009
    rejection_reason  VARCHAR(500) NULL DEFAULT NULL,  -- v010
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_funeral_slug (slug),
    INDEX idx_funeral_user_id (user_id),
    INDEX idx_funeral_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertisements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    image           VARCHAR(255) DEFAULT NULL,
    destination_url VARCHAR(512) DEFAULT NULL,
    ad_type         ENUM('banner','sponsored') NOT NULL DEFAULT 'banner',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    click_count     INT UNSIGNED NOT NULL DEFAULT 0,
    start_date      DATE DEFAULT NULL,
    end_date        DATE DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ads_status (status),
    INDEX idx_ads_type (ad_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content publishing fee settings
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('enable_paid_news_posting',    '0', 'Charge a fee to submit news articles (0=free, 1=paid)'),
    ('enable_paid_event_posting',   '0', 'Charge a fee to submit community events (0=free, 1=paid)'),
    ('enable_paid_funeral_posting', '0', 'Charge a fee to submit funeral announcements (0=free, 1=paid)'),
    ('news_post_fee',    '0.00', 'Fee in GHS to post a news article'),
    ('event_post_fee',   '0.00', 'Fee in GHS to post an event'),
    ('funeral_post_fee', '0.00', 'Fee in GHS to post a funeral announcement');


-- ═══════════════════════════════════════════════════════════════════════════
-- v008  Content publishing fee ENUM extensions
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds 'pending_payment' to news.status and events.status.
-- (Already in the v007 CREATE TABLE statements above; ALTER TABLE below
--  handles existing databases that have the old ENUMs.)

ALTER TABLE platform_payments MODIFY COLUMN payment_type
    ENUM('featured_job','featured_worker','verification','job_post','worker_service',
         'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post') NOT NULL;

ALTER TABLE news MODIFY COLUMN status
    ENUM('pending_payment','draft','published','rejected') NOT NULL DEFAULT 'draft';

ALTER TABLE events MODIFY COLUMN status
    ENUM('pending_payment','draft','published','cancelled','rejected') NOT NULL DEFAULT 'draft';


-- ═══════════════════════════════════════════════════════════════════════════
-- v009  Location mapping
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS locations (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_name     VARCHAR(255) NOT NULL DEFAULT '',
    formatted_address VARCHAR(512) NOT NULL DEFAULT '',
    latitude          DECIMAL(10,7) NOT NULL,
    longitude         DECIMAL(10,7) NOT NULL,
    google_maps_url   VARCHAR(512) NOT NULL DEFAULT '',
    osm_maps_url      VARCHAR(512) NOT NULL DEFAULT '',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lat_lng (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events                ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE service_requests      ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL DEFAULT NULL;


-- ═══════════════════════════════════════════════════════════════════════════
-- v010  Rejection-reason columns
-- ═══════════════════════════════════════════════════════════════════════════
-- These were applied via a one-off temp script (since deleted).
-- Extends service_requests.status to include 'draft' and 'rejected'.
-- Extends news.status and events.status to include 'rejected'.
-- Adds rejection_reason VARCHAR(500) to all four content tables.

-- service_requests: extend status ENUM to add 'draft' and 'rejected'
ALTER TABLE service_requests MODIFY COLUMN status
    ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected')
    NOT NULL DEFAULT 'draft';

-- news: extend status to add 'rejected'
ALTER TABLE news MODIFY COLUMN status
    ENUM('pending_payment','draft','published','rejected') NOT NULL DEFAULT 'draft';

-- events: extend status to add 'rejected'
ALTER TABLE events MODIFY COLUMN status
    ENUM('pending_payment','draft','published','cancelled','rejected') NOT NULL DEFAULT 'draft';

-- funeral_announcements: extend status to add 'rejected'
ALTER TABLE funeral_announcements MODIFY COLUMN status
    ENUM('pending_payment','pending','approved','rejected') NOT NULL DEFAULT 'pending';

ALTER TABLE service_requests      ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE events                ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE news                  ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL DEFAULT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
SET foreign_key_checks = 1;
-- ─────────────────────────────────────────────────────────────────────────────

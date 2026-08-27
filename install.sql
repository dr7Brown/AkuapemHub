-- ─────────────────────────────────────────────────────────────────────────────
-- AkuapemConnect — Master Installation SQL
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
--   v011  Delivery Services module                   (migrate_delivery.php → now in install.sql)
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
    id_type_custom               VARCHAR(100) DEFAULT NULL,
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
    status             ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected','expired') NOT NULL DEFAULT 'draft',
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
    link       VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS link VARCHAR(500) DEFAULT NULL;

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
    thumb_path        VARCHAR(255) DEFAULT NULL,
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
-- INSERT IGNORE INTO featured_job_packages (name, duration_days, price, status) VALUES
--     ('7 Days',  7,  0.00, 'active'),
--     ('14 Days', 14, 0.00, 'active'),
--     ('30 Days', 30, 0.00, 'active');

-- INSERT IGNORE INTO worker_promotion_packages (name, duration_days, price, status) VALUES
--     ('7 Days',  7,  0.00, 'active'),
--     ('30 Days', 30, 0.00, 'active'),
--     ('90 Days', 90, 0.00, 'active');

-- INSERT IGNORE INTO verification_packages (name, price, status) VALUES
--     ('Verified Worker Badge', 0.00, 'active');

-- INSERT IGNORE INTO job_posting_packages (name, post_count, price, status) VALUES
--     ('Single Post',      1,  0.00, 'active'),
--     ('5 Post Bundle',    5,  0.00, 'active'),
--     ('Monthly Unlimited', -1, 0.00, 'active');

-- INSERT IGNORE INTO worker_service_packages (name, duration_days, price, status) VALUES
--     ('Monthly Listing', 30,  0.00, 'active'),
--     ('3 Month Listing', 90,  0.00, 'active'),
--     ('Annual Listing',  365, 0.00, 'active');

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
    ('chat_allow_direct_all',         '0',    'Allow any user to message any other user freely'),
    ('smtp_enabled',                  '0',    'Use SMTP for email delivery (0=PHP mail(), 1=SMTP)'),
    ('smtp_host',                     '',     'SMTP server hostname e.g. smtp.gmail.com'),
    ('smtp_port',                     '587',  'SMTP port: 587=STARTTLS, 465=SSL, 25=plain'),
    ('smtp_encryption',               'tls',  'Encryption: tls, ssl, or none'),
    ('smtp_username',                 '',     'SMTP login username / email address'),
    ('smtp_password',                 '',     'SMTP login password or app-specific password'),
    ('smtp_from_name',                '',     'Sender display name (blank = APP_NAME)'),
    ('smtp_from_email',               '',     'Sender email address (blank = MAIL_FROM in config.php)');


-- Login rate-limiting table (auto-created by functions.php on first use; here for completeness)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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

-- Mark accounts that existed BEFORE this migration as already verified, so they
-- are not locked out (they registered before email verification existed and
-- never received a token). Scoped to a fixed cutoff so re-running install.sql
-- never re-verifies genuinely-unverified accounts created after this date —
-- an unscoped "WHERE email_verified = 0" would silently undo verification for
-- every new signup each time this file is re-run.
UPDATE users SET email_verified = 1 WHERE email_verified = 0 AND created_at < '2026-06-17 00:00:00';


-- ═══════════════════════════════════════════════════════════════════════════
-- v004  Escrow payments
-- ═══════════════════════════════════════════════════════════════════════════
-- Extends service_requests ENUMs and adds escrow columns.
-- Adds escrow_payments table.

ALTER TABLE service_requests MODIFY COLUMN status
    ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected','expired')
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
    view_count        INT UNSIGNED NOT NULL DEFAULT 0,
    published_at      DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_events_slug (slug),
    INDEX idx_events_user_id (user_id),
    INDEX idx_events_status (status),
    INDEX idx_events_start_date (start_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events ADD COLUMN IF NOT EXISTS published_at DATETIME DEFAULT NULL;

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
    view_count        INT UNSIGNED NOT NULL DEFAULT 0,
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
    ENUM('draft','pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled','rejected','expired')
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


-- ═══════════════════════════════════════════════════════════════════════════
-- v011  Delivery Services module
-- ═══════════════════════════════════════════════════════════════════════════
-- New tables: delivery_agents, delivery_requests, delivery_ratings
-- New platform_settings rows: delivery_enabled, delivery_base_fee, delivery_fee_mode

CREATE TABLE IF NOT EXISTS delivery_agents (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    vehicle_type         ENUM('motorbike','bicycle','car','van','truck','public_transport','other') NOT NULL DEFAULT 'motorbike',
    vehicle_registration VARCHAR(100) DEFAULT NULL,
    license_number       VARCHAR(100) DEFAULT NULL,
    service_area         VARCHAR(500) DEFAULT NULL,
    bio                  TEXT DEFAULT NULL,
    availability_status  ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
    verification_status  ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason     TEXT DEFAULT NULL,
    id_type              VARCHAR(100) DEFAULT NULL,
    id_type_custom       VARCHAR(100) DEFAULT NULL,
    id_number            VARCHAR(100) DEFAULT NULL,
    id_document_path     VARCHAR(255) DEFAULT NULL,
    rating               DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    completed_deliveries INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_da_user (user_id),
    KEY idx_da_verification (verification_status),
    KEY idx_da_availability (availability_status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_requests (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id          INT UNSIGNED NOT NULL,
    agent_id             INT UNSIGNED DEFAULT NULL,
    pickup_location      VARCHAR(500) NOT NULL,
    pickup_lat           DECIMAL(10,7) DEFAULT NULL,
    pickup_lng           DECIMAL(10,7) DEFAULT NULL,
    pickup_contact_name  VARCHAR(255) NOT NULL,
    pickup_contact_phone VARCHAR(50) NOT NULL,
    dropoff_location     VARCHAR(500) NOT NULL,
    dropoff_lat          DECIMAL(10,7) DEFAULT NULL,
    dropoff_lng          DECIMAL(10,7) DEFAULT NULL,
    receiver_name        VARCHAR(255) NOT NULL,
    receiver_phone       VARCHAR(50) NOT NULL,
    item_description     TEXT NOT NULL,
    item_category        ENUM('documents','food','electronics','clothing','medical_supplies','groceries','parcels','other') NOT NULL DEFAULT 'parcels',
    package_weight       DECIMAL(6,2) DEFAULT NULL,
    delivery_notes       TEXT DEFAULT NULL,
    delivery_fee         DECIMAL(10,2) DEFAULT NULL,
    payment_method       ENUM('cash','mobile_money','card','wallet') NOT NULL DEFAULT 'cash',
    payment_status       ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    preferred_date       DATE DEFAULT NULL,
    preferred_time       TIME DEFAULT NULL,
    status               ENUM('pending','accepted','picked_up','in_transit','delivered','cancelled','failed') NOT NULL DEFAULT 'pending',
    cancelled_reason     TEXT DEFAULT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dr_customer (customer_id),
    KEY idx_dr_agent (agent_id),
    KEY idx_dr_status (status),
    KEY idx_dr_created (created_at),
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_ratings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_request_id INT UNSIGNED NOT NULL,
    customer_rating     TINYINT DEFAULT NULL,
    customer_comment    TEXT DEFAULT NULL,
    agent_rating        TINYINT DEFAULT NULL,
    agent_comment       TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drat_request (delivery_request_id),
    FOREIGN KEY (delivery_request_id) REFERENCES delivery_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v011 settings seeds
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('delivery_enabled',   '1',    'Whether the Delivery Services module is active (1=yes, 0=no)'),
    ('delivery_base_fee',  '0.00', 'Default delivery base fee in GH₵; 0 means customer and agent negotiate'),
    ('delivery_fee_mode',  'free', 'free = negotiate, fixed = use delivery_base_fee');

-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
-- v012  Delivery Monetization, Approval Workflow & Application System
-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
-- New tables : delivery_applications, delivery_subscriptions,
--              delivery_sponsored_listings, delivery_verifications,
--              delivery_transactions
-- New columns: delivery_requests (is_flagged, flag_reason, auto_approved,
--              rejection_reason); delivery_agents (is_premium, premium_start,
--              premium_end, is_sponsored, sponsored_end, is_verified,
--              selfie_path, drivers_license_path, trust_level,
--              auto_approve_enabled)

-- Extend delivery_requests status ENUM
ALTER TABLE delivery_requests MODIFY COLUMN status
    ENUM('draft','pending','pending_approval','approved','open','assigned',
         'accepted','picked_up','in_progress','in_transit',
         'delivered','cancelled','expired','rejected','failed')
    NOT NULL DEFAULT 'pending_approval';

-- New columns on delivery_requests
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS is_flagged       TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS flag_reason      TEXT DEFAULT NULL;
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS auto_approved    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL;

-- New columns on delivery_agents
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS is_premium           TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS premium_start        DATE DEFAULT NULL;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS premium_end          DATE DEFAULT NULL;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS is_sponsored         TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS sponsored_end        DATE DEFAULT NULL;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS is_verified          TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS selfie_path          VARCHAR(255) DEFAULT NULL;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS drivers_license_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS trust_level          ENUM('new','bronze','silver','gold','platinum') NOT NULL DEFAULT 'new';
ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS auto_approve_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- New table: delivery_applications
CREATE TABLE IF NOT EXISTS delivery_applications (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_request_id INT UNSIGNED NOT NULL,
    agent_id            INT UNSIGNED NOT NULL,
    offer_note          TEXT DEFAULT NULL,
    offered_fee         DECIMAL(10,2) DEFAULT NULL,
    status              ENUM('applied','shortlisted','accepted','rejected','withdrawn','assigned','completed')
                        NOT NULL DEFAULT 'applied',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_da_app  (delivery_request_id, agent_id),
    KEY idx_dapp_agent    (agent_id),
    KEY idx_dapp_status   (status),
    FOREIGN KEY (delivery_request_id) REFERENCES delivery_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id)            REFERENCES delivery_agents(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New table: delivery_subscriptions
CREATE TABLE IF NOT EXISTS delivery_subscriptions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id       INT UNSIGNED NOT NULL,
    plan_type      ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    price_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('mtn_momo','telecel','airtel','wallet','free') NOT NULL DEFAULT 'mtn_momo',
    mobi_number    VARCHAR(30) DEFAULT NULL,
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    status         ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
    activated_by   INT UNSIGNED DEFAULT NULL,
    activated_at   DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dsub_agent  (agent_id),
    KEY idx_dsub_status (status),
    KEY idx_dsub_end    (end_date),
    FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New table: delivery_sponsored_listings
CREATE TABLE IF NOT EXISTS delivery_sponsored_listings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id       INT UNSIGNED NOT NULL,
    package_days   TINYINT UNSIGNED NOT NULL,
    price_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('mtn_momo','telecel','airtel','wallet','free') NOT NULL DEFAULT 'mtn_momo',
    mobi_number    VARCHAR(30) DEFAULT NULL,
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    status         ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
    activated_by   INT UNSIGNED DEFAULT NULL,
    activated_at   DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dsp_agent  (agent_id),
    KEY idx_dsp_status (status),
    KEY idx_dsp_end    (end_date),
    FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New table: delivery_verifications (Verified Rider Badge)
CREATE TABLE IF NOT EXISTS delivery_verifications (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id         INT UNSIGNED NOT NULL,
    ghana_card_path  VARCHAR(255) DEFAULT NULL,
    license_path     VARCHAR(255) DEFAULT NULL,
    vehicle_reg_path VARCHAR(255) DEFAULT NULL,
    selfie_path      VARCHAR(255) DEFAULT NULL,
    fee_paid         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT DEFAULT NULL,
    submitted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at      DATETIME DEFAULT NULL,
    UNIQUE KEY uq_dv_agent (agent_id),
    FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New table: delivery_transactions
CREATE TABLE IF NOT EXISTS delivery_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id         INT UNSIGNED NOT NULL,
    transaction_type ENUM('subscription','sponsored','verification') NOT NULL,
    amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method   ENUM('mtn_momo','telecel','airtel','wallet','free') NOT NULL DEFAULT 'mtn_momo',
    mobi_number      VARCHAR(30) DEFAULT NULL,
    reference        VARCHAR(100) DEFAULT NULL,
    status           ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    related_id       INT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dtx_agent  (agent_id),
    KEY idx_dtx_type   (transaction_type),
    KEY idx_dtx_status (status),
    FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v012 platform_settings seeds
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('delivery_require_approval',            '1',     'Require admin to approve requests before riders see them (1=yes)'),
    ('delivery_auto_approve_min_deliveries', '10',    'Min completed deliveries for customer auto-approval'),
    ('delivery_auto_approve_min_days',       '60',    'Min account age in days for customer auto-approval'),
    ('delivery_enable_premium',              '0',     'Enable premium rider subscriptions feature'),
    ('delivery_premium_requires_payment',    '0',     'Require payment for premium (0=admin grants free)'),
    ('delivery_premium_monthly_price',       '20.00', 'Monthly premium subscription price in GHS'),
    ('delivery_premium_quarterly_price',     '50.00', 'Quarterly premium subscription price in GHS'),
    ('delivery_premium_yearly_price',        '180.00','Yearly premium subscription price in GHS'),
    ('delivery_enable_verification_fee',     '0',     'Charge a fee for the Verified Rider badge (0=free)'),
    ('delivery_verification_fee',            '0.00',  'One-time verification badge fee in GHS'),
    ('delivery_enable_sponsored',            '0',     'Enable sponsored rider listings feature'),
    ('delivery_sponsored_requires_payment',  '0',     'Require payment for sponsored listings'),
    ('delivery_sponsored_7day_price',        '10.00', '7-day sponsored listing price in GHS'),
    ('delivery_sponsored_30day_price',       '30.00', '30-day sponsored listing price in GHS'),
    ('delivery_sponsored_90day_price',       '70.00', '90-day sponsored listing price in GHS');

-- ==========================================================================
-- v013  Marketplace Module
-- ==========================================================================
-- New tables: mp_categories, mp_shops, mp_products, mp_product_images,
--             mp_cart, mp_cart_items, mp_saved_products,
--             mp_orders, mp_order_items, mp_reviews, mp_shop_verifications

CREATE TABLE IF NOT EXISTS mp_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(100) NOT NULL,
    icon       VARCHAR(10)  DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_mcat_slug (slug),
    UNIQUE KEY uq_mcat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_shops (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    shop_name           VARCHAR(180) NOT NULL,
    slug                VARCHAR(180) NOT NULL,
    description         TEXT,
    logo_path           VARCHAR(255),
    banner_path         VARCHAR(255),
    phone               VARCHAR(30),
    email               VARCHAR(180),
    town_id             INT UNSIGNED,
    region              VARCHAR(100),
    verification_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    rejection_reason    TEXT,
    is_featured         TINYINT(1) NOT NULL DEFAULT 0,
    featured_end        DATE,
    is_sponsored        TINYINT(1) NOT NULL DEFAULT 0,
    sponsored_end       DATE,
    rating              DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    total_sales         INT UNSIGNED NOT NULL DEFAULT 0,
    view_count          INT UNSIGNED NOT NULL DEFAULT 0,
    status              ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mshop_user (user_id),
    UNIQUE KEY uq_mshop_slug (slug),
    KEY idx_mshop_status   (status),
    KEY idx_mshop_featured (is_featured),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_products (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id            INT UNSIGNED NOT NULL,
    category_id        INT UNSIGNED,
    name               VARCHAR(255) NOT NULL,
    slug               VARCHAR(255) NOT NULL,
    description        TEXT,
    price              DECIMAL(10,2) NOT NULL,
    discount_price     DECIMAL(10,2),
    stock_quantity     INT NOT NULL DEFAULT 0,
    sku                VARCHAR(100),
    condition_type     ENUM('new','used','refurbished') NOT NULL DEFAULT 'new',
    delivery_available TINYINT(1) NOT NULL DEFAULT 1,
    status             ENUM('draft','pending_approval','approved','rejected','out_of_stock','archived') NOT NULL DEFAULT 'draft',
    rejection_reason   TEXT,
    is_featured        TINYINT(1) NOT NULL DEFAULT 0,
    featured_end       DATE,
    is_sponsored       TINYINT(1) NOT NULL DEFAULT 0,
    sponsored_end      DATE,
    view_count         INT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mprod_shop     (shop_id),
    KEY idx_mprod_category (category_id),
    KEY idx_mprod_status   (status),
    KEY idx_mprod_featured (is_featured),
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_product_images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mpimg_product (product_id),
    FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_cart (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mcart_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_cart_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 1,
    added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mci_cart_product (cart_id, product_id),
    FOREIGN KEY (cart_id)    REFERENCES mp_cart(id)     ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_saved_products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    saved_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_msp_user_product (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)        ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES mp_products(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    shop_id             INT UNSIGNED NOT NULL,
    total_amount        DECIMAL(10,2) NOT NULL,
    delivery_fee        DECIMAL(10,2),
    delivery_address    TEXT,
    receiver_name       VARCHAR(180),
    receiver_phone      VARCHAR(30),
    payment_method      ENUM('cash_on_delivery','mobile_money','card','wallet') NOT NULL DEFAULT 'cash_on_delivery',
    payment_status      ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    status              ENUM('pending','confirmed','processing','ready_for_delivery','in_transit','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
    delivery_request_id INT UNSIGNED,
    notes               TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mord_customer (customer_id),
    KEY idx_mord_shop     (shop_id),
    KEY idx_mord_status   (status),
    FOREIGN KEY (customer_id) REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (shop_id)     REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED,
    product_name VARCHAR(255) NOT NULL,
    price        DECIMAL(10,2) NOT NULL,
    quantity     INT UNSIGNED NOT NULL,
    subtotal     DECIMAL(10,2) NOT NULL,
    KEY idx_moi_order   (order_id),
    KEY idx_moi_product (product_id),
    FOREIGN KEY (order_id)   REFERENCES mp_orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_reviews (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_id INT UNSIGNED NOT NULL,
    order_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED,
    shop_id     INT UNSIGNED,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     TEXT,
    review_type ENUM('product','seller') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mrev_product (product_id),
    KEY idx_mrev_shop    (shop_id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (order_id)    REFERENCES mp_orders(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_shop_verifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id           INT UNSIGNED NOT NULL,
    ghana_card_path   VARCHAR(255),
    business_reg_path VARCHAR(255),
    status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason  TEXT,
    submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at       DATETIME,
    UNIQUE KEY uq_msv_shop (shop_id),
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v013 category seeds
INSERT IGNORE INTO mp_categories (name, slug, icon, sort_order) VALUES
    ('Electronics & Gadgets', 'electronics',  NULL, 1),
    ('Fashion & Clothing',    'fashion',       NULL, 2),
    ('Food & Groceries',      'food',          NULL, 3),
    ('Home & Living',         'home',          NULL, 4),
    ('Health & Beauty',       'health-beauty', NULL, 5),
    ('Agriculture & Farming', 'agriculture',   NULL, 6),
    ('Baby & Kids',           'baby-kids',     NULL, 7),
    ('Books & Education',     'books',         NULL, 8),
    ('Vehicles & Parts',      'vehicles',      NULL, 9),
    ('Art & Crafts',          'art-crafts',    NULL, 10),
    ('Sports & Fitness',      'sports',        NULL, 11),
    ('Business & Industrial', 'business',           NULL, 12),
    -- Extended categories (v013+)
    ('Furniture & Home Décor',   'furniture',          NULL, 13),
    ('Shoes & Footwear',         'shoes',              NULL, 14),
    ('Jewelry & Accessories',    'jewelry',            NULL, 15),
    ('Building & Hardware',      'building-hardware',  NULL, 16),
    ('Kitchen & Cooking',        'kitchen-cooking',    NULL, 17),
    ('Solar & Energy',           'solar-energy',       NULL, 18),
    ('Traditional Crafts',       'traditional-crafts', NULL, 19),
    ('Mobile Phones',            'mobile-phones',      NULL, 20),
    ('Office & Stationery',      'office-stationery',  NULL, 21),
    ('Cleaning Supplies',        'cleaning',           NULL, 22),
    ('Electrical & Plumbing',    'electrical-plumbing',NULL, 23),
    ('School Supplies',          'school',             NULL, 24),
    ('Plants & Garden',          'plants-garden',      NULL, 25),
    ('Pets & Animals',           'pets',               NULL, 26),
    ('Second-Hand / Preloved',   'second-hand',        NULL, 27),
    ('Other',                    'other',              NULL, 99);

-- Set category icons using hex so the SQL file stays pure ASCII
UPDATE mp_categories SET icon = _utf8mb4 0xF09F93B1 WHERE slug = 'electronics';        -- 📱
UPDATE mp_categories SET icon = _utf8mb4 0xF09F9195 WHERE slug = 'fashion';             -- 👕
UPDATE mp_categories SET icon = _utf8mb4 0xF09F9B92 WHERE slug = 'food';                -- 🛒
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8FA0 WHERE slug = 'home';                -- 🏠
UPDATE mp_categories SET icon = _utf8mb4 0xF09F928A WHERE slug = 'health-beauty';       -- 💊
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8CBE WHERE slug = 'agriculture';         -- 🌾
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8DBC WHERE slug = 'baby-kids';           -- 🍼
UPDATE mp_categories SET icon = _utf8mb4 0xF09F939A WHERE slug = 'books';               -- 📚
UPDATE mp_categories SET icon = _utf8mb4 0xF09F9A97 WHERE slug = 'vehicles';            -- 🚗
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8EA8 WHERE slug = 'art-crafts';          -- 🎨
UPDATE mp_categories SET icon = _utf8mb4 0xE29ABD   WHERE slug = 'sports';              -- ⚽
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8FAD WHERE slug = 'business';            -- 🏭
UPDATE mp_categories SET icon = _utf8mb4 0xF09F9B8B WHERE slug = 'furniture';           -- 🛋️
UPDATE mp_categories SET icon = _utf8mb4 0xF09F919F WHERE slug = 'shoes';               -- 👟
UPDATE mp_categories SET icon = _utf8mb4 0xF09F928D WHERE slug = 'jewelry';             -- 💍
UPDATE mp_categories SET icon = _utf8mb4 0xF09F94A8 WHERE slug = 'building-hardware';   -- 🔨
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8DB3 WHERE slug = 'kitchen-cooking';     -- 🍳
UPDATE mp_categories SET icon = _utf8mb4 0xE29880   WHERE slug = 'solar-energy';        -- ☀
UPDATE mp_categories SET icon = _utf8mb4 0xF09FA7B5 WHERE slug = 'traditional-crafts';  -- 🧵
UPDATE mp_categories SET icon = _utf8mb4 0xF09F93B2 WHERE slug = 'mobile-phones';       -- 📲
UPDATE mp_categories SET icon = _utf8mb4 0xF09F938E WHERE slug = 'office-stationery';   -- 📎
UPDATE mp_categories SET icon = _utf8mb4 0xF09FA7B9 WHERE slug = 'cleaning';            -- 🧹
UPDATE mp_categories SET icon = _utf8mb4 0xF09F948C WHERE slug = 'electrical-plumbing'; -- 🔌
UPDATE mp_categories SET icon = _utf8mb4 0xE29C8F   WHERE slug = 'school';              -- ✏
UPDATE mp_categories SET icon = _utf8mb4 0xF09F8CB1 WHERE slug = 'plants-garden';       -- 🌱
UPDATE mp_categories SET icon = _utf8mb4 0xF09F90BE WHERE slug = 'pets';                -- 🐾
UPDATE mp_categories SET icon = _utf8mb4 0xE299BB   WHERE slug = 'second-hand';         -- ♻
UPDATE mp_categories SET icon = _utf8mb4 0xF09F93A6 WHERE slug = 'other';               -- 📦

-- v013 platform_settings
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mp_enabled',                        '1',    'Enable the Marketplace module'),
    ('mp_require_product_approval',       '1',    'Require admin to approve products before listing'),
    ('mp_featured_product_7day_price',   '15.00', 'Featured product 7-day price GHS'),
    ('mp_featured_product_30day_price',  '40.00', 'Featured product 30-day price GHS'),
    ('mp_featured_shop_7day_price',      '20.00', 'Featured shop 7-day price GHS'),
    ('mp_featured_shop_30day_price',     '55.00', 'Featured shop 30-day price GHS'),
    ('mp_seller_subscription_price',     '30.00', 'Premium seller monthly subscription GHS'),
    ('mp_verified_seller_fee',            '0.00', 'Verified seller badge fee GHS (0=free)');

-- ==========================================================================
-- v013b  Marketplace Boost Orders
-- ==========================================================================
-- Sellers pay to feature or sponsor their products / shop.

CREATE TABLE IF NOT EXISTS mp_boost_orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id        INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED,
    boost_type     ENUM('featured_product','sponsored_product','featured_shop','sponsored_shop') NOT NULL,
    package_days   TINYINT UNSIGNED NOT NULL,
    price_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('mtn_momo','telecel','airtel','wallet','free') NOT NULL DEFAULT 'mtn_momo',
    mobi_number    VARCHAR(30),
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    status         ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
    activated_by   INT UNSIGNED,
    activated_at   DATETIME,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mbo_shop    (shop_id),
    KEY idx_mbo_status  (status),
    KEY idx_mbo_product (product_id),
    FOREIGN KEY (shop_id)    REFERENCES mp_shops(id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================================
-- v014  Location columns (reserved for future use)
-- ==========================================================================
-- Adds latitude/longitude columns to events and funeral_announcements.
-- Currently unused — location is stored as text (venue, gps_address) with an
-- optional Google Maps URL. These columns are kept as a hook for future
-- map integration when needed.

ALTER TABLE events ADD COLUMN IF NOT EXISTS latitude     DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE events ADD COLUMN IF NOT EXISTS longitude    DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE events ADD COLUMN IF NOT EXISTS published_at DATETIME      DEFAULT NULL;

ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS latitude  DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) DEFAULT NULL;

-- Google Maps links on delivery addresses (v014b)
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS pickup_maps_link  VARCHAR(512) DEFAULT NULL;
ALTER TABLE delivery_requests ADD COLUMN IF NOT EXISTS dropoff_maps_link VARCHAR(512) DEFAULT NULL;
ALTER TABLE mp_orders         ADD COLUMN IF NOT EXISTS delivery_maps_link VARCHAR(512) DEFAULT NULL;

-- v015  Extend platform_payments.payment_type to cover marketplace boosts + delivery subscriptions
ALTER TABLE platform_payments MODIFY COLUMN payment_type
    ENUM('featured_job','featured_worker','verification','job_post','worker_service',
         'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
         'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification')
    NOT NULL;

-- ==========================================================================
-- v016  Moderator Permission System
-- ==========================================================================
-- Granular per-permission access control for manager accounts.
-- Admins have all permissions implicitly; managers only what is granted here.

CREATE TABLE IF NOT EXISTS moderator_permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    permission  VARCHAR(80) NOT NULL,
    granted_by  INT UNSIGNED NOT NULL,
    granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mp_user_perm (user_id, permission),
    KEY idx_mp_user    (user_id),
    KEY idx_mp_granted (granted_by),
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- v017  Moderator Performance & Rewards Module
-- ==========================================================================

-- Activity log: every moderation action
CREATE TABLE IF NOT EXISTS mod_activity_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mod_id      INT UNSIGNED NOT NULL,
    module      ENUM('jobs','events','funerals','news','marketplace','delivery','users','disputes','ads','general') NOT NULL DEFAULT 'general',
    action_key  VARCHAR(80) NOT NULL,
    record_id   INT UNSIGNED DEFAULT NULL,
    points      DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    notes       TEXT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mal_mod    (mod_id),
    KEY idx_mal_module (module),
    KEY idx_mal_date   (created_at),
    KEY idx_mal_action (action_key),
    FOREIGN KEY (mod_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurable point values per action
CREATE TABLE IF NOT EXISTS mod_point_config (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key VARCHAR(80) NOT NULL UNIQUE,
    label      VARCHAR(120) NOT NULL,
    points     DECIMAL(6,2) NOT NULL DEFAULT 1.00,
    module     VARCHAR(40) NOT NULL DEFAULT 'general'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reward / payout requests
CREATE TABLE IF NOT EXISTS mod_rewards (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mod_id       INT UNSIGNED NOT NULL,
    reward_type  ENUM('cash','wallet','points') NOT NULL DEFAULT 'cash',
    amount_ghs   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    points_used  INT UNSIGNED NOT NULL DEFAULT 0,
    status       ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    mobi_number  VARCHAR(30) DEFAULT NULL,
    notes        TEXT DEFAULT NULL,
    approved_by  INT UNSIGNED DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at      DATETIME DEFAULT NULL,
    KEY idx_mr_mod    (mod_id),
    KEY idx_mr_status (status),
    FOREIGN KEY (mod_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default point config seeds
INSERT IGNORE INTO mod_point_config (action_key, label, points, module) VALUES
    ('approve_job',               'Approve Job Request',          2.00, 'jobs'),
    ('reject_job',                'Reject Job Request',           2.00, 'jobs'),
    ('approve_product',           'Approve Marketplace Product',  2.00, 'marketplace'),
    ('reject_product',            'Reject Marketplace Product',   2.00, 'marketplace'),
    ('approve_shop',              'Verify Seller Shop',           5.00, 'marketplace'),
    ('approve_event',             'Approve Event',                2.00, 'events'),
    ('reject_event',              'Reject Event',                 2.00, 'events'),
    ('approve_funeral',           'Approve Funeral Announcement', 2.00, 'funerals'),
    ('reject_funeral',            'Reject Funeral Announcement',  2.00, 'funerals'),
    ('approve_news',              'Approve News Article',         2.00, 'news'),
    ('reject_news',               'Reject News Article',         2.00, 'news'),
    ('approve_delivery_request',  'Approve Delivery Request',     1.00, 'delivery'),
    ('reject_delivery_request',   'Reject Delivery Request',      1.00, 'delivery'),
    ('approve_delivery_agent',    'Verify Delivery Rider',        5.00, 'delivery'),
    ('reject_delivery_agent',     'Reject Rider Application',     1.00, 'delivery'),
    ('approve_verification',      'Approve Rider Verification',   5.00, 'delivery'),
    ('manage_users_ban',          'Suspend User Account',         8.00, 'users'),
    ('manage_users_unban',        'Restore User Account',         3.00, 'users'),
    ('resolve_dispute',           'Resolve User Dispute',        10.00, 'disputes'),
    ('activate_boost',            'Activate Boost Listing',       2.00, 'marketplace'),
    ('manage_ads',                'Manage Advertisement',         2.00, 'ads');

-- Platform settings for performance module
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mod_perf_enabled',          '1',    'Enable moderator performance tracking'),
    ('mod_reward_100pts',        '10.00', 'Cash reward for 100 points (GHS)'),
    ('mod_reward_500pts',        '60.00', 'Cash reward for 500 points (GHS)'),
    ('mod_reward_1000pts',      '150.00', 'Cash reward for 1000 points (GHS)'),
    ('mod_flag_low_accuracy',    '65',    'Flag moderator if accuracy falls below this % (0=disabled)'),
    ('mod_flag_inactivity_days', '7',     'Flag moderator after this many inactive days (0=disabled)'),
    ('mod_flag_high_approval',   '95',    'Flag if approval rate exceeds this % (0=disabled)');

-- ==========================================================================
-- v018  Conflict of Interest (COI) Policy
-- ==========================================================================
-- Adds reviewed_by / approved_by tracking columns to all content tables
-- so moderation history is permanently recorded and COI is auditable.

ALTER TABLE service_requests      ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE events                ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE news                  ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE mp_products           ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE delivery_requests     ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE delivery_agents       ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED DEFAULT NULL;
ALTER TABLE delivery_agents       ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL;

-- ==========================================================================
-- v019  Resume pending Paystack transactions
-- ==========================================================================
ALTER TABLE platform_payments ADD COLUMN IF NOT EXISTS authorization_url VARCHAR(500) DEFAULT NULL;

-- ==========================================================================
-- v020  Featured listings for Events & Funerals
-- ==========================================================================

ALTER TABLE events                ADD COLUMN IF NOT EXISTS featured          TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE events                ADD COLUMN IF NOT EXISTS featured_end_date DATE DEFAULT NULL;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS featured          TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS featured_end_date DATE DEFAULT NULL;

CREATE TABLE IF NOT EXISTS featured_event_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS featured_funeral_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO featured_event_packages (id, name, duration_days, price, status) VALUES
(1, '7 Days',  7,  15.00, 'active'),
(2, '14 Days', 14, 25.00, 'active'),
(3, '30 Days', 30, 40.00, 'active');

INSERT IGNORE INTO featured_funeral_packages (id, name, duration_days, price, status) VALUES
(1, '7 Days',  7,  10.00, 'active'),
(2, '14 Days', 14, 18.00, 'active'),
(3, '30 Days', 30, 30.00, 'active');

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('enable_paid_featured_events',   '0'),
('enable_paid_featured_funerals', '0');

-- ==========================================================================
-- v021  Featured listings for News
-- ==========================================================================

ALTER TABLE news ADD COLUMN IF NOT EXISTS featured     TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE news ADD COLUMN IF NOT EXISTS featured_end_date DATE DEFAULT NULL;

CREATE TABLE IF NOT EXISTS featured_news_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO featured_news_packages (id, name, duration_days, price, status) VALUES
(1, '7 Days',  7,  8.00, 'active'),
(2, '14 Days', 14, 14.00, 'active'),
(3, '30 Days', 30, 25.00, 'active');

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('enable_paid_featured_news', '0');

-- ==========================================================================
-- v022  Marketplace Monetization — Boost Packages + Seller Subscriptions
-- ==========================================================================

CREATE TABLE IF NOT EXISTS mp_boost_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    boost_type    ENUM('featured_product','sponsored_product','featured_shop','sponsored_shop') NOT NULL,
    name          VARCHAR(100) NOT NULL,
    duration_days INT NOT NULL DEFAULT 7,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO mp_boost_packages (id, boost_type, name, duration_days, price, status) VALUES
(1, 'featured_product',  '7 Days',  7,  15.00, 'active'),
(2, 'featured_product',  '30 Days', 30, 40.00, 'active'),
(3, 'sponsored_product', '7 Days',  7,  18.00, 'active'),
(4, 'sponsored_product', '30 Days', 30, 48.00, 'active'),
(5, 'featured_shop',     '7 Days',  7,  20.00, 'active'),
(6, 'featured_shop',     '30 Days', 30, 55.00, 'active'),
(7, 'sponsored_shop',    '7 Days',  7,  25.00, 'active'),
(8, 'sponsored_shop',    '30 Days', 30, 65.00, 'active');

CREATE TABLE IF NOT EXISTS mp_seller_subscription_plans (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    description   TEXT,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    product_limit INT NOT NULL DEFAULT -1 COMMENT '-1 = unlimited',
    features      TEXT,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO mp_seller_subscription_plans (id, name, description, duration_days, price, product_limit, status) VALUES
(1, 'Starter', 'Up to 10 active products, basic analytics',            30, 20.00,  10, 'active');


CREATE TABLE IF NOT EXISTS mp_seller_subscriptions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id      INT UNSIGNED NOT NULL,
    plan_id      INT UNSIGNED NOT NULL,
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    price_paid   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status       ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
    payment_id   INT UNSIGNED DEFAULT NULL,
    activated_by INT UNSIGNED DEFAULT NULL,
    activated_at DATETIME DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shop   (shop_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS subscription_plan_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS subscription_end     DATE DEFAULT NULL;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS is_subscribed        TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('mp_boost_requires_payment',   '1'),
('mp_featured_product_enabled', '1'),
('mp_sponsored_product_enabled','1'),
('mp_featured_shop_enabled',    '1'),
('mp_sponsored_shop_enabled',   '1'),
('mp_subscription_enabled',     '0');

-- ==========================================================================
-- FINAL: Extend payment_type ENUM with all values added in v018-v022
-- This single MODIFY is the authoritative definition. It is idempotent —
-- running it again does not change existing data since all values are
-- already present. Do NOT add intermediate MODIFY statements above;
-- always extend THIS list when new payment types are introduced.
-- ==========================================================================
ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job',
    'featured_worker',
    'verification',
    'job_post',
    'worker_service',
    'escrow_payment',
    'escrow_with_posting',
    'news_post',
    'event_post',
    'funeral_post',
    'mp_boost',
    'delivery_subscription',
    'delivery_sponsored',
    'delivery_verification',
    'featured_event',
    'featured_funeral',
    'featured_news',
    'mp_subscription',
    'mp_order'
) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v023  Per-service email verification requirements
-- ═══════════════════════════════════════════════════════════════════════════
-- Lets admin choose (via checkboxes in Admin → Monetize) which actions require
-- a verified email, instead of it being hard-blocked for everyone. Defaults to
-- '1' (required) to preserve prior behaviour on existing installs.
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('require_verified_email_job_post',  '1', 'Require verified email before posting a job'),
    ('require_verified_email_job_apply', '1', 'Require verified email before applying to a job');

-- ═══════════════════════════════════════════════════════════════════════════
-- v024  Email verification requirements — remaining services + login
-- ═══════════════════════════════════════════════════════════════════════════
-- 'login' defaults to '1' (required) — matches the hard block already in
-- login.php prior to this version. All other new services default to '0'
-- (not required) to preserve current behaviour, since they were previously
-- never gated on email verification at all.
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('require_verified_email_login',            '1', 'Require verified email before logging in at all'),
    ('require_verified_email_news_post',        '0', 'Require verified email before submitting a news article'),
    ('require_verified_email_event_post',       '0', 'Require verified email before submitting an event'),
    ('require_verified_email_funeral_post',     '0', 'Require verified email before submitting a funeral announcement'),
    ('require_verified_email_shop_create',      '0', 'Require verified email before creating a marketplace shop'),
    ('require_verified_email_product_post',     '0', 'Require verified email before listing a marketplace product'),
    ('require_verified_email_delivery_request', '0', 'Require verified email before creating a delivery request'),
    ('require_verified_email_delivery_agent',   '0', 'Require verified email before registering as a delivery agent');

-- ═══════════════════════════════════════════════════════════════════════════
-- v025  Fix missing worker_profiles.id_type_custom column
-- ═══════════════════════════════════════════════════════════════════════════
-- register.php, become_worker.php and request_verification.php have always
-- inserted/updated this column, but it was never added to worker_profiles —
-- every worker profile creation/update was failing with an unknown-column
-- DB error. delivery_agents already had its own copy of this column.
ALTER TABLE worker_profiles ADD COLUMN IF NOT EXISTS id_type_custom VARCHAR(100) DEFAULT NULL AFTER id_type;

-- Backfill: any user whose role was set to 'worker' directly (e.g. by an
-- admin, bypassing become_worker.php) but has no worker_profiles row yet —
-- give them a minimal one so worker_profile.php stops saying "no profile".
INSERT INTO worker_profiles (user_id, bio, location, contact_phone, availability, created_at)
SELECT u.id, '', COALESCE(t.name, ''), COALESCE(u.phone, ''), 'available', NOW()
FROM users u
LEFT JOIN towns t ON t.id = u.town_id
WHERE u.role = 'worker'
  AND NOT EXISTS (SELECT 1 FROM worker_profiles wp WHERE wp.user_id = u.id);

-- ═══════════════════════════════════════════════════════════════════════════
-- v026  Admin control: keep fully-staffed / completed jobs publicly listed
-- ═══════════════════════════════════════════════════════════════════════════
-- Defaults to '0' (hidden) to preserve current behaviour — jobs.php and
-- browse_jobs.php have always hard-coded status IN ('open','partially_staffed').
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('jobs_list_staffed_completed', '0', 'Keep fully-staffed and completed jobs visible in public job listings');

-- ═══════════════════════════════════════════════════════════════════════════
-- v027  Fix missing columns/tables causing blank pages on event/funeral/news
--       detail pages and the entire chat feature (confirmed via production
--       error log — PHP fatal PDOExceptions, "Unknown column" / "Table doesn't
--       exist", swallowed as blank pages since display_errors is off in prod).
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE events               ADD COLUMN IF NOT EXISTS view_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS view_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE chat_messages         ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(255) DEFAULT NULL AFTER file_path;

CREATE TABLE IF NOT EXISTS news_views (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id  INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_views_news_id (news_id),
    INDEX idx_news_views_user_id (user_id),
    FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_likes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_news_like (news_id, user_id),
    FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_saves (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_news_save (news_id, user_id),
    FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    comment    TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_comments_news_id (news_id),
    FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v028  Per-role configurable idle-session timeout
-- ═══════════════════════════════════════════════════════════════════════════
-- Minutes of inactivity before a session is force-expired (require_login() in
-- auth.php). Defaults to 120 (2 hours) for every role, matching the existing
-- session.gc_maxlifetime default in config.php — no behaviour change until an
-- admin adjusts these in Admin → Monetize → Session Settings. 0 = disabled
-- (idle timeout not enforced; only PHP's native session lifetime applies).
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('session_timeout_customer', '120', 'Idle session timeout for customers, in minutes (0 = disabled)'),
    ('session_timeout_worker',   '120', 'Idle session timeout for workers, in minutes (0 = disabled)'),
    ('session_timeout_manager',  '120', 'Idle session timeout for managers, in minutes (0 = disabled)'),
    ('session_timeout_admin',    '120', 'Idle session timeout for admins, in minutes (0 = disabled)');

-- ═══════════════════════════════════════════════════════════════════════════
-- v029  "Other" location option for users outside the Akuapem town list
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds a sentinel 'Other' town row so users.town_id can still point to a real
-- towns row (keeps existing FK/JOIN logic working everywhere unchanged), plus
-- a free-text column for what they actually typed.
INSERT IGNORE INTO towns (name, district) VALUES ('Other', 'Other');
ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_town VARCHAR(120) DEFAULT NULL AFTER town_id;

-- ═══════════════════════════════════════════════════════════════════════════
-- v030  Account deletion becomes an admin-reviewed request
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    reason       TEXT NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_notes  VARCHAR(500) DEFAULT NULL,
    reviewed_by  INT UNSIGNED DEFAULT NULL,
    reviewed_at  DATETIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adr_user_id (user_id),
    INDEX idx_adr_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v031  Google Maps pickup location for marketplace shops
-- ═══════════════════════════════════════════════════════════════════════════
-- Helps delivery agents locate the shop for pickups.
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS google_maps_link VARCHAR(512) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v032  Checkout stock-race fix: record items dropped for insufficient stock
-- ═══════════════════════════════════════════════════════════════════════════
-- checkout.php now checks whether its stock UPDATE actually affected a row
-- before adding an item to the order. When it doesn't (two buyers grabbed the
-- last unit at the same time), the item is recorded here instead of silently
-- disappearing, so the seller can see it and the customer isn't charged for it.
CREATE TABLE IF NOT EXISTS mp_order_stock_issues (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id       INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED,
    product_name   VARCHAR(255) NOT NULL,
    requested_qty  INT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mosi_order (order_id),
    FOREIGN KEY (order_id)   REFERENCES mp_orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v033  Seller payout system — Paystack checkout, pending/available balance,
--       withdrawal requests reviewed by admin.
-- ═══════════════════════════════════════════════════════════════════════════
-- Flow: buyer pays via Paystack at checkout → net (after commission) credited
-- to the shop's pending_balance → once the linked delivery is marked
-- 'delivered' and the confirmation period passes (cron sweep), it moves to
-- available_balance → seller requests a withdrawal → admin approves/rejects/
-- marks paid, mirroring the existing mod_rewards moderator-payout pattern.

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mp_commission_percent',      '10', 'Platform commission % taken from each paid marketplace order'),
    ('mp_payout_confirmation_days', '3', 'Days after delivery before a seller''s pending balance becomes withdrawable');

ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS pending_balance   DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS available_balance DECIMAL(10,2) NOT NULL DEFAULT 0;

ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS commission_percent DECIMAL(5,2)  DEFAULT NULL;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS commission_amount  DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS net_amount         DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS platform_payment_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS payout_release_at  DATETIME      DEFAULT NULL;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS payout_released    TINYINT(1)    NOT NULL DEFAULT 0;

-- 'paystack' replaces the old cash_on_delivery/mobile_money/card/wallet choice —
-- Paystack's own hosted checkout already lets the buyer pick card vs mobile money.
ALTER TABLE mp_orders MODIFY COLUMN payment_method ENUM('cash_on_delivery','mobile_money','card','wallet','paystack') NOT NULL DEFAULT 'paystack';

CREATE TABLE IF NOT EXISTS mp_wallet_transactions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id       INT UNSIGNED NOT NULL,
    order_id      INT UNSIGNED DEFAULT NULL,
    payout_id     INT UNSIGNED DEFAULT NULL,
    type          ENUM('sale_pending','released_to_available','withdrawal','reversal') NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mwt_shop (shop_id),
    FOREIGN KEY (shop_id)  REFERENCES mp_shops(id)  ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES mp_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_payout_requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id       INT UNSIGNED NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    momo_number   VARCHAR(30) NOT NULL,
    status        ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
    admin_notes   VARCHAR(500) DEFAULT NULL,
    reviewed_by   INT UNSIGNED DEFAULT NULL,
    reviewed_at   DATETIME DEFAULT NULL,
    paid_at       DATETIME DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mpr_shop (shop_id),
    KEY idx_mpr_status (status),
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v034  Homepage delivery feed visibility toggles.
-- ═══════════════════════════════════════════════════════════════════════════
-- Marketplace order deliveries now auto-approve (v033 follow-up), so they show
-- up in the homepage "Open Delivery Requests" feed alongside personal delivery
-- requests. Lets admin show/hide each source independently on that feed only —
-- delivery agents still see every open job on their own dashboard regardless.

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('homepage_show_marketplace_deliveries', '1', 'Show marketplace order deliveries in the homepage Open Delivery Requests feed'),
    ('homepage_show_personal_deliveries',    '1', 'Show personal delivery requests in the homepage Open Delivery Requests feed'),
    ('homepage_delivery_feed_audience',      'everyone', 'Who can see the homepage Open Delivery Requests feed: everyone or agents_only');

-- ═══════════════════════════════════════════════════════════════════════════
-- v035  Delivery complaints — buyer can dispute a delivery marked 'delivered'.
-- ═══════════════════════════════════════════════════════════════════════════
-- The existing `disputes` table has a hard FK to service_requests (jobs only),
-- so delivery complaints get their own table. Filing one on a marketplace-order
-- delivery pauses that order's payout_release_at until admin resolves it —
-- 'resolved' (complaint upheld) refunds the buyer via mp_refund_order(),
-- 'dismissed' (no fault found) resumes the payout release timer.

CREATE TABLE IF NOT EXISTS delivery_disputes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_request_id INT UNSIGNED NOT NULL,
    reported_by         INT UNSIGNED NOT NULL,
    reported_user_id    INT UNSIGNED NOT NULL,
    dispute_type        ENUM('not_delivered','damaged','wrong_item','late','other') NOT NULL,
    description         TEXT NOT NULL,
    status              ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolution_notes    TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NULL,
    INDEX idx_dd_delivery (delivery_request_id),
    INDEX idx_dd_reported_by (reported_by),
    INDEX idx_dd_status (status),
    FOREIGN KEY (delivery_request_id) REFERENCES delivery_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by)         REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v036  Delivery rider commission — running "owed" ledger.
-- ═══════════════════════════════════════════════════════════════════════════
-- Riders still collect the delivery fee directly (cash/MoMo) — the platform
-- never touches that money. Instead, each completed delivery adds a debt line
-- (fee x commission%) to the agent's running balance. Once that balance
-- crosses delivery_commission_block_threshold, the agent can no longer apply
-- for new jobs until an admin marks their debt settled (paid outside the
-- system) on the Commission tab of admin/delivery.php.

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('delivery_commission_percent',           '10', 'Percent of each delivery fee riders owe the platform'),
    ('delivery_commission_block_threshold',   '50', 'GHS owed above which a rider is blocked from accepting new jobs (0 = never block)');

ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS commission_owed DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS delivery_commission_ledger (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id            INT UNSIGNED NOT NULL,
    delivery_request_id INT UNSIGNED DEFAULT NULL,
    type                ENUM('commission_owed','settlement','reversal') NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dcl_agent (agent_id),
    FOREIGN KEY (agent_id)            REFERENCES delivery_agents(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_request_id) REFERENCES delivery_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v037  Fix: mp_orders.payment_status never got widened for refunds.
-- ═══════════════════════════════════════════════════════════════════════════
-- mp_refund_order() sets payment_status='refunded', but the ENUM was only ever
-- ('unpaid','paid') — on this server's non-strict sql_mode that silently
-- truncated to an empty string instead of erroring, so it went unnoticed.
-- Confirmed by direct query against the dev DB, not just code review.

ALTER TABLE mp_orders MODIFY COLUMN payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid';

-- ═══════════════════════════════════════════════════════════════════════════
-- v038  Automated seller payouts via Paystack Transfers.
-- ═══════════════════════════════════════════════════════════════════════════
-- Sellers save one MoMo + one bank payout account and pick per-request which
-- to pay out to. mp_payout_mode ('manual'|'auto') decides whether an admin
-- must approve each withdrawal or Paystack fires the transfer immediately —
-- both paths call the same process_marketplace_payout() so Paystack always
-- does the actual money movement, only the timing differs. mp_banks is a
-- local cache of Paystack's bank/MoMo-network list so the payout-setup form
-- doesn't hit the live API on every page load.

CREATE TABLE IF NOT EXISTS mp_banks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    type        ENUM('bank','mobile_money') NOT NULL,
    currency    VARCHAR(10) NOT NULL DEFAULT 'GHS',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_code_type (code, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe baseline so the MoMo payout form works even before an admin has
-- clicked "Sync Banks" — full bank list still requires a live sync since
-- hardcoding dozens of bank codes isn't safe without verifying against
-- Paystack's current list.
INSERT IGNORE INTO mp_banks (code, name, type, currency) VALUES
    ('MTN', 'MTN Mobile Money',      'mobile_money', 'GHS'),
    ('VOD', 'Vodafone Cash',         'mobile_money', 'GHS'),
    ('ATL', 'AirtelTigo Money',      'mobile_money', 'GHS');

CREATE TABLE IF NOT EXISTS mp_payout_accounts (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id                 INT UNSIGNED NOT NULL,
    method                  ENUM('momo','bank') NOT NULL,
    account_name            VARCHAR(150) NOT NULL,
    account_number          VARCHAR(30) NOT NULL,
    bank_code               VARCHAR(20) DEFAULT NULL,
    bank_name               VARCHAR(100) DEFAULT NULL,
    paystack_recipient_code VARCHAR(60) DEFAULT NULL,
    is_default              TINYINT(1) NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mpa_shop_method (shop_id, method),
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mp_payout_requests MODIFY COLUMN momo_number VARCHAR(30) NULL;
ALTER TABLE mp_payout_requests MODIFY COLUMN status ENUM('pending','approved','processing','rejected','paid','failed') NOT NULL DEFAULT 'pending';
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS payout_account_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS method ENUM('momo','bank') NOT NULL DEFAULT 'momo';
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS account_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS account_number VARCHAR(30) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS bank_code VARCHAR(20) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS paystack_transfer_code VARCHAR(60) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS paystack_transfer_reference VARCHAR(80) DEFAULT NULL;
ALTER TABLE mp_payout_requests ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(255) DEFAULT NULL;

-- Backfill so pre-existing rows display uniformly under the new columns.
UPDATE mp_payout_requests SET account_number = momo_number WHERE account_number IS NULL AND momo_number IS NOT NULL;

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mp_payout_mode', 'manual', 'manual|auto — whether seller withdrawals need admin approval or Paystack pays instantly');

-- ═══════════════════════════════════════════════════════════════════════════
-- v039  "Stay logged in" — persistent login tokens.
-- ═══════════════════════════════════════════════════════════════════════════
-- Selector/validator pattern: selector is a plain lookup key, validator is
-- only ever stored as a SHA-256 hash — a DB leak alone can't forge a cookie.
-- Rotated on every successful auto-login so a stolen cookie has a shrinking
-- window before the legitimate user's next visit invalidates it.

CREATE TABLE IF NOT EXISTS remember_tokens (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    selector       VARCHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('remember_me_days', '30', 'How many days a "Stay logged in" session lasts before requiring a fresh login');

-- ═══════════════════════════════════════════════════════════════════════════
-- v040  Real module enable/disable enforcement.
-- ═══════════════════════════════════════════════════════════════════════════
-- mp_enabled and delivery_enabled already existed but were never actually
-- checked anywhere — toggling them off did nothing. Adding the missing
-- per-module settings for Jobs/Events/News/Funerals here; enforcement itself
-- lives in code (module_enabled()/require_module_enabled() in
-- functions.php/auth.php), gating each module's entry points and hiding
-- disabled modules from index.php's navigation.

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('jobs_enabled',     '1', 'Whether the Jobs & Services module is active (1=yes, 0=no)'),
    ('events_enabled',   '1', 'Whether the Events module is active (1=yes, 0=no)'),
    ('news_enabled',     '1', 'Whether the News module is active (1=yes, 0=no)'),
    ('funerals_enabled', '1', 'Whether the Funeral Announcements module is active (1=yes, 0=no)');

-- ═══════════════════════════════════════════════════════════════════════════
-- v041  Marketplace Subscription Package Module — full package system.
-- ═══════════════════════════════════════════════════════════════════════════
-- Extends the existing (previously inert) mp_seller_subscription_plans /
-- mp_seller_subscriptions tables into a real, enforced package system: richer
-- package fields (badge, yearly price, feature flags), renewal/cancellation
-- tracking, a shop-facing subscription history log, and a per-milestone
-- reminder dedup table. Reuses platform_payments (payment_type='mp_subscription')
-- as the payment ledger rather than adding a parallel one.

ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS yearly_price DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS max_images INT NOT NULL DEFAULT 5;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS unlimited_images TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS featured_shop_included TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS featured_products_included INT NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS priority_ranking INT NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS ad_credits INT NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS analytics_access TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS support_level VARCHAR(30) NOT NULL DEFAULT 'standard';
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS verification_included TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS badge_name VARCHAR(40) DEFAULT NULL;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS badge_color VARCHAR(20) DEFAULT NULL;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS display_order INT NOT NULL DEFAULT 0;
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE mp_seller_subscriptions ADD COLUMN IF NOT EXISTS renewal_date DATE DEFAULT NULL;
ALTER TABLE mp_seller_subscriptions ADD COLUMN IF NOT EXISTS cancelled_at DATETIME DEFAULT NULL;
ALTER TABLE mp_seller_subscriptions MODIFY COLUMN status
    ENUM('pending','active','expired','cancelled','suspended','pending_renewal') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS mp_subscription_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NOT NULL,
    shop_id INT UNSIGNED NOT NULL,
    event ENUM('purchased','upgraded','downgraded','renewed','cancelled','expired') NOT NULL,
    from_plan_id INT UNSIGNED DEFAULT NULL,
    to_plan_id INT UNSIGNED DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msh_shop (shop_id),
    FOREIGN KEY (subscription_id) REFERENCES mp_seller_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mp_subscription_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NOT NULL,
    milestone ENUM('14d','7d','3d','24h','on_expiry','7d_after') NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sub_milestone (subscription_id, milestone),
    FOREIGN KEY (subscription_id) REFERENCES mp_seller_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mp_subscription_upgrade_proration', '0', 'Prorate upgrade charges (1) vs charge full package price (0)');

-- ═══════════════════════════════════════════════════════════════════════════
-- v042  Complimentary Memberships — platform-wide free access to paid features.
-- ═══════════════════════════════════════════════════════════════════════════
-- Admin can flag a user so every paid gate on the platform (job posting fees,
-- worker service fees, featured job/worker/event/news/funeral, verification,
-- marketplace subscriptions/boosts, delivery premium/sponsored/verification)
-- treats them as already-paid. Escrow commission is deliberately untouched —
-- a per-transaction revenue cut, not a feature-access toggle.

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_complimentary TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS complimentary_granted_at DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS complimentary_granted_by INT UNSIGNED NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v043  Delivery commission: in-app payment + day-based grace period.
-- ═══════════════════════════════════════════════════════════════════════════
-- Riders can now pay off their commission_owed balance through the app via
-- Paystack (pay_delivery_commission.php), instead of only admin manually
-- marking it settled. Admin can also set a grace period in days — a rider
-- may owe commission and keep accepting jobs for up to that many days before
-- being blocked, independent of (and in addition to) the existing amount
-- threshold. commission_owed_since tracks when the CURRENT debt started,
-- since commission_owed itself is just a running total with no per-debit aging.

ALTER TABLE delivery_agents ADD COLUMN IF NOT EXISTS commission_owed_since DATETIME NULL;

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('delivery_commission_grace_days', '0', 'Days a rider may owe commission before being blocked from new jobs (0 = no day-based limit, amount threshold still applies)');

ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job','featured_worker','verification','job_post','worker_service',
    'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
    'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification',
    'featured_event','featured_funeral','featured_news','mp_subscription','mp_order',
    'delivery_commission'
) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v044  Master Product Catalog (Provision Shops, extensible to future
--        catalog types — electrical, bookshop, etc.)
-- ═══════════════════════════════════════════════════════════════════════════
-- Central, admin-curated product catalog so sellers can pick an existing
-- product (e.g. "Milo 400g") instead of typing every field from scratch.
-- Kept deliberately flat: one table, category as plain text (not a separate
-- lookup table — mp_categories is a different, coarser concept and a shop
-- still picks its own mp_categories value normally), one image per product,
-- import summaries logged via the existing audit_logs (log_audit_action())
-- instead of a dedicated history table.

CREATE TABLE IF NOT EXISTS master_products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    catalog_type    VARCHAR(40) NOT NULL DEFAULT 'provision',
    category        VARCHAR(100) DEFAULT NULL,
    name            VARCHAR(255) NOT NULL,
    brand           VARCHAR(120) DEFAULT NULL,
    sku             VARCHAR(100) DEFAULT NULL,
    description     TEXT,
    package_size    VARCHAR(60) DEFAULT NULL,
    search_keywords TEXT,
    default_image   VARCHAR(255) DEFAULT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mp_catalog (catalog_type),
    KEY idx_mp_status (status),
    KEY idx_mp_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brings an earlier, more complex draft of this table (category_id FK,
-- separate alternative_name/manufacturer/weight/volume/unit columns — built
-- and only ever tested locally, never used with real data) in line with the
-- simplified schema above. All safe no-ops if master_products was just
-- created fresh by the CREATE TABLE above.
--
-- The category_id FK constraint must be dropped explicitly before the column
-- itself on real MySQL (MariaDB cascades this automatically when the column
-- is dropped, but MySQL 8 errors with "Cannot drop index ... needed in a
-- foreign key constraint" otherwise). Looked up dynamically via
-- information_schema rather than a hardcoded constraint name, since
-- unnamed foreign keys are auto-named by the server and that name isn't
-- guaranteed to be the same across every database this has been applied to.
SET @fk_name := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_products'
      AND COLUMN_NAME = 'category_id' AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);
SET @drop_fk_sql := IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE master_products DROP FOREIGN KEY `', @fk_name, '`'),
    'DO 0'
);
PREPARE stmt FROM @drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE master_products DROP COLUMN IF EXISTS category_id;
ALTER TABLE master_products DROP COLUMN IF EXISTS alternative_name;
ALTER TABLE master_products DROP COLUMN IF EXISTS manufacturer;
ALTER TABLE master_products DROP COLUMN IF EXISTS weight;
ALTER TABLE master_products DROP COLUMN IF EXISTS volume;
ALTER TABLE master_products DROP COLUMN IF EXISTS unit;
ALTER TABLE master_products DROP COLUMN IF EXISTS created_by;
ALTER TABLE master_products ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT NULL AFTER catalog_type;

-- The earlier draft's now-unneeded sibling tables (categories lookup, image
-- gallery, import history) — folded into master_products/audit_logs above.
DROP TABLE IF EXISTS master_product_images;
DROP TABLE IF EXISTS master_product_imports;
DROP TABLE IF EXISTS master_product_categories;

-- Provenance link: which catalog entry a shop's product came from (nullable —
-- manually-created products never set this)
ALTER TABLE mp_products ADD COLUMN IF NOT EXISTS master_product_id INT UNSIGNED DEFAULT NULL AFTER category_id;

-- ═══════════════════════════════════════════════════════════════════════════
-- v045  Master Product Catalog — admin-manageable catalog types
-- ═══════════════════════════════════════════════════════════════════════════
-- Catalog types (Provision Shop, and later Electrical/Bookshop/...) were a
-- hardcoded PHP array — admins now add/edit these themselves, so they need
-- to live in the database instead. `slug` is what's actually stored in
-- master_products.catalog_type (a plain VARCHAR, not a real FK), so it's
-- immutable once created — editing only ever changes the display `name`.
CREATE TABLE IF NOT EXISTS catalog_types (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug       VARCHAR(40) NOT NULL,
    name       VARCHAR(100) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO catalog_types (slug, name, sort_order) VALUES ('provision', 'Provision Shop', 0);

-- ═══════════════════════════════════════════════════════════════════════════
-- v046  Marketplace Quote Requests — buyer sends a shopping list, seller
--       prices it, buyer pays through the existing Paystack/mp_orders flow.
-- ═══════════════════════════════════════════════════════════════════════════
-- Each item is priced as one lump sum for the whole line (e.g. "2kg rice —
-- GH₵25"), not unit price × numeric quantity, so `quantity_note` stays free
-- text with no parsing required. Once quoted+paid, this converts into a real
-- mp_orders/mp_order_items pair (product_id NULL — that FK is nullable) and
-- everything downstream (delivery, payouts) runs through the unmodified
-- existing pipeline.
CREATE TABLE IF NOT EXISTS mp_quote_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    shop_id             INT UNSIGNED NOT NULL,
    status              ENUM('pending','quoted','declined','cancelled','expired','paid') NOT NULL DEFAULT 'pending',
    buyer_notes         TEXT,
    decline_reason      TEXT,
    total_amount        DECIMAL(10,2) DEFAULT NULL,
    platform_payment_id INT UNSIGNED DEFAULT NULL,
    order_id            INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    quoted_at           DATETIME DEFAULT NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mqr_customer (customer_id),
    KEY idx_mqr_shop (shop_id),
    KEY idx_mqr_status (status),
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_quote_request_items (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_request_id INT UNSIGNED NOT NULL,
    item_name         VARCHAR(255) NOT NULL,
    quantity_note     VARCHAR(100) DEFAULT NULL,
    buyer_note        VARCHAR(255) DEFAULT NULL,
    price             DECIMAL(10,2) DEFAULT NULL,
    is_available      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_mqri_request (quote_request_id),
    FOREIGN KEY (quote_request_id) REFERENCES mp_quote_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v047  Per-Feature User Bans — restrict a user from a specific module
--       (Jobs, Marketplace, News, Events, Funerals, Delivery) without
--       blocking their login or every other feature, mirroring the existing
--       moderator_permissions table shape.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS user_feature_bans (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    feature_key VARCHAR(40) NOT NULL,
    reason      VARCHAR(255) DEFAULT NULL,
    banned_by   INT UNSIGNED NOT NULL,
    banned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ufb_user_feature (user_id, feature_key),
    KEY idx_ufb_user (user_id),
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v048  Marketplace Quote Requests — admin settings defaults
-- ═══════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('mp_quotes_enabled', '1'),
('mp_quote_response_days', '2'),
('mp_quote_eligible_shops', 'all');

-- ═══════════════════════════════════════════════════════════════════════════
-- v049  Marketplace — admin-configurable default product listing sort order.
-- 'default' = featured/sponsored first, then a daily-reshuffled mix of
-- recently-posted and popular items, then everything else (see the
-- $defaultOrderBy build in marketplace.php).
-- ═══════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('mp_default_sort', 'default');

-- ═══════════════════════════════════════════════════════════════════════════
-- v050  Marketplace — indexes for the sort columns marketplace.php's "Newest",
-- "Most Viewed", and "Default" listing orders use. Added dynamically via
-- information_schema (rather than plain ADD INDEX IF NOT EXISTS) since that
-- clause isn't reliably supported the same way on real MySQL 8 as it is on
-- MariaDB — same reasoning as the FK-drop lookup earlier in this file.
-- ═══════════════════════════════════════════════════════════════════════════
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mp_products' AND INDEX_NAME = 'idx_mprod_created'
);
SET @add_idx_sql := IF(@idx_exists = 0,
    'ALTER TABLE mp_products ADD INDEX idx_mprod_created (created_at)',
    'DO 0'
);
PREPARE stmt FROM @add_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mp_products' AND INDEX_NAME = 'idx_mprod_viewcount'
);
SET @add_idx_sql := IF(@idx_exists = 0,
    'ALTER TABLE mp_products ADD INDEX idx_mprod_viewcount (view_count)',
    'DO 0'
);
PREPARE stmt FROM @add_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- v051  Worker Premium Subscription — real paid tier for worker_profiles'
-- existing subscription_status column (previously just a free self-toggle
-- with no functional effect beyond a cosmetic badge). Mirrors the
-- worker_service listing-fee flow: package table, Paystack payment, expiry.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE worker_profiles
    ADD COLUMN IF NOT EXISTS premium_expiry DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS premium_renewal_notice_sent TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS worker_premium_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL,
    description   TEXT NULL DEFAULT NULL,
    duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE INTO worker_premium_packages (name, duration_days, price, status) VALUES
--     ('Monthly Premium', 30,  0.00, 'active'),
--     ('Annual Premium',  365, 0.00, 'active');

ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job','featured_worker','verification','job_post','worker_service',
    'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
    'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification',
    'featured_event','featured_funeral','featured_news','mp_subscription','mp_order',
    'delivery_commission','worker_premium'
) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v052  View-count analytics for jobs & worker profiles — extends the existing
-- view_count pattern already used by events/funeral_announcements/news/
-- mp_shops/mp_products (session-deduped increment, one row-write per PK) to
-- the two remaining listing types.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS view_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE worker_profiles  ADD COLUMN IF NOT EXISTS view_count INT UNSIGNED NOT NULL DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════════════════
-- v053  Granular complimentary-membership grants — lets an admin comp a
-- specific paid feature (or 'full' for everything, including any paid
-- feature added later) instead of always granting every feature at once.
-- users.is_complimentary stays the "has at least one active grant" flag.
-- Backfills a 'full' grant for every pre-existing complimentary member so
-- upgrading doesn't silently strip access they were already granted under
-- the old blanket (single-flag) system.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS complimentary_grants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  feature_key VARCHAR(64) NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by INT UNSIGNED NULL,
  UNIQUE KEY uniq_user_feature (user_id, feature_key),
  KEY idx_cg_user (user_id)
);
INSERT IGNORE INTO complimentary_grants (user_id, feature_key, granted_by)
    SELECT id, 'full', complimentary_granted_by FROM users WHERE is_complimentary = 1;

-- ═══════════════════════════════════════════════════════════════════════════
-- v054  applications.status was missing the intermediate hiring-workflow
-- values (under_review/shortlisted/interview_scheduled/offered) and terminal
-- values (hired/expired/position_filled) that manage_applicants.php's
-- "update_status" action, my_applications.php, and
-- partials/_applicant_card.php already read and write — the ENUM only had
-- the original pending/approved/rejected/withdrawn/completed/accepted/
-- declined set, so setting any of the newer statuses silently failed under
-- non-strict SQL mode (coerced to an empty value instead of erroring),
-- leaving the application stuck showing "pending" even after the employer
-- moved it forward and the applicant was notified.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE applications MODIFY COLUMN status ENUM(
    'pending','approved','rejected','withdrawn','completed','accepted','declined',
    'under_review','shortlisted','interview_scheduled','offered','hired','expired','position_filled'
) NOT NULL DEFAULT 'pending';

-- ═══════════════════════════════════════════════════════════════════════════
-- v055  Sponsors — a public homepage sponsor list. Businesses pick a paid
-- package, submit their details (incl. logo), and go live once an admin
-- approves — same pending_payment -> pending_approval -> active shape as
-- news/events/funeral announcements, so it reuses the same monetization
-- and moderation patterns rather than inventing a new one.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS sponsor_packages (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  duration_days INT NOT NULL DEFAULT 30,
  price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO sponsor_packages (id, name, duration_days, price, status) VALUES
(1, 'Bronze - 30 Days', 30, 100.00, 'active'),
(2, 'Silver - 90 Days', 90, 250.00, 'active'),
(3, 'Gold - 365 Days', 365, 800.00, 'active');

CREATE TABLE IF NOT EXISTS sponsors (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  package_id       INT UNSIGNED NOT NULL,
  name             VARCHAR(150) NOT NULL,
  logo_path        VARCHAR(255) NOT NULL,
  website_url      VARCHAR(512) DEFAULT NULL,
  description      VARCHAR(500) DEFAULT NULL,
  contact_email    VARCHAR(180) DEFAULT NULL,
  contact_phone    VARCHAR(30)  DEFAULT NULL,
  status           ENUM('pending_payment','pending_approval','active','rejected','expired') NOT NULL DEFAULT 'pending_payment',
  rejection_reason VARCHAR(500) DEFAULT NULL,
  start_date       DATE DEFAULT NULL,
  end_date         DATE DEFAULT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL,
  KEY idx_sponsors_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job','featured_worker','verification','job_post','worker_service',
    'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
    'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification',
    'featured_event','featured_funeral','featured_news','mp_subscription','mp_order',
    'delivery_commission','worker_premium','sponsor'
) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v056  Let an admin add a sponsor directly (comp'd/partner sponsors that
-- never go through the paid become_sponsor.php flow) — no owning platform
-- user and no purchased package are required for these, so both columns
-- need to allow NULL. Paid, user-submitted sponsors are unaffected.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE sponsors MODIFY COLUMN user_id INT UNSIGNED NULL;
ALTER TABLE sponsors MODIFY COLUMN package_id INT UNSIGNED NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v057  Track which admin composed a notification, so admin-sent messages
-- (distinct from system-triggered ones like payment/approval alerts) can be
-- listed and managed from the Communication Centre's Notifications tab.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS sent_by_admin_id INT UNSIGNED NULL AFTER user_id;

-- ═══════════════════════════════════════════════════════════════════════════
-- v058  Optional price unit — lets a seller say a product price is "per
-- litre"/"per acre"/"per kg" and a customer say a job budget is "per
-- day"/"per month", displayed alongside the price wherever it's shown.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE mp_products ADD COLUMN IF NOT EXISTS price_unit VARCHAR(40) NULL AFTER price;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS price_unit VARCHAR(40) NULL AFTER budget_amount;

-- ═══════════════════════════════════════════════════════════════════════════
-- v059  Periodic Markets — Ofie Market, Nkurakan, Asenema, Adowso, etc.
-- Extends the Marketplace module rather than duplicating it: a market shop
-- is a normal mp_shops row with market_id set, and a market order is a
-- normal mp_orders row that takes the new 'at_storehouse' fulfillment leg
-- instead of the delivery-agent leg. Sellers list/browse/checkout exactly
-- as before; only the physical handoff differs (storehouse pickup, managed
-- per-market by an assigned manager, instead of a delivery rider).
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS markets (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(120) NOT NULL,
  slug                 VARCHAR(120) NOT NULL UNIQUE,
  description          VARCHAR(500) DEFAULT NULL,
  schedule_note        VARCHAR(200) DEFAULT NULL,
  storehouse_location  VARCHAR(200) DEFAULT NULL,
  storehouse_maps_link VARCHAR(512) DEFAULT NULL,
  status               ENUM('open','closed') NOT NULL DEFAULT 'closed',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_managers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  market_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  granted_by  INT UNSIGNED NOT NULL,
  granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_manager (market_id, user_id),
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS market_id INT UNSIGNED NULL AFTER user_id;
ALTER TABLE mp_orders MODIFY COLUMN status ENUM(
    'pending','confirmed','processing','ready_for_delivery','in_transit',
    'delivered','cancelled','refunded','at_storehouse'
) NOT NULL DEFAULT 'pending';

-- ═══════════════════════════════════════════════════════════════════════════
-- v060  Fix: mp_shops.market_id was being read live for order fulfillment
-- decisions (who can manage an order, whether a seller can self-ship it).
-- A seller changing/clearing their shop's market mid-order would silently
-- orphan in-flight orders or let them skip the storehouse leg. Snapshot the
-- market onto the order at checkout instead, same as mp_order_items already
-- snapshots product_name/price rather than joining live to mp_products.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS market_id INT UNSIGNED NULL AFTER shop_id;

-- ═══════════════════════════════════════════════════════════════════════════
-- v061  Market-seller packages — periodic market sellers may need different
-- pricing/limits than regular Marketplace sellers (in-person storehouse
-- pickup is a lighter-weight, occasional-trader use case). Reuses the
-- existing seller subscription plan system rather than duplicating it —
-- a plan is now scoped to either regular Marketplace shops or market shops.
-- Existing plans default to 'marketplace', so nothing changes for them.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE mp_seller_subscription_plans ADD COLUMN IF NOT EXISTS scope ENUM('marketplace','market') NOT NULL DEFAULT 'marketplace' AFTER name;

-- ═══════════════════════════════════════════════════════════════════════════
-- v062  Multi-shop sellers — a seller may now have at most ONE regular
-- Marketplace shop PLUS at most ONE shop per periodic market (not
-- unlimited shops). mp_shops.user_id used to be flatly UNIQUE, blocking a
-- seller from ever opening a second (market) shop alongside their existing
-- regular one. Swaps that for a generated-column trick since plain
-- UNIQUE(user_id, market_id) wouldn't work — NULL never equals NULL in a
-- unique index, so it wouldn't actually cap regular shops at one.
-- Index changes go through information_schema/dynamic SQL rather than
-- ADD/DROP INDEX IF EXISTS — see the v050 note above on why.
-- ═══════════════════════════════════════════════════════════════════════════
-- Add the replacement index BEFORE dropping the old one — uq_mshop_user
-- also backs the user_id foreign key, and MariaDB refuses to drop an index
-- an FK depends on unless another index can take over that role first.
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS market_uniq INT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(market_id, 0)) STORED AFTER market_id;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mp_shops' AND INDEX_NAME = 'uq_mshop_user_market'
);
SET @add_idx_sql := IF(@idx_exists = 0,
    'ALTER TABLE mp_shops ADD UNIQUE KEY uq_mshop_user_market (user_id, market_uniq)',
    'DO 0'
);
PREPARE stmt FROM @add_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mp_shops' AND INDEX_NAME = 'uq_mshop_user'
);
SET @drop_idx_sql := IF(@idx_exists > 0,
    'ALTER TABLE mp_shops DROP INDEX uq_mshop_user',
    'DO 0'
);
PREPARE stmt FROM @drop_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- v063  Market recurrence schedule + pre-order window. Some markets run
-- weekly (e.g. every Monday & Thursday), others monthly (e.g. the first
-- Saturday of the month) — recurrence_type picks which, and
-- recurrence_weekdays/recurrence_week_of_month hold the pattern. Each
-- market can independently open pre-orders N days ahead of its computed
-- next market day (preorder_days). Markets that never configure a
-- schedule (recurrence_type stays 'manual') keep today's exact behaviour —
-- the admin's manual Open/Closed toggle is the only gate, with no
-- pre-order window — so this is purely additive.
-- `status` keeps its existing ENUM('open','closed') and becomes an
-- emergency override in scheduled markets: 'closed' force-shuts the
-- market regardless of the computed schedule window.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE markets ADD COLUMN IF NOT EXISTS recurrence_type ENUM('manual','weekly','monthly') NOT NULL DEFAULT 'manual' AFTER schedule_note;
ALTER TABLE markets ADD COLUMN IF NOT EXISTS recurrence_weekdays VARCHAR(20) NULL AFTER recurrence_type;
ALTER TABLE markets ADD COLUMN IF NOT EXISTS recurrence_week_of_month TINYINT NULL AFTER recurrence_weekdays;
ALTER TABLE markets ADD COLUMN IF NOT EXISTS preorder_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER recurrence_week_of_month;

-- ═══════════════════════════════════════════════════════════════════════════
-- v064  Market fulfillment charges — storehouse pickup is no longer free by
-- default; each market sets its own pickup_fee, plus an optional per-town
-- home-delivery price list (market_delivery_towns, reusing the existing
-- global `towns` table admin/towns.php already manages). The buyer picks
-- Pickup or Delivery-to-town at payment time in pay_quote.php; the chosen
-- fee is added to the charge and stored on mp_orders.delivery_fee — that
-- column already existed but was never actually read or written anywhere
-- in the app, so this is its first real use rather than a new column.
-- Fulfillment stays agent-handled (no rider/Delivery-Services integration):
-- the same assigned market agent who already staffs
-- admin/market_deliveries.php just gets a "Delivered" step alongside
-- "Handed to Buyer" depending on which the buyer chose.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE markets ADD COLUMN IF NOT EXISTS pickup_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER preorder_days;

CREATE TABLE IF NOT EXISTS market_delivery_towns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_id     INT UNSIGNED NOT NULL,
    town_id       INT UNSIGNED NOT NULL,
    delivery_fee  DECIMAL(10,2) NOT NULL DEFAULT 0,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_market_town (market_id, town_id),
    FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE,
    FOREIGN KEY (town_id)   REFERENCES towns(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS fulfillment_method ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup' AFTER market_id;
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS delivery_town_id INT UNSIGNED NULL AFTER fulfillment_method;

-- ═══════════════════════════════════════════════════════════════════════════
-- v065  Backfill: markets created before the periodic-markets custom-order
-- pivot (the hidden "system shop" per market — see admin/markets.php's
-- save_market handler) never got their companion mp_shops row, since that
-- auto-creation only runs on INSERT going forward. Any such market silently
-- broke "Send Custom Order" — request_market_order.php couldn't find a
-- shop_id to attach the quote request to and bounced the buyer back to
-- markets.php with no explanation. Idempotent: only inserts for markets
-- that still have no companion shop.
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO mp_shops (user_id, shop_name, slug, market_id, status)
SELECT (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1),
       CONCAT(m.name, ' — Custom Orders'),
       CONCAT('market-', m.id, '-custom-orders'),
       m.id,
       'active'
FROM markets m
WHERE NOT EXISTS (SELECT 1 FROM mp_shops s WHERE s.market_id = m.id)
  AND EXISTS (SELECT 1 FROM users WHERE role = 'admin');

-- ═══════════════════════════════════════════════════════════════════════════
-- v066  Order cutoff time — a scheduled market's payment window used to stay
-- open all day on market day (until midnight). Admin can now set a precise
-- time-of-day that orders close instead; NULL (the default) keeps today's
-- exact behaviour — open through end of day. get_market_schedule() combines
-- it with market day's date to compute order_close_at.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE markets ADD COLUMN IF NOT EXISTS order_close_time TIME NULL AFTER preorder_days;

-- ═══════════════════════════════════════════════════════════════════════════
-- v067  Per-market brand colour — markets.php cards and market_view.php now
-- each render with their own gradient instead of one fixed green, derived
-- from a single admin-picked base hex via mkt_color_shades() (functions.php).
-- NULL/blank falls back to the app's default green.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE markets ADD COLUMN IF NOT EXISTS color VARCHAR(7) NULL AFTER pickup_fee;

-- ═══════════════════════════════════════════════════════════════════════════
-- v068  Buyer-proposed item pricing + a platform-wide "system charge" on
-- market custom orders. Buyers now suggest a price per item when they send
-- their shopping list (mp_quote_request_items.price is populated at
-- submission instead of staying NULL) — the agent then confirms it as-is or
-- edits it in admin/market_orders.php, same "price" action as before.
-- System charge is one global rate (platform_settings
-- market_system_charge_type 'flat'|'percent' + market_system_charge_value,
-- read via get_market_system_charge() in functions.php) added on top of the
-- item total and fulfillment fee at payment time — distinct from
-- pickup_fee/delivery fees, which stay per-market.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS system_charge DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_fee;

-- ═══════════════════════════════════════════════════════════════════════════
-- v069  Quick Services — a lightweight, reusable "digital service desk"
-- module, separate from the Jobs & Services (worker-hiring) module, which
-- already owns the `service_requests`/`service_categories` table names —
-- hence the `quick_service*` prefix here to avoid any collision. A user
-- picks a service (Airtime, ECG, BECE results, etc.), fills a short form
-- whose fields are fully admin-configurable (quick_services.form_fields,
-- JSON — no developer involvement needed to add a new service), pays, and
-- a delegated manager (quick_service_managers, same per-record assignment
-- pattern as market_managers/user_can_manage_market()) manually processes
-- it and replies with a result. Pricing separates the underlying service
-- cost (fixed, e.g. BECE = GHS 20, or user-entered, e.g. an ECG top-up
-- amount) from AkuapemConnect's own service fee (flat or %), so both are
-- always shown to the buyer as distinct line items. Seeded services start
-- 'inactive' — an admin must review the fee and assign a manager before
-- switching each one on.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS quick_services (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(120) NOT NULL,
    slug              VARCHAR(120) NOT NULL UNIQUE,
    icon              VARCHAR(20) DEFAULT NULL,
    description       VARCHAR(255) DEFAULT NULL,
    instructions      TEXT,
    form_fields       JSON NOT NULL,
    pricing_mode      ENUM('fixed','user_entered') NOT NULL DEFAULT 'fixed',
    base_cost         DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_field_key  VARCHAR(60) NULL,
    service_fee_type  ENUM('flat','percent') NOT NULL DEFAULT 'flat',
    service_fee_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    status            ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    display_order     INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quick_service_managers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    granted_by  INT UNSIGNED NOT NULL,
    granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qsm (service_id, user_id),
    FOREIGN KEY (service_id) REFERENCES quick_services(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job','featured_worker','verification','job_post','worker_service',
    'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
    'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification',
    'featured_event','featured_funeral','featured_news','mp_subscription','mp_order',
    'delivery_commission','worker_premium','sponsor','quick_service'
) NOT NULL;

CREATE TABLE IF NOT EXISTS quick_service_requests (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    service_id           INT UNSIGNED NOT NULL,
    request_data         JSON NOT NULL,
    service_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
    service_fee          DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status       ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    platform_payment_id  INT UNSIGNED NULL,
    status               ENUM('pending_payment','paid','processing','completed','unable_to_process','cancelled') NOT NULL DEFAULT 'pending_payment',
    manager_response     TEXT NULL,
    response_file_path   VARCHAR(255) NULL,
    processed_by         INT UNSIGNED NULL,
    processed_at         DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES quick_services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO quick_services (name, slug, icon, description, instructions, form_fields, pricing_mode, base_cost, amount_field_key, service_fee_type, service_fee_value, status, display_order) VALUES
('Airtime & Data', 'airtime-data', '📱', 'Top up airtime or data on any network', 'Tell us the network, the phone number to top up, and the amount — we will process it and confirm once done.',
 '[{"key":"network","label":"Network","type":"select","required":true,"options":["MTN","Vodafone","AirtelTigo","Telecel"]},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"amount","label":"Amount (GHS)","type":"number","required":true,"placeholder":"e.g. 20"}]',
 'user_entered', 0, 'amount', 'flat', 2.00, 'inactive', 1),
('ECG Prepaid', 'ecg-prepaid', '⚡', 'Buy ECG prepaid electricity units', 'Enter your meter number, the phone number for the token, and the amount to top up.',
 '[{"key":"meter_number","label":"Meter Number","type":"text","required":true,"placeholder":"e.g. 0300XXXXXXXX"},{"key":"amount","label":"Amount (GHS)","type":"number","required":true,"placeholder":"e.g. 50"},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"}]',
 'user_entered', 0, 'amount', 'flat', 2.00, 'inactive', 2),
('BECE Results Checker', 'bece-results', '📄', 'Check BECE results with a checker PIN', 'Provide your candidate number and exam year — we will get your results checker PIN sorted.',
 '[{"key":"candidate_number","label":"Candidate Number","type":"text","required":true},{"key":"exam_year","label":"Exam Year","type":"select","required":true,"options":["2023","2024","2025","2026"]},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"}]',
 'fixed', 20.00, NULL, 'flat', 5.00, 'inactive', 3),
('WASSCE Results Checker', 'wassce-results', '📄', 'Check WASSCE results with a checker PIN', 'Provide your candidate/index number and exam year — we will get your results checker PIN sorted.',
 '[{"key":"candidate_number","label":"Candidate/Index Number","type":"text","required":true},{"key":"exam_year","label":"Exam Year","type":"select","required":true,"options":["2023","2024","2025","2026"]},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"}]',
 'fixed', 20.00, NULL, 'flat', 5.00, 'inactive', 4),
('TV Subscription', 'tv-subscription', '📺', 'Renew DStv, GOtv or StarTimes subscriptions', 'Tell us your provider, smartcard/IUC number, and the amount to load.',
 '[{"key":"provider","label":"Provider","type":"select","required":true,"options":["DStv","GOtv","StarTimes"]},{"key":"smartcard_number","label":"Smartcard/IUC Number","type":"text","required":true},{"key":"amount","label":"Amount (GHS)","type":"number","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"}]',
 'user_entered', 0, 'amount', 'flat', 2.00, 'inactive', 5),
('Passport Assistance', 'passport-assistance', '🛂', 'Help with Ghana passport applications', 'Share your details and our team will guide you through the passport application process.',
 '[{"key":"full_name","label":"Full Name","type":"text","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"ghana_card_number","label":"Ghana Card Number","type":"text","required":true},{"key":"notes","label":"Additional Notes","type":"textarea","required":false}]',
 'fixed', 0, NULL, 'flat', 10.00, 'inactive', 6),
('Ghana Card Assistance', 'ghana-card-assistance', '🪪', 'Help with Ghana Card registration or update', 'Share your details and our team will guide you through the process.',
 '[{"key":"full_name","label":"Full Name","type":"text","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"notes","label":"Additional Notes","type":"textarea","required":false}]',
 'fixed', 0, NULL, 'flat', 10.00, 'inactive', 7),
('Printing & Documents', 'printing-documents', '🖨️', 'Printing, scanning & document assistance', 'Tell us what you need printed or prepared and how many copies.',
 '[{"key":"document_type","label":"Document Type","type":"text","required":true},{"key":"copies","label":"Number of Copies","type":"number","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"notes","label":"Additional Notes","type":"textarea","required":false}]',
 'fixed', 0, NULL, 'flat', 5.00, 'inactive', 8),
('School Application Assistance', 'school-application', '🎓', 'Help applying to schools', 'Share the student and school details and our team will assist with the application.',
 '[{"key":"student_name","label":"Student Name","type":"text","required":true},{"key":"school_level","label":"School/Level","type":"text","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"notes","label":"Additional Notes","type":"textarea","required":false}]',
 'fixed', 0, NULL, 'flat', 10.00, 'inactive', 9),
('Business Registration Assistance', 'business-registration', '🏢', 'Help registering a business with the RGD', 'Share your business details and our team will guide you through registration.',
 '[{"key":"business_name","label":"Business Name","type":"text","required":true},{"key":"business_type","label":"Business Type","type":"text","required":true},{"key":"phone_number","label":"Phone Number","type":"tel","required":true,"placeholder":"024XXXXXXX"},{"key":"notes","label":"Additional Notes","type":"textarea","required":false}]',
 'fixed', 0, NULL, 'flat', 15.00, 'inactive', 10);

-- ═══════════════════════════════════════════════════════════════════════════
-- v070  Quick Services — optional admin-uploaded custom image per service,
-- shown instead of the emoji icon on service cards when set (falls back to
-- the emoji icon everywhere it's still NULL).
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE quick_services ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER icon;

-- ═══════════════════════════════════════════════════════════════════════════
-- v071  Sponsor Packages — optional rich-text "Benefits" field, edited via
-- the shared rich-editor.js component (admin/monetization.php, Community
-- Packages tab), shown on become_sponsor.php under each package. Content
-- is admin-authored HTML, rendered back out through render_rich().
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE sponsor_packages ADD COLUMN IF NOT EXISTS benefits TEXT NULL AFTER status;

-- ═══════════════════════════════════════════════════════════════════════════
-- v072  Promotions / Special Offers — lightweight, admin-defined time-limited
-- free-access (or discount) campaigns, redeemable by claim or promo code.
-- Reuses the existing complimentary-access gate (user_has_complimentary_access()
-- in functions.php) as the free-access enforcement point, and initializePayment()
-- (paystack.php) as the discount enforcement point — no new package system,
-- no cron. Expiry is computed on read (expiry_date comparisons); status
-- columns are only lazily refreshed for display, never trusted for gating.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS promotions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(160) NOT NULL,
    description      VARCHAR(500) NULL,
    type             ENUM('free_package','free_listing','free_featured_listing','discount','free_service') NOT NULL,
    feature_key      VARCHAR(64) NOT NULL,
    duration_days    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    discount_percent TINYINT UNSIGNED NULL,
    promo_code       VARCHAR(32) NULL,
    starts_at        DATE NOT NULL,
    ends_at          DATE NULL,
    max_claims       INT UNSIGNED NULL,
    claims_count     INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('draft','active','expired','disabled') NOT NULL DEFAULT 'draft',
    notify_message   VARCHAR(500) NULL,
    notified_at      DATETIME NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_promo_code (promo_code),
    INDEX idx_promotions_status_dates (status, starts_at, ends_at),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_claims (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id      INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    feature_key       VARCHAR(64) NOT NULL,
    payment_type      VARCHAR(32) NULL,
    discount_percent  TINYINT UNSIGNED NULL,
    status            ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    claimed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiry_date       DATE NOT NULL,
    UNIQUE KEY uq_user_promotion (user_id, promotion_id),
    INDEX idx_claims_gate (user_id, feature_key, status, expiry_date),
    INDEX idx_claims_discount (user_id, payment_type, status, expiry_date),
    INDEX idx_claims_promotion (promotion_id),
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v073  Accommodation module — Rooms/Houses + Hotels/Guest Houses, one listing
-- engine distinguished by accommodation_types.category. Verification/approval
-- mirrors mp_shops.verification_status + mp_products.status exactly. Enquiries
-- reuse the existing chat system (conversations widened with a new type +
-- accommodation_listing_id, no separate messaging built) — see
-- accommodation_enquiry.php / chat_functions.php.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS accommodation_types (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category   ENUM('room_house','hotel') NOT NULL,
    name       VARCHAR(80) NOT NULL,
    slug       VARCHAR(80) NOT NULL UNIQUE,
    icon       VARCHAR(10) NULL,
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_types_category_status (category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accommodation_types (category, name, slug, icon, sort_order) VALUES
('room_house', 'Single Room',            'single-room',            '🚪', 1),
('room_house', 'Chamber & Hall',         'chamber-and-hall',       '🚪', 2),
('room_house', 'Self-Contained',         'self-contained',         '🏠', 3),
('room_house', 'Apartment',              'apartment',              '🏢', 4),
('room_house', 'Shared Room',            'shared-room',            '🛏️', 5),
('room_house', 'Hostel',                 'hostel-room',            '🏨', 6),
('room_house', 'Student Accommodation',  'student-accommodation',  '🎓', 7),
('room_house', 'House',                  'house',                  '🏡', 8),
('room_house', "Boys' Quarters",         'boys-quarters',          '🏠', 9),
('hotel',      'Hotel',                  'hotel',                  '🏨', 1),
('hotel',      'Guest House',            'guest-house',            '🏘️', 2),
('hotel',      'Lodge',                  'lodge',                  '🏕️', 3),
('hotel',      'Short-Stay Apartment',   'short-stay-apartment',   '🏢', 4),
('hotel',      'Bed & Breakfast',        'bed-and-breakfast',      '🥐', 5),
('hotel',      'Hostel',                 'hostel',                 '🏨', 6);

CREATE TABLE IF NOT EXISTS accommodation_facilities (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(60) NOT NULL,
    slug       VARCHAR(60) NOT NULL UNIQUE,
    icon       VARCHAR(10) NULL,
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accommodation_facilities (name, slug, icon, sort_order) VALUES
('Wi-Fi',            'wifi',            '📶', 1),
('Parking',          'parking',         '🅿️', 2),
('Water',            'water',           '🚰', 3),
('Electricity',      'electricity',     '💡', 4),
('Air Conditioning', 'air-conditioning','❄️', 5),
('TV',               'tv',              '📺', 6),
('Kitchen',          'kitchen',         '🍳', 7),
('Swimming Pool',    'swimming-pool',   '🏊', 8),
('Security',         'security',        '🔒', 9),
('Restaurant',       'restaurant',      '🍽️', 10),
('Laundry',          'laundry',         '🧺', 11);

CREATE TABLE IF NOT EXISTS accommodation_listings (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    accommodation_type_id INT UNSIGNED NOT NULL,
    title                VARCHAR(160) NOT NULL,
    slug                 VARCHAR(180) NOT NULL UNIQUE,
    description          TEXT NULL,
    town_id              INT UNSIGNED NULL,
    area                 VARCHAR(120) NULL,
    price                DECIMAL(10,2) NULL,
    price_period         ENUM('night','week','month','year','negotiable','on_request') NOT NULL DEFAULT 'month',
    facilities           TEXT NULL COMMENT 'JSON array of accommodation_facilities.id',
    bedrooms             TINYINT UNSIGNED NULL,
    bathrooms            TINYINT UNSIGNED NULL,
    furnished_status     ENUM('furnished','unfurnished','partly_furnished') NULL,
    guests_capacity      SMALLINT UNSIGNED NULL,
    checkin_info         VARCHAR(200) NULL,
    checkout_info        VARCHAR(200) NULL,
    availability_status  ENUM('available','unavailable','rented','temporarily_unavailable','fully_booked') NOT NULL DEFAULT 'available',
    verification_status  ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    status               ENUM('draft','pending_approval','approved','rejected','archived') NOT NULL DEFAULT 'draft',
    rejection_reason     TEXT NULL,
    view_count           INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_acc_listings_status_type (status, accommodation_type_id),
    INDEX idx_acc_listings_town (town_id),
    INDEX idx_acc_listings_availability (availability_status),
    INDEX idx_acc_listings_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (accommodation_type_id) REFERENCES accommodation_types(id),
    FOREIGN KEY (town_id) REFERENCES towns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accommodation_images (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id  INT UNSIGNED NOT NULL,
    image_path  VARCHAR(255) NOT NULL,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_images_listing (listing_id),
    FOREIGN KEY (listing_id) REFERENCES accommodation_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accommodation_reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id  INT UNSIGNED NOT NULL,
    reporter_id INT UNSIGNED NOT NULL,
    reason      ENUM('fake','wrong_info','already_rented','scam','inappropriate','other') NOT NULL,
    details     TEXT NULL,
    status      ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_reports_listing (listing_id),
    INDEX idx_acc_reports_status (status),
    FOREIGN KEY (listing_id) REFERENCES accommodation_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Widen the existing chat system for accommodation enquiries instead of
-- building new messaging — see chat_functions.php get_or_create_conversation().
ALTER TABLE conversations MODIFY COLUMN conversation_type
    ENUM('job_application','job_hired','worker_direct','direct','admin_granted','accommodation_enquiry')
    NOT NULL DEFAULT 'direct';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS accommodation_listing_id INT UNSIGNED NULL;

-- ADD INDEX IF NOT EXISTS isn't reliably portable on real MySQL 8 (see the
-- v050 note above) — same information_schema-guarded pattern here.
SET @acc_conv_idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = 'idx_conv_accommodation_listing_id'
);
SET @acc_conv_add_idx_sql := IF(@acc_conv_idx_exists = 0,
    'ALTER TABLE conversations ADD INDEX idx_conv_accommodation_listing_id (accommodation_listing_id)',
    'DO 0'
);
PREPARE acc_conv_idx_stmt FROM @acc_conv_add_idx_sql;
EXECUTE acc_conv_idx_stmt;
DEALLOCATE PREPARE acc_conv_idx_stmt;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('accommodation_enabled', '1');

-- ═══════════════════════════════════════════════════════════════════════════
-- v074  Accommodation — Featured Listing packages, admin-managed exactly like
-- Featured Event/Funeral/News packages (same table shape, same generic
-- save_package/delete_package handler in admin/monetization.php's
-- "Community Pkgs" tab — see $communityPackageSections there).
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE accommodation_listings ADD COLUMN IF NOT EXISTS featured          TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE accommodation_listings ADD COLUMN IF NOT EXISTS featured_end_date DATE DEFAULT NULL;

CREATE TABLE IF NOT EXISTS featured_accommodation_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO featured_accommodation_packages (id, name, duration_days, price, status) VALUES
(1, '7 Days',  7,  15.00, 'active'),
(2, '14 Days', 14, 25.00, 'active'),
(3, '30 Days', 30, 40.00, 'active');

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('enable_paid_featured_accommodation', '0');

-- ═══════════════════════════════════════════════════════════════════════════
-- v075  Accommodation — Listing Packages: a subscription-style package that
-- gates the ability to publish a listing at all (separate from "Featured",
-- which only boosts visibility of an already-listable owner). Mirrors
-- mp_seller_subscription_plans / mp_seller_subscriptions, scoped down to the
-- fields accommodation actually needs (no image limits/badges/etc. — those
-- are marketplace-shop-specific perks that don't apply here). Subscriptions
-- are keyed by user_id directly since accommodation listings belong to a
-- user, not a shop entity.
-- ═══════════════════════════════════════════════════════════════════════════
-- Whether a listing package is required is governed by the platform's
-- existing Free/Hybrid/Paid monetization_mode + is_feature_paid(), the same
-- system every other paid feature uses — not a standalone toggle. See
-- 'enable_paid_accommodation_listing' below and admin/monetization.php's
-- Settings tab.
CREATE TABLE IF NOT EXISTS accommodation_listing_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    description   TEXT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    listing_limit INT NOT NULL DEFAULT -1,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accommodation_listing_subscriptions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    package_id   INT UNSIGNED NOT NULL,
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    price_paid   DECIMAL(10,2) NOT NULL DEFAULT 0,
    status       ENUM('pending','active','cancelled') NOT NULL DEFAULT 'pending',
    payment_id   INT UNSIGNED NULL,
    activated_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acc_sub_user_status (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES accommodation_listing_packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('enable_paid_accommodation_listing', '0');

-- ═══════════════════════════════════════════════════════════════════════════
-- v076  Sign in with Google — links a users row to a Google account. NULLs
-- don't collide under a MySQL UNIQUE index, so this is safe for every
-- existing local-password account (google_id stays NULL for all of them).
-- auth_provider isn't load-bearing for login logic (a random password_hash
-- already makes local password-login impossible for a Google-only account)
-- but is cheap to have for UI/reporting later.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_provider ENUM('local','google') NOT NULL DEFAULT 'local';

-- ADD INDEX/UNIQUE IF NOT EXISTS isn't reliably portable on real MySQL 8 (see
-- the v050 note earlier in this file) — same information_schema-guarded
-- PREPARE/EXECUTE pattern here.
SET @google_id_idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_id'
);
SET @google_id_add_idx_sql := IF(@google_id_idx_exists = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_google_id (google_id)',
    'DO 0'
);
PREPARE google_id_idx_stmt FROM @google_id_add_idx_sql;
EXECUTE google_id_idx_stmt;
DEALLOCATE PREPARE google_id_idx_stmt;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('google_client_id', ''),
('google_client_secret', '');

-- ═══════════════════════════════════════════════════════════════════════════
-- v077: Marketplace customer-side checkout charge
-- Mirrors the Markets module's market_system_charge_type/value pattern
-- (see get_market_system_charge() in functions.php) — a flat-or-percent
-- charge shown to the buyer at checkout.php, on top of item totals, kept
-- separate from the existing seller-side mp_commission_percent. Defaults to
-- 0 (free) so nothing changes for existing installs until an admin opts in.
-- No new mp_orders column needed — it already has a system_charge column
-- (added for the Nearby Markets custom-quote flow) which this now reuses.
-- ═══════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
('mp_customer_charge_type', 'flat'),
('mp_customer_charge_value', '0');

-- ═══════════════════════════════════════════════════════════════════════════
-- v078: Fast Payout — opt-in Paystack subaccounts for marketplace sellers
-- ═══════════════════════════════════════════════════════════════════════════
-- Phase 1: the existing wallet/ledger payout system (pending_balance /
-- available_balance + seller-initiated Transfer withdrawals) stays the
-- default for every shop, unchanged. A seller can opt in to "Fast Payout" on
-- seller_payout_accounts.php, which creates a Paystack subaccount for their
-- shop. For single-shop checkouts only (a cart spanning multiple shops still
-- uses the standard flow — a Paystack split targets one subaccount per
-- transaction), the seller's net cut is routed directly into that subaccount
-- at checkout time via a transaction split, instead of the platform's own
-- balance.
--
-- The subaccount is created — and kept — on Paystack settlement_schedule =
-- 'manual', so Paystack itself never auto-pays it out on its own clock. Our
-- existing confirmation-window sweep (sweep_marketplace_payout_releases() in
-- functions.php) still governs every order exactly as before, including
-- dispute pausing. A second sweep, sweep_fast_payout_settlements(), flips a
-- shop's subaccount schedule to 'auto' (letting Paystack settle it on its own
-- next run) only once that shop has ZERO orders still awaiting release — so
-- money can never move before its confirmation window closes, though an
-- already-matured order can wait a little longer if a newer, still-
-- unconfirmed order from the same seller is blocking the flip. Any new order
-- for that shop re-locks the schedule to 'manual' before it's charged
-- (mp_ensure_fast_payout_locked() in marketplace_functions.php, called from
-- checkout.php) so a fresh order's money can never ride out on an
-- already-AUTO schedule.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS fast_payout_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS paystack_subaccount_code VARCHAR(60) DEFAULT NULL;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS subaccount_settlement_schedule ENUM('manual','auto') NOT NULL DEFAULT 'manual';

ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS fast_payout TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE mp_wallet_transactions MODIFY COLUMN type ENUM('sale_pending','released_to_available','withdrawal','reversal','auto_settled') NOT NULL;

CREATE TABLE IF NOT EXISTS mp_fast_payout_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id     INT UNSIGNED NOT NULL,
    event       ENUM('enabled','disabled','schedule_manual','schedule_auto','bank_synced') NOT NULL,
    detail      VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mfpl_shop (shop_id),
    FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 'bank_synced' logs whenever an already-enabled shop's subaccount is
-- re-pointed at a changed default payout account (see
-- mp_sync_fast_payout_bank_account() in marketplace_functions.php) —
-- defensive MODIFY in case this table already exists from an earlier run
-- of this same v078 block, before this event value was added.
ALTER TABLE mp_fast_payout_log MODIFY COLUMN event ENUM('enabled','disabled','schedule_manual','schedule_auto','bank_synced') NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v079: Fast Payout admin controls — eligibility allowlist + optional
-- admin-approval gate before a seller's opt-in actually activates.
-- ═══════════════════════════════════════════════════════════════════════════
-- Two new admin levers, both cautious-by-default so nothing changes for
-- existing installs until an admin acts:
--   - mp_fast_payout_module_enabled ('0') — master switch. While off, the
--     Fast Payout section is hidden from every seller's dashboard entirely,
--     regardless of any shop's eligibility, and checkout.php won't route a
--     split even for an already-active shop (its held orders still wind
--     down normally either way — this only gates NEW routing).
--   - mp_fast_payout_requires_approval ('1') — when on, a seller clicking
--     "Enable" only files a request (fast_payout_requested_at set); the
--     subaccount isn't created until an admin approves it from
--     admin/mp_payouts.php. When off, Enable activates immediately as
--     introduced in v078.
-- Per-shop, mp_shops.fast_payout_eligible is the actual "who can see this"
-- allowlist — an admin must explicitly grant it before a shop's seller even
-- sees the section, independent of the approval-gate setting above.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('mp_fast_payout_module_enabled', '0', 'Master switch — show the Fast Payout opt-in to any seller at all'),
    ('mp_fast_payout_requires_approval', '1', 'If on, a seller enabling Fast Payout only requests it — an admin must approve before it activates');

ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS fast_payout_eligible TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS fast_payout_requested_at DATETIME DEFAULT NULL;
ALTER TABLE mp_shops ADD COLUMN IF NOT EXISTS fast_payout_rejected_reason VARCHAR(255) DEFAULT NULL;

ALTER TABLE mp_fast_payout_log MODIFY COLUMN event ENUM('enabled','disabled','schedule_manual','schedule_auto','bank_synced','eligibility_granted','eligibility_revoked','requested','approved','rejected') NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v080: Advertisements — placement targeting, weighted/impression-balanced
-- rotation, video ads.
-- ═══════════════════════════════════════════════════════════════════════════
-- Every page that shows ads used to run its own ad-hoc `ORDER BY RAND()`
-- query with no placement awareness (any banner ad could show on any of the
-- handful of pages that happened to be wired up) and no rotation fairness
-- (pure luck each page load). get_ads_for_placement() in functions.php is
-- now the one shared entry point every page uses instead — see its doc
-- comment for the selection algorithm.
--
--   - placements: comma-separated placement keys (e.g. "homepage,jobs") this
--     ad is eligible for. NULL/empty means "eligible everywhere" — the
--     default, so every ad created before this migration keeps showing
--     exactly where it always did.
--   - weight: admin-set priority (higher = shown more often), combined with
--     impression_count so lower-weight/newer ads still get fair rotation
--     instead of being drowned out.
--   - impression_count: incremented each time an ad is actually selected to
--     be shown, alongside the existing click_count, so admins can see CTR.
--   - video: path to an uploaded MP4/WebM file for ad_type='video' — shown
--     as a muted, autoplay, looping <video> in place of the image.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE advertisements MODIFY COLUMN ad_type ENUM('banner','sponsored','video') NOT NULL DEFAULT 'banner';
ALTER TABLE advertisements ADD COLUMN IF NOT EXISTS video VARCHAR(255) DEFAULT NULL;
ALTER TABLE advertisements ADD COLUMN IF NOT EXISTS placements VARCHAR(255) DEFAULT NULL;
ALTER TABLE advertisements ADD COLUMN IF NOT EXISTS weight TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE advertisements ADD COLUMN IF NOT EXISTS impression_count INT UNSIGNED NOT NULL DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════════════════
-- v081: Marketplace — buyer chooses delivery method at checkout.
-- ═══════════════════════════════════════════════════════════════════════════
-- Previously mp_create_delivery_for_order() (marketplace_functions.php) ran
-- unconditionally the moment a seller marked an order ready_for_delivery —
-- there was no way for a buyer who'd rather arrange their own pickup (or
-- meet the seller directly) to opt out of a delivery_requests row being
-- created and posted to every delivery agent. delivery_mode, chosen once per
-- checkout in checkout.php and stored per order, now gates that: 'platform'
-- (default — unchanged behavior) still auto-creates the delivery request;
-- 'self_arranged' skips it entirely, and seller_dashboard.php shows the
-- buyer's choice instead of the (inapplicable) "Retry Delivery Request"
-- failure state.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE mp_orders ADD COLUMN IF NOT EXISTS delivery_mode ENUM('platform','self_arranged') NOT NULL DEFAULT 'platform';

-- ═══════════════════════════════════════════════════════════════════════════
-- v082: Accommodation — admin-configurable max photos per accommodation type.
-- ═══════════════════════════════════════════════════════════════════════════
-- accommodation_form.php used to hard-code a max of 10 photos for every
-- listing regardless of type. max_images on accommodation_types lets an
-- admin set that limit per type (e.g. fewer for a Single Room, more for a
-- Hotel) from admin/accommodation.php's Types tab.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE accommodation_types ADD COLUMN IF NOT EXISTS max_images TINYINT UNSIGNED NOT NULL DEFAULT 10;

-- ═══════════════════════════════════════════════════════════════════════════
-- v083: Accommodation — per-listing contact phone + optional WhatsApp.
-- ═══════════════════════════════════════════════════════════════════════════
-- accommodation_detail.php used to show the listing owner's account phone
-- (users.phone) for the Call button. These two columns let the lister give
-- out a different phone number for this listing specifically (front desk,
-- caretaker, etc.) plus an optional WhatsApp number, shown instead of the
-- account phone.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE accommodation_listings ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(30) NULL;
ALTER TABLE accommodation_listings ADD COLUMN IF NOT EXISTS contact_whatsapp VARCHAR(30) NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v084: Accommodation — optional room/class label (e.g. "Standard Room",
-- "Deluxe Room", "Executive Suite"), shown on listing cards below the title.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE accommodation_listings ADD COLUMN IF NOT EXISTS room_class VARCHAR(60) NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v083b: CRITICAL FIX — platform_payments.payment_type is a strict ENUM that
-- was never extended when the accommodation module's two payment types were
-- introduced (pay_accommodation_subscription.php / feature_accommodation.php
-- both call initializePayment() with these). In this DB's non-strict SQL
-- mode, inserting a value outside the ENUM silently stores '' instead of
-- erroring — so those two flows were charging users via Paystack without
-- ever recording a matching payment_type, meaning
-- activatePurchasedFeature()'s switch (paystack.php) could never match and
-- activate the subscription/feature the user just paid for.
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM(
    'featured_job','featured_worker','verification','job_post','worker_service',
    'escrow_payment','escrow_with_posting','news_post','event_post','funeral_post',
    'mp_boost','delivery_subscription','delivery_sponsored','delivery_verification',
    'featured_event','featured_funeral','featured_news','mp_subscription','mp_order',
    'delivery_commission','worker_premium','sponsor','quick_service',
    'accommodation_subscription','featured_accommodation'
) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v085: Marketplace — per-category toggle for the Condition (New/Used/
-- Refurbished) strip. Some categories (e.g. digital goods, services) never
-- have a meaningful condition, so an admin can hide the badge/filter for
-- just those categories instead of it always showing everywhere.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE mp_categories ADD COLUMN IF NOT EXISTS show_condition TINYINT(1) NOT NULL DEFAULT 1;

-- ═══════════════════════════════════════════════════════════════════════════
-- v086: Funeral announcements — optional town, shown combined with the venue
-- on listing cards ("Venue - Town"), same pattern as accommodation's
-- area/town combination.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE funeral_announcements ADD COLUMN IF NOT EXISTS town_id INT UNSIGNED NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- v087  Worker Portfolio — lets a worker showcase past work/projects on their
-- public profile, mirroring the marketplace's shop → products relationship
-- (worker_profiles is the "shopfront", each portfolio item is a "listing"
-- with its own photo gallery). Managed from worker_portfolio.php /
-- worker_portfolio_form.php, displayed on worker_profile_public.php.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS worker_portfolio_items (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT UNSIGNED NOT NULL,
    title             VARCHAR(160) NOT NULL,
    description       TEXT NULL,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wpi_worker (worker_profile_id),
    FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_portfolio_images (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id     INT UNSIGNED NOT NULL,
    image_path  VARCHAR(255) NOT NULL,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wpimg_item (item_id),
    FOREIGN KEY (item_id) REFERENCES worker_portfolio_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v088  Milestone Reward Claim System — sits on top of the existing points
-- module (points_wallets/points_transactions/award_points(), unchanged).
-- Reaching a milestone only unlocks eligibility; the user must actively
-- submit a claim, which locks the required points immediately (a real
-- points_transactions debit, event='reward_claim_lock') so they can never be
-- spent twice. Admin review moves the claim through pending → under_review →
-- approved → processing → fulfilled, or rejects/cancels it, which credits
-- the points back (event='reward_claim_release'). See
-- modules/rewards/service.php for the full state machine.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS reward_milestones (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title              VARCHAR(160) NOT NULL,
    description        TEXT NULL,
    required_points    INT UNSIGNED NOT NULL,
    reward_type        ENUM('cash','airtime','data','physical_item','discount','voucher','badge','other') NOT NULL,
    reward_value       DECIMAL(10,2) NULL,
    reward_description VARCHAR(255) NOT NULL,
    claim_frequency    ENUM('one_time','repeatable') NOT NULL DEFAULT 'one_time',
    max_claims         INT UNSIGNED NULL COMMENT 'NULL = unlimited',
    claims_count       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Active (non-rejected/cancelled) claims — maintained transactionally alongside points locking',
    start_date         DATE NULL,
    end_date           DATE NULL,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rm_active (active),
    INDEX idx_rm_points (required_points),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_claims (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_code        VARCHAR(24) NOT NULL,
    user_id               INT UNSIGNED NOT NULL,
    milestone_id          INT UNSIGNED NOT NULL,
    points_locked         INT UNSIGNED NOT NULL,
    reward_type           ENUM('cash','airtime','data','physical_item','discount','voucher','badge','other') NOT NULL,
    reward_value          DECIMAL(10,2) NULL,
    claim_details         JSON NULL COMMENT 'Dynamic form fields captured at claim time (momo number, network, delivery address, etc.)',
    status                ENUM('pending','under_review','approved','processing','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'pending',
    rejection_reason      VARCHAR(255) NULL,
    admin_note            TEXT NULL,
    fulfillment_note      TEXT NULL,
    fulfillment_reference VARCHAR(120) NULL,
    approved_by           INT UNSIGNED NULL,
    approved_at           DATETIME NULL,
    processing_at         DATETIME NULL,
    fulfilled_at          DATETIME NULL,
    rejected_at           DATETIME NULL,
    cancelled_at          DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rc_ref (reference_code),
    INDEX idx_rc_user (user_id),
    INDEX idx_rc_milestone (milestone_id),
    INDEX idx_rc_status (status),
    INDEX idx_rc_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (milestone_id) REFERENCES reward_milestones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
    ('rewards_enabled', '1');

-- ═══════════════════════════════════════════════════════════════════════════
-- v089  Dedup table for the "🎉 Milestone Reached!" push notification —
-- hooked into award_points() (modules/referrals/service.php) via a guarded,
-- file_exists()-checked call so it's a no-op if this module is ever removed.
-- INSERT IGNORE against the unique key is the atomic guard against notifying
-- twice, even under concurrent point-earning requests.
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS reward_milestone_notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    milestone_id INT UNSIGNED NOT NULL,
    notified_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rmn_user_milestone (user_id, milestone_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (milestone_id) REFERENCES reward_milestones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- v090  Deletion approval — users can no longer directly delete their own
-- events or news articles. Clicking "Delete" now flags a deletion_requested
-- row instead of an immediate DELETE; a moderator with approve_events/
-- approve_news must approve it (which performs the real delete) or reject it
-- (which clears the flag and restores the item to normal). See
-- my_events.php/my_news.php (request) and admin/mod_action.php
-- (approve_delete_event/news, reject_delete_event/news).
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE events ADD COLUMN IF NOT EXISTS deletion_requested TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE events ADD COLUMN IF NOT EXISTS deletion_requested_at DATETIME NULL;
ALTER TABLE news   ADD COLUMN IF NOT EXISTS deletion_requested TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE news   ADD COLUMN IF NOT EXISTS deletion_requested_at DATETIME NULL;

-- last updated ends here


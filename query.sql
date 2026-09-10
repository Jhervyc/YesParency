-- =================== user authentication table =======================
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user','bidder','admin','superadmin') NOT NULL DEFAULT 'user',
    status ENUM('pending','active','inactive') DEFAULT 'active',
    profile_picture_url VARCHAR(2048) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =================== bidder profile table =======================
CREATE TABLE bidder_profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    business_name VARCHAR(255) NOT NULL,
    philgeps_number VARCHAR(100),
    tin_number VARCHAR(100),
    business_type VARCHAR(100),
    year_established YEAR,
    business_address TEXT,
    business_email VARCHAR(255),
    business_phone VARCHAR(50),
    application_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
    REFERENCES users(user_id)
    ON DELETE CASCADE
);

-- =================== Bidder's Document table =======================
CREATE TABLE bidder_documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    document_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
    REFERENCES users(user_id)
    ON DELETE CASCADE
);

ALTER TABLE bidder_profiles
ADD application_status ENUM(
    'pending',
    'approved',
    'rejected'
) DEFAULT 'pending';

-- =================== Procurement table =======================
CREATE TABLE procurements (
    id INT AUTO_INCREMENT PRIMARY KEY,

    procurement_ref_no VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    abc DECIMAL(15,2) NOT NULL,

    procurement_mode VARCHAR(100) NOT NULL,

    procurement_type ENUM(
        'goods_services',
        'infrastructure'
    ) NOT NULL DEFAULT 'goods_services',

    posting_date DATETIME NULL,
    closing_date DATETIME NULL,
    opening_date DATETIME NULL,

    status ENUM(
        'draft',
        'open',
        'closed',
        'awarded',
        'cancelled'
    ) NOT NULL DEFAULT 'draft',

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER TABLE procurements
-- ADD COLUMN procurement_type ENUM(
--     'goods_services',
--     'infrastructure'
-- ) NOT NULL DEFAULT 'goods_services'
-- AFTER procurement_mode;

-- ALTER TABLE procurements
-- CHANGE COLUMN philgeps_ref_no procurement_ref_no VARCHAR(255) NOT NULL;

-- =================== lots table =======================
CREATE TABLE lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    procurement_id INT NOT NULL,
    lot_number INT NOT NULL,
    lot_title VARCHAR(255) NOT NULL,
    description TEXT,
    abc DECIMAL(15,2),

    status ENUM(
        'pending',
        'awarded',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    FOREIGN KEY (procurement_id)
        REFERENCES procurements(id)
        ON DELETE CASCADE
);

-- ALTER TABLE lots
-- ADD COLUMN status ENUM(
--     'pending',
--     'awarded',
--     'failed'
-- ) NOT NULL DEFAULT 'pending'
-- AFTER abc;

-- =================== Procurement_Documents table =======================

CREATE TABLE procurement_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,

    procurement_id INT NOT NULL,

    document_category ENUM(
        'original',
        'associated'
    ) NOT NULL DEFAULT 'original',

    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,

    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (procurement_id)
        REFERENCES procurements(id)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER TABLE procurement_documents
-- ADD COLUMN document_category ENUM(
--     'original',
--     'associated'
-- ) NOT NULL DEFAULT 'original'
-- AFTER procurement_id;

-- =================== Bids table =======================
CREATE TABLE bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bidder_id INT NOT NULL,
    procurement_id INT NOT NULL,
    bid_type ENUM(
        'quotation',
        'bid'
    ) NOT NULL DEFAULT 'bid',
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('submitted','opened','pending','awarded','rejected') DEFAULT 'submitted',
    UNIQUE KEY unique_bidder_procurement (bidder_id, procurement_id),
    FOREIGN KEY (bidder_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (procurement_id) REFERENCES procurements(id) ON DELETE CASCADE
);

-- ALTER TABLE bids
-- ADD COLUMN bid_type ENUM(
--     'quotation',
--     'bid'
-- ) NOT NULL DEFAULT 'bid'
-- AFTER procurement_id;
-- =================== Bids_lots table =======================
CREATE TABLE bid_lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bid_id INT NOT NULL,
    lot_id INT NOT NULL,

    total_offered_bid DECIMAL(15,2) NULL,

    eligibility_status ENUM(
        'opened',
        'pending',
        'eligible',
        'disqualified'
    ) NOT NULL DEFAULT 'pending',

    financial_status ENUM(
        'opened',
        'pending',
        'qualified',
        'non_compliant'
    ) NOT NULL DEFAULT 'pending',

    FOREIGN KEY (bid_id)
        REFERENCES bids(id)
        ON DELETE CASCADE,

    FOREIGN KEY (lot_id)
        REFERENCES lots(id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_bid_lot (bid_id, lot_id),

    INDEX idx_lot (lot_id),
    INDEX idx_eligibility (eligibility_status),
    INDEX idx_financial (financial_status)
);

-- ALTER TABLE bid_lots
-- DROP COLUMN status;

-- ALTER TABLE bid_lots
-- ADD COLUMN eligibility_status ENUM(
--     'opened',
--     'pending',
--     'eligible',
--     'disqualified'
-- ) NOT NULL DEFAULT 'pending'
-- AFTER lot_id,

-- ADD COLUMN financial_status ENUM(
--     'opened',
--     'pending',
--     'qualified',
--     'non_compliant'
-- ) NOT NULL DEFAULT 'pending'
-- AFTER eligibility_status;

-- ALTER TABLE bid_lots
-- ADD COLUMN total_offered_bid DECIMAL(15,2) NULL
-- AFTER lot_id;

-- =================== Bids_Documents table =======================
CREATE TABLE bid_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bid_id INT NOT NULL,
    document_type ENUM('eligibility', 'financial', 'qoutation', 'other') DEFAULT 'other',
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE
);

-- ALTER TABLE bid_documents
-- MODIFY COLUMN document_type ENUM(
--     'eligibility',
--     'financial',
--     'quotation',
--     'other'
-- );

-- =================== Awards table =======================
CREATE TABLE IF NOT EXISTS awards (
    id INT AUTO_INCREMENT PRIMARY KEY,

    lot_id INT NOT NULL,
    bid_lot_id INT NOT NULL,

    awarded_amount DECIMAL(15,2) NOT NULL,
    award_date DATE NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (lot_id)
        REFERENCES lots(id)
        ON DELETE CASCADE,

    FOREIGN KEY (bid_lot_id)
        REFERENCES bid_lots(id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_lot_award (lot_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== System table =======================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings matching your UI
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('org_name', 'YesParency'),
    ('short_name', 'YSP'),
    ('official_website', 'https://example.gov.ph'),
    ('contact_email', 'procurement@example.gov.ph'),
    ('address', 'Full office address...'),
    ('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- =================== System Notifications Table =======================
-- Handles announcements targeted to Everyone ('all'), Specific Roles ('role'), or Individual Users ('user')
CREATE TABLE IF NOT EXISTS system_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_type ENUM('all', 'role', 'user') NOT NULL DEFAULT 'all',
    target_role ENUM('user', 'bidder', 'admin', 'superadmin') NULL,
    target_user_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (target_user_id) 
        REFERENCES users(user_id) 
        ON DELETE CASCADE,
        
    FOREIGN KEY (created_by) 
        REFERENCES users(user_id) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== User Notification Reads Table =======================
-- Tracks read status and timestamps per user without duplicating notification text
CREATE TABLE IF NOT EXISTS user_notification_reads (
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (notification_id, user_id),

    FOREIGN KEY (notification_id) 
        REFERENCES system_notifications(notification_id) 
        ON DELETE CASCADE,

    FOREIGN KEY (user_id) 
        REFERENCES users(user_id) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== Audit Trail / System Logs Table =======================
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL allows logging system-generated actions or unauthenticated attempts (e.g., failed logins)
    action VARCHAR(50) NOT NULL, -- e.g., 'CREATE', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'TOGGLE_MAINTENANCE'
    module VARCHAR(50) NOT NULL, -- e.g., 'procurements', 'bids', 'users', 'settings', 'auth'
    record_id INT NULL, -- Primary Key ID of the affected record (e.g., procurement_id or bid_id)
    description TEXT NOT NULL, -- Human-readable summary (e.g., 'Approved bidder profile for Business ABC')
    old_values JSON NULL, -- JSON snapshot of data BEFORE change (for UPDATE/DELETE)
    new_values JSON NULL, -- JSON snapshot of data AFTER change (for CREATE/UPDATE)
    ip_address VARCHAR(45) NULL, -- Supports IPv4 and IPv6
    user_agent VARCHAR(255) NULL, -- Client browser / device details
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) 
        REFERENCES users(user_id) 
        ON DELETE SET NULL,
        
    INDEX idx_user_action (user_id, action),
    INDEX idx_module_record (module, record_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== Admin Roles Table =======================
CREATE TABLE admin_roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE, -- Ensures 1-to-1 or 1-to-0 assignment per admin
    admin_type ENUM('BAC', 'TWG', 'SECRETARIAT') NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) 
        REFERENCES users(user_id) 
        ON DELETE CASCADE
);

-- =================== Bid Openning Session Table =======================
CREATE TABLE IF NOT EXISTS bid_opening_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    procurement_id INT NOT NULL,

    stream_path VARCHAR(255) NOT NULL DEFAULT 'live',
    title VARCHAR(255) NULL,

    current_lot_id INT NULL,

    status ENUM(
        'scheduled',
        'started',
        'eligibility',
        'financial',
        'awarding',
        'ended'
    ) NOT NULL DEFAULT 'scheduled',

    signing_status ENUM(
        'not_started',
        'signing',
        'done'
    ) NOT NULL DEFAULT 'not_started',

    started_at DATETIME NULL,
    ended_at DATETIME NULL,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (procurement_id)
        REFERENCES procurements(id)
        ON DELETE CASCADE,

    FOREIGN KEY (current_lot_id)
        REFERENCES lots(id)
        ON DELETE SET NULL,

    FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_procurement (procurement_id),
    INDEX idx_status (status),
    INDEX idx_current_lot (current_lot_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER TABLE bid_opening_sessions 
-- MODIFY COLUMN status ENUM(
--     'scheduled',
--     'started',
--     'eligibility',
--     'financial',
--     'awarding',
--     'ended'
-- ) NOT NULL DEFAULT 'scheduled';

-- ALTER TABLE bid_opening_sessions
-- ADD COLUMN current_lot_id INT NULL
-- AFTER title;

-- ALTER TABLE bid_opening_sessions
-- ADD CONSTRAINT fk_session_current_lot
-- FOREIGN KEY (current_lot_id)
-- REFERENCES lots(id)
-- ON DELETE SET NULL;

-- ALTER TABLE bid_opening_sessions
-- ADD INDEX idx_current_lot (current_lot_id);

-- ALTER TABLE bid_opening_sessions
-- ADD COLUMN signing_status ENUM(
--     'not_started',
--     'signing',
--     'done'
-- ) NOT NULL DEFAULT 'not_started'
-- AFTER status;

-- =================== Bid Session Invited Table =======================

CREATE TABLE bid_session_invited (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bid_session_id INT NOT NULL,
    user_id INT NOT NULL,
    invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bid_session_id)
        REFERENCES bid_opening_sessions(id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_session_user (bid_session_id, user_id)
);

-- =================== Live Comments Table =======================
CREATE TABLE IF NOT EXISTS live_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,

    bid_session_id INT NOT NULL,
    user_id INT NOT NULL,

    comment TEXT NOT NULL,

    status ENUM('visible', 'hidden')
        NOT NULL DEFAULT 'visible',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bid_session_id)
        REFERENCES bid_opening_sessions(id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE,

    INDEX idx_session_status (bid_session_id, status),
    INDEX idx_created (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =================== bid lot signature table =======================

CREATE TABLE bid_lot_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,

    bid_lot_id INT NOT NULL,
    user_id INT NOT NULL,

    opening_type ENUM(
        'eligibility',
        'financial'
    ) NOT NULL,

    signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bid_lot_id)
        REFERENCES bid_lots(id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_bid_lot_user_type (
        bid_lot_id,
        user_id,
        opening_type
    ),

    INDEX idx_bid_lot (bid_lot_id),
    INDEX idx_user (user_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== checklist_templates =======================
CREATE TABLE checklist_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,

    procurement_type ENUM(
        'goods_services',
        'infrastructure'
    ) NOT NULL,

    checklist_type ENUM(
        'eligibility',
        'financial'
    ) NOT NULL,

    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,

    is_required BOOLEAN NOT NULL DEFAULT TRUE,
    display_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_type_order (
        procurement_type,
        checklist_type,
        display_order
    )

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== checklist_templates =======================
CREATE TABLE bid_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,

    bid_lot_id INT NOT NULL,
    template_item_id INT NOT NULL,

    item_name VARCHAR(255) NOT NULL,

    result ENUM(
        'pending',
        'present',
        'missing',
        'not_applicable'
    ) NOT NULL DEFAULT 'pending',

    remarks TEXT NULL,

    checked_by INT NULL,
    checked_at DATETIME NULL,

    FOREIGN KEY (bid_lot_id)
        REFERENCES bid_lots(id)
        ON DELETE CASCADE,

    FOREIGN KEY (template_item_id)
        REFERENCES checklist_templates(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (checked_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    UNIQUE KEY unique_bid_lot_item (
        bid_lot_id,
        template_item_id
    ),

    INDEX idx_bid_lot (bid_lot_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== email_queue =======================
CREATE TABLE email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,

    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) NULL,

    subject VARCHAR(255) NOT NULL,

    template VARCHAR(100) NOT NULL,
    payload JSON NULL,

    status ENUM(
        'pending',
        'processing',
        'sent',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    attempts INT NOT NULL DEFAULT 0,

    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,

    last_error TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_recipient (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =================== Invitation tables (run these if tables don't exist yet) =======================

-- user_invitations: stores one-time tokens sent to approved applicants
CREATE TABLE IF NOT EXISTS `user_invitations` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `role`       VARCHAR(50)  NOT NULL DEFAULT 'user',
    `token`      VARCHAR(64)  NOT NULL UNIQUE,
    `status`     ENUM('pending','accepted','expired') DEFAULT 'pending',
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME     NOT NULL,
    INDEX idx_token  (token),
    INDEX idx_email  (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- invitation_requests: stores portal access requests submitted via register.php
CREATE TABLE IF NOT EXISTS `invitation_requests` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `company_name`     VARCHAR(255) NOT NULL,
    `contact_person`   VARCHAR(255) NOT NULL,
    `email`            VARCHAR(255) NOT NULL,
    `phone`            VARCHAR(50)  DEFAULT NULL,
    `tax_id_tin`       VARCHAR(100) DEFAULT NULL,
    `business_address` TEXT         DEFAULT NULL,
    `business_type`    VARCHAR(100) DEFAULT NULL,
    `requested_role`   VARCHAR(50)  DEFAULT 'user',
    `status`           ENUM('pending','approved','rejected') DEFAULT 'pending',
    `admin_notes`      TEXT         DEFAULT NULL,
    `reviewed_by`      INT          DEFAULT NULL,
    `reviewed_at`      DATETIME     DEFAULT NULL,
    `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

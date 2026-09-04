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
    philgeps_ref_no VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    abc DECIMAL(15,2) NOT NULL,
    procurement_mode VARCHAR(100),
    posting_date DATE,
    closing_date DATETIME,
    opening_date DATETIME,
    status ENUM('draft','open','closed','awarded','cancelled') DEFAULT 'draft',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
    document_name VARCHAR(255),
    file_path VARCHAR(255),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (procurement_id)
    REFERENCES procurements(id)
    ON DELETE CASCADE
);

-- =================== Bids table =======================
CREATE TABLE bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bidder_id INT NOT NULL,
    procurement_id INT NOT NULL,
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('submitted','opened','pending','awarded','rejected') DEFAULT 'submitted',
    UNIQUE KEY unique_bidder_procurement (bidder_id, procurement_id),
    FOREIGN KEY (bidder_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (procurement_id) REFERENCES procurements(id) ON DELETE CASCADE
);
-- =================== Bids_lots table =======================
CREATE TABLE bid_lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bid_id INT NOT NULL,
    lot_id INT NOT NULL,

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

-- =================== Bids_Documents table =======================
CREATE TABLE bid_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bid_id INT NOT NULL,
    document_type ENUM('eligibility', 'financial', 'other') DEFAULT 'other',
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE
);

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

    -- Live stream information
    stream_path VARCHAR(255) NOT NULL DEFAULT 'live',
    title VARCHAR(255) NULL,

    -- Current lot being processed
    current_lot_id INT NULL,

    -- Session / livestream status
    status ENUM(
        'scheduled',
        'started',
        'eligibility',
        'financial',
        'awarding',
        'ended'
    ) NOT NULL DEFAULT 'scheduled',

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
    INDEX idx_current_lot (current_lot_id),
    INDEX idx_status (status)

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
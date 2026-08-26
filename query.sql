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
    lot_title VARCHAR(255),
    description TEXT,
    abc DECIMAL(15,2),

    FOREIGN KEY (procurement_id)
    REFERENCES procurements(id)
    ON DELETE CASCADE
);

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

    FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE,
    FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
);


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
CREATE TABLE awards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    bidder_id INT NOT NULL,
    awarded_amount DECIMAL(15,2),
    award_date DATE,

    FOREIGN KEY (lot_id)
    REFERENCES lots(id),

    FOREIGN KEY (bidder_id)
    REFERENCES users(id)
);

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


-- MIGRATION_v2.sql
-- Upgrades an existing astech_lucky database to v2.
-- Safe to run more than once. Import in phpMyAdmin (Import tab) or run:
--   mysql -u root < MIGRATION_v2.sql
-- (Fresh installs should use FULL_DATABASE_SETUP.sql instead.)

USE astech_lucky;

-- Valid product codes: only codes in this table can be registered
CREATE TABLE IF NOT EXISTS product_codes (
    code VARCHAR(100) PRIMARY KEY,
    batch_name VARCHAR(100) NOT NULL DEFAULT '',
    created_by VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch (batch_name)
);

-- Keep codes that were already registered before v2 valid
INSERT IGNORE INTO product_codes (code, batch_name)
SELECT purchase_id, 'Legacy' FROM lucky_draw_entries;

-- Campaign settings (key/value)
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(50) PRIMARY KEY,
    value TEXT NOT NULL
);

-- Lucky draw winners
CREATE TABLE IF NOT EXISTS winners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    customer_id VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    purchase_id VARCHAR(100) NOT NULL,
    prize VARCHAR(150) NOT NULL DEFAULT '',
    drawn_by VARCHAR(50) NULL,
    drawn_at DATETIME NOT NULL,
    INDEX idx_customer (customer_id)
);

-- Customer IDs are now generated from an auto-increment number (no more COUNT(*) clashes)
ALTER TABLE lucky_customers ADD COLUMN IF NOT EXISTS id INT NOT NULL AUTO_INCREMENT UNIQUE FIRST;

-- Indexes used by the dashboard charts
ALTER TABLE lucky_draw_entries ADD INDEX IF NOT EXISTS idx_entry_date (entry_date);

-- Standardise phone numbers to 60XXXXXXXXX
UPDATE lucky_customers   SET customer_phone = CONCAT('6',  customer_phone) WHERE customer_phone LIKE '0%';
UPDATE lucky_customers   SET customer_phone = CONCAT('60', customer_phone) WHERE customer_phone LIKE '1%';
UPDATE lucky_draw_entries SET customer_phone = CONCAT('6',  customer_phone) WHERE customer_phone LIKE '0%';
UPDATE lucky_draw_entries SET customer_phone = CONCAT('60', customer_phone) WHERE customer_phone LIKE '1%';

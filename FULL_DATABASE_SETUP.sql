-- Full database setup for the ASTECH lucky draw system (creates all 7 tables, no data)
--
-- How to use:
--   1. Create an empty database (character set utf8mb4, collation utf8mb4_unicode_ci):
--        XAMPP:     phpMyAdmin > New > name it astech_lucky
--        Hostinger: hPanel > Databases > create database + user (name will be like u123456789_astech)
--   2. In phpMyAdmin, click that database, then Import > choose this file > Go.
--   3. Put the database name, user and password in config.php.
-- Safe to run more than once: existing tables are left as they are.

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lucky_customers (
    id INT NOT NULL AUTO_INCREMENT UNIQUE,
    customer_id VARCHAR(20) PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL UNIQUE,
    first_entry_date DATETIME NOT NULL,
    last_entry_date DATETIME NOT NULL,
    total_entries INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS lucky_draw_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id VARCHAR(20) NOT NULL,
    purchase_id VARCHAR(100) NOT NULL UNIQUE,
    product_code VARCHAR(100) NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45) NULL,
    entry_date DATETIME NOT NULL,
    INDEX idx_customer_id (customer_id),
    INDEX idx_entry_date (entry_date)
);

CREATE TABLE IF NOT EXISTS qr_scan_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id VARCHAR(100) NOT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purchase_id (purchase_id)
);

-- Valid product codes: only codes in this table can be registered
CREATE TABLE IF NOT EXISTS product_codes (
    code VARCHAR(100) PRIMARY KEY,
    batch_name VARCHAR(100) NOT NULL DEFAULT '',
    created_by VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch (batch_name)
);

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

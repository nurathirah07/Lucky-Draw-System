-- Database Setup for Admin User Management
-- Run this SQL to create the admin_users table

-- Create the admin_users table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- Optional: Add an initial admin user for testing
-- Username: admin
-- Password: admin123
-- IMPORTANT: Change this after first login!
-- To generate the hash, use: password_hash('admin123', PASSWORD_BCRYPT)
-- Or register a new admin using the registration page

INSERT INTO admin_users (username, email, password) VALUES 
('admin', 'admin@example.com', '$2y$10$YIjlrTyVeJ.XvXfVIKqReOKZzN8z4J8F5F5F5F5F5F5F5F5F5F5F5F');

-- Note: The above hash is a placeholder. To create a proper hash:
-- 1. Use the registration page (admin_register.php) to create new admin accounts
-- 2. Or use PHP to generate the hash:
--    $hash = password_hash('your_password_here', PASSWORD_BCRYPT);
--    Then insert it manually

-- Example of how to update an admin's password:
-- UPDATE admin_users SET password = '$2y$10$...' WHERE username = 'admin';

-- To delete an admin user:
-- DELETE FROM admin_users WHERE username = 'admin';

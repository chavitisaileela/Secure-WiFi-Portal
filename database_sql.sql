CREATE DATABASE IF NOT EXISTS wifi_portal;
USE wifi_portal;

CREATE TABLE IF NOT EXISTS wifi_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL UNIQUE,
    passcode VARCHAR(6) NOT NULL,
    generated_time DATETIME NOT NULL,
    expiry_time DATETIME NOT NULL,
    status ENUM('PENDING', 'APPROVED', 'EXPIRED', 'USED') NOT NULL,
    device_macs JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
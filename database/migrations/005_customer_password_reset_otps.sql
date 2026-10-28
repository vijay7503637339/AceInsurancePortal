-- One-time password records for customer password recovery.
-- Run this migration ONCE against the existing portal database.
CREATE TABLE IF NOT EXISTS password_reset_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    dot_number VARCHAR(24) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    used_at DATETIME NULL,
    request_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_otp_email_created (email, created_at),
    INDEX idx_reset_otp_ip_created (request_ip, created_at),
    INDEX idx_reset_otp_user_status (user_id, used_at, expires_at),
    CONSTRAINT fk_reset_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

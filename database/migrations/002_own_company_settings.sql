-- Run once in phpMyAdmin against the portal's configured database.
-- Singleton id=1 stores the own company profile used on certificates.
CREATE TABLE IF NOT EXISTS own_company_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
 company_name VARCHAR(180) NOT NULL DEFAULT '',
 company_address TEXT NOT NULL,
 contact_person VARCHAR(180) NOT NULL DEFAULT '',
 phone_number VARCHAR(60) NOT NULL DEFAULT '',
 email_address VARCHAR(254) NOT NULL DEFAULT '',
 authorized_signature_name VARCHAR(180) NOT NULL DEFAULT '',
 authorized_signature_file VARCHAR(80) NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_own_company_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

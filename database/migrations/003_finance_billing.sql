-- Run once in phpMyAdmin against the existing AceInsurancePortal database.
CREATE TABLE IF NOT EXISTS finance_billing (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 insured_id BIGINT UNSIGNED NOT NULL, policy_id BIGINT UNSIGNED NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'Pending Setup',
 coverage_policy_type VARCHAR(80) NOT NULL, finance_company VARCHAR(255) NOT NULL,
 finance_company_other VARCHAR(255) NULL, finance_account_number VARCHAR(100) NULL,
 down_payment DECIMAL(13,2) NULL, installments SMALLINT UNSIGNED NULL,
 monthly_amount DECIMAL(13,2) NULL, next_due_date DATE NULL, pending_past_due DECIMAL(13,2) NULL,
 autopay VARCHAR(20) NULL, payment_method VARCHAR(40) NULL, last_payment_date DATE NULL,
 last_payment_amount DECIMAL(13,2) NULL, billing_notes TEXT NULL,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_finance_billing_insured (insured_id), INDEX idx_finance_billing_policy (policy_id), INDEX idx_finance_billing_status (status),
 FOREIGN KEY(insured_id) REFERENCES insureds(id) ON DELETE RESTRICT,
 FOREIGN KEY(policy_id) REFERENCES policies(id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

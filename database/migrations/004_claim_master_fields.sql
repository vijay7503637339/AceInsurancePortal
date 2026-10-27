-- Claim Master fields. Run once in phpMyAdmin against the existing portal database.
ALTER TABLE claims
 ADD COLUMN coverage VARCHAR(80) NULL AFTER claim_type,
 ADD COLUMN claim_side VARCHAR(40) NULL AFTER coverage,
 ADD COLUMN insurance_carrier VARCHAR(180) NULL AFTER claim_side,
 ADD COLUMN adjuster_name VARCHAR(180) NULL AFTER insurance_carrier,
 ADD COLUMN adjuster_contact VARCHAR(180) NULL AFTER adjuster_name,
 ADD COLUMN accident_loss_summary TEXT NULL AFTER description,
 ADD COLUMN latest_update_brief TEXT NULL AFTER accident_loss_summary,
 ADD COLUMN next_action TEXT NULL AFTER latest_update_brief,
 ADD COLUMN follow_up_date DATE NULL AFTER next_action,
 ADD COLUMN assigned_team_member_id BIGINT UNSIGNED NULL AFTER created_by,
 ADD INDEX idx_claims_follow_up_date (follow_up_date),
 ADD INDEX idx_claims_coverage (coverage),
 ADD INDEX idx_claims_claim_side (claim_side),
 ADD CONSTRAINT fk_claims_assigned_team_member FOREIGN KEY (assigned_team_member_id) REFERENCES users(id) ON DELETE SET NULL;

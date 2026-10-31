-- Claim adjusters and immutable per-claim change history.
-- Run this migration ONCE in phpMyAdmin against the existing portal database.

ALTER TABLE claims
    ADD COLUMN adjusters JSON NULL AFTER adjuster_contact;

-- Preserve existing single-adjuster values in the new multi-adjuster structure.
UPDATE claims
SET adjusters = JSON_ARRAY(
    JSON_OBJECT(
        'name', COALESCE(adjuster_name, ''),
        'summary', '',
        'contact', COALESCE(adjuster_contact, '')
    )
)
WHERE adjuster_name IS NOT NULL OR adjuster_contact IS NOT NULL;

-- Actor name and role are snapshotted so history remains readable if a user account changes.
-- Deliberately no foreign keys: audit history must survive claim/user deletion.
CREATE TABLE claim_change_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id BIGINT UNSIGNED NOT NULL,
    claim_number VARCHAR(100) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(160) NOT NULL,
    actor_role VARCHAR(30) NOT NULL,
    event_type VARCHAR(30) NOT NULL DEFAULT 'updated',
    changes_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim_change_logs_claim_time (claim_id, created_at, id),
    INDEX idx_claim_change_logs_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

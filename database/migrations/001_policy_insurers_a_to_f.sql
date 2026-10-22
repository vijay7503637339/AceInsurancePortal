-- Add insurer A-F company names and NAIC numbers to existing policies.
-- Run this migration ONCE against the portal's configured MySQL database.
ALTER TABLE policies
    ADD COLUMN insurer_a_name VARCHAR(180) NULL AFTER carrier_name,
    ADD COLUMN insurer_a_naic VARCHAR(30) NULL AFTER insurer_a_name,
    ADD COLUMN insurer_b_name VARCHAR(180) NULL AFTER insurer_a_naic,
    ADD COLUMN insurer_b_naic VARCHAR(30) NULL AFTER insurer_b_name,
    ADD COLUMN insurer_c_name VARCHAR(180) NULL AFTER insurer_b_naic,
    ADD COLUMN insurer_c_naic VARCHAR(30) NULL AFTER insurer_c_name,
    ADD COLUMN insurer_d_name VARCHAR(180) NULL AFTER insurer_c_naic,
    ADD COLUMN insurer_d_naic VARCHAR(30) NULL AFTER insurer_d_name,
    ADD COLUMN insurer_e_name VARCHAR(180) NULL AFTER insurer_d_naic,
    ADD COLUMN insurer_e_naic VARCHAR(30) NULL AFTER insurer_e_name,
    ADD COLUMN insurer_f_name VARCHAR(180) NULL AFTER insurer_e_naic,
    ADD COLUMN insurer_f_naic VARCHAR(30) NULL AFTER insurer_f_name;

-- Preserve existing policy carrier values as Insurer A.
UPDATE policies
SET insurer_a_name = NULLIF(TRIM(carrier_name), '')
WHERE (insurer_a_name IS NULL OR TRIM(insurer_a_name) = '')
  AND carrier_name IS NOT NULL;

-- Migrate an NAIC value previously stored in coverage_data to Insurer A.
UPDATE policies
SET insurer_a_naic = COALESCE(
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(coverage_data, '$.naic_number')), ''),
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(coverage_data, '$.insurer_naic')), ''),
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(coverage_data, '$.naic')), '')
)
WHERE (insurer_a_naic IS NULL OR TRIM(insurer_a_naic) = '')
  AND coverage_data IS NOT NULL;

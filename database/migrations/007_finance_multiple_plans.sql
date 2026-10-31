-- Add support for up to four finance plans inside one Finance Billing record.
-- Run this migration ONCE against the existing AceInsurancePortal database.
ALTER TABLE finance_billing
    ADD COLUMN finance_plans JSON NULL AFTER finance_company_other;

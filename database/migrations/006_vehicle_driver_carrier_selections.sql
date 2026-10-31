-- Store the policy-carrier selections made for each vehicle and driver.
-- Run this migration ONCE against the existing portal database.
ALTER TABLE vehicles
    ADD COLUMN carrier_selections JSON NULL AFTER insured_id;

ALTER TABLE drivers
    ADD COLUMN carrier_selections JSON NULL AFTER insured_id;

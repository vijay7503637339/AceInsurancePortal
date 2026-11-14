-- Store the selected coverage types for each vehicle and driver.
-- Carrier selection columns are retained for backward compatibility but are no longer used by the UI.
ALTER TABLE vehicles
    ADD COLUMN coverage_selections JSON NULL AFTER insured_id;

ALTER TABLE drivers
    ADD COLUMN coverage_selections JSON NULL AFTER insured_id;

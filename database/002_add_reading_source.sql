-- OLTC Dashboard: audit source for incoming/manual readings
-- Run this once against database `oltc-dashboard`.

ALTER TABLE counter_readings
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'api' AFTER foto_path;

-- Existing rows are treated as API-ingested records because they predate
-- the manual editing/audit feature.
UPDATE counter_readings
SET source = 'api'
WHERE source IS NULL OR source = '';

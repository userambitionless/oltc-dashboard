-- OLTC Counter Dashboard
-- Audit trail for admin corrections. Run this file once in the project's database.
CREATE TABLE IF NOT EXISTS counter_reading_corrections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    counter_reading_id BIGINT UNSIGNED NOT NULL,
    old_tanggal DATE NOT NULL,
    old_jam TIME NOT NULL,
    old_nilai_data DECIMAL(12,3) NOT NULL,
    new_tanggal DATE NOT NULL,
    new_jam TIME NOT NULL,
    new_nilai_data DECIMAL(12,3) NOT NULL,
    original_source VARCHAR(20) NOT NULL,
    changed_by_user_id BIGINT NULL,
    changed_by_username VARCHAR(100) NOT NULL,
    reason VARCHAR(500) NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correction_reading_time (counter_reading_id, changed_at, id),
    KEY idx_correction_user_time (changed_by_user_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

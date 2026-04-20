-- Asset transfer "pending" fázis (1. lépéshez ajánlott bővítés)
-- Ha már léteznek ezek az oszlopok, a megfelelő ALTER hibát fog dobni (ez normális).
-- Ajánlott futtatás előtt: SHOW COLUMNS FROM asset_assignments;

ALTER TABLE asset_assignments
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'accepted' AFTER note,
  ADD COLUMN expires_at DATETIME NULL AFTER status,
  ADD COLUMN responded_at DATETIME NULL AFTER expires_at,
  ADD COLUMN response_note TEXT NULL AFTER responded_at;

-- Javasolt indexek
CREATE INDEX idx_asset_assignments_status_to ON asset_assignments (status, to_employee_id);
CREATE INDEX idx_asset_assignments_asset_status ON asset_assignments (asset_id, status);

-- Megjegyzés:
-- A mostani (1. fázis) kód működik oszlopok nélkül is, de akkor csak naplózás történik "pending" nélkül.

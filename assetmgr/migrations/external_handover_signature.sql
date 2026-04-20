-- Külsős átadás: aláírás tárolás (assetmgr)
-- Futtatás: mysql -u ... -p assetmgr_db < migrations/external_handover_signature.sql

USE assetmgr_db;

ALTER TABLE asset_external_assignments
  ADD COLUMN signature_path VARCHAR(255) NULL AFTER note;

-- opcionális index (ha később keresnél fájlokra)
-- CREATE INDEX idx_aea_signature ON asset_external_assignments(signature_path);

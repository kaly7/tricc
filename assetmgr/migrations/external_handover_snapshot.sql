-- Külsős átadás: partner adatok snapshotja (assetmgr)
USE assetmgr_db;

ALTER TABLE asset_external_assignments
  ADD COLUMN ext_company VARCHAR(190) NULL AFTER signature_path,
  ADD COLUMN ext_contact VARCHAR(190) NULL AFTER ext_company,
  ADD COLUMN ext_phone   VARCHAR(80)  NULL AFTER ext_contact;

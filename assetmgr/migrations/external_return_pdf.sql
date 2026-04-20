-- Visszavételi PDF tárolása külsős átadáshoz
USE assetmgr_db;

ALTER TABLE asset_external_assignments
  ADD COLUMN return_pdf_path VARCHAR(255) NULL AFTER pdf_path;

-- Ensure pbx_files has soft-delete + uploader text columns (MariaDB 10.11 supports IF NOT EXISTS)
ALTER TABLE pbx_files
  ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER uploaded_by_email,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER is_deleted,
  ADD COLUMN IF NOT EXISTS uploaded_by_name  VARCHAR(255) NULL AFTER uploaded_by,
  ADD COLUMN IF NOT EXISTS uploaded_by_email VARCHAR(255) NULL AFTER uploaded_by_name;

-- Make uploaded_by nullable (center auth ids won't match local users ids)
ALTER TABLE pbx_files
  MODIFY uploaded_by INT NULL;

-- If you still have a foreign key on uploaded_by, drop it (name may differ):
-- SHOW CREATE TABLE pbx_files;
-- then: ALTER TABLE pbx_files DROP FOREIGN KEY <fk_name>;

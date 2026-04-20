-- Center-auth compatibility for catalog_files
-- Drop FK to local users (ids won't match)
ALTER TABLE catalog_files
  DROP FOREIGN KEY fk_files_user;

ALTER TABLE catalog_files
  MODIFY uploaded_by INT NULL;

ALTER TABLE catalog_files
  ADD COLUMN IF NOT EXISTS uploaded_by_name  VARCHAR(255) NULL AFTER uploaded_by,
  ADD COLUMN IF NOT EXISTS uploaded_by_email VARCHAR(255) NULL AFTER uploaded_by_name,
  ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

-- If your FK name differs:
-- SHOW CREATE TABLE catalog_files;
-- ALTER TABLE catalog_files DROP FOREIGN KEY <fk_name>;

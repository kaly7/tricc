-- catalog_files schema fix: add missing columns used by upload UI
ALTER TABLE catalog_files
  ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) NOT NULL,
  ADD COLUMN IF NOT EXISTS stored_name   VARCHAR(255) NOT NULL,
  ADD COLUMN IF NOT EXISTS mime          VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS size          INT NULL,
  ADD COLUMN IF NOT EXISTS created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_at    DATETIME NULL,
  ADD COLUMN IF NOT EXISTS uploaded_by   INT NULL,
  ADD COLUMN IF NOT EXISTS uploaded_by_name  VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS uploaded_by_email VARCHAR(255) NULL;

-- If you still have an FK on uploaded_by, drop it (name may differ):
-- SHOW CREATE TABLE catalog_files;
-- ALTER TABLE catalog_files DROP FOREIGN KEY <fk_name>;

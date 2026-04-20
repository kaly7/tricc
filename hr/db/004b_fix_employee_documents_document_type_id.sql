-- HR hotfix: ensure employee_documents has document_type_id
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Ensure document_types exists
CREATE TABLE IF NOT EXISTS document_types (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Ensure there's at least one type to reference
INSERT IGNORE INTO document_types (id, name, is_active) VALUES (1, 'Egyéb', 1);

-- Add column if missing (MariaDB-safe dynamic SQL)
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND COLUMN_NAME = 'document_type_id'
);

SET @sql := IF(@has_col = 0,
  'ALTER TABLE employee_documents ADD COLUMN document_type_id INT(10) UNSIGNED NULL AFTER employee_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- If there is an older column name (doc_type_id or type_id), copy it over
SET @has_doc_type_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND COLUMN_NAME = 'doc_type_id'
);
SET @sql := IF(@has_doc_type_id > 0,
  'UPDATE employee_documents SET document_type_id = doc_type_id WHERE document_type_id IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_type_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND COLUMN_NAME = 'type_id'
);
SET @sql := IF(@has_type_id > 0,
  'UPDATE employee_documents SET document_type_id = type_id WHERE document_type_id IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Default any remaining NULLs to 1 (Egyéb)
UPDATE employee_documents SET document_type_id = 1 WHERE document_type_id IS NULL;

-- Make NOT NULL + index
SET @sql := 'ALTER TABLE employee_documents MODIFY document_type_id INT(10) UNSIGNED NOT NULL';
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND INDEX_NAME = 'idx_empdocs_type'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE employee_documents ADD KEY idx_empdocs_type (document_type_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK if missing (try; if already exists, it will error; run manually if needed)
-- We'll do it via dynamic check to avoid hard error.
SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND COLUMN_NAME = 'document_type_id'
    AND REFERENCED_TABLE_NAME = 'document_types'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE employee_documents ADD CONSTRAINT fk_empdocs_type FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;

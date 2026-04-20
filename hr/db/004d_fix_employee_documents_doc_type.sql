-- HR hotfix: make legacy employee_documents.doc_type optional
SET NAMES utf8mb4;

-- If the legacy column doc_type exists and is NOT NULL without default, inserts will fail.
-- We make it NULLable with default NULL so newer code can omit it safely.

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employee_documents'
    AND COLUMN_NAME = 'doc_type'
);

SET @sql := IF(@has_col > 0,
  'ALTER TABLE employee_documents MODIFY doc_type VARCHAR(120) NULL DEFAULT NULL',
  'SELECT 1'
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

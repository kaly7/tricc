-- HR hotfix: align employee_documents columns with code
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Helper: add column if missing
-- (Using dynamic SQL for MariaDB)
-- file_path
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='file_path');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN file_path VARCHAR(255) NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- original_name
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='original_name');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN original_name VARCHAR(255) NULL AFTER file_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mime
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='mime');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN mime VARCHAR(120) NULL AFTER original_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- file_size
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='file_size');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN file_size INT(10) UNSIGNED NULL AFTER mime', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- title
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='title');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN title VARCHAR(190) NULL AFTER document_type_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- expires_at
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='expires_at');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN expires_at DATE NULL AFTER file_size', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- created_at
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND COLUMN_NAME='created_at');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP()', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- indexes
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents' AND INDEX_NAME='idx_empdocs_expires');
SET @sql := IF(@c=0, 'ALTER TABLE employee_documents ADD KEY idx_empdocs_expires (expires_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;

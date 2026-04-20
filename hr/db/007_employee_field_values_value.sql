-- Fix employee_field_values schema to match controller expectations
-- Adds missing columns if they don't exist: value, updated_at

SET @db := DATABASE();

-- Add column `value` if missing
SET @c := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='employee_field_values' AND COLUMN_NAME='value'
);
SET @sql := IF(@c=0,
  'ALTER TABLE employee_field_values ADD COLUMN value TEXT NULL AFTER field_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add column `updated_at` if missing
SET @c2 := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='employee_field_values' AND COLUMN_NAME='updated_at'
);
SET @sql2 := IF(@c2=0,
  'ALTER TABLE employee_field_values ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

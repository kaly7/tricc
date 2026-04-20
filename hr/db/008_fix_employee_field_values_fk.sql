-- Fix FK of employee_field_values.field_id to reference employee_fields instead of custom_fields
-- because the HR extra fields module uses employee_fields table.

SET @db := DATABASE();

-- Find FK constraint name on employee_field_values(field_id)
SELECT CONSTRAINT_NAME INTO @fk
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='employee_field_values'
  AND COLUMN_NAME='field_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL
LIMIT 1;

-- Drop FK if exists
SET @drop_sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE employee_field_values DROP FOREIGN KEY ', @fk));
PREPARE s1 FROM @drop_sql; EXECUTE s1; DEALLOCATE PREPARE s1;

-- Also drop any index named like the old fk index (optional - MySQL keeps indexes)
-- Ensure field_id index exists
SET @idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='employee_field_values' AND INDEX_NAME='idx_efv_field_id'
);
SET @idx_sql := IF(@idx=0, 'ALTER TABLE employee_field_values ADD INDEX idx_efv_field_id (field_id)', 'SELECT 1');
PREPARE s2 FROM @idx_sql; EXECUTE s2; DEALLOCATE PREPARE s2;

-- Add correct FK to employee_fields(id)
-- Avoid duplicate by checking if any FK already references employee_fields
SET @has_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='employee_field_values' AND COLUMN_NAME='field_id'
    AND REFERENCED_TABLE_NAME='employee_fields'
);
SET @add_sql := IF(@has_fk=0,
  'ALTER TABLE employee_field_values ADD CONSTRAINT fk_efv_employee_fields FOREIGN KEY (field_id) REFERENCES employee_fields(id) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE s3 FROM @add_sql; EXECUTE s3; DEALLOCATE PREPARE s3;

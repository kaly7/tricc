SET @db := DATABASE();
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='employee_field_values' AND COLUMN_NAME='show_on_card'
);
SET @sql := IF(@c=0,
  'ALTER TABLE employee_field_values ADD COLUMN show_on_card TINYINT(1) NOT NULL DEFAULT 1 AFTER value',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

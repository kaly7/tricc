USE warehousemgr;

ALTER TABLE audit_log
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(64) NULL AFTER details_json,
  ADD COLUMN IF NOT EXISTS request_uri VARCHAR(255) NULL AFTER ip_address,
  ADD COLUMN IF NOT EXISTS request_method VARCHAR(10) NULL AFTER request_uri,
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER request_method;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'audit_log'
    AND index_name = 'idx_audit_user'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE audit_log ADD INDEX idx_audit_user (auth_user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

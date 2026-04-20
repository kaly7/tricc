USE warehousemgr;

SET @has_transfer_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'transfer_type'
);
SET @sql := IF(@has_transfer_type = 0,
  'ALTER TABLE stock_transfers ADD COLUMN transfer_type VARCHAR(20) NOT NULL DEFAULT ''internal'' AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_partner_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'partner_id'
);
SET @sql := IF(@has_partner_id = 0,
  'ALTER TABLE stock_transfers ADD COLUMN partner_id INT UNSIGNED NULL AFTER cancelled_at',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_partner_name := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'partner_name'
);
SET @sql := IF(@has_partner_name = 0,
  'ALTER TABLE stock_transfers ADD COLUMN partner_name VARCHAR(190) NULL AFTER partner_id',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_receiver_name := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'receiver_name'
);
SET @sql := IF(@has_receiver_name = 0,
  'ALTER TABLE stock_transfers ADD COLUMN receiver_name VARCHAR(190) NULL AFTER partner_name',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_receiver_phone := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'receiver_phone'
);
SET @sql := IF(@has_receiver_phone = 0,
  'ALTER TABLE stock_transfers ADD COLUMN receiver_phone VARCHAR(80) NULL AFTER receiver_name',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_receiver_email := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'receiver_email'
);
SET @sql := IF(@has_receiver_email = 0,
  'ALTER TABLE stock_transfers ADD COLUMN receiver_email VARCHAR(190) NULL AFTER receiver_phone',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_project_no := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'project_no'
);
SET @sql := IF(@has_project_no = 0,
  'ALTER TABLE stock_transfers ADD COLUMN project_no VARCHAR(120) NULL AFTER receiver_email',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_auto_reference := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'auto_reference'
);
SET @sql := IF(@has_auto_reference = 0,
  'ALTER TABLE stock_transfers ADD COLUMN auto_reference TINYINT(1) NOT NULL DEFAULT 0 AFTER project_no',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_type := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND INDEX_NAME = 'idx_st_type'
);
SET @sql := IF(@has_idx_type = 0,
  'ALTER TABLE stock_transfers ADD KEY idx_st_type (transfer_type)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_partner := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND INDEX_NAME = 'idx_st_partner'
);
SET @sql := IF(@has_idx_partner = 0,
  'ALTER TABLE stock_transfers ADD KEY idx_st_partner (partner_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk_partner := (
  SELECT COUNT(*)
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_st_partner'
    AND TABLE_NAME = 'stock_transfers'
);
SET @sql := IF(@has_fk_partner = 0,
  'ALTER TABLE stock_transfers ADD CONSTRAINT fk_st_partner FOREIGN KEY (partner_id) REFERENCES warehouse_partners (id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE stock_transfers
SET transfer_type = 'internal'
WHERE COALESCE(transfer_type, '') = '';

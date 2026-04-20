USE warehousemgr;

CREATE TABLE IF NOT EXISTS warehouse_partners (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_name VARCHAR(190) NOT NULL,
  receiver_name VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  note TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wp_partner_name (partner_name),
  KEY idx_wp_receiver_name (receiver_name),
  KEY idx_wp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_warehouse_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'warehouses'
    AND COLUMN_NAME = 'warehouse_type'
);
SET @sql := IF(@has_warehouse_type = 0,
  'ALTER TABLE warehouses ADD COLUMN warehouse_type VARCHAR(30) NOT NULL DEFAULT ''internal'' AFTER description',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_partner_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'warehouses'
    AND COLUMN_NAME = 'partner_id'
);
SET @sql := IF(@has_partner_id = 0,
  'ALTER TABLE warehouses ADD COLUMN partner_id INT UNSIGNED NULL AFTER warehouse_type',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_type := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'warehouses'
    AND INDEX_NAME = 'idx_warehouses_type'
);
SET @sql := IF(@has_idx_type = 0,
  'ALTER TABLE warehouses ADD KEY idx_warehouses_type (warehouse_type)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_partner := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'warehouses'
    AND INDEX_NAME = 'idx_warehouses_partner'
);
SET @sql := IF(@has_idx_partner = 0,
  'ALTER TABLE warehouses ADD KEY idx_warehouses_partner (partner_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk_partner := (
  SELECT COUNT(*)
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_warehouses_partner'
    AND TABLE_NAME = 'warehouses'
);
SET @sql := IF(@has_fk_partner = 0,
  'ALTER TABLE warehouses ADD CONSTRAINT fk_warehouses_partner FOREIGN KEY (partner_id) REFERENCES warehouse_partners (id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

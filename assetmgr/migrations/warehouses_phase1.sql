CREATE TABLE IF NOT EXISTS warehouses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  note TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_warehouses_active (is_active),
  KEY idx_warehouses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS warehouse_admins (
  warehouse_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (warehouse_id, user_id),
  KEY idx_warehouse_admins_user (user_id),
  CONSTRAINT fk_warehouse_admins_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE assets
  ADD COLUMN current_warehouse_id INT UNSIGNED NULL AFTER current_employee_id,
  ADD KEY idx_assets_current_warehouse (current_warehouse_id),
  ADD CONSTRAINT fk_assets_current_warehouse FOREIGN KEY (current_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL;

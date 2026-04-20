USE warehousemgr;

CREATE TABLE IF NOT EXISTS warehouse_stock (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warehouse_material (warehouse_id, material_id),
  KEY idx_ws_material (material_id),
  KEY idx_ws_qty (quantity),
  CONSTRAINT fk_ws_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_material FOREIGN KEY (material_id) REFERENCES material_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  quantity_change DECIMAL(14,3) NOT NULL,
  quantity_before DECIMAL(14,3) NOT NULL,
  quantity_after DECIMAL(14,3) NOT NULL,
  reference_no VARCHAR(120) NULL,
  note TEXT NULL,
  performed_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_warehouse (warehouse_id),
  KEY idx_sm_material (material_id),
  KEY idx_sm_type (movement_type),
  KEY idx_sm_created (created_at),
  KEY idx_sm_user (performed_by),
  CONSTRAINT fk_sm_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE,
  CONSTRAINT fk_sm_material FOREIGN KEY (material_id) REFERENCES material_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

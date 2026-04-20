USE warehousemgr;

CREATE TABLE IF NOT EXISTS material_identifier_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier_value VARCHAR(191) NOT NULL,
  identifier_value_norm VARCHAR(191) NOT NULL,
  status ENUM('pending','assigned','discarded') NOT NULL DEFAULT 'pending',
  capture_source VARCHAR(120) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  result_message TEXT DEFAULT NULL,
  assigned_material_id BIGINT UNSIGNED DEFAULT NULL,
  assigned_warehouse_id BIGINT UNSIGNED DEFAULT NULL,
  assigned_identifier_id BIGINT UNSIGNED DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  assigned_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mis_status_created (status, created_at),
  KEY idx_mis_norm (identifier_value_norm),
  KEY idx_mis_assigned_material (assigned_material_id),
  KEY idx_mis_assigned_warehouse (assigned_warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

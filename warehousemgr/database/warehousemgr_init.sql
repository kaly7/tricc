CREATE DATABASE IF NOT EXISTS warehousemgr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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

CREATE TABLE IF NOT EXISTS warehouses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id INT UNSIGNED NULL,
  code VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  warehouse_type VARCHAR(30) NOT NULL DEFAULT 'internal',
  partner_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warehouses_code (code),
  KEY idx_warehouses_parent (parent_id),
  KEY idx_warehouses_type (warehouse_type),
  KEY idx_warehouses_partner (partner_id),
  CONSTRAINT fk_warehouses_parent FOREIGN KEY (parent_id) REFERENCES warehouses (id) ON DELETE SET NULL,
  CONSTRAINT fk_warehouses_partner FOREIGN KEY (partner_id) REFERENCES warehouse_partners (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_user_access (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id INT UNSIGNED NOT NULL,
  auth_user_id INT UNSIGNED NOT NULL,
  role_key VARCHAR(30) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warehouse_user (warehouse_id, auth_user_id),
  KEY idx_wua_user (auth_user_id),
  CONSTRAINT fk_wua_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(120) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(30) NULL,
  category_name VARCHAR(120) NULL,
  minimum_stock DECIMAL(14,3) NULL,
  note TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_material_sku (sku),
  KEY idx_material_name (name),
  KEY idx_material_category (category_name),
  KEY idx_material_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_import_batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_name VARCHAR(255) NOT NULL,
  imported_by INT UNSIGNED NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  inserted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_material_import_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_import_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id INT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  row_json LONGTEXT NULL,
  error_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mie_batch (batch_id),
  CONSTRAINT fk_mie_batch FOREIGN KEY (batch_id) REFERENCES material_import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  auth_user_id INT UNSIGNED NULL,
  action_key VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id INT UNSIGNED NULL,
  details_json LONGTEXT NULL,
  ip_address VARCHAR(64) NULL,
  request_uri VARCHAR(255) NULL,
  request_method VARCHAR(10) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_action (action_key),
  KEY idx_audit_user (auth_user_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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



CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_warehouse_id INT UNSIGNED NOT NULL,
  target_warehouse_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  transfer_type VARCHAR(20) NOT NULL DEFAULT 'internal',
  reference_no VARCHAR(120) NULL,
  note TEXT NULL,
  decision_note TEXT NULL,
  requested_by INT UNSIGNED NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_by INT UNSIGNED NULL,
  accepted_at TIMESTAMP NULL DEFAULT NULL,
  rejected_by INT UNSIGNED NULL,
  rejected_at TIMESTAMP NULL DEFAULT NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  partner_id INT UNSIGNED NULL,
  partner_name VARCHAR(190) NULL,
  receiver_name VARCHAR(190) NULL,
  receiver_phone VARCHAR(80) NULL,
  receiver_email VARCHAR(190) NULL,
  project_no VARCHAR(120) NULL,
  auto_reference TINYINT(1) NOT NULL DEFAULT 0,
  receiver_signature_data LONGTEXT NULL,
  receiver_signature_signed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_st_source (source_warehouse_id),
  KEY idx_st_target (target_warehouse_id),
  KEY idx_st_material (material_id),
  KEY idx_st_status (status),
  KEY idx_st_type (transfer_type),
  KEY idx_st_partner (partner_id),
  KEY idx_st_requested_at (requested_at),
  CONSTRAINT fk_st_source FOREIGN KEY (source_warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_target FOREIGN KEY (target_warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_material FOREIGN KEY (material_id) REFERENCES material_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_partner FOREIGN KEY (partner_id) REFERENCES warehouse_partners (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_transfer_material (transfer_id, material_id),
  KEY idx_ti_transfer (transfer_id),
  KEY idx_ti_material (material_id),
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_material FOREIGN KEY (material_id) REFERENCES material_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

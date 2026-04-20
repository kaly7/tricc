-- Vehicle fuel purchases import + tracking (compatible with existing projectmgr schema)

-- Create tables if missing (use types matching users.id = INT(10) UNSIGNED and vehicles.id = INT(11) signed)

CREATE TABLE IF NOT EXISTS fuel_imports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  uploaded_by INT(10) UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  orig_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NULL,
  file_hash CHAR(64) NOT NULL,
  rows_total INT(11) NOT NULL DEFAULT 0,
  rows_imported INT(11) NOT NULL DEFAULT 0,
  rows_skipped INT(11) NOT NULL DEFAULT 0,
  rows_unmatched INT(11) NOT NULL DEFAULT 0,
  note TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fuel_imports_hash (file_hash),
  KEY idx_fuel_imports_uploaded_by (uploaded_by),
  CONSTRAINT fk_fuel_imports_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vehicle_fuel_entries (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id INT(11) NOT NULL,
  vehicle_id INT(11) NOT NULL,
  fueled_at DATETIME NOT NULL,
  odometer_km INT(10) UNSIGNED NULL,
  fuel_product VARCHAR(120) NULL,
  quantity_l DECIMAL(12,3) NULL,
  unit_price_huf DECIMAL(12,2) NULL,
  gross_huf DECIMAL(12,2) NULL,
  station_name VARCHAR(190) NULL,
  station_id VARCHAR(64) NULL,
  country VARCHAR(64) NULL,
  slip_id VARCHAR(120) NULL,
  invoice_no VARCHAR(120) NULL,
  card_no VARCHAR(64) NULL,
  raw_row_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicle_fuel_entries_row (raw_row_hash),
  KEY idx_vehicle_fuel_entries_vehicle (vehicle_id),
  KEY idx_vehicle_fuel_entries_import (import_id),
  KEY idx_vehicle_fuel_entries_created_by (created_by),
  CONSTRAINT fk_vehicle_fuel_entries_import FOREIGN KEY (import_id) REFERENCES fuel_imports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_vehicle_fuel_entries_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_vehicle_fuel_entries_user FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Compatibility: if earlier versions used rows_ok, keep it (the PHP handles both),
-- but ensure new columns exist for the current importer.

ALTER TABLE fuel_imports
  ADD COLUMN IF NOT EXISTS stored_path VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS file_hash CHAR(64) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS rows_total INT(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rows_imported INT(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rows_skipped INT(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rows_unmatched INT(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS note TEXT NULL;

-- Fix uploaded_by type if needed (safe on MariaDB 10.11+)
-- (If this fails on older MariaDB, you can ignore and keep the PHP fallback.)
ALTER TABLE fuel_imports
  MODIFY COLUMN uploaded_by INT(10) UNSIGNED NOT NULL;

-- üzemanyag import napló
CREATE TABLE IF NOT EXISTS fuel_imports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by INT(10) UNSIGNED NOT NULL,
  orig_name VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  rows_total INT(11) NOT NULL DEFAULT 0,
  rows_ok INT(11) NOT NULL DEFAULT 0,
  rows_skipped INT(11) NOT NULL DEFAULT 0,
  note TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fuel_imports_hash (file_hash),
  KEY idx_fuel_imports_uploaded_by (uploaded_by),
  CONSTRAINT fk_fuel_imports_user
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- tankolások
CREATE TABLE IF NOT EXISTS vehicle_fuel_entries (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id INT(11) NOT NULL,
  vehicle_id INT(11) NOT NULL,                 -- FIGYELEM: vehicles.id SIGNED -> itt is SIGNED
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
  CONSTRAINT fk_vehicle_fuel_entries_import
    FOREIGN KEY (import_id) REFERENCES fuel_imports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_vehicle_fuel_entries_vehicle
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_vehicle_fuel_entries_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
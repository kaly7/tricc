-- Vehicle service module for projectmgr (tab: Szerviz + Költségek + alerts baseline)
-- Run in DB: projectmgr

-- Track last maintenance points directly on vehicle for fast alert calc
ALTER TABLE vehicles
  ADD COLUMN last_oil_km INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN last_oil_date DATE NULL DEFAULT NULL,
  ADD COLUMN last_service_km INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN last_service_date DATE NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS vehicle_service_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  service_date DATE NOT NULL,
  odometer_km INT UNSIGNED NOT NULL,
  reset_oil TINYINT(1) NOT NULL DEFAULT 0,
  reset_service TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  materials TEXT NULL DEFAULT NULL,
  labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  material_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(120) NOT NULL DEFAULT '',
  vendor_address VARCHAR(255) NOT NULL DEFAULT '',
  invoice_no VARCHAR(80) NOT NULL DEFAULT '',
  invoice_path VARCHAR(255) NULL DEFAULT NULL,
  invoice_orig_name VARCHAR(255) NULL DEFAULT NULL,
  invoice_mime VARCHAR(120) NULL DEFAULT NULL,
  invoice_size BIGINT UNSIGNED NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vs_vehicle_date (vehicle_id, service_date),
  CONSTRAINT fk_vs_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

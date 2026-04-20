-- Vehicle divisions + tire storage locations
-- Run in DB: projectmgr

CREATE TABLE IF NOT EXISTS vehicle_divisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vdiv_active (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- vehicles.division_id
ALTER TABLE vehicles
  ADD COLUMN division_id INT NULL DEFAULT NULL AFTER vehicle_type_id,
  ADD INDEX idx_vehicle_division (division_id),
  ADD CONSTRAINT fk_vehicle_division FOREIGN KEY (division_id) REFERENCES vehicle_divisions(id)
    ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS tire_storage_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  details VARCHAR(255) NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tsl_active (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store where removed tires are placed
ALTER TABLE vehicle_tire_installations
  ADD COLUMN removed_storage_location_id INT NULL DEFAULT NULL AFTER removed_km,
  ADD INDEX idx_vti_storage (removed_storage_location_id),
  ADD CONSTRAINT fk_vti_storage FOREIGN KEY (removed_storage_location_id) REFERENCES tire_storage_locations(id)
    ON UPDATE CASCADE ON DELETE SET NULL;

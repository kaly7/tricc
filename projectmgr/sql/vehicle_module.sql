-- Vehicle module tables for projectmgr
-- Run in DB: projectmgr

CREATE TABLE IF NOT EXISTS vehicle_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed common types (safe to run multiple times if you keep names unique manually)
INSERT INTO vehicle_types (name, sort) VALUES
 ('személygépkocsi', 10),
 ('tehergépjármű', 20),
 ('kosaras', 30),
 ('darus', 40),
 ('munkagép', 50),
 ('utánfutó', 60),
 ('pótkocsi', 70);

CREATE TABLE IF NOT EXISTS vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  license_plate VARCHAR(16) NOT NULL,
  make VARCHAR(80) NOT NULL DEFAULT '',
  model VARCHAR(80) NOT NULL DEFAULT '',
  fuel_type ENUM('petrol','diesel','electric','hybrid') NOT NULL DEFAULT 'diesel',
  vehicle_type_id INT NOT NULL,
  axle_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
  odometer_km INT UNSIGNED NOT NULL DEFAULT 0,
  oil_interval_km INT UNSIGNED NOT NULL DEFAULT 15000,
  service_interval_km INT UNSIGNED NULL DEFAULT NULL,
  service_interval_months INT UNSIGNED NULL DEFAULT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vehicle_plate (license_plate),
  CONSTRAINT fk_vehicle_type FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_axles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  axle_no TINYINT UNSIGNED NOT NULL,                 -- 1..5
  wheels_count TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- 2 or 4
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vehicle_axle (vehicle_id, axle_no),
  CONSTRAINT fk_axle_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

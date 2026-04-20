-- Vehicle extra fields + lookup lists (MariaDB/MySQL)
-- Run: mysql -u ppdb -p projectmgr < sql/vehicle_extra_fields.sql

CREATE TABLE IF NOT EXISTS vehicle_euro_classes (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vehicle_body_types (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vehicle_colors (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  hex_code VARCHAR(16) NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vehicle_vignette_types (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add columns (NOTE: if any column already exists, comment that line out and rerun)
ALTER TABLE vehicles
  ADD COLUMN registration_doc_no VARCHAR(64) NULL AFTER license_plate,
  ADD COLUMN tech_valid_until DATE NULL AFTER last_service_date,
  ADD COLUMN euro_class_id INT(11) NULL AFTER tech_valid_until,
  ADD COLUMN body_type_id INT(11) NULL AFTER euro_class_id,
  ADD COLUMN seats TINYINT(3) UNSIGNED NULL AFTER body_type_id,
  ADD COLUMN curb_weight_kg INT(10) UNSIGNED NULL AFTER seats,
  ADD COLUMN gross_weight_kg INT(10) UNSIGNED NULL AFTER curb_weight_kg,
  ADD COLUMN color_id INT(11) NULL AFTER gross_weight_kg,
  ADD COLUMN power_kw INT(10) UNSIGNED NULL AFTER color_id,
  ADD COLUMN manufacture_year SMALLINT(5) UNSIGNED NULL AFTER power_kw,
  ADD COLUMN vignette_type_id INT(11) NULL AFTER manufacture_year,
  ADD COLUMN vignette_valid_until DATE NULL AFTER vignette_type_id,
  ADD COLUMN hugo_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vignette_valid_until,
  ADD COLUMN odometer_recorded_at DATETIME NULL AFTER odometer_km;

ALTER TABLE vehicles
  ADD CONSTRAINT fk_vehicles_euro_class FOREIGN KEY (euro_class_id) REFERENCES vehicle_euro_classes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_vehicles_body_type FOREIGN KEY (body_type_id) REFERENCES vehicle_body_types(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_vehicles_color FOREIGN KEY (color_id) REFERENCES vehicle_colors(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_vehicles_vignette FOREIGN KEY (vignette_type_id) REFERENCES vehicle_vignette_types(id) ON DELETE SET NULL;

-- Seed some defaults
INSERT IGNORE INTO vehicle_body_types (name, sort_order) VALUES ('nyitott', 10), ('zárt', 20);

INSERT IGNORE INTO vehicle_euro_classes (name, sort_order) VALUES
('EURO 0',0),('EURO 1',10),('EURO 2',20),('EURO 3',30),('EURO 4',40),('EURO 5',50),('EURO 6',60),('EURO 6d',70);

INSERT IGNORE INTO vehicle_vignette_types (name, sort_order) VALUES
('D1 (személy)',10),('D2 (teher)',20),('U (utánfutó)',30),('B2 (busz)',40);

INSERT IGNORE INTO vehicle_colors (name, hex_code, sort_order) VALUES
('fehér','#FFFFFF',10),('fekete','#000000',20),('szürke','#808080',30),('piros','#FF0000',40),('kék','#0000FF',50),('zöld','#00AA00',60);

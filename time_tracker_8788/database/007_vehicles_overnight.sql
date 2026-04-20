ALTER TABLE tt_entries
  ADD COLUMN vehicle_id INT UNSIGNED DEFAULT NULL AFTER note,
  ADD COLUMN vehicle_plate VARCHAR(20) DEFAULT NULL AFTER vehicle_id,
  ADD COLUMN travel_km DECIMAL(10,1) DEFAULT NULL AFTER vehicle_plate,
  ADD COLUMN travel_minutes INT NOT NULL DEFAULT 0 AFTER travel_km,
  ADD COLUMN crosses_midnight TINYINT(1) NOT NULL DEFAULT 0 AFTER travel_minutes,
  ADD KEY idx_tt_entries_vehicle (vehicle_id);

CREATE TABLE IF NOT EXISTS tt_vehicles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plate_number VARCHAR(20) NOT NULL,
  label VARCHAR(120) DEFAULT NULL,
  avg_speed_kmh DECIMAL(6,2) NOT NULL DEFAULT 60.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tt_vehicles_plate (plate_number),
  KEY idx_tt_vehicles_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tt_vehicles (plate_number, label, avg_speed_kmh, is_active)
SELECT 'ABC-123', 'Minta jármű', 60.00, 1
WHERE NOT EXISTS (SELECT 1 FROM tt_vehicles WHERE plate_number='ABC-123');

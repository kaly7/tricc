-- Vehicle tires module v1 for projectmgr
-- Run in DB: projectmgr

CREATE TABLE IF NOT EXISTS vehicle_tires (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  tire_kind ENUM('winter','summer','allseason','general') NOT NULL DEFAULT 'general',
  brand VARCHAR(80) NOT NULL DEFAULT '',
  tire_model VARCHAR(120) NOT NULL DEFAULT '',
  tire_size VARCHAR(40) NOT NULL DEFAULT '',
  dot_code VARCHAR(20) NOT NULL DEFAULT '',
  purchased_date DATE NULL DEFAULT NULL,
  purchased_km INT UNSIGNED NULL DEFAULT NULL,
  notes TEXT NULL DEFAULT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vt_vehicle (vehicle_id, is_archived),
  CONSTRAINT fk_vt_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_tire_installations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  axle_no TINYINT UNSIGNED NOT NULL,
  position_no TINYINT UNSIGNED NOT NULL, -- 1..4 depending on axle config
  tire_id INT NOT NULL,
  installed_date DATE NOT NULL,
  installed_km INT UNSIGNED NOT NULL,
  removed_date DATE NULL DEFAULT NULL,
  removed_km INT UNSIGNED NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vti_active (vehicle_id, axle_no, position_no, removed_date),
  INDEX idx_vti_tire (tire_id, removed_date),
  CONSTRAINT fk_vti_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_vti_tire FOREIGN KEY (tire_id) REFERENCES vehicle_tires(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

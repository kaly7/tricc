-- Vehicle issues module (tab 3) for projectmgr
-- Run in DB: projectmgr

CREATE TABLE IF NOT EXISTS vehicle_issues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  reported_date DATE NOT NULL,
  description TEXT NOT NULL,
  fixed_date DATE NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vehicle_issues_vehicle (vehicle_id, reported_date),
  INDEX idx_vehicle_issues_fixed (fixed_date),
  CONSTRAINT fk_vehicle_issues_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

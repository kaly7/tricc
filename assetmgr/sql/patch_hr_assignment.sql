USE assetmgr_db;

ALTER TABLE assets
  ADD COLUMN current_employee_id INT NULL,
  ADD KEY idx_assets_current_employee (current_employee_id);

CREATE TABLE IF NOT EXISTS asset_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  from_employee_id INT NULL,
  to_employee_id INT NULL,
  assigned_by_user_id INT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_asset_assignments_asset (asset_id),
  KEY idx_asset_assignments_to (to_employee_id),
  CONSTRAINT fk_asset_assignments_asset
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

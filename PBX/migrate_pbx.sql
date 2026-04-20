-- PBX Registry migrate: PBX systems + assigned devices (endpoints)
-- Safe to run multiple times (uses IF NOT EXISTS where possible).

CREATE TABLE IF NOT EXISTS pbx_systems (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  location VARCHAR(160) NULL,
  catalog_item_id INT NULL,
  contact_name VARCHAR(120) NULL,
  contact_email VARCHAR(160) NULL,
  contact_phone VARCHAR(60) NULL,
  ip VARCHAR(45) NULL,
  access_url VARCHAR(255) NULL,
  access_user VARCHAR(120) NULL,
  access_pass VARCHAR(255) NULL,
  notes TEXT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pbx_catalog_item FOREIGN KEY (catalog_item_id) REFERENCES catalog_items(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pbx_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pbx_id INT NOT NULL,
  catalog_item_id INT NULL,
  extension VARCHAR(50) NOT NULL,
  ip VARCHAR(45) NULL,
  access_url VARCHAR(255) NULL,
  access_user VARCHAR(120) NULL,
  access_pass VARCHAR(255) NULL,
  notes TEXT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dev_pbx FOREIGN KEY (pbx_id) REFERENCES pbx_systems(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dev_catalog_item FOREIGN KEY (catalog_item_id) REFERENCES catalog_items(id) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_pbx_devices_pbx (pbx_id),
  INDEX idx_pbx_devices_ext (extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Külsős átadás funkció (assetmgr)
USE assetmgr_db;

CREATE TABLE IF NOT EXISTS external_holders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_name VARCHAR(190) NOT NULL,
  contact_name VARCHAR(190) NOT NULL,
  phone VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_external_holders_company (company_name),
  KEY idx_external_holders_contact (contact_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS asset_external_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  external_holder_id INT UNSIGNED NOT NULL,
  courier_ref VARCHAR(190) NOT NULL,
  note TEXT NULL,
  signature_path VARCHAR(255) NULL,
  pdf_path VARCHAR(255) NULL,
  ext_email VARCHAR(255) NULL,
  assigned_by_user_id INT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at DATETIME NULL,
  returned_by_user_id INT NULL,
  return_note TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_aea_asset_status (asset_id, status),
  KEY idx_aea_holder_status (external_holder_id, status),
  CONSTRAINT fk_aea_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_aea_holder FOREIGN KEY (external_holder_id) REFERENCES external_holders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

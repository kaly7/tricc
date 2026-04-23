-- Felülvizsgálat / kalibráció funkció

ALTER TABLE assets ADD COLUMN IF NOT EXISTS inspection_required TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS asset_inspections (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id        INT UNSIGNED NOT NULL,
  inspection_date DATE         NOT NULL,
  next_date       DATE         DEFAULT NULL,
  interval_value  INT          DEFAULT NULL,
  interval_unit   ENUM('day','month','year') DEFAULT NULL,
  note            TEXT         DEFAULT NULL,
  created_by      INT          DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_asset (asset_id),
  KEY idx_ai_next  (next_date),
  CONSTRAINT fk_ai_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_inspection_docs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inspection_id INT UNSIGNED NOT NULL,
  asset_id      INT UNSIGNED NOT NULL,
  file_path     VARCHAR(512) NOT NULL,
  original_name VARCHAR(255) DEFAULT NULL,
  mime_type     VARCHAR(128) DEFAULT NULL,
  file_size     INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aid_inspection (inspection_id),
  KEY idx_aid_asset      (asset_id),
  CONSTRAINT fk_aid_inspection FOREIGN KEY (inspection_id) REFERENCES asset_inspections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

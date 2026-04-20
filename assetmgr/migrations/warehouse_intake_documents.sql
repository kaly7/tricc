CREATE TABLE IF NOT EXISTS warehouse_intake_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  doc_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source_label VARCHAR(255) NULL,
  note TEXT NULL,
  recipient_email VARCHAR(255) NULL,
  pdf_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wint_asset (asset_id),
  KEY idx_wint_wh (warehouse_id),
  KEY idx_wint_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

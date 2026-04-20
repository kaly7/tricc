CREATE TABLE IF NOT EXISTS warehouse_issue_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  to_employee_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  doc_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note TEXT NULL,
  recipient_email VARCHAR(255) NULL,
  pdf_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wid_asset (asset_id),
  KEY idx_wid_wh (warehouse_id),
  KEY idx_wid_emp (to_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

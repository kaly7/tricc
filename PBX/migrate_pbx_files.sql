-- PBX Registry migrate: PBX system file attachments
CREATE TABLE IF NOT EXISTS pbx_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pbx_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NOT NULL DEFAULT '',
  size_bytes BIGINT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pbx_files_pbx FOREIGN KEY (pbx_id) REFERENCES pbx_systems(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pbx_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_pbx_files_pbx (pbx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

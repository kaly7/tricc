-- Files + Activity log schema (hotfix)
USE projectmgr;

-- Re-create project_files with correct NULLable FK column (uploaded_by)
DROP TABLE IF EXISTS project_files;
CREATE TABLE project_files (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  rel_dir VARCHAR(255) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(190) NULL,
  size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  description VARCHAR(500) NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pf_proj_dir (project_id, rel_dir),
  INDEX ix_pf_proj_name (project_id, filename),
  CONSTRAINT fk_pf_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pf_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity log table (unchanged)
CREATE TABLE IF NOT EXISTS project_activity (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  details TEXT NULL,
  ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pa_proj_time (project_id, created_at),
  INDEX ix_pa_proj_action (project_id, action),
  CONSTRAINT fk_pa_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

USE projectmgr;

CREATE TABLE IF NOT EXISTS project_messages (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NULL,         -- NULL => globális üzenőfal
  user_id INT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pm_proj_time (project_id, created_at),
  INDEX ix_pm_parent (parent_id),
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_msg_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_msg_parent FOREIGN KEY (parent_id) REFERENCES project_messages(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

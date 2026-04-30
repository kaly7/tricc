CREATE DATABASE IF NOT EXISTS workplanner_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE workplanner_db;

CREATE TABLE IF NOT EXISTS locations (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL,
  color      CHAR(7)      NOT NULL DEFAULT '#6c757d',
  use_count  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  location_id INT UNSIGNED NULL,
  task_date   DATE         NOT NULL,
  time_from   TIME         NULL,
  time_to     TIME         NULL,
  color       CHAR(7)      NOT NULL DEFAULT '#0d6efd',
  note        TEXT         NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  KEY idx_date (task_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_assignments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id     INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_task_emp (task_id, employee_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50)  NULL,
  entity_id   INT UNSIGNED NULL,
  details_json TEXT        NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS config (
  k VARCHAR(50) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO config (k, v) VALUES ('last_modified', UNIX_TIMESTAMP())
  ON DUPLICATE KEY UPDATE v = VALUES(v);

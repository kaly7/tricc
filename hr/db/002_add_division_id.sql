-- HR migration: add divisions + employees.division_id (if missing)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS divisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_divisions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Add column if it does not exist (MariaDB doesn't support IF NOT EXISTS for columns in all versions),
-- so we do it in a safe way: try/catch manually OR run once and ignore error if already exists.
ALTER TABLE employees
  ADD COLUMN division_id INT UNSIGNED NULL AFTER company_number;

ALTER TABLE employees
  ADD KEY idx_employees_division_id (division_id);

ALTER TABLE employees
  ADD CONSTRAINT fk_employees_division
    FOREIGN KEY (division_id) REFERENCES divisions(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS=1;

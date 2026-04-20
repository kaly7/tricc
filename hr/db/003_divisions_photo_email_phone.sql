-- HR migration v3: divisions as selectable list + employee extras (photo, email, phone)
-- Compatible with existing employees table that has: full_name, company_emp_no, company_division (text), profile_image_path, etc.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- divisions table already exists in your DB; keep CREATE IF NOT EXISTS for safety
CREATE TABLE IF NOT EXISTS divisions (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_divisions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Add division_id / email / phone columns (run once; if it errors "Duplicate column", ignore)
ALTER TABLE employees
  ADD COLUMN division_id INT(10) UNSIGNED NULL AFTER company_division;

ALTER TABLE employees
  ADD COLUMN email VARCHAR(190) NULL AFTER tax_id;

ALTER TABLE employees
  ADD COLUMN phone VARCHAR(60) NULL AFTER email;

-- Index + FK for division_id (if already exists, ignore errors)
ALTER TABLE employees
  ADD KEY idx_employees_division_id (division_id);

ALTER TABLE employees
  ADD CONSTRAINT fk_employees_division
    FOREIGN KEY (division_id) REFERENCES divisions(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS=1;

-- HR - Employees database schema (v1)
-- Target: MariaDB/MySQL (InnoDB), utf8mb4
-- Date: 2026-01-23

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Optional: divisions (céges divíziók)
CREATE TABLE IF NOT EXISTS divisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_divisions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Employees (dolgozói alapadatok + fix mezők)
CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Basic identity
  name VARCHAR(150) NOT NULL,
  birth_name VARCHAR(150) NULL,
  mother_name VARCHAR(150) NULL,
  birth_place VARCHAR(150) NULL,
  birth_date DATE NULL,

  -- IDs
  tax_id VARCHAR(32) NULL,          -- adóazonosító (tárolás plain; megjelenítés maszkban)
  company_number VARCHAR(64) NULL,  -- céges törzsszám

  -- Division link (optional)
  division_id INT UNSIGNED NULL,

  -- Profile picture (stored as relative path under /public/uploads/...)
  profile_photo_path VARCHAR(255) NULL,

  -- Status + audit
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  KEY idx_employees_name (name),
  KEY idx_employees_birth_date (birth_date),
  KEY idx_employees_tax_id (tax_id),
  KEY idx_employees_company_number (company_number),
  KEY idx_employees_division_id (division_id),

  CONSTRAINT fk_employees_division
    FOREIGN KEY (division_id) REFERENCES divisions(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Addresses (one employee can have multiple addresses, but typically 1)
CREATE TABLE IF NOT EXISTS employee_addresses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,

  postal_code VARCHAR(16) NULL,
  city VARCHAR(100) NULL,
  address_line VARCHAR(200) NULL,

  type ENUM('home','temporary','other') NOT NULL DEFAULT 'home',

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_empaddr_employee_id (employee_id),
  CONSTRAINT fk_empaddr_employee
    FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Phone numbers (multiple)
CREATE TABLE IF NOT EXISTS employee_phones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,

  phone VARCHAR(40) NOT NULL,
  type ENUM('mobile','work','home','other') NOT NULL DEFAULT 'mobile',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_empphone_employee_id (employee_id),
  KEY idx_empphone_phone (phone),
  CONSTRAINT fk_empphone_employee
    FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Emails (multiple)
CREATE TABLE IF NOT EXISTS employee_emails (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,

  email VARCHAR(190) NOT NULL,
  type ENUM('personal','work','other') NOT NULL DEFAULT 'personal',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_empemail_employee_id (employee_id),
  UNIQUE KEY uq_empemail_email (email),
  CONSTRAINT fk_empemail_employee
    FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Employee documents (végzettség, jogosultság, stb.)
CREATE TABLE IF NOT EXISTS employee_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,

  doc_type VARCHAR(120) NOT NULL,   -- admin által definiált "típus" (később listából)
  title VARCHAR(200) NULL,          -- opcionális megnevezés
  file_path VARCHAR(255) NOT NULL,  -- relative path under /public/uploads/...
  mime_type VARCHAR(120) NULL,
  file_size INT UNSIGNED NULL,

  issued_on DATE NULL,
  expires_on DATE NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_empdoc_employee_id (employee_id),
  KEY idx_empdoc_doc_type (doc_type),
  KEY idx_empdoc_expires (expires_on),

  CONSTRAINT fk_empdoc_employee
    FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

SET FOREIGN_KEY_CHECKS=1;

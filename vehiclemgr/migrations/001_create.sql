-- vehiclemgr_db - Jármű nyilvántartó modul
-- Futtatás: mysql -u ppdb -pabrakadabra < 001_create.sql

CREATE DATABASE IF NOT EXISTS vehiclemgr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vehiclemgr_db;

-- Ki tartja épp a járművet
CREATE TABLE IF NOT EXISTS vehicle_assignments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id      INT NOT NULL COMMENT 'projectmgr.vehicles.id',
  employee_id     INT NOT NULL COMMENT 'hr.employees.id',
  assigned_by_user_id INT NOT NULL COMMENT 'auth_db.users.id',
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status          ENUM('active','returned') NOT NULL DEFAULT 'active',
  returned_at     DATETIME NULL,
  INDEX idx_vehicle  (vehicle_id),
  INDEX idx_employee (employee_id),
  INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Visszaadás / átadás kérések
CREATE TABLE IF NOT EXISTS vehicle_transfers (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  assignment_id         INT UNSIGNED NOT NULL,
  type                  ENUM('return_to_fleet','transfer_to_employee') NOT NULL,
  from_employee_id      INT NOT NULL COMMENT 'hr.employees.id',
  to_employee_id        INT NULL COMMENT 'hr.employees.id - NULL ha telepre adja',
  status                ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
  initiated_by_user_id  INT NOT NULL COMMENT 'auth_db.users.id',
  initiated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at          DATETIME NULL,
  responded_by_user_id  INT NULL,
  note                  TEXT NULL,
  new_assignment_id     INT UNSIGNED NULL COMMENT 'létrehozott hozzárendelés elfogadáskor',
  INDEX idx_assignment   (assignment_id),
  INDEX idx_from_emp     (from_employee_id),
  INDEX idx_to_emp       (to_employee_id),
  INDEX idx_status       (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin által per-jármű definiált checklist sablonok
CREATE TABLE IF NOT EXISTS checklist_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id  INT NOT NULL COMMENT 'projectmgr.vehicles.id',
  item_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  item_text   VARCHAR(255) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vehicle (vehicle_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kitöltött checklistek
CREATE TABLE IF NOT EXISTS checklist_submissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id    INT NOT NULL,
  employee_id   INT NOT NULL,
  assignment_id INT UNSIGNED NOT NULL,
  type          ENUM('daily','takeover','return','transfer') NOT NULL,
  submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  odometer_km   INT UNSIGNED NULL,
  hour_meter    DECIMAL(10,1) NULL,
  notes         TEXT NULL,
  transfer_id   INT UNSIGNED NULL COMMENT 'kapcsolódó transfer id',
  INDEX idx_vehicle_date (vehicle_id, submitted_at),
  INDEX idx_employee     (employee_id),
  INDEX idx_assignment   (assignment_id),
  INDEX idx_type_date    (type, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tételenkénti válaszok
CREATE TABLE IF NOT EXISTS checklist_answers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  submission_id    INT UNSIGNED NOT NULL,
  template_item_id INT UNSIGNED NOT NULL,
  is_ok            TINYINT(1) NOT NULL DEFAULT 1,
  note             VARCHAR(500) NULL,
  INDEX idx_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Checklist fotók
CREATE TABLE IF NOT EXISTS checklist_photos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  submission_id INT UNSIGNED NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hibajegyek / megjegyzések
CREATE TABLE IF NOT EXISTS vehicle_notes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id            INT NOT NULL,
  employee_id           INT NOT NULL,
  assignment_id         INT UNSIGNED NULL,
  note_text             TEXT NOT NULL,
  status                ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at           DATETIME NULL,
  resolved_by_user_id   INT NULL,
  projectmgr_issue_id   INT NULL COMMENT 'jövőbeli összekötés: projectmgr.vehicle_issues',
  INDEX idx_vehicle (vehicle_id),
  INDEX idx_status  (vehicle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit napló
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(50) NULL,
  entity_id    INT NULL,
  details_json TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user    (user_id),
  INDEX idx_entity  (entity_type, entity_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HR Jogosultságkezelés + Audit napló
-- Futtasd: mysql -u ppdb -p hr < 003_hr_permissions.sql

CREATE TABLE IF NOT EXISTS hr_permissions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  user_name   VARCHAR(190) NOT NULL DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_perm_divisions (
  perm_id     INT UNSIGNED NOT NULL,
  division_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (perm_id, division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_perm_fields (
  perm_id     INT UNSIGNED NOT NULL,
  field_key   VARCHAR(100) NOT NULL,
  PRIMARY KEY (perm_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_perm_extra_fields (
  perm_id        INT UNSIGNED NOT NULL,
  extra_field_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (perm_id, extra_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  user_name   VARCHAR(190) NOT NULL DEFAULT '',
  action      VARCHAR(60)  NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  field_key   VARCHAR(100) DEFAULT NULL,
  old_value   TEXT         DEFAULT NULL,
  new_value   TEXT         DEFAULT NULL,
  detail      TEXT         DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_id     (user_id),
  KEY idx_employee_id (employee_id),
  KEY idx_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

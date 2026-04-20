CREATE TABLE IF NOT EXISTS tt_entry_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  color_class VARCHAR(80) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tt_entry_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  entry_date DATE NOT NULL,
  entry_kind VARCHAR(40) NOT NULL,
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  break_minutes INT NOT NULL DEFAULT 0,
  work_minutes INT NOT NULL DEFAULT 0,
  note TEXT DEFAULT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  group_uid CHAR(36) DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_tt_entries_employee_date (employee_id, entry_date),
  KEY idx_tt_entries_kind (entry_kind),
  KEY idx_tt_entries_group (group_uid),
  KEY idx_tt_entries_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_locks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  locked_by_user_id INT UNSIGNED NOT NULL,
  locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  revoked_by_user_id INT UNSIGNED DEFAULT NULL,
  revoked_reason VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_tt_locks_range (date_from, date_to),
  KEY idx_tt_locks_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NOT NULL,
  target_employee_id INT DEFAULT NULL,
  action_key VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  before_json LONGTEXT DEFAULT NULL,
  after_json LONGTEXT DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tt_audit_created (created_at),
  KEY idx_tt_audit_action (action_key),
  KEY idx_tt_audit_target (target_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tt_day_color_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  minutes_from INT NOT NULL,
  minutes_to INT NOT NULL,
  label VARCHAR(100) DEFAULT NULL,
  bg_color VARCHAR(20) NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tt_day_color_rules_active_sort (is_active, sort_order),
  KEY idx_tt_day_color_rules_range (minutes_from, minutes_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tt_absence_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  badge_text VARCHAR(20) DEFAULT NULL,
  bg_color VARCHAR(20) NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tt_absence_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_day_absences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  absence_type_id INT UNSIGNED NOT NULL,
  absence_date DATE NOT NULL,
  note TEXT DEFAULT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_tt_day_absences_employee_date (employee_id, absence_date),
  KEY idx_tt_day_absences_type (absence_type_id),
  KEY idx_tt_day_absences_deleted (deleted_at),
  CONSTRAINT fk_tt_day_absences_type FOREIGN KEY (absence_type_id) REFERENCES tt_absence_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_holidays (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_date DATE NOT NULL,
  label VARCHAR(120) NOT NULL,
  badge_text VARCHAR(20) DEFAULT NULL,
  bg_color VARCHAR(20) NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tt_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tt_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  template_type ENUM('work','absence') NOT NULL DEFAULT 'work',
  entry_kind VARCHAR(40) DEFAULT NULL,
  absence_type_id INT UNSIGNED DEFAULT NULL,
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  break_minutes INT NOT NULL DEFAULT 0,
  note TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tt_templates_type (template_type, is_active, sort_order),
  CONSTRAINT fk_tt_templates_absence_type FOREIGN KEY (absence_type_id) REFERENCES tt_absence_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

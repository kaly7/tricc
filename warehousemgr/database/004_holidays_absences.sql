
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

INSERT INTO tt_absence_types (code, label, badge_text, bg_color, text_color, sort_order, is_active) VALUES
('leave', 'Szabadság', 'SZAB', '#dcfce7', '#166534', 10, 1),
('sick', 'Betegszabadság', 'BET', '#ffedd5', '#c2410c', 20, 1),
('business_trip', 'Kiküldetés', 'KIK', '#ede9fe', '#6d28d9', 30, 1),
('home_office', 'Home office', 'HO', '#dbeafe', '#1d4ed8', 40, 1),
('excused', 'Igazolt távollét', 'IG', '#fef3c7', '#92400e', 50, 1),
('unpaid_leave', 'Fizetés nélküli szabadság', 'FNSZ', '#e5e7eb', '#374151', 60, 1),
('other_absence', 'Egyéb távollét', 'EGY', '#f3f4f6', '#4b5563', 70, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  badge_text = VALUES(badge_text),
  bg_color = VALUES(bg_color),
  text_color = VALUES(text_color),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

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

INSERT INTO tt_templates (name, template_type, entry_kind, absence_type_id, start_time, end_time, break_minutes, note, sort_order, is_active)
SELECT * FROM (
  SELECT 'Normál munkanap' AS name, 'work' AS template_type, 'work' AS entry_kind, NULL AS absence_type_id, '08:00' AS start_time, '16:30' AS end_time, 30 AS break_minutes, NULL AS note, 10 AS sort_order, 1 AS is_active
  UNION ALL SELECT 'Délelőttös', 'work', 'work', NULL, '06:00', '14:00', 20, NULL, 20, 1
  UNION ALL SELECT 'Délutános', 'work', 'work', NULL, '14:00', '22:00', 20, NULL, 30, 1
  UNION ALL SELECT 'Home office nap', 'work', 'home_office', NULL, '08:00', '16:30', 30, 'Home office', 40, 1
) AS seed_templates
WHERE NOT EXISTS (SELECT 1 FROM tt_templates t WHERE t.name = seed_templates.name);

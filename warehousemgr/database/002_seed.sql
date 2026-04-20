INSERT INTO tt_entry_types (code, label, color_class, sort_order, is_active) VALUES
('work', 'Munkaidő', 'text-bg-primary', 10, 1),
('home_office', 'Home office', 'text-bg-info', 20, 1),
('leave', 'Szabadság', 'text-bg-success', 30, 1),
('sick', 'Betegszabadság', 'text-bg-warning', 40, 1),
('other', 'Egyéb', 'text-bg-secondary', 50, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  color_class = VALUES(color_class),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);


INSERT INTO tt_day_color_rules (minutes_from, minutes_to, label, bg_color, text_color, sort_order, is_active) VALUES
(0, 0, 'Nincs bejegyzés', '#dbeafe', '#1e3a8a', 10, 1),
(1, 239, 'Kevés munkaidő', '#fee2e2', '#991b1b', 20, 1),
(240, 449, 'Részleges nap', '#fef3c7', '#92400e', 30, 1),
(450, 2000, 'Teljes nap', '#dcfce7', '#166534', 40, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  bg_color = VALUES(bg_color),
  text_color = VALUES(text_color),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);


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


INSERT INTO tt_templates (name, template_type, entry_kind, absence_type_id, start_time, end_time, break_minutes, note, sort_order, is_active)
SELECT * FROM (
  SELECT 'Normál munkanap' AS name, 'work' AS template_type, 'work' AS entry_kind, NULL AS absence_type_id, '08:00' AS start_time, '16:30' AS end_time, 30 AS break_minutes, NULL AS note, 10 AS sort_order, 1 AS is_active
  UNION ALL SELECT 'Délelőttös', 'work', 'work', NULL, '06:00', '14:00', 20, NULL, 20, 1
  UNION ALL SELECT 'Délutános', 'work', 'work', NULL, '14:00', '22:00', 20, NULL, 30, 1
  UNION ALL SELECT 'Home office nap', 'work', 'home_office', NULL, '08:00', '16:30', 30, 'Home office', 40, 1
) AS seed_templates
WHERE NOT EXISTS (SELECT 1 FROM tt_templates t WHERE t.name = seed_templates.name);

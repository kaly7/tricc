USE workplanner_db;

-- Feladatbank architektúra: tasks tábla átszervezése
-- task_date, time_from, time_to, location_id kivezetése; status hozzáadása

-- 1) Régi adatok törlése (tiszta újrakezdés)
DELETE FROM task_assignments;
DELETE FROM tasks;

-- 2) tasks tábla módosítása
ALTER TABLE tasks
  ADD COLUMN status ENUM('aktív','passzív','vár','archív') NOT NULL DEFAULT 'aktív' AFTER title;

ALTER TABLE tasks
  DROP FOREIGN KEY tasks_ibfk_1;  -- location_id FK (ha a neve eltér, manuálisan ellenőrizni)

ALTER TABLE tasks
  DROP COLUMN task_date,
  DROP COLUMN time_from,
  DROP COLUMN time_to,
  DROP COLUMN location_id,
  DROP KEY idx_date;

-- 3) task_assignments tábla módosítása
ALTER TABLE task_assignments
  DROP INDEX uq_task_emp;

ALTER TABLE task_assignments
  ADD COLUMN task_date  DATE         NOT NULL DEFAULT '2000-01-01' AFTER employee_id,
  ADD COLUMN created_by INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- DEFAULT csak az ALTER-hez kell, utána eltávolítjuk
ALTER TABLE task_assignments
  ALTER COLUMN task_date DROP DEFAULT;

ALTER TABLE task_assignments
  ADD UNIQUE KEY uniq_assign (task_id, employee_id, task_date),
  ADD KEY idx_date (task_date);

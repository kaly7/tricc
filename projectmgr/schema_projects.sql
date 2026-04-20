-- Project module schema v2
USE projectmgr;

-- Ensure projects table has required columns
ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS archived TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS start_date DATE NULL,
  ADD COLUMN IF NOT EXISTS number VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS code VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS root_dir VARCHAR(255) NULL;

-- Backfill number from code if needed
UPDATE projects SET number = code WHERE number IS NULL AND code IS NOT NULL;

-- Enforce constraints (if possible; ignore errors if already exist)
ALTER TABLE projects
  MODIFY COLUMN number VARCHAR(64) NOT NULL;

-- Unique index on number if not exists
CREATE UNIQUE INDEX IF NOT EXISTS uq_projects_number ON projects(number);

-- Project members
CREATE TABLE IF NOT EXISTS project_members (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('manager','member','viewer') NOT NULL DEFAULT 'member',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member (project_id, user_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Milestones
CREATE TABLE IF NOT EXISTS project_milestones (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  due_date DATE NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- Global dir template
CREATE TABLE IF NOT EXISTS dir_templates (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  path VARCHAR(255) NOT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_path (path)
);

-- Per-project dir overrides
CREATE TABLE IF NOT EXISTS project_dir_templates (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_proj_path (project_id, path),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- Seed default global template if empty
INSERT INTO dir_templates (path, sort)
SELECT * FROM (
  SELECT '01_Dokumentacio' AS path, 10 AS sort UNION ALL
  SELECT '02_Szerzodesek', 20 UNION ALL
  SELECT '03_Tervek_DWG', 30 UNION ALL
  SELECT '04_Rajzok_PDF', 40 UNION ALL
  SELECT '05_Tablazatok_XLS', 50 UNION ALL
  SELECT '06_Kepek', 60 UNION ALL
  SELECT '99_Egyeb', 990
) AS t
WHERE NOT EXISTS (SELECT 1 FROM dir_templates);

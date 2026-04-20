USE payslip;

-- 1) Status enum upgrade
ALTER TABLE page_jobs
  MODIFY status ENUM('PENDING','SAVED','MAILED','NO_MATCH','ERROR') NOT NULL DEFAULT 'PENDING';

-- migrate existing values (if any)
UPDATE page_jobs SET status='SAVED' WHERE status='SENT';
UPDATE page_jobs SET status='ERROR' WHERE status='FAILED';

-- 2) Divisions
CREATE TABLE IF NOT EXISTS divisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE,
  slug VARCHAR(128) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE uploads
  ADD COLUMN division_id INT NULL AFTER month,
  ADD INDEX idx_division_id (division_id);

-- optional FK (kept simple; comment out if you prefer no FK)
-- ALTER TABLE uploads
--   ADD CONSTRAINT fk_uploads_division FOREIGN KEY (division_id) REFERENCES divisions(id);

-- default division if table empty
INSERT INTO divisions(name, slug, active)
SELECT 'Alapértelmezett', 'alapertelemzett', 1
WHERE NOT EXISTS (SELECT 1 FROM divisions);

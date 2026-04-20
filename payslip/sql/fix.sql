USE payslip;

-- 1) Ideiglenesen engedjük meg a régi értékeket is
ALTER TABLE page_jobs
  MODIFY status ENUM('PENDING','SENT','FAILED','NO_MATCH','SAVED','MAILED','ERROR')
  NOT NULL DEFAULT 'PENDING';

-- 2) Régi -> új értékek migrálása
UPDATE page_jobs SET status='SAVED' WHERE status='SENT';
UPDATE page_jobs SET status='ERROR' WHERE status='FAILED';

-- 3) Most már leszűkíthetjük a tiszta új készletre
ALTER TABLE page_jobs
  MODIFY status ENUM('PENDING','SAVED','MAILED','NO_MATCH','ERROR')
  NOT NULL DEFAULT 'PENDING';
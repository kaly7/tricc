-- patch_v10_taxid.sql
-- Adds tax_id (Adóazonosító jel / "Adójel") to employees + page_jobs and indexes.
-- Safe first step: no UNIQUE constraint yet.

ALTER TABLE employees
  ADD COLUMN tax_id VARCHAR(16) NULL AFTER email;

CREATE INDEX idx_employees_tax_id ON employees(tax_id);

ALTER TABLE page_jobs
  ADD COLUMN tax_id VARCHAR(16) NULL AFTER extracted_name;

CREATE INDEX idx_page_jobs_tax_id ON page_jobs(tax_id);

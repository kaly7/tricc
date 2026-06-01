-- patch_hr_id.sql
-- payslip.employees kiegészítése hr_id mezővel (HR szinkron jelzőként)
ALTER TABLE employees
  ADD COLUMN hr_id INT NULL COMMENT 'hr.employees.id; NULL = manuális/unmatched rekord',
  ADD INDEX idx_hr_id (hr_id);

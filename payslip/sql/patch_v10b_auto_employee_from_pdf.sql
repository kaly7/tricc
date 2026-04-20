-- patch_v10b_auto_employee_from_pdf.sql
-- Required for auto-creating employees from PDF when email is unknown.
ALTER TABLE employees
  MODIFY email VARCHAR(255) NULL;

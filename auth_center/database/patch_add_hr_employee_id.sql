-- Add HR employee link to Auth Center users
USE auth_db;

ALTER TABLE users
  ADD COLUMN hr_employee_id INT NULL,
  ADD KEY idx_users_hr_employee (hr_employee_id);

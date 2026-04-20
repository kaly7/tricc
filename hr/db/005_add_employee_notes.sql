SET NAMES utf8mb4;

ALTER TABLE employees
  ADD COLUMN notes TEXT NULL AFTER phone;

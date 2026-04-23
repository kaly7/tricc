-- Migration 012: Belépés és kilépés dátuma
ALTER TABLE employees
  ADD COLUMN hired_on DATE NULL AFTER is_active,
  ADD COLUMN left_on  DATE NULL AFTER hired_on;

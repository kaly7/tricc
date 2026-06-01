-- 013_payslip_email.sql
-- Privát email + bérjegyzék email célpont jelölő

ALTER TABLE employees
  ADD COLUMN email_private VARCHAR(190) NULL AFTER email,
  ADD COLUMN payslip_email_target ENUM('ceges','privat') NOT NULL DEFAULT 'ceges' AFTER email_private;

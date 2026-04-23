-- Migration 011: Bankszámlaszám és bank neve mezők az employees táblához
ALTER TABLE employees
  ADD COLUMN bank_account VARCHAR(34) NULL AFTER company_emp_no,
  ADD COLUMN bank_name    VARCHAR(100) NULL AFTER bank_account;

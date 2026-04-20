-- Add email column to auth_db.users
-- Run: mysql -u ppdb -p auth_db < patch_add_email.sql
ALTER TABLE users
  ADD COLUMN email VARCHAR(190) NULL AFTER username,
  ADD UNIQUE KEY uniq_users_email (email);

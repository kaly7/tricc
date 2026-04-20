-- FK fix for pbx_files when using Auth Center
-- If your FK name differs, run: SHOW CREATE TABLE pbx_files; and drop the actual FK name.

ALTER TABLE pbx_files
  DROP FOREIGN KEY fk_pbx_files_user;

ALTER TABLE pbx_files
  MODIFY uploaded_by INT NULL;

ALTER TABLE pbx_files
  ADD COLUMN uploaded_by_name  VARCHAR(255) NULL AFTER uploaded_by,
  ADD COLUMN uploaded_by_email VARCHAR(255) NULL AFTER uploaded_by_name;

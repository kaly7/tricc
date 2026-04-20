ALTER TABLE catalog_items
  ADD COLUMN default_admin_url  VARCHAR(255) NULL AFTER model,
  ADD COLUMN default_reboot_url VARCHAR(255) NULL AFTER default_admin_url;

-- PBX Registry migrate: device admin/reboot URL suffixes on pbx_devices
-- If you get "Duplicate column" errors, the column already exists and you can ignore.
ALTER TABLE pbx_devices ADD COLUMN admin_url  VARCHAR(255) NULL AFTER access_url;
ALTER TABLE pbx_devices ADD COLUMN reboot_url VARCHAR(255) NULL AFTER admin_url;

USE warehousemgr;

ALTER TABLE `material_identifiers`
  ADD COLUMN IF NOT EXISTS `secondary_identifier_value` VARCHAR(191) DEFAULT NULL AFTER `identifier_value_norm`,
  ADD COLUMN IF NOT EXISTS `secondary_identifier_value_norm` VARCHAR(191) DEFAULT NULL AFTER `secondary_identifier_value`,
  ADD INDEX `idx_mat_ident_secondary_norm` (`secondary_identifier_value_norm`);

ALTER TABLE `material_identifier_staging`
  ADD COLUMN IF NOT EXISTS `secondary_identifier_value` VARCHAR(191) DEFAULT NULL AFTER `identifier_value_norm`,
  ADD COLUMN IF NOT EXISTS `secondary_identifier_value_norm` VARCHAR(191) DEFAULT NULL AFTER `secondary_identifier_value`,
  ADD COLUMN IF NOT EXISTS `scan_mode` ENUM('single','pair') NOT NULL DEFAULT 'single' AFTER `secondary_identifier_value_norm`,
  ADD INDEX `idx_mis_secondary_norm` (`secondary_identifier_value_norm`),
  ADD INDEX `idx_mis_scan_mode` (`scan_mode`);

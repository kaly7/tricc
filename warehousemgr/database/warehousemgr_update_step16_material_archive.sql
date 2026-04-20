USE warehousemgr;

ALTER TABLE `material_items`
  ADD COLUMN IF NOT EXISTS `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD INDEX `idx_material_archived` (`is_archived`);

ALTER TABLE `material_identifiers`
  ADD COLUMN IF NOT EXISTS `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD INDEX `idx_mat_ident_archived` (`is_archived`);

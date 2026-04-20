ALTER TABLE `material_items`
  ADD COLUMN IF NOT EXISTS `is_identified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `identifier_label` VARCHAR(80) DEFAULT NULL AFTER `is_identified`;

CREATE TABLE IF NOT EXISTS `material_identifiers` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `material_id` INT(10) UNSIGNED NOT NULL,
  `warehouse_id` INT(10) UNSIGNED NOT NULL,
  `identifier_value` VARCHAR(191) NOT NULL,
  `identifier_value_norm` VARCHAR(191) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'in_stock',
  `note` TEXT DEFAULT NULL,
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `updated_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_material_identifier_norm` (`material_id`, `identifier_value_norm`),
  KEY `idx_mat_ident_warehouse` (`warehouse_id`),
  KEY `idx_mat_ident_status` (`status`),
  KEY `idx_mat_ident_created` (`created_at`),
  CONSTRAINT `fk_mat_ident_material` FOREIGN KEY (`material_id`) REFERENCES `material_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mat_ident_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

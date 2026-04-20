USE warehousemgr;

CREATE TABLE IF NOT EXISTS stock_transfer_item_identifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_item_id BIGINT UNSIGNED NOT NULL,
  material_identifier_id BIGINT UNSIGNED NOT NULL,
  identifier_value VARCHAR(191) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_transfer_item_identifier (transfer_item_id, material_identifier_id),
  KEY idx_stii_transfer_item (transfer_item_id),
  KEY idx_stii_identifier (material_identifier_id),
  CONSTRAINT fk_stii_transfer_item FOREIGN KEY (transfer_item_id) REFERENCES stock_transfer_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_stii_identifier FOREIGN KEY (material_identifier_id) REFERENCES material_identifiers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

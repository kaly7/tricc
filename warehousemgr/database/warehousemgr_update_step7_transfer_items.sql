USE warehousemgr;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_transfer_material (transfer_id, material_id),
  KEY idx_ti_transfer (transfer_id),
  KEY idx_ti_material (material_id),
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_material FOREIGN KEY (material_id) REFERENCES material_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stock_transfer_items (transfer_id, material_id, quantity)
SELECT tr.id, tr.material_id, tr.quantity
FROM stock_transfers tr
LEFT JOIN stock_transfer_items ti
  ON ti.transfer_id = tr.id AND ti.material_id = tr.material_id
WHERE ti.id IS NULL;

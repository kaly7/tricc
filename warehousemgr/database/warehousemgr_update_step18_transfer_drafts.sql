-- Szállítólevél vázlatok: autosave és parkoló funkció
-- Két új tábla, a meglévő stock_transfers és stock_transfer_items táblákat nem érinti.

CREATE TABLE IF NOT EXISTS transfer_drafts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  draft_type VARCHAR(20) NOT NULL DEFAULT 'autosave',
  transfer_type VARCHAR(20) NOT NULL DEFAULT 'internal',
  user_id INT UNSIGNED NOT NULL,
  draft_label VARCHAR(255) NULL DEFAULT NULL,
  source_warehouse_id INT UNSIGNED NULL DEFAULT NULL,
  target_warehouse_id INT UNSIGNED NULL DEFAULT NULL,
  reference_no VARCHAR(120) NULL DEFAULT NULL,
  note TEXT NULL DEFAULT NULL,
  receiver_name VARCHAR(190) NULL DEFAULT NULL,
  receiver_phone VARCHAR(80) NULL DEFAULT NULL,
  receiver_email VARCHAR(190) NULL DEFAULT NULL,
  project_no VARCHAR(120) NULL DEFAULT NULL,
  draft_meta TEXT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_td_user (user_id),
  KEY idx_td_lookup (user_id, draft_type, transfer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfer_draft_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  draft_id BIGINT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (id),
  KEY idx_tdi_draft (draft_id),
  CONSTRAINT fk_tdi_draft FOREIGN KEY (draft_id) REFERENCES transfer_drafts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

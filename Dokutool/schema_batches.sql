-- Batches for generated documents
CREATE TABLE IF NOT EXISTS batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  project_id INT NULL,
  combined_pdf_path VARCHAR(512) NULL,
  combined_html_path VARCHAR(512) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS batch_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  template_id INT NOT NULL,
  partner_id INT NOT NULL,
  item_pdf_path VARCHAR(512) NULL,
  item_html_path VARCHAR(512) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ok',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(batch_id), INDEX(template_id), INDEX(partner_id),
  CONSTRAINT fk_batch_items_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

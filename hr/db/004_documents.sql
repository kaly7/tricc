-- HR migration v4: document types + employee documents
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS document_types (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS employee_documents (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT(10) UNSIGNED NOT NULL,
  document_type_id INT(10) UNSIGNED NOT NULL,
  title VARCHAR(190) DEFAULT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) DEFAULT NULL,
  mime VARCHAR(120) DEFAULT NULL,
  file_size INT(10) UNSIGNED DEFAULT NULL,
  expires_at DATE DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  KEY idx_empdocs_employee (employee_id),
  KEY idx_empdocs_type (document_type_id),
  KEY idx_empdocs_expires (expires_at),
  CONSTRAINT fk_empdocs_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_empdocs_type FOREIGN KEY (document_type_id) REFERENCES document_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

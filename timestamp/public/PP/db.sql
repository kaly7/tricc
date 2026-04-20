-- Adatbázis séma – STARTER FIX

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS pp_statuses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS cities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pp_status_id INT NOT NULL,
  kiadva DATE NOT NULL,
  hatarido DATE AS (DATE_ADD(kiadva, INTERVAL 38 DAY)) VIRTUAL,
  eventus INT NULL,
  city_id INT NOT NULL,
  irsz VARCHAR(10) NULL,
  utca VARCHAR(200) NULL,
  hazszam VARCHAR(50) NULL,
  elvegzendo VARCHAR(255) NULL,
  korzet VARCHAR(120) NULL,
  leiras TEXT NULL,
  vallalt_hatarido DATE NULL,
  megjegyzes TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_t_status FOREIGN KEY (pp_status_id) REFERENCES pp_statuses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_t_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE RESTRICT,
  INDEX idx_hatarido (hatarido),
  INDEX idx_kiadva (kiadva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(64) PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Alap adatok
INSERT INTO pp_statuses (name, is_active) VALUES
  ('Új',1),('Folyamatban',1),('Kész',1)
ON DUPLICATE KEY UPDATE is_active=VALUES(is_active);

-- admin@example.com / admin123
INSERT INTO users (name, email, password_hash, role, is_active) VALUES
  ('Admin', 'admin@example.com', '$2y$10$ZcXW9wH8oCz7n4OQ0f1ZbO6gA5s6WwQWmQb0o7cXzvX7yOeXw2Z2y', 'admin', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);


-- === PRO kiegészítések ===
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  entity VARCHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip_addr VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity, action),
  INDEX idx_entity_item (entity, entity_id),
  INDEX idx_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS task_field_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  field_name VARCHAR(64) NOT NULL,
  note_text TEXT NULL,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_task_field (task_id, field_name),
  CONSTRAINT fk_tfn_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tfn_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;


CREATE TABLE roles (
  id TINYINT PRIMARY KEY,
  name VARCHAR(32) NOT NULL UNIQUE
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name  VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id TINYINT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE pp_status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  color_hex CHAR(7) NOT NULL DEFAULT '#f0f0f0'
);

CREATE TABLE cities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE
);

CREATE TABLE records (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  eventus VARCHAR(15) NOT NULL,
  pp_status_id INT NOT NULL,
  issued_at DATE NOT NULL,
  due_at DATE NOT NULL,
  city_id INT NOT NULL,
  address VARCHAR(190) NOT NULL,
  operation VARCHAR(120) NOT NULL,
  long_desc TEXT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  deleted_by INT NULL,
  created_by INT NOT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pp_status_id) REFERENCES pp_status(id),
  FOREIGN KEY (city_id) REFERENCES cities(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (deleted_by) REFERENCES users(id)
);

CREATE TABLE record_changes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  record_id BIGINT NOT NULL,
  changed_by INT NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  field VARCHAR(64) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  FOREIGN KEY (record_id) REFERENCES records(id),
  FOREIGN KEY (changed_by) REFERENCES users(id),
  INDEX (record_id, changed_at)
);

CREATE INDEX idx_records_eventus ON records(eventus);
CREATE INDEX idx_records_dates ON records(issued_at, due_at);
CREATE INDEX idx_records_flags ON records(archived, deleted_at);
CREATE INDEX idx_records_status_city ON records(pp_status_id, city_id);

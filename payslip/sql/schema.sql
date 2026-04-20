CREATE DATABASE IF NOT EXISTS payslip CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
CREATE USER IF NOT EXISTS 'ppdb'@'localhost' IDENTIFIED BY 'abrakadabra';
GRANT ALL PRIVILEGES ON payslip.* TO 'ppdb'@'localhost';
FLUSH PRIVILEGES;

USE payslip;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  name_norm VARCHAR(255) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  month CHAR(7) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  total_pages INT NOT NULL DEFAULT 0,
  file_sha256 CHAR(64) NOT NULL,
  uploaded_by VARCHAR(128) NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS page_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  upload_id INT NOT NULL,
  page_no INT NOT NULL,
  extracted_name VARCHAR(255) NULL,
  extracted_name_norm VARCHAR(255) NULL,
  employee_id INT NULL,
  email_to VARCHAR(255) NULL,
  output_path VARCHAR(512) NULL,
  status ENUM('PENDING','SENT','FAILED','NO_MATCH') NOT NULL DEFAULT 'PENDING',
  error_message TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_upload_page (upload_id, page_no),
  INDEX(upload_id),
  INDEX(status),
  INDEX(extracted_name_norm)
);

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level ENUM('INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO',
  action VARCHAR(64) NOT NULL,
  upload_id INT NULL,
  page_job_id BIGINT NULL,
  message VARCHAR(512) NOT NULL,
  context_json JSON NULL,
  INDEX(action),
  INDEX(upload_id),
  INDEX(page_job_id)
);

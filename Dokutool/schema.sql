SET NAMES utf8mb4;

DROP TABLE IF EXISTS partner_field_values;
DROP TABLE IF EXISTS partner_fields;
DROP TABLE IF EXISTS partner_contacts;
DROP TABLE IF EXISTS partners;
DROP TABLE IF EXISTS users;

CREATE TABLE partners (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  megnevezes       VARCHAR(255) NOT NULL,
  cim_irsz         VARCHAR(16),
  cim_telepules    VARCHAR(128),
  cim_utca         VARCHAR(255),
  cim_hazszam      VARCHAR(64),
  cim_egyeb        VARCHAR(255),
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE partner_contacts (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  partner_id       BIGINT UNSIGNED NOT NULL,
  nev              VARCHAR(255) NOT NULL,
  beosztas         VARCHAR(255),
  telefon          VARCHAR(64),
  email            VARCHAR(255),
  is_primary       TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME NULL,
  CONSTRAINT fk_pc_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE partner_fields (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name         VARCHAR(64) NOT NULL UNIQUE,
  label        VARCHAR(255) NOT NULL,
  type         ENUM('text','number','date','email','tel','textarea','select','checkbox') NOT NULL DEFAULT 'text',
  options_json JSON NULL,
  required     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   INT NOT NULL DEFAULT 0,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE partner_field_values (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  partner_id   BIGINT UNSIGNED NOT NULL,
  field_id     BIGINT UNSIGNED NOT NULL,
  value_text   TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_partner_field (partner_id, field_id),
  CONSTRAINT fk_pfv_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfv_field FOREIGN KEY (field_id) REFERENCES partner_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE users (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  username         VARCHAR(64) NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NOT NULL,
  email            VARCHAR(255) NOT NULL,
  is_admin         TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

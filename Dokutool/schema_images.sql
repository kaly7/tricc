SET NAMES utf8mb4;

DROP TABLE IF EXISTS images;
CREATE TABLE images (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `key`          VARCHAR(64) NOT NULL UNIQUE,   -- hivatkozási azonosító: pl. 'logo', 'pecset'
  title          VARCHAR(255) NULL,
  alt_text       VARCHAR(255) NULL,
  tags           VARCHAR(255) NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_name    VARCHAR(255) NOT NULL,         -- tényleges fájlnév a tárhelyen
  mime_type      VARCHAR(64) NOT NULL,
  file_size      INT UNSIGNED NOT NULL,
  width          INT NULL,
  height         INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

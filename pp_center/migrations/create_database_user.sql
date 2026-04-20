CREATE DATABASE IF NOT EXISTS pp_center CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'ppdb'@'localhost' IDENTIFIED BY 'abrakadabra';
GRANT ALL PRIVILEGES ON pp_center.* TO 'ppdb'@'localhost';
FLUSH PRIVILEGES;

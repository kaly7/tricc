-- ============================================================
-- Pannon Mechanika – Robot DB teljes séma + migráció
-- Utolsó frissítés: 2026-05-03
-- Futtatás: mysql -u robot -p Robot < migrate_robot_db.sql
--
-- BIZTONSÁGOS: meglévő adatok nem törlődnek.
--   • CREATE TABLE IF NOT EXISTS  → csak akkor hoz létre, ha nincs meg
--   • ALTER TABLE ADD COLUMN IF NOT EXISTS → csak a hiányzó oszlopokat adja hozzá
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- ------------------------------------------------------------
-- Buttons
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Buttons` (
  `Index_`      bigint(20)  NOT NULL AUTO_INCREMENT,
  `Button_name` varchar(40) DEFAULT NULL,
  `Megjegyzes`  varchar(20) DEFAULT NULL,
  `Megjegyzes2` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Goals
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Goals` (
  `Index_`     bigint(20)  NOT NULL AUTO_INCREMENT,
  `Goal_name`  varchar(30) DEFAULT NULL,
  `Active`     varchar(3)  DEFAULT NULL,
  `Megjegyzes` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Route
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Route` (
  `Index_`     bigint(20)   NOT NULL AUTO_INCREMENT,
  `Megnevezes` varchar(100) NOT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Route_adatok
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Route_adatok` (
  `Index_`      bigint(20) NOT NULL AUTO_INCREMENT,
  `Route_index` int(11)    NOT NULL,
  `Goal_index`  int(11)    NOT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Robots
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Robots` (
  `Index_`      bigint(20)  NOT NULL AUTO_INCREMENT,
  `Robot_name`  varchar(30) DEFAULT NULL,
  `Megjegyzes`  varchar(30) DEFAULT NULL,
  `Active`      varchar(3)  DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Felhasznalok
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Felhasznalok` (
  `Index_`     bigint(20)  NOT NULL AUTO_INCREMENT,
  `nev`        varchar(30) DEFAULT NULL,
  `admin`      varchar(10) DEFAULT NULL,
  `jogok`      varchar(10) DEFAULT NULL,
  `goal_name`  bigint(20)  DEFAULT NULL,
  `funkcio`    varchar(7)  DEFAULT NULL,
  `jelszo`     varchar(20) DEFAULT NULL,
  `ip`         varchar(20) DEFAULT NULL,
  `last_login` timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Felhasznalo_goal_eleje
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Felhasznalo_goal_eleje` (
  `Index_`            bigint(20)  NOT NULL AUTO_INCREMENT,
  `Felhasznalo_index` bigint(20)  DEFAULT NULL,
  `Goal_index`        bigint(20)  DEFAULT NULL,
  `Akcio`             varchar(10) DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Felhasznalo_goal_vege
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Felhasznalo_goal_vege` (
  `Index_`            bigint(20)  NOT NULL AUTO_INCREMENT,
  `Felhasznalo_index` bigint(20)  DEFAULT NULL,
  `Goal_index`        bigint(20)  DEFAULT NULL,
  `Akcio`             varchar(10) DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Button_Goals
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Button_Goals` (
  `Index_`        bigint(20)  NOT NULL AUTO_INCREMENT,
  `Button_index`  bigint(20)  DEFAULT NULL,
  `Goal_name`     varchar(30) DEFAULT NULL,
  `Megjegyzes`    varchar(50) DEFAULT NULL,
  `prioritas`     varchar(10) DEFAULT NULL,
  `akcio`         varchar(10) DEFAULT NULL,
  PRIMARY KEY (`Index_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- akcio oszlop régebbi telepítéseknél hiányozhat:
ALTER TABLE `Button_Goals`
  ADD COLUMN IF NOT EXISTS `akcio` varchar(10) DEFAULT NULL AFTER `prioritas`;

-- ------------------------------------------------------------
-- records  (ismétlődő ütemezések)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `records` (
  `id`            int(11)     NOT NULL AUTO_INCREMENT,
  `Route_id`      int(11)     NOT NULL,
  `days`          varchar(50) NOT NULL,
  `time`          time        NOT NULL,
  `active`        tinyint(1)  NOT NULL,
  `inactiveUntil` tinyint(1)  NOT NULL,
  `inactiveDate`  date        DEFAULT NULL,
  `skipNext`      tinyint(1)  NOT NULL,
  `onceOnly`      tinyint(1)  NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- nap_tipusok  (ünnepnap / munkaszüneti nap nyilvántartás)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nap_tipusok` (
  `id`    int(11) NOT NULL AUTO_INCREMENT,
  `datum` date    NOT NULL,
  `tipus` enum('Munkanap','Ünnepnap','Munkaszüneti nap') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `datum` (`datum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- days  (naptár napok típusai – alternatív tábla)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `days` (
  `id`          int(11)                                    NOT NULL AUTO_INCREMENT,
  `date`        date                                       NOT NULL,
  `day_type`    enum('workday','holiday','non-working')    NOT NULL,
  `description` varchar(255)                               DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- kozbenso_goal  (pont-pont közbülső megálló)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kozbenso_goal` (
  `id`         int(11)    NOT NULL AUTO_INCREMENT,
  `goal_index` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- egyedi_utemezesek  (pont-pont időzített indítások)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `egyedi_utemezesek` (
  `id`                  int(11)     NOT NULL AUTO_INCREMENT,
  `indulo_goal_index`   bigint(20)  NOT NULL,
  `kozbenso_goal_index` bigint(20)  NOT NULL,
  `cel_goal_index`      bigint(20)  NOT NULL,
  `idopont`             datetime    NOT NULL,
  `active`              tinyint(1)  NOT NULL DEFAULT 1,
  `letrehozva`          timestamp   NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Régebbi telepítéseknél hiányozhatnak az alábbi oszlopok:
ALTER TABLE `egyedi_utemezesek`
  ADD COLUMN IF NOT EXISTS `indulo_goal_index`   bigint(20)  NOT NULL DEFAULT 0   AFTER `id`,
  ADD COLUMN IF NOT EXISTS `kozbenso_goal_index` bigint(20)  NOT NULL DEFAULT 0   AFTER `indulo_goal_index`,
  ADD COLUMN IF NOT EXISTS `active`              tinyint(1)  NOT NULL DEFAULT 1   AFTER `idopont`,
  ADD COLUMN IF NOT EXISTS `letrehozva`          timestamp   NULL DEFAULT current_timestamp() AFTER `active`;

-- ------------------------------------------------------------
-- munkaallomas  (robot-ide funkció: IP-alapú munkaállomások)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `munkaallomas` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `ip`                varchar(20)  NOT NULL,
  `nev`               varchar(50)  NOT NULL,
  `cel_goal_index`    bigint(20)   NOT NULL,
  `vissza_goal_index` bigint(20)   NOT NULL DEFAULT 0,
  `allapot`           varchar(10)  NOT NULL DEFAULT 'szabad',
  `aktiv_job_id`      varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Régebbi telepítéseknél hiányozhatnak az alábbi oszlopok:
ALTER TABLE `munkaallomas`
  ADD COLUMN IF NOT EXISTS `aktiv_job_id` varchar(100) DEFAULT NULL AFTER `allapot`;

ALTER TABLE `munkaallomas`
  MODIFY COLUMN `vissza_goal_index` bigint(20) NOT NULL DEFAULT 0;

-- Régi 'ide'/'vissza' állapotok átnevezése 'szabad'-ra:
UPDATE `munkaallomas` SET allapot='szabad' WHERE allapot IN ('ide', 'vissza');

-- ------------------------------------------------------------
-- munkaallomas – közbenső cél + job lista láthatóság beállítás
-- ------------------------------------------------------------
ALTER TABLE `munkaallomas`
  ADD COLUMN IF NOT EXISTS `kozbenso_goal_index` bigint(20) NOT NULL DEFAULT 0 AFTER `vissza_goal_index`,
  ADD COLUMN IF NOT EXISTS `job_lathatosag` enum('semmi','sajat','osszes') NOT NULL DEFAULT 'sajat' AFTER `kozbenso_goal_index`;

-- ------------------------------------------------------------
-- pm_konfig  (globális kulcs-érték beállítások)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pm_konfig` (
  `kulcs`  varchar(50)  NOT NULL,
  `ertek`  varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`kulcs`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `pm_konfig` (`kulcs`, `ertek`) VALUES ('pp_job_lathatosag', 'sajat');

-- ------------------------------------------------------------
SET foreign_key_checks = 1;
-- ============================================================
-- Ellenőrzés (opcionális):
--   SHOW TABLES;
--   SHOW COLUMNS FROM munkaallomas;
--   SHOW COLUMNS FROM egyedi_utemezesek;
--   SHOW COLUMNS FROM Button_Goals;
-- ============================================================

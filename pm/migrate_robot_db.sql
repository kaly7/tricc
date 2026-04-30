-- ============================================================
-- Pannon Mechanika – Robot DB migráció
-- Fejlesztve: 2026-04 (pont-pont útvonal, Robot ide/Vissza,
--             munkaállomás kezelés, közbülső goal beállítás)
-- Futtatás: mysql -u robot -p Robot < migrate_robot_db.sql
-- BIZTONSÁGOS: IF NOT EXISTS / IF NOT EXISTS védelem mindenütt
-- ============================================================

-- ------------------------------------------------------------
-- 1. munkaallomas tábla (új)
--    Munkaállomások IP/név + cél és vissza goal hozzárendelés
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `munkaallomas` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `ip`                varchar(20)  NOT NULL,
  `nev`               varchar(50)  NOT NULL,
  `cel_goal_index`    bigint(20)   NOT NULL,
  `vissza_goal_index` bigint(20)   NOT NULL,
  `allapot`           varchar(10)  NOT NULL DEFAULT 'vissza',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 2. kozbenso_goal tábla (új)
--    Pont-pont útvonalnál a közbülső megálló goal tárolja
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kozbenso_goal` (
  `id`          int(11)    NOT NULL AUTO_INCREMENT,
  `goal_index`  bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 3. egyedi_utemezesek tábla – új oszlopok
--    Ha a tábla már létezett, az alábbi oszlopok kerülnek hozzá.
--    Ha nem létezett, a teljes CREATE TABLE fut le.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `egyedi_utemezesek` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `indulo_goal_index`   bigint(20)   NOT NULL,
  `kozbenso_goal_index` bigint(20)   NOT NULL,
  `cel_goal_index`      bigint(20)   NOT NULL,
  `idopont`             datetime     NOT NULL,
  `active`              tinyint(1)   NOT NULL DEFAULT 1,
  `letrehozva`          timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ha a tábla már volt, de hiányoznak az új oszlopok:
ALTER TABLE `egyedi_utemezesek`
  ADD COLUMN IF NOT EXISTS `indulo_goal_index`   bigint(20)  NOT NULL DEFAULT 0     AFTER `id`,
  ADD COLUMN IF NOT EXISTS `kozbenso_goal_index` bigint(20)  NOT NULL DEFAULT 0     AFTER `indulo_goal_index`,
  ADD COLUMN IF NOT EXISTS `active`              tinyint(1)  NOT NULL DEFAULT 1     AFTER `idopont`,
  ADD COLUMN IF NOT EXISTS `letrehozva`          timestamp   NULL DEFAULT current_timestamp() AFTER `active`;

-- ------------------------------------------------------------
-- 4. Button_Goals tábla – akcio oszlop (új)
--    A pont-pont és robot-ide funkciókhoz szükséges
-- ------------------------------------------------------------
ALTER TABLE `Button_Goals`
  ADD COLUMN IF NOT EXISTS `akcio` varchar(10) DEFAULT NULL AFTER `prioritas`;

-- ------------------------------------------------------------
-- 5. Robot ide újratervezés (2026-04-24)
--    Csak egy cél goal, job_id tracking, nincs Vissza gomb
-- ------------------------------------------------------------

-- Aktív job_id tárolásához új oszlop
ALTER TABLE `munkaallomas`
  ADD COLUMN IF NOT EXISTS `aktiv_job_id` varchar(100) DEFAULT NULL AFTER `allapot`;

-- vissza_goal_index legyen opcionális (DEFAULT 0)
ALTER TABLE `munkaallomas`
  MODIFY COLUMN `vissza_goal_index` bigint(20) NOT NULL DEFAULT 0;

-- Meglévő állapotok migrálása → új 'szabad' állapot
UPDATE `munkaallomas` SET allapot='szabad' WHERE allapot IN ('vissza', 'ide');

-- ------------------------------------------------------------
-- Ellenőrzés (opcionális – lefuttatás után megnézhető)
-- ------------------------------------------------------------
-- SHOW COLUMNS FROM munkaallomas;
-- SHOW COLUMNS FROM kozbenso_goal;
-- SHOW COLUMNS FROM egyedi_utemezesek;
-- SHOW COLUMNS FROM Button_Goals;

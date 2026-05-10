-- fitnessmgr_db séma + seed adatok
-- 1. lépés (root szükséges): sudo mysql -e "CREATE DATABASE IF NOT EXISTS fitnessmgr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci; GRANT ALL PRIVILEGES ON fitnessmgr_db.* TO 'ppdb'@'localhost'; FLUSH PRIVILEGES;"
-- 2. lépés: mysql -uppdb -pabrakadabra fitnessmgr_db < /var/www/html/fitnessmgr/database/fitnessmgr_create.sql

-- =============================================
-- Élelmiszer adatbázis
-- =============================================
CREATE TABLE IF NOT EXISTS food_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(200) NOT NULL,
  name_normalized     VARCHAR(200) NOT NULL,          -- kisbetűs, ékezetek nélkül kereséshez
  category            VARCHAR(60)  NOT NULL DEFAULT 'egyéb',
  calories_per_100g   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  protein_g           DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  carbs_g             DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  fat_g               DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  fiber_g             DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  is_custom           TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = felhasználó saját bejegyzése
  created_by_user_id  INT          NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name_normalized),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Étkezési napló
-- =============================================
CREATE TABLE IF NOT EXISTS food_entries (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT          NOT NULL DEFAULT 1,
  food_item_id      INT UNSIGNED NULL,
  custom_food_name  VARCHAR(200) NULL,
  amount_g          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  calories          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  protein_g         DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  carbs_g           DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  fat_g             DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  meal_type         ENUM('reggeli','tizorai','ebed','uzsonna','vacsora') NOT NULL DEFAULT 'ebed',
  eaten_at          DATE         NOT NULL,
  notes             VARCHAR(500) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_date (user_id, eaten_at),
  INDEX idx_food_item (food_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Mozgás / edzés típusok
-- =============================================
CREATE TABLE IF NOT EXISTS exercise_types (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(200) NOT NULL,
  category        ENUM('kardio','erő','rugalmasság','sport','egyéb') NOT NULL DEFAULT 'egyéb',
  calories_per_hour SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  met_value       DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  is_custom       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Edzésnapló
-- =============================================
CREATE TABLE IF NOT EXISTS exercise_entries (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id               INT          NOT NULL DEFAULT 1,
  exercise_type_id      INT UNSIGNED NULL,
  custom_exercise_name  VARCHAR(200) NULL,
  duration_min          SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  calories_burned       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  done_at               DATE         NOT NULL,
  notes                 VARCHAR(500) NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_date (user_id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Súlynapló
-- =============================================
CREATE TABLE IF NOT EXISTS weight_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL DEFAULT 1,
  weight_kg    DECIMAL(5,2) NOT NULL,
  bmi          DECIMAL(4,2) NULL,
  measured_at  DATE         NOT NULL,
  notes        VARCHAR(300) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_date (user_id, measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Felhasználói profil
-- =============================================
CREATE TABLE IF NOT EXISTS user_profile (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT          NOT NULL DEFAULT 1 UNIQUE,
  height_cm           SMALLINT UNSIGNED NULL,
  birth_year          SMALLINT UNSIGNED NULL,
  gender              ENUM('férfi','nő','egyéb') NOT NULL DEFAULT 'férfi',
  activity_level      ENUM('ülő','könnyű','mérsékelt','aktív','nagyon aktív') NOT NULL DEFAULT 'mérsékelt',
  target_weight_kg    DECIMAL(5,2) NULL,
  daily_calorie_goal  SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
  protein_goal_g      SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  carbs_goal_g        SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  fat_goal_g          SMALLINT UNSIGNED NOT NULL DEFAULT 65,
  water_goal_ml       SMALLINT UNSIGNED NOT NULL DEFAULT 2500,
  exercise_goal_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  mattermost_username VARCHAR(100) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- Mattermost interakció log
-- =============================================
CREATE TABLE IF NOT EXISTS mm_interactions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL DEFAULT 1,
  message_type  ENUM('checkin','reminder','summary','slash','ai') NOT NULL,
  content       TEXT         NOT NULL,
  sent_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_sent (user_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- =============================================
-- AI javaslatok
-- =============================================
CREATE TABLE IF NOT EXISTS ai_suggestions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT          NOT NULL DEFAULT 1,
  suggestion_type   ENUM('kaloria_becsles','napi_ertekelés','edzésterv','motiváció','recept') NOT NULL,
  prompt_summary    VARCHAR(300) NOT NULL,
  content           TEXT         NOT NULL,
  tokens_used       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at   DATETIME     NULL,
  INDEX idx_user_type (user_id, suggestion_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- SEED: Élelmiszer adatbázis (~120 leggyakoribb magyar étel)
-- calories_per_100g | protein_g | carbs_g | fat_g | fiber_g
-- ============================================================
INSERT INTO food_items (name, name_normalized, category, calories_per_100g, protein_g, carbs_g, fat_g, fiber_g) VALUES

-- HÚSOK
('Csirkemell (főtt, bőr nélkül)', 'csirkemell fott bor nelkul', 'hús', 165, 31.0, 0.0, 3.6, 0.0),
('Csirkecomb (sült)', 'csirkecomb sult', 'hús', 215, 23.0, 0.0, 13.0, 0.0),
('Csirkeszárny (sült)', 'csirkeszarny sult', 'hús', 290, 27.0, 0.0, 19.0, 0.0),
('Sertéskaraj (sült)', 'serteskaraj sult', 'hús', 185, 26.0, 0.0, 9.0, 0.0),
('Sertésszűz (sült)', 'sertesszuz sult', 'hús', 175, 28.0, 0.0, 6.0, 0.0),
('Darált sertés', 'daralt sertes', 'hús', 250, 17.0, 0.0, 20.0, 0.0),
('Marharostélyos (sült)', 'marharostelyos sult', 'hús', 270, 26.0, 0.0, 18.0, 0.0),
('Marhahús (darált)', 'marhahus daralt', 'hús', 215, 19.0, 0.0, 15.0, 0.0),
('Bacon (sütve)', 'bacon sutve', 'hús', 540, 37.0, 1.0, 42.0, 0.0),
('Párizsi', 'parizsi', 'hús', 250, 12.0, 2.0, 21.0, 0.0),
('Kolbász (füstölt)', 'kolbasz fustolt', 'hús', 350, 14.0, 2.0, 31.0, 0.0),
('Virsli', 'virsli', 'hús', 290, 11.0, 3.0, 26.0, 0.0),
('Sonka (főtt)', 'sonka fott', 'hús', 130, 18.0, 1.0, 6.0, 0.0),
('Lazac (sütve)', 'lazac sutve', 'hal', 208, 20.0, 0.0, 13.0, 0.0),
('Tőkehal (sütve)', 'tokehal sutve', 'hal', 105, 23.0, 0.0, 1.0, 0.0),
('Tonhal (konzerv, olajban)', 'tonhal konzervben', 'hal', 195, 29.0, 0.0, 9.0, 0.0),
('Tonhal (konzerv, saját lében)', 'tonhal sajat leben', 'hal', 116, 25.5, 0.0, 0.8, 0.0),
('Makréla (füstölt)', 'makrela fustolt', 'hal', 305, 19.0, 0.0, 25.0, 0.0),
('Rántott csirkemell', 'rantott csirkemell', 'hús', 230, 22.0, 10.0, 11.0, 0.5),
('Rántott sertésszelet', 'rantott sertesszelet', 'hús', 270, 20.0, 12.0, 16.0, 0.5),

-- TOJÁS
('Tojás (főtt)', 'tojas fott', 'tojás', 155, 13.0, 1.1, 11.0, 0.0),
('Tojás (rántotta, olajjal)', 'tojas rantotta', 'tojás', 195, 12.0, 1.0, 16.0, 0.0),
('Tojás (sült tükörtojás)', 'tojas tukortoja', 'tojás', 185, 12.0, 0.5, 15.0, 0.0),
('Tojásfehérje (főtt)', 'tojasfeherje fott', 'tojás', 52, 11.0, 0.7, 0.2, 0.0),

-- TEJTERMÉKEK
('Tej (2,8%)', 'tej', 'tejtermék', 49, 3.2, 5.0, 2.0, 0.0),
('Tej (sovány, 1,5%)', 'tej sovany', 'tejtermék', 43, 3.4, 5.0, 1.5, 0.0),
('Kefir', 'kefir', 'tejtermék', 52, 3.5, 4.5, 2.5, 0.0),
('Joghurt (natúr 3%)', 'joghurt natur', 'tejtermék', 60, 4.0, 5.5, 3.0, 0.0),
('Joghurt (0%)', 'joghurt sovany', 'tejtermék', 40, 4.2, 5.5, 0.2, 0.0),
('Tejföl (20%)', 'tejfol', 'tejtermék', 195, 3.0, 3.5, 20.0, 0.0),
('Tejföl (12%)', 'tejfol 12', 'tejtermék', 120, 3.0, 3.5, 12.0, 0.0),
('Túró (sovány)', 'turo sovany', 'tejtermék', 85, 14.0, 3.5, 1.0, 0.0),
('Túró (zsíros)', 'turo zsiros', 'tejtermék', 140, 11.0, 3.5, 9.0, 0.0),
('Trappista sajt', 'trappista sajt', 'tejtermék', 360, 24.0, 0.5, 29.0, 0.0),
('Edami sajt', 'edami sajt', 'tejtermék', 340, 25.0, 1.5, 26.0, 0.0),
('Mozzarella', 'mozzarella', 'tejtermék', 280, 18.0, 2.0, 22.0, 0.0),
('Feta sajt', 'feta sajt', 'tejtermék', 264, 14.0, 4.0, 21.0, 0.0),
('Vaj', 'vaj', 'tejtermék', 717, 0.9, 0.1, 81.0, 0.0),
('Margarin', 'margarin', 'tejtermék', 540, 0.5, 0.5, 60.0, 0.0),
('Tejszín (30%)', 'tejszin', 'tejtermék', 285, 2.0, 3.5, 30.0, 0.0),

-- KENYÉR, PÉKÁRUK
('Fehér kenyér', 'feher kenyer', 'pékáru', 265, 8.0, 50.0, 3.0, 2.5),
('Barna kenyér (teljes kiőrlésű)', 'barna kenyer', 'pékáru', 250, 9.0, 46.0, 3.0, 6.5),
('Rozskenyér', 'rozskenyér', 'pékáru', 258, 8.5, 48.0, 3.3, 6.0),
('Zsemle', 'zsemle', 'pékáru', 275, 9.0, 52.0, 4.0, 2.5),
('Kifli', 'kifli', 'pékáru', 310, 9.0, 55.0, 7.0, 2.0),
('Bagel', 'bagel', 'pékáru', 270, 10.0, 53.0, 1.5, 2.0),
('Pita kenyér', 'pita', 'pékáru', 275, 9.0, 56.0, 1.2, 2.5),
('Lángos (sima)', 'langos sima', 'pékáru', 280, 7.0, 45.0, 9.0, 2.0),
('Lángos (tejföl, sajt)', 'langos tejfol sajt', 'pékáru', 360, 10.0, 46.0, 16.0, 2.0),

-- GABONA, TÉSZTA, RIZS
('Fehér rizs (főtt)', 'feher rizs fott', 'gabona', 130, 2.7, 28.0, 0.3, 0.4),
('Barna rizs (főtt)', 'barna rizs fott', 'gabona', 123, 2.7, 26.0, 1.0, 1.8),
('Tészta (főtt)', 'teszta fott', 'gabona', 131, 5.0, 25.0, 1.1, 1.8),
('Teljes kiőrlésű tészta (főtt)', 'teljeskioreslu teszta', 'gabona', 124, 5.5, 23.0, 1.0, 4.0),
('Zabpehely', 'zabpehely', 'gabona', 379, 13.0, 67.0, 7.0, 10.0),
('Muesli (cukrozatlan)', 'muesli cukrozatlan', 'gabona', 360, 9.0, 66.0, 6.0, 7.0),
('Corn flakes', 'corn flakes', 'gabona', 372, 7.0, 84.0, 0.8, 2.0),
('Burgonya (főtt)', 'burgonya fott', 'gabona', 77, 2.0, 17.0, 0.1, 1.8),
('Burgonya (sütőben sült)', 'burgonya sutve', 'gabona', 93, 2.5, 21.0, 0.1, 2.2),
('Sültkrumpli (olajban)', 'sultzkrumpli', 'gabona', 312, 3.4, 41.0, 15.0, 3.5),
('Chips', 'chips', 'gabona', 536, 7.0, 53.0, 35.0, 4.8),

-- ZÖLDSÉGEK
('Paradicsom', 'paradicsom', 'zöldség', 18, 0.9, 3.9, 0.2, 1.2),
('Uborka', 'uborka', 'zöldség', 12, 0.6, 2.2, 0.1, 0.5),
('Paprika (piros)', 'paprika piros', 'zöldség', 26, 1.0, 5.0, 0.3, 2.1),
('Paprika (zöld)', 'paprika zold', 'zöldség', 20, 0.9, 3.7, 0.2, 1.8),
('Sárgarépa', 'sargarepa', 'zöldség', 35, 0.9, 7.7, 0.2, 2.8),
('Hagyma', 'hagyma', 'zöldség', 40, 1.1, 9.3, 0.1, 1.7),
('Fokhagyma', 'fokhagyma', 'zöldség', 149, 6.4, 33.0, 0.5, 2.1),
('Fejes saláta', 'fejes salata', 'zöldség', 14, 1.4, 2.1, 0.2, 1.3),
('Spenót (friss)', 'spinot friss', 'zöldség', 23, 2.9, 3.6, 0.4, 2.2),
('Brokkoli', 'brokkoli', 'zöldség', 34, 2.8, 6.6, 0.4, 2.6),
('Karfiol', 'karfiol', 'zöldség', 25, 2.0, 5.0, 0.3, 2.0),
('Káposzta (fejes)', 'kaposzta fejes', 'zöldség', 25, 1.3, 5.8, 0.1, 2.5),
('Kelkáposzta', 'kelkaposzta', 'zöldség', 28, 2.0, 5.4, 0.4, 2.0),
('Kukorica (főtt)', 'kukorica fott', 'zöldség', 86, 3.2, 18.7, 1.2, 2.0),
('Zöldborsó (főtt)', 'zoldborsó fott', 'zöldség', 84, 5.4, 15.6, 0.4, 5.7),
('Bab (főtt)', 'bab fott', 'zöldség', 127, 8.7, 22.8, 0.5, 6.4),
('Csicseriborsó (főtt)', 'csicseriborsó', 'zöldség', 164, 8.9, 27.4, 2.6, 7.6),
('Lencse (főtt)', 'lencse fott', 'zöldség', 116, 9.0, 20.1, 0.4, 7.9),
('Avokádó', 'avokado', 'zöldség', 160, 2.0, 9.0, 15.0, 7.0),
('Gomba', 'gomba', 'zöldség', 22, 3.1, 3.3, 0.3, 1.0),

-- GYÜMÖLCSÖK
('Alma', 'alma', 'gyümölcs', 52, 0.3, 14.0, 0.2, 2.4),
('Körte', 'korte', 'gyümölcs', 57, 0.4, 15.0, 0.1, 3.1),
('Banán', 'banan', 'gyümölcs', 89, 1.1, 23.0, 0.3, 2.6),
('Narancs', 'narancs', 'gyümölcs', 47, 0.9, 12.0, 0.1, 2.4),
('Mandarin', 'mandarin', 'gyümölcs', 53, 0.8, 13.3, 0.3, 1.8),
('Citrom', 'citrom', 'gyümölcs', 29, 1.1, 9.3, 0.3, 2.8),
('Szőlő', 'szolo', 'gyümölcs', 62, 0.6, 17.0, 0.4, 0.9),
('Eper', 'eper', 'gyümölcs', 32, 0.7, 7.7, 0.3, 2.0),
('Cseresznye', 'cseresznye', 'gyümölcs', 50, 1.0, 12.8, 0.2, 1.6),
('Meggy', 'meggy', 'gyümölcs', 50, 1.0, 12.2, 0.3, 1.6),
('Őszibarack', 'oszibarack', 'gyümölcs', 39, 0.9, 9.5, 0.3, 1.5),
('Sárgabarack', 'sargabarack', 'gyümölcs', 48, 1.4, 11.1, 0.4, 2.0),
('Szilva', 'szilva', 'gyümölcs', 46, 0.7, 11.4, 0.3, 1.4),
('Görögdinnye', 'gorogdinnye', 'gyümölcs', 30, 0.6, 7.6, 0.2, 0.4),
('Sárgadinnye', 'sargadinnye', 'gyümölcs', 34, 0.8, 8.2, 0.2, 0.9),
('Áfonya', 'afonya', 'gyümölcs', 57, 0.7, 14.5, 0.3, 2.4),
('Mangó', 'mango', 'gyümölcs', 60, 0.8, 15.0, 0.4, 1.6),
('Ananász', 'ananas', 'gyümölcs', 50, 0.5, 13.1, 0.1, 1.4),
('Kivi', 'kivi', 'gyümölcs', 61, 1.1, 14.9, 0.5, 3.0),
('Grapefruit', 'grapefruit', 'gyümölcs', 42, 0.8, 10.7, 0.1, 1.6),

-- MAGYAR ÉTELEK (előre kiszámolt készételek)
('Gulyásleves', 'gulyasleves', 'főzelék/leves', 65, 5.0, 6.0, 2.5, 0.8),
('Pörkölt (sertés)', 'porkolt sertes', 'főzelék/leves', 185, 15.0, 5.0, 12.0, 0.5),
('Pörkölt (marha)', 'porkolt marha', 'főzelék/leves', 175, 16.0, 5.0, 10.0, 0.5),
('Paprikás csirke (tejföllel)', 'paprikas csirke', 'főzelék/leves', 155, 16.0, 4.0, 9.0, 0.3),
('Töltött káposzta', 'toltott kaposzta', 'főételek', 130, 8.0, 8.0, 8.0, 1.5),
('Töltött paprika (paradicsomszószban)', 'toltott paprika', 'főételek', 100, 7.0, 9.0, 4.0, 1.0),
('Lecsó (kolbásszal)', 'lecso kolbasszal', 'főételek', 120, 5.0, 8.0, 8.0, 1.5),
('Lecsó (kolbász nélkül)', 'lecso', 'főételek', 65, 2.0, 8.0, 3.0, 2.0),
('Főtt tészta (tejföl, sajt)', 'teszta tejfol sajt', 'főételek', 250, 9.0, 32.0, 10.0, 1.5),
('Túrós csusza', 'turos csusza', 'főételek', 210, 10.0, 25.0, 9.0, 1.0),
('Tejfölös spenót főzelék', 'spinot fozelek', 'főzelék/leves', 75, 3.5, 6.0, 4.0, 2.0),
('Bab főzelék', 'bab fozelek', 'főzelék/leves', 110, 6.0, 15.0, 3.0, 5.0),
('Krumplileves', 'krumplileves', 'főzelék/leves', 70, 2.0, 12.0, 1.5, 1.5),
('Húsleves (tyúk)', 'husleves tyuk', 'főzelék/leves', 40, 4.5, 2.0, 1.5, 0.2),
('Halászlé', 'halaszle', 'főzelék/leves', 75, 8.0, 4.0, 3.0, 0.5),
('Rakott burgonya', 'rakott burgonya', 'főételek', 175, 7.0, 18.0, 9.0, 1.5),
('Rántott hús (sertés, bundában)', 'rantott szelet', 'főételek', 270, 20.0, 12.0, 16.0, 0.5),
('Sertéskaraj krumplival', 'serteskaraj krumplival', 'főételek', 220, 18.0, 17.0, 9.0, 2.0),

-- SNACK, ÉDESSÉG
('Étcsokoládé (70%+)', 'etcsokolade', 'édesség', 598, 7.0, 46.0, 43.0, 11.0),
('Tejcsokoládé', 'tejcsokolade', 'édesség', 545, 7.5, 59.0, 32.0, 1.5),
('Keksz (vajas)', 'keksz vajas', 'édesség', 480, 6.0, 65.0, 22.0, 1.5),
('Croissant', 'croissant', 'pékáru', 406, 8.0, 45.0, 21.0, 1.5),
('Fánk (sima)', 'fank', 'édesség', 380, 6.0, 50.0, 18.0, 1.0),
('Túrórudi', 'turorudi', 'édesség', 280, 9.0, 32.0, 13.0, 0.5),
('Mogyoróvaj', 'mogyorovaj', 'zsír/olaj', 588, 25.0, 20.0, 50.0, 6.0),
('Mandula', 'mandula', 'zsír/olaj', 579, 21.0, 22.0, 49.0, 12.5),
('Dió', 'dio', 'zsír/olaj', 654, 15.0, 14.0, 65.0, 6.7),

-- ITALOK (per 100ml)
('Narancslé (frissen préselt)', 'narancsley friss', 'ital', 45, 0.7, 10.4, 0.2, 0.2),
('Almalé (100%)', 'almale', 'ital', 46, 0.1, 11.4, 0.1, 0.2),
('Szőlőlé', 'szolole', 'ital', 60, 0.5, 14.5, 0.1, 0.1),
('Kávé (fekete)', 'kave fekete', 'ital', 2, 0.3, 0.0, 0.0, 0.0),
('Cappuccino (tejjel, cukor nélkül)', 'cappuccino', 'ital', 40, 2.5, 4.0, 1.5, 0.0),
('Tejeskávé (latte)', 'latte', 'ital', 55, 3.0, 5.5, 2.0, 0.0),
('Gyümölcstea (cukrozatlan)', 'gyumolcstea', 'ital', 3, 0.0, 0.7, 0.0, 0.0),
('Szénsavas ásványvíz', 'asvanyviz', 'ital', 0, 0.0, 0.0, 0.0, 0.0),
('Cola', 'cola', 'ital', 42, 0.0, 10.5, 0.0, 0.0),
('Sör (4.5%)', 'sor', 'ital', 43, 0.5, 3.6, 0.0, 0.0),
('Bor (fehér, száraz)', 'bor feher', 'ital', 82, 0.1, 2.5, 0.0, 0.0),
('Bor (vörös, száraz)', 'bor voros', 'ital', 85, 0.1, 2.6, 0.0, 0.0),
('Palinka (50%)', 'palinka', 'ital', 230, 0.0, 0.0, 0.0, 0.0),

-- OLAJOK, ZSÍROK
('Napraforgóolaj', 'napraforgoólaj', 'zsír/olaj', 884, 0.0, 0.0, 100.0, 0.0),
('Olívaolaj', 'olivaolaj', 'zsír/olaj', 884, 0.0, 0.0, 100.0, 0.0),
('Disznózsír', 'disznozseralj', 'zsír/olaj', 902, 0.0, 0.0, 100.0, 0.0),

-- GYORSÉTELEK
('Pizza Margherita (szelet)', 'pizza margherita', 'gyorsétterem', 266, 11.0, 33.0, 10.0, 2.0),
('Hamburger (alap)', 'hamburger', 'gyorsétterem', 295, 15.0, 24.0, 14.0, 1.5),
('Hot dog', 'hot dog', 'gyorsétterem', 290, 11.0, 23.0, 17.0, 1.5),
('Shawarma', 'shawarma', 'gyorsétterem', 245, 14.0, 22.0, 10.0, 2.0),
('Kebab', 'kebab', 'gyorsétterem', 235, 15.0, 18.0, 11.0, 2.0);

-- =============================================
-- SEED: Mozgásformák
-- =============================================
INSERT INTO exercise_types (name, category, calories_per_hour, met_value) VALUES
('Gyaloglás (lassú, ~4 km/h)', 'kardio', 200, 2.8),
('Gyaloglás (közepes, ~5 km/h)', 'kardio', 280, 3.5),
('Gyaloglás (gyors, ~6 km/h)', 'kardio', 350, 4.5),
('Kocogás (~8 km/h)', 'kardio', 480, 7.0),
('Futás (~10 km/h)', 'kardio', 600, 9.0),
('Futás (gyors, ~12 km/h)', 'kardio', 750, 11.0),
('Kerékpározás (könnyű)', 'kardio', 290, 4.0),
('Kerékpározás (közepes)', 'kardio', 490, 6.8),
('Kerékpározás (intenzív)', 'kardio', 700, 10.0),
('Úszás (mérsékelten)', 'kardio', 430, 6.0),
('Úszás (intenzíven)', 'kardio', 570, 8.0),
('Evezőgép', 'kardio', 500, 7.0),
('Ugrókötelezés', 'kardio', 600, 10.0),
('Aerobik', 'kardio', 420, 6.0),
('Zumba', 'kardio', 400, 5.5),
('Elliptikus tréner', 'kardio', 450, 5.0),
('Fekvőtámasz', 'erő', 250, 3.8),
('Guggolás (testsúllyal)', 'erő', 280, 4.0),
('Súlyzós edzés (könnyű)', 'erő', 220, 3.0),
('Súlyzós edzés (közepes)', 'erő', 350, 5.0),
('Súlyzós edzés (intenzív)', 'erő', 440, 6.0),
('Jóga', 'rugalmasság', 180, 2.5),
('Pilates', 'rugalmasság', 240, 3.3),
('Nyújtás (stretching)', 'rugalmasság', 150, 2.0),
('Focizás', 'sport', 490, 7.0),
('Kosárlabda', 'sport', 470, 6.5),
('Tenisz', 'sport', 420, 7.3),
('Asztalitenisz', 'sport', 290, 4.0),
('Röplabda', 'sport', 290, 4.0),
('Síelés', 'sport', 410, 5.7),
('Inline korcsolyázás', 'sport', 480, 7.0),
('Táncol (szórakozóhelyen)', 'sport', 330, 4.5),
('Házimunka (porszívózás, takarítás)', 'egyéb', 200, 2.5),
('Kerti munka (ásás, gereblyézés)', 'egyéb', 300, 4.0),
('Lépcsőzés (fel-le)', 'kardio', 550, 8.0);

-- Alap profil (user_id=1, ha belép)
INSERT IGNORE INTO user_profile (user_id, height_cm, birth_year, gender, activity_level, target_weight_kg, daily_calorie_goal)
VALUES (1, NULL, NULL, 'férfi', 'mérsékelt', NULL, 2000);

# Timesheet (PHP + MariaDB) – Komplett csomag (HU)

Ez a csomag tartalmaz mindent egyben:
- **Alap alkalmazás** (bejelentkezés, projektek, naptár, riportok, lezárások)
- **Modern UI** (reszponzív, light/dark mód, hamburger menü)
- **Teljes név mező** (`users.full_name`)
- **Admin naptárnézet + opcionális szerkesztés** más felhasználó nevében
- **Riport bővítés**: felhasználói összes (minden projekt együtt) + CSV export

## Követelmények
- PHP 8.1+ (PDO)
- MariaDB/MySQL
- Apache/Nginx (Apache: mod_php vagy php-fpm)

## Telepítés
1. Másold fel a projektet a szerverre (pl. `/var/www/timesheet`).
2. A webroot a `public/` mappa legyen (DocumentRoot).
3. Hozd létre az adatbázist és importáld a sémát:
   ```sql
   CREATE DATABASE timesheet CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
   CREATE USER 'timesheet'@'localhost' IDENTIFIED BY 'eros_jelszo';
   GRANT ALL PRIVILEGES ON timesheet.* TO 'timesheet'@'localhost';
   FLUSH PRIVILEGES;
   ```
   ```bash
   mysql -u timesheet -p timesheet < install.sql
   ```
4. A `config.php`-ban állítsd be a DB elérést (DSN / user / jelszó).
5. Nyisd meg a `/login.php`-t, és hozd létre az első **admin** felhasználót.

## Tippek
- A **Naptár** oldalon adminként felhasználót választhatsz és bekapcsolhatod a **„Másik felhasználó szerkesztése”** opciót.
- A **Riportok** oldalon CSV export érhető el és felhasználónkénti összes is van.
- Lezárt intervallumban a nem-admin felhasználók nem tudnak módosítani; admin felülbírálhat.
- Stílus: light/dark mód automatikusan a rendszerbeállítás alapján.

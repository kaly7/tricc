Dokutool – Bejelentkezés & Session védelem (telepítési jegyzet)
===========================================================

Fájlok:
- app/session.php     — secure session indítás
- app/auth.php        — jelszó hash/verify + login/logout + current_user
- app/guard.php       — oldalvédelem (ha nincs login, átirányít /login.php?next=...)
- public/login.php    — bejelentkezés
- public/logout.php   — kijelentkezés

Integráció (gyors):
1) Másold be a fájlokat a projektbe.
2) Minden admin oldal elején (AHOL védeni szeretnéd) hívd meg a guardot **a lehető legkorábban**:
     require __DIR__ . '/../app/guard.php';
   Például, ha egy oldal tetején már van:
     require __DIR__ . '/../app/db.php';
   akkor KÖZVETLEN utána jöhet a guard:
     require __DIR__ . '/../app/guard.php';
3) Tedd ki a fejlécbe (ha van menü):
   - aktuális felhasználó neve:  $_SESSION['username'] ?? ''
   - kijelentkezés link:        /logout.php

Megjegyzések:
- A guard jelenleg **csak** a `login.php` és `logout.php` fájlokra enged belépést bejelentkezés nélkül.
- Ha van olyan publikus oldalad, amit belépés nélkül is meg kell jeleníteni, add a fájl nevét a
  $public_scripts tömbhöz az app/guard.php-ben.

Tipp:
- Hozz létre legalább 1 felhasználót a /users.php oldalon. Onnantól csak bejelentkezve éred el az admin oldalakat.

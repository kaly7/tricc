PBX Registry (v1) – PHP/MySQL/Apache

Tartalom:
- Gyártók (manufacturers) admin CRUD + archiválás + szűrő (Aktív / Archív / Mind)
- Alap UI + menü (HR/payslip jellegű világos, modern stílus)
- Alap auth (session alapú, local users tábla) – később könnyen cserélhető központi auth-ra

Telepítés (röviden):
1) Másold a projektet a webszerverre pl. /var/www/html/PBXREG
2) Hozd létre az adatbázist és futtasd az install.sql-t
3) Állítsd be az app/config.php-ban az adatbázis elérést
4) Nyisd meg: /public/login.php (alap admin: admin / admin)

Biztonság:
- CSRF védelem POST-ra
- Jelszó hash (password_hash)

Következő lépés (v2):
- Eszköz-típus katalógus (központ / végberendezés) + gyártó dropdown + badge
- Dokumentáció feltöltés (több fájl) eszköz-típushoz
- Központok + mellékek modul

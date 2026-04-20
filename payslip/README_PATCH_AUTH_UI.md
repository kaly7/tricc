# Payslip patch: Auth + UI

Ez a patch:
- Bejelentkezés (users tábla, session)
- "PP-s" kinézet: topbar + cardok
- Minden oldal Auth::requireLogin()

## Telepítés
1) Unzip a patch-et a projekt gyökerébe (/var/www/html/payslip)
2) SQL:
   mysql -u ppdb -pabrakadabra payslip < sql/users.sql
3) Admin user:
   php tools/create_user.php admin Er0sJelszo admin

## Oldalak
- /login.php (ha nincs session)
- / (index.php) csak belépés után

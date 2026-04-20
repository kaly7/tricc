# PP rendszer – *Plain PHP* (JS nélküli CRUD) v1

- Teljesen szerveroldali, **sima HTML űrlapok** (nincs JS az űrlapokhoz, csak opcionális confirm).
- Oldalak:
  - `public/login.php`, `logout.php`, `login_process.php`
  - `public/records.php` (lista + szűrők + rendezés)
  - `public/records_new.php` (új tétel)
  - `public/records_edit.php?id=...` (szerkesztés)
  - `public/changes.php?record_id=...` (változásnapló)
  - `public/admin_dicts.php` (PP státusz + Város törzsek, színválasztóval)
  - `public/admin_users.php` (felhasználók felvitele, lista)
- Műveletek POST végpontjai: `public/actions/*.php` (CSRF védelemmel)
- +38 nap **szerveroldalon** számolódik mentéskor (űrlapon tájékoztatásként látszik).

## Telepítés
1. Hozz létre DB-t (pl. `pp_system_ok`), majd futtasd: `schema.sql`, utána `seed.sql`.
2. `src/config.php`-ban állítsd be a DB elérést.
3. Nyisd meg: `http://<IP>/.../public/login.php`
4. Admin: **admin@example.com** / **admin123**

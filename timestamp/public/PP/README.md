# PP Kezelő – Starter FIX

- Javítva: admin oldalak **redirectjei** most már `admin/...` útvonalra mutatnak (almappában nem száll el).
- Javítva: **Aktív/Inaktív** jelzés zöld pipa / piros X, a gomb szövege ennek megfelelően változik.
- Beállítás: ha a projekt **/PP/** mappában fut, a `config.php`-ban `base_url` legyen **`/PP/`**.

## Telepítés
1) Adatbázis létrehozás → `db.sql` import.  
2) `config.php` DB adatok + `base_url` beállítás.  
3) Böngésző: `/PP/login.php`  
   - Admin: `admin@example.com` / `admin123` (változtasd meg!).

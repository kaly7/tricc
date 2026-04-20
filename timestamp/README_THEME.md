# Timesheet – Több téma és felhasználónkénti választás

Ez a csomag lehetővé teszi, hogy minden felhasználó a **Profil** oldalon kiválassza a felület témáját:
- `modern` (alapértelmezett, reszponzív, light/dark aware)
- `light` (világos, letisztult)
- `dark` (sötét, kontrasztos)
- `industrial` (ipari hatás, rácsos táblák)
- `playful` (vidám, kicsit színesebb)

## Telepítés

1) **Adatbázis migráció** (egyszer futtatandó, ha már létező telepítésed van):
```sql
ALTER TABLE users ADD COLUMN theme VARCHAR(32) NOT NULL DEFAULT 'modern' AFTER full_name;
```
> Új telepítéshez a mellékelt `install.sql` már tartalmazza a `theme` oszlopot.

2) Másold a csomag fájljait a projektedbe, **felülírva** az azonos nevű fájlokat:
- `public/common_header.php`
- `public/profile.php`
- `public/login.php`
- `install.sql` (csak új telepítéshez)
- `public/themes/*.css` (új fájlok, tedd a `public/themes` mappába)

3) Nyisd meg a **Profil** oldalt, és válassz témát. A választás azonnal mentésre kerül és a felület a következő kérésnél a kiválasztott CSS-t tölti be.

## Megjegyzések
- A téma a felhasználóhoz kötődik (`users.theme`) és sessionben is tároljuk az azonnali élményért.
- Ha egy téma törlésre kerül vagy hibás név kerül az adatbázisba, automatikusan a `modern` témára esünk vissza.

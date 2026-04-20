warehousemgr / Raktárkezelő

Step 6 csomag tartalma:
- auth_center kompatibilis belépés
- raktár és alraktár kezelés
- raktárhoz auth_center felhasználó hozzárendelés (HR név feloldással)
- közös anyagtörzs és CSV import
- admin audit napló kereséssel és szűréssel
- raktár törlés, naplózással
- raktárankénti készletkezelés
- bevételezés raktárba
- készletkorrekció (beállítás / növelés / csökkentés)
- készletmozgás napló oldal szűrőkkel
- raktárközi átadás indítása
- cél raktári elfogadás vagy elutasítás
- forrás oldali függő átadás törlése
- készlet csak elfogadáskor mozog
- az elfogadott átadás két készletmozgásként is naplózódik (ki / be)

Új fájlok / oldalak:
- public/transfers.php
- database/warehousemgr_update_step6_transfers.sql

Telepítés:
1. másold fel a csomag fájljait a warehousemgr projektbe
2. futtasd a database/warehousemgr_update_step6_transfers.sql fájlt a warehousemgr adatbázison
3. ellenőrizd az app/config/app.php beállításait

Megjegyzés:
- átadást az indíthat, akinek a forrás raktárhoz helyi user/admin vagy modul admin joga van
- elfogadni vagy elutasítani az tud, akinek a cél raktárhoz helyi user/admin vagy modul admin joga van
- viewer csak megtekinteni tudja az átadásokat
- minden átadás auditálva van

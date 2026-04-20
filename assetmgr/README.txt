Raktár -> külsős kiadás és külsős -> raktár visszavétel

Mit tud:
- raktárkészlet oldalon külsős partnernek kiadás
- aláírás, PDF, email ugyanúgy, mint a dolgozói külsős átadásnál
- aktív külsős kiadások listája az adott raktárhoz
- külsőstől visszavétel közvetlenül a raktárba
- visszavételi PDF és email
- asset_history oldalon külsős eseménynél megjelenik a forrás raktár és ha raktárba került vissza, az is

Telepítés:
1) Futtasd:
   mysql -u ppdb -p assetmgr_db < /var/www/html/assetmgr/migrations/external_handover_warehouse.sql

2) Másold felül:
   public/warehouse_stock.php
   public/asset_history.php
   app/pdf_mpdf.php
   migrations/external_handover_warehouse.sql

Megjegyzés:
- Ez a patch csak az új, raktárból indított külsős átadásoknál tudja biztosan a forrás raktárat menteni.

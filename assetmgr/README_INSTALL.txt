ASSETMGR telepítés (PBX mintára)

1) Kicsomagolás:
   /var/www/html/assetmgr/

2) Apache vhost (már nálad megvan):
   Port: 87
   DocumentRoot: /var/www/html/assetmgr/public
   PLUSZ: Alias a /storage útvonalra, hogy a feltöltött képek elérhetők legyenek:
     Alias /storage /var/www/html/assetmgr/storage
     <Directory /var/www/html/assetmgr/storage>
       Require all granted
     </Directory>

3) DB:
   CREATE DATABASE assetmgr_db ...;
   mysql -u ppdb -p assetmgr_db < /var/www/html/assetmgr/sql/schema.sql

4) Auth Center:
   modules táblában legyen assetmgr (port 87, path /assets.php?module=assetmgr)
   user jogosultságokban add hozzá.

Kezdőoldal:
  http://SERVER:87/assets.php?module=assetmgr

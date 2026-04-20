TIME TRACKER / harmonized v5

Telepítés:
1. Hozd létre a time_tracker2 adatbázist.
2. Futtasd:
   mysql -u ppdb -p time_tracker2 < database/001_schema.sql
   mysql -u ppdb -p time_tracker2 < database/002_seed.sql
3. Másold a csomagot a /var/www/html/time_tracker_8788 könyvtárba.
4. Ellenőrizd az app/config/app.php fájlt.
5. Apache DocumentRoot legyen a public/ mappa VAGY a public tartalma kerüljön a webrootba.

V5 újdonságok:
- 0 bejegyzésnél nincs kiírt felirat a napcella közepén
- közös rögzítés több kollégára, ütközésellenőrzéssel
- időbevitel dropdownnal: óra 00-23, perc 10 perces bontásban
- kapcsolható rögzítés: "Vége idő" vagy "Időtartam"
- számolt időtartam / számolt befejezés megjelenítése
- naptárból egyszerű drag-and-drop: a kiválasztott napi rekord áthúzható másik napra

Megjegyzés:
- a drag-and-drop ebben a verzióban napi áthelyezést tud (nem órasávos időnyújtást)
- a korábbi v4 funkciók is benne maradtak: lakat ikon, színszabályok, riport, audit


V6 változások:
- Erősebb keret a kijelölt napnak
- Tooltip eltávolítva a naptárcellákról
- Külön SQL migráció a group_uid mezőhöz: database/003_add_group_uid.sql

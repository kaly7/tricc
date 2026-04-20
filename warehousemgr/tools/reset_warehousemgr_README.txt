Használat:

1. Másold a reset_warehousemgr.php fájlt a warehousemgr/tools/ mappába
   (ha nincs tools mappa, hozd létre).

2. Futtasd innen:
   php tools/reset_warehousemgr.php --yes

Mit töröl:
- warehousemgr adatbázis üzleti táblái
- generált PDF-ek
- ideiglenes fájlok
- raktártörlési archív JSON-ok

Mit nem töröl:
- auth_center / HR adatbázis
- alkalmazás forráskód
- mPDF cache / vendor

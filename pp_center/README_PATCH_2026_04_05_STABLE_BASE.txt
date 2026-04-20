Ez a csomag a pp_0405_3 stabil alapra készült, célzott patch-ekkel.

Változások:
- Adatbázis session timezone igazítás az alkalmazás időzónájához.
- Pagelés: devices.php, telemetry.php, alerts.php, queue.php.
- Alapértelmezett 20 tétel/oldal, állítható 20 / 50 / 100.
- Konfiguráció oldalon új gombok:
  - Strukturált mentés + push
  - Raw mentés + push
- Worker callback köré try/catch került, hogy a feldolgozási hibák naplózódjanak és ne némán akadjanak el.

Szándékosan NEM lett módosítva:
- auth_center integrációs fájlok
- meglévő auth helper logika
- az ESP firmware fájl

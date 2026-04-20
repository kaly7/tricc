PP Center – eszköz grafikon oldal

Új elemek:
- public/device_charts.php
- eszköz adatlapról elérhető „Grafikonok” gomb
- hőmérséklet + páratartalom közös grafikon
- Wi-Fi jelszint külön grafikon
- GSM jelszint külön grafikon
- kontaktok állapota külön lépcsős grafikon
- időablakok: 1h, 6h, 12h, 24h, 2d, 7d, egyedi intervallum

Módosított fájlok:
- app/Services/TelemetryService.php
- templates/header.php
- public/device.php
- public/device_charts.php
- public/assets/app.css

Megjegyzés:
- Adatbázis migráció nem szükséges.
- A Wi-Fi/GSM jelszint a telemetry_log.raw_json mezőből is visszaolvasható, ha ott szerepel.

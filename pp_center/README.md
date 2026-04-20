# pp_center

PP Center – MQTT / Mattermost / eszközfelügyeleti központ induló alap.

## Mi van most kész

- Bootstrap alapú, auth_centerre előkészített adminfelület
- publikus assetek a `public/assets/` alatt, Apache `DocumentRoot /var/www/html/pp_center/public` kompatibilisen
- Mattermost outgoing webhook végpont: `public/api/mattermost_outgoing.php`
- Mattermost incoming webhook service
- PHP CLI `mqtt_worker.php` a fő bridge szerepre
- heartbeat checker CLI script
- queue nézet a weben
- config verziók, desired/reported mismatch követése
- presence/LWT napló

## Apache

A `DocumentRoot` legyen:

```apache
DocumentRoot /var/www/html/pp_center/public
<Directory /var/www/html/pp_center/public>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

## Auth Center integráció

A webes oldalak a közös auth réteget használják:

- `/var/www/html/_common/auth/db.php`
- `/var/www/html/_common/auth/url.php`
- `/var/www/html/_common/auth/Auth.php`

A modul regisztrálásához futtasd az Auth Center adatbázisán:

```sql
SOURCE /var/www/html/pp_center/database/auth_center_add_pp_center_module.sql;
```

Alap modulbejegyzés:

- `module_key`: `pp_center`
- `module_name`: `PP Center`
- `port`: `8790`
- `path`: `/`

## Konfiguráció

- app / db / mattermost / mqtt: `config/config.php`
- auth: `config/auth.php`

A `base_url` port-alapú VirtualHostnál maradhat üresen.

## Adatbázis

Friss telepítéshez:

```bash
mysql -u root -p < migrations/create_database_user.sql
mysql -u root -p < migrations/full_install.sql
```

Már futó rendszer frissítéséhez:

```bash
mysql -u root -p pp_center < patches/2026_04_04_backend_upgrade.sql
```

## MQTT worker

Composer csomagok telepítése után:

```bash
composer install
php cli/mqtt_worker.php
```

systemd példa: `deploy/mm-bridge.service.example`

## Heartbeat checker

```bash
php cli/heartbeat_checker.php
```

Ezt cronból vagy systemd timerből érdemes futtatni.

## Mattermost parancsok V1

- `help`
- `status <device_id>`
- `queue <device_id>`
- `cfg show <device_id>`
- `cfg push <device_id>`
- `cfg validate <device_id>`
- `cmd <device_id> <parancs> [json]`

## Megjegyzés

Ebben a modellben a riasztási döntést az ESP hozza meg a letöltött konfiguráció alapján. A központi PHP/MySQL háttér tárolja a desired configot, naplózza a telemetriát/riasztást, és a Mattermost felé operátori felületet ad.


## Bridge telepítés

1. A `config/app.php` vagy `config/config.php` fájlban állítsd be:
   - `mqtt.host`, `mqtt.port`, `mqtt.username`, `mqtt.password`
   - `mattermost.incoming_webhook_url`
   - `mattermost.outgoing_token`
   - `mattermost.enabled = true`

2. A Mattermost szerveren hozz létre:
   - **Incoming webhookot** a riasztási csatornához
   - **Outgoing webhookot** a `http://PP_CENTER_SZERVER:8790/api/mattermost_outgoing.php` URL-re

3. Engedélyezd a worker szolgáltatást systemd-vel a `deploy/pp-center-mqtt-worker.service.example` minta alapján.

4. Kézi tesztek:

```bash
php cli/test_mattermost.php
php cli/test_queue_command.php esp001 get_status
```

5. A webes felületen a **Bridge** menüben látszik, hogy a worker küldött-e heartbeatet.


## Mattermost `/pp` slash command

A stabilabb Mattermost vezérléshez állíts be egy egyedi slash commandot:

- Trigger szó: `pp`
- Request URL: `http://PP_CENTER_HOST:8790/api/mattermost_slash.php`
- Method: `POST`
- Token: ezt tedd be a `config/config.php` `mattermost.slash_token` mezőjébe.

Példák:

- `/pp help`
- `/pp bridge status`
- `/pp status esp001`
- `/pp cfg show esp001`
- `/pp cfg push esp001`
- `/pp cmd esp001 get_status`

A slash command Mattermost dokumentációja szerint `application/x-www-form-urlencoded` POST-tal küld adatot, és tokennel védi a kérést. A `pp_center` végpont mind a body `token`, mind az `Authorization: Token ...` fejléc alapján tud hitelesíteni.


## ESP MQTT payloadok

A webes felületen a `Payloadok` menüpont alatt megtalálod az ajánlott JSON mintákat.

A bridge a következő topicokat figyeli:

- `pp/<device_id>/telemetry`
- `pp/<device_id>/alert`
- `pp/<device_id>/state/reported`
- `pp/<device_id>/cmd/out`
- `pp/<device_id>/lwt`

A feldolgozó kompatibilis a lapos és a strukturált payloadokkal is. A nyers JSON minden esetben elmentődik a `raw_json` mezőkbe.

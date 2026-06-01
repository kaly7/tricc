# AGV Manager – Telepítési útmutató

**Verzió:** 2.0.0  
**VDA5050 v2.0 kompatibilis MQTT koordináta-figyelő és fleet management rendszer**

---

## Rendszerkövetelmények

| Komponens | Minimum |
|-----------|---------|
| OS | Debian 11+ / Ubuntu 22.04+ |
| PHP | 8.0+ (`php-cli`, `php-mysql`, `php-mbstring`) |
| Webszerver | Apache 2.4+ (`mod_php` vagy `php-fpm`) |
| Adatbázis | MariaDB 10.5+ vagy MySQL 8.0+ |
| PHP kiterjesztések | `mysqli`, `pdo_mysql`, `json`, `mbstring` |

---

## 1. Fájlok másolása

Az agvmgr modul a PM webszerver alá kerül:

```bash
# Csomag kicsomagolása
tar -xzf agvmgr-v2.0.0-YYYYMMDD.tar.gz -C /var/www/html/pm/

# Eredmény: /var/www/html/pm/agvmgr/
```

---

## 2. Apache vhost

Ha az Apache már be van állítva a `/var/www/html/pm` könyvtárra
(pl. port 8791), **nem kell külön vhost** – az agvmgr azonnal elérhető:

```
http://<ip>:8791/agvmgr/
```

### Új, önálló vhost (ha nincs PM konfig)

```apache
# /etc/apache2/sites-available/agvmgr-8791.conf
<VirtualHost *:8791>
    DocumentRoot /var/www/html/pm
    <Directory /var/www/html/pm>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/agvmgr_error.log
    CustomLog ${APACHE_LOG_DIR}/agvmgr.log combined
</VirtualHost>
```

```bash
echo "Listen 8791" >> /etc/apache2/ports.conf
a2ensite agvmgr-8791.conf
systemctl reload apache2
```

---

## 3. Telepítő script futtatása

```bash
sudo bash /var/www/html/pm/agvmgr/agvmgr_setup.sh
```

A script elvégzi:
- MySQL adatbázis és felhasználó létrehozása (`agvmgr` / `robot`)
- Összes tábla létrehozása / frissítése
- MQTT worker systemd service telepítése és indítása
- Fájljogoultságok beállítása
- Ellenőrzések és visszajelzés

---

## 4. Adatbázis konfig (`db.php`)

Ha eltérő DB adatokat szeretnél, szerkeszd a telepítés előtt:

```php
// /var/www/html/pm/agvmgr/db.php
define('AGV_DB_HOST', 'localhost');
define('AGV_DB_USER', 'robot');
define('AGV_DB_PASS', 'abrakadabra');   // ← változtasd meg!
define('AGV_DB_NAME', 'agvmgr');
```

A setup scriptben (`agvmgr_setup.sh`) a `DB_PASS` változót is ugyanerre állítsd:

```bash
DB_PASS="abrakadabra"   # ← sor ~14
```

---

## 5. MQTT worker (systemd)

A setup script automatikusan telepíti. Kézi ellenőrzés:

```bash
# Státusz
systemctl status agvmgr-worker

# Log élőben
tail -f /var/log/agvmgr_worker.log

# Újraindítás
systemctl restart agvmgr-worker
```

---

## 6. Első belépés

| Mező | Érték |
|------|-------|
| URL | `http://<ip>:<port>/agvmgr/` |
| Felhasználónév | `admin` |
| Jelszó | `admin1234` |

> **Fontos:** Belépés után azonnal változtasd meg a jelszót a **Rendszer → Felhasználók** menüben!

---

## 7. AGV beállítása

1. Nyisd meg: **Rendszer → AGV beállítások**
2. Add meg az MQTT broker IP-jét és portját → **Mentés**
3. Kattints a **Kapcsolat tesztelése** gombra – ellenőrizd az `AGV_TESZT` topicot
4. Adj hozzá AGV-ket (Gyártó, S/N, MQTT topic)
5. Indítsd újra a workert: `systemctl restart agvmgr-worker`

---

## 8. Honeywell logó (opcionális)

A fejlécben a logó a `../img/honeywell_logo.svg` útvonalon van.
Ha nincs PM könyvtárstruktúra, másold be:

```bash
mkdir -p /var/www/html/pm/img/
cp honeywell_logo.svg /var/www/html/pm/img/
```

---

## Könyvtárstruktúra

```
/var/www/html/pm/agvmgr/
├── agvmgr_setup.sh       ← telepítő script
├── package.sh            ← csomagoló script
├── setup.sql             ← DB séma (referencia)
├── db.php                ← DB kapcsolat konfig
├── auth.php              ← session kezelés
├── index.php             ← Dashboard
├── agvs.php              ← AGV állapot nézet
├── map.php               ← Térkép (Canvas)
├── map_api.php           ← Térkép JSON API
├── events.php            ← Eseménynapló
├── users.php             ← Felhasználókezelés
├── admin.php             ← AGV beállítások
├── omron.php             ← Omron átadás konfig
├── broker_test.php       ← MQTT teszt (AGV_TESZT)
├── omron_test.php        ← MQTT teszt (OMRON_TESZT)
├── worker_status.php     ← Worker állapot API
├── worker/
│   ├── mqtt_worker.php   ← MQTT figyelő worker (PHP)
│   └── phpMQTT.php       ← MQTT kliens könyvtár
└── assets/bootstrap/     ← Bootstrap 5 (helyi, offline)
```

---

## Hibaelhárítás

| Tünet | Megoldás |
|-------|----------|
| Fehér oldal (500) | `tail -50 /var/log/apache2/agvmgr_error.log` |
| DB kapcsolat hiba | Ellenőrizd `db.php` adatait, MariaDB fut-e |
| Worker nem indul | `php -l worker/mqtt_worker.php` – szintaxis hiba? |
| MQTT teszt sikertelen | Ellenőrizd a broker IP-t és a tűzfalat |
| Honeywell logó hiányzik | Másold be az `../img/` könyvtárba (lásd 8. pont) |

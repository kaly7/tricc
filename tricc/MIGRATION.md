# BabL42 (Tricc) — Szerver telepítési útmutató

> Ez a dokumentum a **Szerver_rv42** Claude számára készült az új szerverre való telepítéshez.
> Kommunikáció: `MESSAGES.md` fájlban, `[Szerver_rv42]` és `[Szerver Claude]` jelzésekkel.

---

## Előfeltételek

Az új szerveren szükséges:
- **PHP 8.0+** + extensions: `curl`, `openssl`, `pdo_mysql`, `mbstring`, `fileinfo`
- **Composer**
- **MySQL 8** vagy **MariaDB 10.5+**
- **Apache2** + modulok: `mod_ssl`, `mod_proxy`, `mod_proxy_wstunnel`, `mod_rewrite`
- **Git**

---

## 1. Kód klónozása

```bash
git clone https://github.com/kaly7/tricc.git /var/www/html/tricc
cd /var/www/html/tricc
composer install --no-dev
```

---

## 2. Adatbázis létrehozása

```sql
CREATE DATABASE tricc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tricc_user'@'localhost' IDENTIFIED BY 'VALASSZ_JELSZOT';
GRANT ALL PRIVILEGES ON tricc.* TO 'tricc_user'@'localhost';
FLUSH PRIVILEGES;
```

Séma importálása:
```bash
mysql -u tricc_user -p tricc < /var/www/html/tricc/db/schema.sql
```

---

## 3. Konfiguráció

```bash
cp /var/www/html/tricc/config.example.php /var/www/html/tricc/config.php
```

Szerkeszd ki a `config.php`-t:

```php
'db_host'     => '127.0.0.1',
'db_name'     => 'tricc',
'db_user'     => 'tricc_user',
'db_pass'     => 'VALASSZ_JELSZOT',

'jwt_secret'  => 'UGYANAZ_MINT_A_REGI_SZERVEREN',   // ← a régi szerverről átvesszük!

'admin_user'  => 'kaly@compunet.hu',
'admin_pass'  => 'asdqwe',

'apns_key_file'       => '/opt/tricc/AuthKey_94HGSV4WAL.p8',
'apns_key_id'         => '94HGSV4WAL',
'apns_team_id'        => 'K7Z734X92Z',
'apns_bundle_id'      => 'com.rv42.babl42',

'fcm_service_account' => '/opt/tricc/firebase-service-account.json',
```

---

## 4. Push értesítés kulcsfájlok

```bash
sudo mkdir -p /opt/tricc
# APNs .p8 kulcs — a régi szerverről másolandó:
sudo scp REGI_SZERVER:/opt/tricc/AuthKey_94HGSV4WAL.p8 /opt/tricc/
# Firebase service account JSON — a régi szerverről másolandó:
sudo scp REGI_SZERVER:/opt/tricc/firebase-service-account.json /opt/tricc/
sudo chmod 640 /opt/tricc/*.p8 /opt/tricc/*.json
sudo chown root:www-data /opt/tricc/*.p8 /opt/tricc/*.json
```

---

## 5. Uploads mappa

```bash
mkdir -p /var/www/html/tricc/uploads/avatars /var/www/html/tricc/uploads/files
chown -R www-data:www-data /var/www/html/tricc/uploads
chmod -R 775 /var/www/html/tricc/uploads
```

---

## 6. Apache portok

`/etc/apache2/ports.conf`-ba add hozzá:
```
Listen 9453
Listen 9456
```

Szükséges modulok:
```bash
a2enmod ssl proxy proxy_http proxy_wstunnel rewrite
```

---

## 7. Apache vhost — HTTP (9453)

```bash
cp /var/www/html/tricc/apache/tricc.conf /etc/apache2/sites-available/tricc.conf
```

A `tricc.conf`-ban a `DocumentRoot` és `Alias` útvonalak ellenőrizendők (alapértelmezetten `/var/www/html/tricc/...` — ha más helyre telepítettél, módosítsd).

```bash
a2ensite tricc
```

---

## 8. Apache vhost — HTTPS (9456)

SSL tanúsítvány szükséges. Önaláírt:
```bash
sudo mkdir -p /etc/apache2/ssl/tricc
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/apache2/ssl/tricc/tricc.key \
  -out /etc/apache2/ssl/tricc/tricc.crt \
  -subj "/CN=UJ_SZERVER_IP"
```

Módosítsd a `tricc-ssl.conf`-ban a tanúsítvány útvonalakat, majd:
```bash
cp /var/www/html/tricc/apache/tricc-ssl.conf /etc/apache2/sites-available/tricc-ssl.conf
# Szerkeszd: SSLCertificateFile és SSLCertificateKeyFile útvonalak
a2ensite tricc-ssl
systemctl reload apache2
```

---

## 9. WebSocket szerver (systemd)

```bash
cp /var/www/html/tricc/systemd/tricc-ws.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tricc-ws
systemctl status tricc-ws
```

---

## 10. Adatmigráció a régi szerverről

### DB dump exportálása (régi szerveren):
```bash
mysqldump -u ppdb -p tricc > /tmp/tricc_dump.sql
```

### Átvitel és importálás (új szerveren):
```bash
scp REGI_SZERVER:/tmp/tricc_dump.sql /tmp/
mysql -u tricc_user -p tricc < /tmp/tricc_dump.sql
```

### Uploads mappa másolása:
```bash
scp -r REGI_SZERVER:/var/www/html/tricc/uploads/ /var/www/html/tricc/
chown -R www-data:www-data /var/www/html/tricc/uploads/
```

---

## Ellenőrzés

```bash
# API él?
curl http://localhost:9453/auth/me
# → {"ok":false,"error":"..."} — ez helyes (401)

# WebSocket fut?
systemctl is-active tricc-ws

# Admin panel elérhető?
curl -s http://localhost:9453/admin/login.php | grep -c "BabL42"
```

---

## Portok összefoglalása

| Port | Protokoll | Szerepe |
|------|-----------|---------|
| 9453 | HTTP | REST API + Admin panel |
| 9454 | WS | WebSocket (Ratchet, publikus) |
| 9455 | TCP | Belső broadcast (csak localhost) |
| 9456 | HTTPS/WSS | Biztonságos API + WebSocket |

---

## Kommunikáció

Ha valami nem megy, írj a `MESSAGES.md`-be `[Szerver_rv42]` jelzéssel — a jelenlegi Szerver Claude (192.168.16.22) válaszol.

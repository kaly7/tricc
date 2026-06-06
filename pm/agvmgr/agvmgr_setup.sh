#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  agvmgr_setup.sh – telepítő és ellenőrző script
#  Futtatás: bash agvmgr_setup.sh
#  (sudo jelszót automatikusan bekéri, ha szükséges)
# ═══════════════════════════════════════════════════════════════════

# ── Sudo elevation ────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    echo ""
    echo "  Root jogosultság szükséges. Add meg a sudo jelszót:"
    exec sudo bash "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
AGVMGR_DIR="$SCRIPT_DIR"
WORKER_DIR="$AGVMGR_DIR/worker"
LOG_FILE="/var/log/agvmgr_worker.log"
SERVICE_FILE="/etc/systemd/system/agvmgr-worker.service"

DB_NAME="agvmgr"
DB_USER="robot"
DB_PASS="abrakadabra"
DB_HOST="localhost"
DEFAULT_PORT="8791"

EXPECTED_VER="2.0.0"

OK=0; WARN=0; ERR=0; FIX=0

_ok()   { echo "  [OK]      $1"; OK=$((OK+1)); }
_warn() { echo "  [WARN]    $1"; WARN=$((WARN+1)); }
_err()  { echo "  [HIBA]    $1"; ERR=$((ERR+1)); }
_fix()  { echo "  [JAVÍTÁS] $1"; FIX=$((FIX+1)); }
_info() { echo "            $1"; }
_head() { echo ""; echo "══════════════════════════════════════════"; echo "  $1"; echo "══════════════════════════════════════════"; }

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   agvmgr – telepítő és ellenőrző script                     ║"
echo "║   $(date '+%Y-%m-%d %H:%M:%S')                                    ║"
echo "║   Könyvtár: $AGVMGR_DIR"
echo "╚══════════════════════════════════════════════════════════════╝"

# ════════════════════════════════════════════════════════════════════
_head "1. Verzió"

VER_FILE="$AGVMGR_DIR/version.txt"
if [ -f "$VER_FILE" ]; then
    VER=$(tr -d '[:space:]' < "$VER_FILE")
    if [ "$VER" = "$EXPECTED_VER" ]; then _ok "version.txt: $VER"
    else _warn "Verzió: $VER (elvárás: $EXPECTED_VER)"; fi
else
    _err "version.txt hiányzik"
fi

# ════════════════════════════════════════════════════════════════════
_head "2. Kötelező fájlok"

PHP_FILES=(
    "index.php" "login.php" "logout.php" "admin.php"
    "agvs.php" "omron.php" "events.php" "users.php"
    "map.php" "map_api.php" "worker_status.php"
    "broker_test.php" "omron_test.php" "coords_api.php"
    "mqtt_test.php" "db.php" "auth.php"
    "_header.php" "_footer.php"
    "styles.css" "setup.sql"
)
WORKER_FILES=("worker/mqtt_worker.php")
ASSET_FILES=("assets/bootstrap/bootstrap.min.css" "assets/bootstrap/bootstrap.bundle.min.js")

for f in "${PHP_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then _ok "$f"
    else _err "$f – HIÁNYZIK"; fi
done
for f in "${WORKER_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then _ok "$f"
    else _err "$f – HIÁNYZIK"; fi
done
for f in "${ASSET_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then _ok "$f"
    else _warn "$f – hiányzik (Bootstrap offline nélkül az UI nem jelenik meg rendesen)"; fi
done

# ════════════════════════════════════════════════════════════════════
_head "3. Rendszer csomagok"

apt_install() {
    local PKG="$1"
    if dpkg -l "$PKG" 2>/dev/null | grep -q '^ii'; then
        _ok "csomag telepítve: $PKG"
    else
        _fix "csomag telepítése: $PKG"
        apt-get install -y "$PKG" > /dev/null 2>&1
        if dpkg -l "$PKG" 2>/dev/null | grep -q '^ii'; then
            _ok "$PKG telepítve"
        else
            _err "$PKG telepítése sikertelen – kézzel: apt install $PKG"
        fi
    fi
}

apt-get update -qq > /dev/null 2>&1

apt_install "php-cli"
apt_install "php-mysql"
apt_install "mosquitto-clients"
apt_install "apache2"
apt_install "mariadb-server"

# ════════════════════════════════════════════════════════════════════
_head "4. MySQL elérés (root)"

MYSQL_ROOT="mysql --batch --skip-column-names"
if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
    _ok "MySQL root hozzáférés: socket auth"
else
    MYSQL_ROOT="mysql -u root --batch --skip-column-names"
    if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
        _ok "MySQL root hozzáférés: -u root"
    else
        _err "Nem sikerült MySQL root hozzáférés. Add meg a jelszót:"
        read -s -r -p "  MySQL root jelszó (Enter = üres): " ROOT_PASS
        echo ""
        MYSQL_ROOT="mysql -u root -p${ROOT_PASS} --batch --skip-column-names"
        if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
            _ok "MySQL root hozzáférés: jelszóval"
        else
            _err "MySQL root hozzáférés sikertelen."; echo "MEGÁLLÍTVA."; exit 1
        fi
    fi
fi

# ════════════════════════════════════════════════════════════════════
_head "5. Adatbázis"

if $MYSQL_ROOT -e "USE $DB_NAME" > /dev/null 2>&1; then
    _ok "Adatbázis létezik: $DB_NAME"
else
    _fix "Adatbázis létrehozása: $DB_NAME"
    $MYSQL_ROOT -e "CREATE DATABASE IF NOT EXISTS $DB_NAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    [ $? -eq 0 ] && _ok "Adatbázis létrehozva" || { _err "Adatbázis létrehozása sikertelen"; exit 1; }
fi

# ════════════════════════════════════════════════════════════════════
_head "6. MySQL felhasználó ($DB_USER)"

USER_EXISTS=$($MYSQL_ROOT -e "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER' AND host='localhost'" 2>/dev/null)
if [ "${USER_EXISTS:-0}" -ge 1 ] 2>/dev/null; then
    _ok "MySQL user létezik: $DB_USER@localhost"
else
    _fix "MySQL user létrehozása: $DB_USER"
    $MYSQL_ROOT -e "CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';" 2>/dev/null
fi
$MYSQL_ROOT -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" > /dev/null 2>&1
_ok "Jogosultság frissítve: $DB_USER → $DB_NAME.*"

DB_AGV="mysql -h$DB_HOST -u$DB_USER -p$DB_PASS $DB_NAME --batch --skip-column-names"
if $DB_AGV -e "SELECT 1" > /dev/null 2>&1; then
    _ok "DB elérés: $DB_USER@$DB_HOST/$DB_NAME – OK"
else
    _err "DB elérés sikertelen a $DB_USER felhasználóval"; exit 1
fi

# ════════════════════════════════════════════════════════════════════
_head "7. Adatbázis táblák (setup.sql)"

if [ -f "$AGVMGR_DIR/setup.sql" ]; then
    $MYSQL_ROOT $DB_NAME < "$AGVMGR_DIR/setup.sql" 2>&1
    if [ $? -eq 0 ]; then
        _ok "setup.sql lefuttatva"
    else
        _warn "setup.sql futtatás közben figyelmeztetések (esetleg már létező táblák – OK)"
    fi
else
    _err "setup.sql hiányzik – táblák nem hozhatók létre"
fi

# ════════════════════════════════════════════════════════════════════
_head "8. Tábla mezők (upgrade ellenőrzés)"

check_column() {
    local TABLE="$1"; local COL="$2"; local DDL_ADD="$3"
    if $DB_AGV -e "SELECT $COL FROM $TABLE LIMIT 1" > /dev/null 2>&1; then
        _ok "$TABLE.$COL"
    else
        _fix "$TABLE.$COL hiányzik – hozzáadás"
        $DB_AGV -e "ALTER TABLE $TABLE ADD COLUMN $DDL_ADD;" 2>&1
        [ $? -eq 0 ] && _ok "$TABLE.$COL hozzáadva" || _err "$TABLE.$COL hozzáadása sikertelen"
    fi
}

check_column "agv"        "type"                 "type VARCHAR(100) NOT NULL DEFAULT '' AFTER manufacturer"
check_column "agv_coords" "theta"                "theta DECIMAL(10,6) NULL AFTER y"
check_column "agv_coords" "map_id"               "map_id VARCHAR(100) NOT NULL DEFAULT '' AFTER theta"
check_column "agv_coords" "position_initialized" "position_initialized TINYINT(1) NULL AFTER map_id"
check_column "agv_coords" "localization_score"   "localization_score DECIMAL(5,4) NULL AFTER position_initialized"
check_column "agv_coords" "deviation_range"      "deviation_range DECIMAL(10,4) NULL AFTER localization_score"
check_column "agv_coords" "vx"                   "vx DECIMAL(10,4) NULL AFTER deviation_range"
check_column "agv_coords" "vy"                   "vy DECIMAL(10,4) NULL AFTER vx"
check_column "agv_coords" "omega"                "omega DECIMAL(10,6) NULL AFTER vy"
check_column "agv_coords" "battery_charge"       "battery_charge DECIMAL(5,2) NULL AFTER omega"
check_column "agv_coords" "battery_voltage"      "battery_voltage DECIMAL(7,3) NULL AFTER battery_charge"
check_column "agv_coords" "operating_mode"       "operating_mode VARCHAR(20) NOT NULL DEFAULT '' AFTER battery_voltage"
check_column "agv_coords" "driving"              "driving TINYINT(1) NULL AFTER operating_mode"
check_column "agv_coords" "paused"               "paused TINYINT(1) NULL AFTER driving"
check_column "agv_coords" "source"               "source VARCHAR(15) NOT NULL DEFAULT 'state' AFTER paused"
check_column "agv_coords" "raw_payload"          "raw_payload MEDIUMTEXT NULL AFTER source"

# ════════════════════════════════════════════════════════════════════
_head "9. Admin felhasználó"

ADMIN_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM users WHERE username='admin'" 2>/dev/null)
if [ "${ADMIN_CNT:-0}" -ge 1 ] 2>/dev/null; then
    _ok "Admin user létezik (username: admin)"
else
    HASH='$2y$10$8GqT.T5J893236MP49zGauiK68.FBLeXjLElUjYdkZpy0yyNSJ2S.'
    $DB_AGV -e "INSERT INTO users (username,password,is_admin) VALUES ('admin','$HASH',1);" > /dev/null 2>&1
    _fix "Admin user létrehozva – admin / admin1234  (belépés után változtasd meg!)"
fi

# ════════════════════════════════════════════════════════════════════
_head "10. PHP CLI és mosquitto ellenőrzés"

PHP_BIN=$(command -v php || true)
if [ -z "$PHP_BIN" ]; then
    _err "php-cli nem található"
else
    _ok "php-cli: $PHP_BIN ($(php --version | head -1))"
    if php -r "new PDO('mysql:host=127.0.0.1', 'x', 'x');" 2>&1 | grep -q "could not find driver"; then
        _err "php-mysql (PDO) nincs telepítve"
    else
        _ok "php-mysql (PDO) elérhető"
    fi
    if [ -f "$WORKER_DIR/mqtt_worker.php" ]; then
        if php -l "$WORKER_DIR/mqtt_worker.php" > /dev/null 2>&1; then _ok "mqtt_worker.php szintaxis OK"
        else _err "mqtt_worker.php szintaxis hiba"; fi
    else
        _err "mqtt_worker.php hiányzik: $WORKER_DIR/mqtt_worker.php"
    fi
fi

if command -v mosquitto_sub > /dev/null 2>&1; then
    _ok "mosquitto_sub elérhető: $(which mosquitto_sub)"
else
    _err "mosquitto_sub hiányzik – telepítés: apt install mosquitto-clients"
fi

# ════════════════════════════════════════════════════════════════════
_head "11. Log fájl"

touch "$LOG_FILE" 2>/dev/null
chmod 666 "$LOG_FILE" 2>/dev/null
if [ -f "$LOG_FILE" ]; then _ok "Log fájl: $LOG_FILE"
else _err "Log fájl nem hozható létre: $LOG_FILE"; fi

# ════════════════════════════════════════════════════════════════════
_head "12. Fájl jogosultságok"

chown -R www-data:www-data "$AGVMGR_DIR" > /dev/null 2>&1
find "$AGVMGR_DIR" -name "*.php" -exec chmod 644 {} \;
find "$AGVMGR_DIR" -name "*.sh"  -exec chmod 755 {} \;
find "$AGVMGR_DIR" -type d       -exec chmod 755 {} \;
_fix "Jogosultságok beállítva (PHP:644, SH:755, könyvtárak:755, tulajdonos:www-data)"

# ════════════════════════════════════════════════════════════════════
_head "13. Systemd service (agvmgr-worker)"

PHP_BIN=$(command -v php || echo "/usr/bin/php")

if [ ! -f "$SERVICE_FILE" ]; then
    _fix "Service fájl létrehozása: $SERVICE_FILE"
    cat > "$SERVICE_FILE" << EOF
[Unit]
Description=agvmgr MQTT Worker – VDA5050 pozíció rögzítő
After=network.target mysql.service mariadb.service
Wants=network.target

[Service]
Type=simple
ExecStart=$PHP_BIN $WORKER_DIR/mqtt_worker.php
Restart=always
RestartSec=10
User=www-data
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

[Install]
WantedBy=multi-user.target
EOF
    _ok "Service fájl létrehozva"
else
    _ok "Service fájl létezik: $SERVICE_FILE"
fi

systemctl daemon-reload > /dev/null 2>&1

if ! systemctl is-enabled agvmgr-worker > /dev/null 2>&1; then
    systemctl enable agvmgr-worker > /dev/null 2>&1
    _fix "Service engedélyezve (autostart)"
else
    _ok "Service engedélyezve (autostart)"
fi

if systemctl is-active --quiet agvmgr-worker; then
    _fix "Worker fut – újraindítás (új kód betöltése)"
    systemctl restart agvmgr-worker > /dev/null 2>&1
    sleep 2
else
    systemctl start agvmgr-worker > /dev/null 2>&1
    sleep 2
fi

if systemctl is-active --quiet agvmgr-worker; then
    _ok "Worker FUT (agvmgr-worker active)"
else
    _err "Worker indítása sikertelen"
    _info "→ Ellenőrzés: systemctl status agvmgr-worker"
    _info "→ Log:        tail -20 $LOG_FILE"
fi

# ════════════════════════════════════════════════════════════════════
_head "14. Apache vhost"

PM_VHOST_EXISTS=0
PM_PORT=""
for conf in /etc/apache2/sites-enabled/*.conf; do
    if grep -q "/var/www/html/pm" "$conf" 2>/dev/null; then
        PM_PORT=$(grep -m1 "VirtualHost" "$conf" | grep -oP ':\K[0-9]+')
        PM_VHOST_EXISTS=1
        _ok "PM vhost megtalálva: port $PM_PORT ($(basename "$conf"))"
        break
    fi
done

if [ "$PM_VHOST_EXISTS" -eq 0 ]; then
    _warn "PM vhost nem található – létrehozás..."
    VHOST_PORT="$DEFAULT_PORT"

    if ! grep -q "Listen $VHOST_PORT" /etc/apache2/ports.conf 2>/dev/null; then
        echo "Listen $VHOST_PORT" >> /etc/apache2/ports.conf
        _fix "ports.conf: Listen $VHOST_PORT hozzáadva"
    fi

    VHOST_FILE="/etc/apache2/sites-available/agvmgr-${VHOST_PORT}.conf"
    cat > "$VHOST_FILE" << VHEOF
<VirtualHost *:${VHOST_PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/pm
    <Directory /var/www/html/pm>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/agvmgr_error.log
    CustomLog \${APACHE_LOG_DIR}/agvmgr.log combined
</VirtualHost>
VHEOF
    a2ensite "agvmgr-${VHOST_PORT}.conf" > /dev/null 2>&1
    systemctl reload apache2 > /dev/null 2>&1
    _fix "Vhost létrehozva: port $VHOST_PORT"
    PM_PORT="$VHOST_PORT"
fi

# ════════════════════════════════════════════════════════════════════
_head "15. MQTT broker konfig"

BROKER_IP=$($DB_AGV -e "SELECT ip FROM mqtt_broker WHERE id=1" 2>/dev/null)
if [ -n "$BROKER_IP" ]; then
    _ok "AGV MQTT broker IP beállítva: $BROKER_IP"
else
    _warn "AGV MQTT broker IP nincs beállítva"
    _info "→ Beállítás: http://<ip>:$PM_PORT/agvmgr/admin.php"
fi

# ════════════════════════════════════════════════════════════════════
_head "16. AGV-k a DB-ben"

AGV_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM agv" 2>/dev/null)
AGV_ENA=$($DB_AGV -e "SELECT COUNT(*) FROM agv WHERE enabled=1" 2>/dev/null)
if [ "${AGV_CNT:-0}" -ge 1 ] 2>/dev/null; then
    _ok "AGV-k: $AGV_CNT db (aktív: $AGV_ENA)"
    $DB_AGV -e "SELECT CONCAT('  → #',id,' ',manufacturer,' ',serial_no,' [',IF(enabled,'aktív','letiltva'),'] topic:',topic) FROM agv;" 2>/dev/null
else
    _warn "Nincs AGV felvéve"
    _info "→ Hozzáadás: http://<ip>:$PM_PORT/agvmgr/admin.php"
fi

# ════════════════════════════════════════════════════════════════════
_head "17. HTTP elérhetőség"

SERVER_IP=$(hostname -I | awk '{print $1}')
if [ -n "$PM_PORT" ]; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        --connect-timeout 3 "http://127.0.0.1:$PM_PORT/agvmgr/login.php" 2>/dev/null)
    if [ "$HTTP_CODE" = "200" ]; then
        _ok "HTTP válasz: 200 OK"
    else
        _warn "HTTP válasz: ${HTTP_CODE:-nincs} (Apache fut-e?)"
    fi
fi

# ════════════════════════════════════════════════════════════════════
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
printf "║  Eredmény:  ✓ OK: %-4d  ⚑ JAVÍTÁS: %-4d  ✗ HIBA: %-4d    ║\n" "$OK" "$FIX" "$ERR"
echo "╠══════════════════════════════════════════════════════════════╣"
if [ -n "$PM_PORT" ] && [ -n "$SERVER_IP" ]; then
    printf "║  URL: http://%-47s║\n" "${SERVER_IP}:${PM_PORT}/agvmgr/"
fi
echo "║  Belépés:  admin / admin1234  (változtasd meg!)              ║"
echo "║  Log:      tail -f /var/log/agvmgr_worker.log                ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

if [ "$ERR" -gt 0 ]; then
    echo "  Vannak hibák – nézd meg a [HIBA] sorokat fentebb."
    exit 1
else
    exit 0
fi

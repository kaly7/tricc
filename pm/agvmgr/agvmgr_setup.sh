#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  agvmgr_setup.sh – telepítő és ellenőrző script
#  Futtatás: sudo bash agvmgr_setup.sh
#  Elvégzi: DB létrehozás, táblák, Python csomagok, systemd service,
#           jogosultságok, alapértelmezett admin user.
# ═══════════════════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
AGVMGR_DIR="$SCRIPT_DIR"
WORKER_DIR="$AGVMGR_DIR/worker"
LOG_FILE="/var/log/agvmgr_worker.log"
SERVICE_FILE="/etc/systemd/system/agvmgr-worker.service"

DB_NAME="agvmgr"
DB_USER="robot"
DB_PASS="abrakadabra"
DB_HOST="localhost"

EXPECTED_VER="1.0.0"

OK=0; WARN=0; ERR=0; FIX=0

_ok()   { echo "  [OK]      $1"; OK=$((OK+1)); }
_warn() { echo "  [WARN]    $1"; WARN=$((WARN+1)); }
_err()  { echo "  [HIBA]    $1"; ERR=$((ERR+1)); }
_fix()  { echo "  [JAVÍTÁS] $1"; FIX=$((FIX+1)); }
_info() { echo "            $1"; }
_head() { echo ""; echo "══════════════════════════════════════════"; echo "  $1"; echo "══════════════════════════════════════════"; }

# ── Root ellenőrzés ───────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    echo ""
    echo "  HIBA: A script root jogosultságot igényel."
    echo "  Futtatás: sudo bash $0"
    echo ""
    exit 1
fi

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
    if [ "$VER" = "$EXPECTED_VER" ]; then
        _ok "version.txt: $VER"
    else
        _warn "Verzió: $VER (elvárás: $EXPECTED_VER)"
    fi
else
    _err "version.txt hiányzik"
fi

# ════════════════════════════════════════════════════════════════════
_head "2. Kötelező fájlok"

PHP_FILES=(
    "index.php" "login.php" "logout.php" "admin.php"
    "agvs.php" "omron.php"
    "broker_test.php" "omron_test.php" "coords_api.php"
    "db.php" "auth.php"
    "_header.php" "_footer.php"
    "styles.css" "setup.sql"
)
WORKER_FILES=("worker/mqtt_worker.py" "worker/requirements.txt" "worker/install.sh")
ASSET_FILES=("assets/bootstrap/bootstrap.min.css" "assets/bootstrap/bootstrap.bundle.min.js")

ALL_MISSING=0
for f in "${PHP_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then
        _ok "$f"
    else
        _err "$f – HIÁNYZIK"
        ALL_MISSING=$((ALL_MISSING+1))
    fi
done
for f in "${WORKER_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then
        _ok "$f"
    else
        _err "$f – HIÁNYZIK"
        ALL_MISSING=$((ALL_MISSING+1))
    fi
done
for f in "${ASSET_FILES[@]}"; do
    if [ -f "$AGVMGR_DIR/$f" ]; then
        _ok "$f"
    else
        _warn "$f – hiányzik (Bootstrap CDN nélkül nem fog megjelenni az UI)"
    fi
done

# ════════════════════════════════════════════════════════════════════
_head "3. MySQL elérés (root)"

# Megpróbálunk root-ként csatlakozni (socket auth)
MYSQL_ROOT="mysql --batch --skip-column-names"
if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
    _ok "MySQL root hozzáférés: socket auth"
else
    # Próbálkozzunk jelszó nélküli root-tal
    MYSQL_ROOT="mysql -u root --batch --skip-column-names"
    if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
        _ok "MySQL root hozzáférés: -u root (jelszó nélkül)"
    else
        _err "Nem sikerült MySQL root hozzáférés. Add meg a root jelszót:"
        read -s -r -p "  MySQL root jelszó (Enter = üres): " ROOT_PASS
        echo ""
        MYSQL_ROOT="mysql -u root -p${ROOT_PASS} --batch --skip-column-names"
        if $MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1; then
            _ok "MySQL root hozzáférés: jelszóval"
        else
            _err "MySQL root hozzáférés sikertelen. Állítsd be kézzel."
            echo ""; echo "MEGÁLLÍTVA: MySQL root hozzáférés nélkül nem folytatható."; exit 1
        fi
    fi
fi

# ════════════════════════════════════════════════════════════════════
_head "4. Adatbázis"

if $MYSQL_ROOT -e "USE $DB_NAME" > /dev/null 2>&1; then
    _ok "Adatbázis létezik: $DB_NAME"
else
    _fix "Adatbázis létrehozása: $DB_NAME"
    $MYSQL_ROOT -e "CREATE DATABASE IF NOT EXISTS $DB_NAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
    if [ $? -eq 0 ]; then
        _ok "Adatbázis létrehozva: $DB_NAME"
    else
        _err "Adatbázis létrehozása sikertelen"
        exit 1
    fi
fi

# ════════════════════════════════════════════════════════════════════
_head "5. MySQL felhasználó ($DB_USER)"

USER_EXISTS=$($MYSQL_ROOT -e "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER' AND host='localhost'" 2>/dev/null)
if [ "$USER_EXISTS" -ge 1 ] 2>/dev/null; then
    _ok "MySQL user létezik: $DB_USER@localhost"
    # Jogosultság ellenőrzés
    $MYSQL_ROOT -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" > /dev/null 2>&1
    $MYSQL_ROOT -e "FLUSH PRIVILEGES;" > /dev/null 2>&1
    _fix "Jogosultság frissítve: $DB_USER → $DB_NAME.*"
else
    _fix "MySQL user létrehozása: $DB_USER@localhost"
    $MYSQL_ROOT -e "CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';" 2>/dev/null
    $MYSQL_ROOT -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" > /dev/null 2>&1
    $MYSQL_ROOT -e "FLUSH PRIVILEGES;" > /dev/null 2>&1
    _ok "MySQL user létrehozva és jogosultság adva"
fi

# DB elérés tesztelése az agvmgr userrel
DB_AGV="mysql -h$DB_HOST -u$DB_USER -p$DB_PASS $DB_NAME --batch --skip-column-names"
if $DB_AGV -e "SELECT 1" > /dev/null 2>&1; then
    _ok "DB elérés: $DB_USER@$DB_HOST/$DB_NAME – OK"
else
    _err "DB elérés sikertelen a $DB_USER felhasználóval"
    exit 1
fi

# ════════════════════════════════════════════════════════════════════
_head "6. Adatbázis táblák"

create_table_if_missing() {
    local TABLE="$1"
    local DDL="$2"
    if $DB_AGV -e "DESCRIBE $TABLE" > /dev/null 2>&1; then
        _ok "Tábla létezik: $TABLE"
    else
        _fix "Tábla létrehozása: $TABLE"
        $MYSQL_ROOT $DB_NAME -e "$DDL" 2>&1
        if [ $? -eq 0 ]; then _ok "Tábla létrehozva: $TABLE"
        else _err "Tábla létrehozása sikertelen: $TABLE"; fi
    fi
}

create_table_if_missing "users" "
CREATE TABLE IF NOT EXISTS users (
    id       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created  DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

create_table_if_missing "mqtt_broker" "
CREATE TABLE IF NOT EXISTS mqtt_broker (
    id       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip       VARCHAR(100) NOT NULL DEFAULT '',
    port     INT NOT NULL DEFAULT 1883,
    username VARCHAR(100) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL DEFAULT '',
    enabled  TINYINT(1) NOT NULL DEFAULT 1,
    updated  DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

create_table_if_missing "agv" "
CREATE TABLE IF NOT EXISTS agv (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    manufacturer VARCHAR(100) NOT NULL DEFAULT '',
    type         VARCHAR(100) NOT NULL DEFAULT '',
    serial_no    VARCHAR(50) NOT NULL DEFAULT '',
    name         VARCHAR(100) NOT NULL DEFAULT '',
    topic        VARCHAR(255) NOT NULL DEFAULT '',
    enabled      TINYINT(1) NOT NULL DEFAULT 1,
    created      DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

create_table_if_missing "agv_coords" "
CREATE TABLE IF NOT EXISTS agv_coords (
    id                   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agv_id               INT NOT NULL,
    x                    DECIMAL(12,4) NULL,
    y                    DECIMAL(12,4) NULL,
    theta                DECIMAL(10,6) NULL,
    map_id               VARCHAR(100) NOT NULL DEFAULT '',
    position_initialized TINYINT(1) NULL,
    localization_score   DECIMAL(5,4) NULL,
    deviation_range      DECIMAL(10,4) NULL,
    vx                   DECIMAL(10,4) NULL,
    vy                   DECIMAL(10,4) NULL,
    omega                DECIMAL(10,6) NULL,
    battery_charge       DECIMAL(5,2) NULL,
    battery_voltage      DECIMAL(7,3) NULL,
    operating_mode       VARCHAR(20) NOT NULL DEFAULT '',
    driving              TINYINT(1) NULL,
    paused               TINYINT(1) NULL,
    source               VARCHAR(15) NOT NULL DEFAULT 'state',
    raw_payload          MEDIUMTEXT NULL,
    updated_at           DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
    UNIQUE KEY uk_agv (agv_id),
    FOREIGN KEY (agv_id) REFERENCES agv(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

create_table_if_missing "omron_broker" "
CREATE TABLE IF NOT EXISTS omron_broker (
    id       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip       VARCHAR(100) NOT NULL DEFAULT '',
    port     INT NOT NULL DEFAULT 1883,
    username VARCHAR(100) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL DEFAULT '',
    enabled  TINYINT(1) NOT NULL DEFAULT 0,
    updated  DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

create_table_if_missing "omron_forward" "
CREATE TABLE IF NOT EXISTS omron_forward (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agv_id         INT NOT NULL,
    topic_template VARCHAR(255) NOT NULL DEFAULT '',
    fields         JSON NOT NULL,
    enabled        TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_agv (agv_id),
    FOREIGN KEY (agv_id) REFERENCES agv(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

# ════════════════════════════════════════════════════════════════════
_head "7. Tábla mezők (upgrade ellenőrzés)"

check_column() {
    local TABLE="$1"; local COL="$2"; local DDL_ADD="$3"
    if $DB_AGV -e "SELECT $COL FROM $TABLE LIMIT 1" > /dev/null 2>&1; then
        _ok "$TABLE.$COL"
    else
        _fix "$TABLE.$COL hiányzik – hozzáadás"
        $DB_AGV -e "ALTER TABLE $TABLE ADD COLUMN $DDL_ADD;" 2>&1
        if [ $? -eq 0 ]; then _ok "$TABLE.$COL hozzáadva"
        else _err "$TABLE.$COL hozzáadása sikertelen"; fi
    fi
}

check_column "agv"        "type"                "type VARCHAR(100) NOT NULL DEFAULT '' AFTER manufacturer"
check_column "agv_coords" "theta"               "theta DECIMAL(10,6) NULL AFTER y"
check_column "agv_coords" "map_id"              "map_id VARCHAR(100) NOT NULL DEFAULT '' AFTER theta"
check_column "agv_coords" "position_initialized" "position_initialized TINYINT(1) NULL AFTER map_id"
check_column "agv_coords" "localization_score"  "localization_score DECIMAL(5,4) NULL AFTER position_initialized"
check_column "agv_coords" "deviation_range"     "deviation_range DECIMAL(10,4) NULL AFTER localization_score"
check_column "agv_coords" "vx"                  "vx DECIMAL(10,4) NULL AFTER deviation_range"
check_column "agv_coords" "vy"                  "vy DECIMAL(10,4) NULL AFTER vx"
check_column "agv_coords" "omega"               "omega DECIMAL(10,6) NULL AFTER vy"
check_column "agv_coords" "battery_charge"      "battery_charge DECIMAL(5,2) NULL AFTER omega"
check_column "agv_coords" "battery_voltage"     "battery_voltage DECIMAL(7,3) NULL AFTER battery_charge"
check_column "agv_coords" "operating_mode"      "operating_mode VARCHAR(20) NOT NULL DEFAULT '' AFTER battery_voltage"
check_column "agv_coords" "driving"             "driving TINYINT(1) NULL AFTER operating_mode"
check_column "agv_coords" "paused"              "paused TINYINT(1) NULL AFTER driving"
check_column "agv_coords" "source"              "source VARCHAR(15) NOT NULL DEFAULT 'state' AFTER paused"

# ════════════════════════════════════════════════════════════════════
_head "8. Alapértelmezett sorok (seed)"

# mqtt_broker default sor
BROKER_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM mqtt_broker WHERE id=1" 2>/dev/null)
if [ "$BROKER_CNT" -eq 0 ] 2>/dev/null; then
    $DB_AGV -e "INSERT INTO mqtt_broker (ip,port) VALUES ('',1883);" > /dev/null 2>&1
    _fix "mqtt_broker alapértelmezett sor létrehozva"
else
    _ok "mqtt_broker seed sor megvan"
fi

OMRON_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM omron_broker WHERE id=1" 2>/dev/null)
if [ "$OMRON_CNT" -eq 0 ] 2>/dev/null; then
    $DB_AGV -e "INSERT INTO omron_broker (ip,port,enabled) VALUES ('',1883,0);" > /dev/null 2>&1
    _fix "omron_broker alapértelmezett sor létrehozva"
else
    _ok "omron_broker seed sor megvan"
fi

# ════════════════════════════════════════════════════════════════════
_head "9. Admin felhasználó"

ADMIN_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM users WHERE username='admin'" 2>/dev/null)
if [ "$ADMIN_CNT" -ge 1 ] 2>/dev/null; then
    _ok "Admin user létezik (username: admin)"
else
    # Jelszó hash: admin1234
    HASH='$2y$10$HXmXJcIzP1or6Kdq9AWZLeJ210uQMuffUF0uRTnlpvvtKkoiLOBg.'
    $DB_AGV -e "INSERT INTO users (username,password,is_admin) VALUES ('admin','$HASH',1);" > /dev/null 2>&1
    _fix "Admin user létrehozva – belépés: admin / admin1234"
    _info "→ FONTOS: belépés után változtasd meg a jelszót!"
fi

# ════════════════════════════════════════════════════════════════════
_head "10. Python függőségek"

PYTHON=$(command -v python3)
if [ -z "$PYTHON" ]; then
    _err "python3 nem található"
else
    _ok "python3: $PYTHON ($(python3 --version 2>&1))"

    # paho-mqtt
    if python3 -c "import paho.mqtt.client" > /dev/null 2>&1; then
        _ok "paho-mqtt telepítve"
    else
        _fix "paho-mqtt telepítése..."
        if apt-get install -y python3-paho-mqtt > /dev/null 2>&1; then
            _ok "paho-mqtt telepítve (apt)"
        elif pip3 install paho-mqtt --break-system-packages > /dev/null 2>&1; then
            _ok "paho-mqtt telepítve (pip)"
        else
            _err "paho-mqtt telepítése sikertelen – próbáld: apt install python3-paho-mqtt"
        fi
    fi

    # mysql-connector-python
    if python3 -c "import mysql.connector" > /dev/null 2>&1; then
        _ok "mysql-connector-python telepítve"
    else
        _fix "mysql-connector-python telepítése..."
        if apt-get install -y python3-mysql.connector > /dev/null 2>&1; then
            _ok "mysql-connector-python telepítve (apt)"
        elif pip3 install mysql-connector-python --break-system-packages > /dev/null 2>&1; then
            _ok "mysql-connector-python telepítve (pip)"
        else
            _err "mysql-connector-python telepítése sikertelen"
        fi
    fi

    # Végső ellenőrzés
    if python3 -c "import paho.mqtt.client; import mysql.connector" > /dev/null 2>&1; then
        _ok "Worker Python függőségek: mind OK"
    else
        _err "Egy vagy több Python csomag még hiányzik"
    fi
fi

# ════════════════════════════════════════════════════════════════════
_head "11. Log fájl"

if [ -f "$LOG_FILE" ]; then
    _ok "Log fájl létezik: $LOG_FILE"
else
    touch "$LOG_FILE"
    _fix "Log fájl létrehozva: $LOG_FILE"
fi
chmod 666 "$LOG_FILE"
_ok "Log fájl jogosultság: 666"

# ════════════════════════════════════════════════════════════════════
_head "12. Worker jogosultságok"

chmod +x "$WORKER_DIR/mqtt_worker.py" 2>/dev/null && _ok "mqtt_worker.py: futtatható"
chmod +x "$WORKER_DIR/install.sh"    2>/dev/null && _ok "install.sh: futtatható"

# www-data olvashat minden fájlt
chown -R www-data:www-data "$AGVMGR_DIR" > /dev/null 2>&1
_fix "Tulajdonos beállítva: www-data:www-data → $AGVMGR_DIR"
find "$AGVMGR_DIR" -name "*.php" -exec chmod 644 {} \; 2>/dev/null
find "$AGVMGR_DIR" -name "*.py"  -exec chmod 755 {} \; 2>/dev/null
find "$AGVMGR_DIR" -name "*.sh"  -exec chmod 755 {} \; 2>/dev/null
find "$AGVMGR_DIR" -type d -exec chmod 755 {} \; 2>/dev/null
_ok "Fájl jogosultságok beállítva (PHP:644, PY/SH:755, könyvtárak:755)"

# ════════════════════════════════════════════════════════════════════
_head "13. Systemd service (agvmgr-worker)"

PYTHON_BIN=$(command -v python3)

if [ -f "$SERVICE_FILE" ]; then
    _ok "Service fájl létezik: $SERVICE_FILE"
else
    _fix "Service fájl létrehozása: $SERVICE_FILE"
    cat > "$SERVICE_FILE" << EOF
[Unit]
Description=agvmgr MQTT Worker – VDA5050 pozíció rögzítő és Omron forward
After=network.target mysql.service mariadb.service
Wants=network.target

[Service]
Type=simple
ExecStart=$PYTHON_BIN $WORKER_DIR/mqtt_worker.py
Restart=always
RestartSec=10
User=www-data
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

[Install]
WantedBy=multi-user.target
EOF
    _ok "Service fájl létrehozva"
fi

# Ellenőrizzük, hogy a worker path helyes a service fájlban
if grep -q "$WORKER_DIR/mqtt_worker.py" "$SERVICE_FILE"; then
    _ok "Service: worker path helyes"
else
    _warn "Service fájlban a worker path eltér a várttól: $WORKER_DIR/mqtt_worker.py"
    _info "→ Frissítsd a $SERVICE_FILE fájlt kézzel, vagy töröld és futtasd újra ezt a scriptet."
fi

systemctl daemon-reload > /dev/null 2>&1

if systemctl is-enabled agvmgr-worker > /dev/null 2>&1; then
    _ok "Service engedélyezve (autostart)"
else
    systemctl enable agvmgr-worker > /dev/null 2>&1
    _fix "Service engedélyezve (autostart)"
fi

# Worker futás állapota
if systemctl is-active --quiet agvmgr-worker; then
    _ok "Worker FUT (agvmgr-worker active)"
else
    _warn "Worker NEM fut – indítás..."
    systemctl start agvmgr-worker > /dev/null 2>&1
    sleep 2
    if systemctl is-active --quiet agvmgr-worker; then
        _fix "Worker sikeresen elindult"
    else
        _err "Worker indítása sikertelen"
        _info "→ Ellenőrzés: systemctl status agvmgr-worker"
        _info "→ Log: tail -20 $LOG_FILE"
    fi
fi

# ════════════════════════════════════════════════════════════════════
_head "14. MQTT broker konfig ellenőrzés"

BROKER_IP=$($DB_AGV -e "SELECT ip FROM mqtt_broker WHERE id=1" 2>/dev/null)
if [ -n "$BROKER_IP" ]; then
    _ok "AGV MQTT broker IP beállítva: $BROKER_IP"
else
    _warn "AGV MQTT broker IP nincs beállítva"
    _info "→ Állítsd be az admin felületen: http://<szerver>:<port>/agvmgr/admin.php"
fi

OMRON_ENA=$($DB_AGV -e "SELECT enabled FROM omron_broker WHERE id=1" 2>/dev/null)
if [ "$OMRON_ENA" = "1" ]; then
    OMRON_IP=$($DB_AGV -e "SELECT ip FROM omron_broker WHERE id=1" 2>/dev/null)
    if [ -n "$OMRON_IP" ]; then
        _ok "Omron forwarding engedélyezve → $OMRON_IP"
    else
        _warn "Omron forwarding engedélyezve, de nincs IP beállítva"
    fi
else
    _ok "Omron forwarding kikapcsolva (nem kötelező)"
fi

# ════════════════════════════════════════════════════════════════════
_head "15. AGV-k a DB-ben"

AGV_CNT=$($DB_AGV -e "SELECT COUNT(*) FROM agv" 2>/dev/null)
AGV_ENA=$($DB_AGV -e "SELECT COUNT(*) FROM agv WHERE enabled=1" 2>/dev/null)
if [ "$AGV_CNT" -ge 1 ] 2>/dev/null; then
    _ok "AGV-k a DB-ben: $AGV_CNT db (aktív: $AGV_ENA)"
    $DB_AGV -e "SELECT CONCAT('  → #',id,' ',manufacturer,' ',serial_no,' [',IF(enabled,'aktív','letiltva'),'] topic:',topic) FROM agv;" 2>/dev/null
else
    _warn "Nincs AGV felvéve az adatbázisban"
    _info "→ Add hozzá az admin felületen: http://<szerver>:<port>/agvmgr/admin.php"
fi

# ════════════════════════════════════════════════════════════════════
_head "16. Webszerver elérhetőség"

# Megkeressük, melyik Apache porton érhető el a PM modul (és ezzel az agvmgr)
PM_PORT=""
for conf in /etc/apache2/sites-enabled/*.conf; do
    if grep -q "/var/www/html/pm" "$conf" 2>/dev/null; then
        PM_PORT=$(grep -m1 "VirtualHost" "$conf" | grep -oP ':\K[0-9]+')
        break
    fi
done

if [ -n "$PM_PORT" ]; then
    _ok "PM Apache konfig megtalálva: port $PM_PORT"
    _info "→ agvmgr elérhető: http://$(hostname -I | awk '{print $1}'):$PM_PORT/agvmgr/"
    # Gyors HTTP teszt
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        --connect-timeout 3 "http://127.0.0.1:$PM_PORT/agvmgr/login.php" 2>/dev/null)
    if [ "$HTTP_CODE" = "200" ]; then
        _ok "HTTP válasz: 200 OK (login.php elérhető)"
    elif [ -n "$HTTP_CODE" ]; then
        _warn "HTTP válasz: $HTTP_CODE (várható: 200)"
    else
        _warn "HTTP teszt sikertelen (curl nem elérhető vagy nem fut az Apache)"
    fi
else
    _warn "PM Apache konfig nem található /etc/apache2/sites-enabled/-ben"
    _info "→ Az agvmgr-nek nincs szükség külön vhost-ra, a PM alatt érhető el."
    _info "→ Ellenőrizd: ls /etc/apache2/sites-enabled/"
fi

# ════════════════════════════════════════════════════════════════════
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
printf "║  Eredmény:  ✓ OK: %-4d  ⚑ JAVÍTÁS: %-4d  ✗ HIBA: %-4d    ║\n" "$OK" "$FIX" "$ERR"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

if [ "$ERR" -gt 0 ]; then
    echo "  Vannak hibák – nézd meg a [HIBA] sorokat fentebb."
    echo ""
    exit 1
elif [ "$WARN" -gt 0 ]; then
    echo "  Minden kritikus ellenőrzés OK, de vannak figyelmeztetések."
    echo "  → Worker log: tail -f $LOG_FILE"
    echo ""
    exit 0
else
    echo "  Minden rendben! Az agvmgr készen áll."
    echo "  → Worker log: tail -f $LOG_FILE"
    echo ""
    exit 0
fi

#!/bin/bash
set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== Tricc setup ==="

# --- config.php ---
if [ ! -f config.php ]; then
    cp config.example.php config.php
    SECRET=$(tr -dc 'A-Za-z0-9!@#$%' < /dev/urandom | head -c 40)
    sed -i "s/CHANGE_ME_RANDOM_32_CHARS/$SECRET/" config.php
    echo "[OK] config.php létrehozva – töltsd ki az adatbázis és APNs adatokat!"
else
    echo "[--] config.php már létezik, kihagyva."
fi

# --- composer ---
if [ ! -d vendor ]; then
    if command -v composer &>/dev/null; then
        composer install --no-dev --optimize-autoloader
    else
        echo "[!!] Composer nem található. Telepítsd majd futtasd: composer install"
    fi
else
    echo "[--] vendor/ már létezik."
fi

# --- uploads könyvtár ---
mkdir -p uploads/avatars
chown -R www-data:www-data uploads/
chmod -R 755 uploads/
echo "[OK] uploads/ könyvtár kész."

# --- adatbázis ---
if [ -f db/schema.sql ]; then
    read -rp "Futtatod a db/schema.sql-t? (i/N) " ans
    if [[ "$ans" =~ ^[Ii]$ ]]; then
        read -rp "  MySQL felhasználó: " DBUSER
        read -rsp "  MySQL jelszó: " DBPASS; echo
        read -rp "  Adatbázis neve [tricc]: " DBNAME
        DBNAME=${DBNAME:-tricc}
        mysql -u"$DBUSER" -p"$DBPASS" -e "CREATE DATABASE IF NOT EXISTS \`$DBNAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        mysql -u"$DBUSER" -p"$DBPASS" "$DBNAME" < db/schema.sql
        echo "[OK] Séma importálva."
    fi
fi

# --- Apache ---
if [ -f apache/tricc.conf ]; then
    read -rp "Apache vhost telepítése? (i/N) " ans
    if [[ "$ans" =~ ^[Ii]$ ]]; then
        cp apache/tricc.conf /etc/apache2/sites-available/tricc.conf
        # 9453-as port hozzáadása, ha még nincs
        if ! grep -q "Listen 9453" /etc/apache2/ports.conf 2>/dev/null; then
            echo "Listen 9453" >> /etc/apache2/ports.conf
        fi
        a2ensite tricc.conf
        a2enmod rewrite
        apache2ctl configtest && systemctl reload apache2
        echo "[OK] Apache vhost aktív (port 9453)."
    fi
fi

# --- systemd WebSocket szerver ---
if [ -f systemd/tricc-ws.service ]; then
    read -rp "WebSocket systemd service telepítése? (i/N) " ans
    if [[ "$ans" =~ ^[Ii]$ ]]; then
        cp systemd/tricc-ws.service /etc/systemd/system/tricc-ws.service
        systemctl daemon-reload
        systemctl enable tricc-ws
        systemctl start tricc-ws
        echo "[OK] tricc-ws.service elindítva."
    fi
fi

echo ""
echo "=== Kész ==="
echo "  REST API:   http://<server>:9453/auth/login"
echo "  WebSocket:  ws://<server>:9454"
echo "  Config:     $SCRIPT_DIR/config.php"

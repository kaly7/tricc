#!/bin/bash
# agvmgr MQTT worker telepítő
# Futtatás: sudo bash install.sh
set -e

WORKER_DIR="$(cd "$(dirname "$0")" && pwd)"
SERVICE_FILE="/etc/systemd/system/agvmgr-worker.service"

echo "=== agvmgr MQTT worker telepítés ==="
echo "Worker könyvtár: $WORKER_DIR"

# PHP CLI ellenőrzés
echo ""
echo ">>> PHP CLI ellenőrzése..."
PHP_BIN=$(command -v php || true)
if [ -z "$PHP_BIN" ]; then
    echo "HIBA: php-cli nem található. Telepítés: sudo apt install php-cli php-mysql"
    exit 1
fi
echo "    OK: $PHP_BIN ($(php --version | head -1))"

# Log fájl jogosultság
touch /var/log/agvmgr_worker.log
chmod 666 /var/log/agvmgr_worker.log

# Systemd service
echo ""
echo ">>> Systemd service létrehozása: $SERVICE_FILE"
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=agvmgr MQTT Worker – VDA5050 pozíció rögzítő és Omron forward
After=network.target mysql.service mariadb.service
Wants=mysql.service mariadb.service

[Service]
Type=simple
ExecStart=$PHP_BIN $WORKER_DIR/mqtt_worker.php
Restart=always
RestartSec=10
User=www-data
StandardOutput=append:/var/log/agvmgr_worker.log
StandardError=append:/var/log/agvmgr_worker.log

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable agvmgr-worker
systemctl restart agvmgr-worker

echo ""
echo "=== Kész ==="
echo "Státusz: systemctl status agvmgr-worker"
echo "Log:     tail -f /var/log/agvmgr_worker.log"

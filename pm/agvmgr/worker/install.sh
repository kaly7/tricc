#!/bin/bash
# agvmgr MQTT worker telepítő
# Futtatás: sudo bash install.sh
set -e

WORKER_DIR="$(cd "$(dirname "$0")" && pwd)"
SERVICE_FILE="/etc/systemd/system/agvmgr-worker.service"

echo "=== agvmgr MQTT worker telepítés ==="
echo "Worker könyvtár: $WORKER_DIR"

# Python csomagok
echo ""
echo ">>> Python függőségek telepítése..."
pip3 install -r "$WORKER_DIR/requirements.txt" --quiet

# Log fájl jogosultság
touch /var/log/agvmgr_worker.log
chmod 666 /var/log/agvmgr_worker.log

# Systemd service
echo ""
echo ">>> Systemd service létrehozása: $SERVICE_FILE"
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=agvmgr MQTT Worker – VDA5050 pozíció rögzítő
After=network.target mysql.service
Wants=mysql.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 $WORKER_DIR/mqtt_worker.py
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

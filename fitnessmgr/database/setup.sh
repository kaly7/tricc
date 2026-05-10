#!/bin/bash
# Fitnessmgr telepítő script
# Futtatás: sudo bash /var/www/html/fitnessmgr/database/setup.sh

set -e

echo "=== Fitnessmgr telepítés ==="

# 1. Adatbázis létrehozás
echo "[1/4] Adatbázis létrehozása..."
mysql -e "CREATE DATABASE IF NOT EXISTS fitnessmgr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;"
mysql -e "GRANT ALL PRIVILEGES ON fitnessmgr_db.* TO 'ppdb'@'localhost'; FLUSH PRIVILEGES;"
mysql -uppdb -pabrakadabra fitnessmgr_db < /var/www/html/fitnessmgr/database/fitnessmgr_create.sql
echo "    OK: adatbázis és táblák létrehozva, seed adatok betöltve"

# 2. Apache port hozzáadás
echo "[2/4] Apache port 9448 beállítása..."
if ! grep -q "Listen 9448" /etc/apache2/ports.conf; then
  echo "Listen 9448" >> /etc/apache2/ports.conf
  echo "    OK: port 9448 hozzáadva a ports.conf-hoz"
else
  echo "    (port 9448 már szerepelt)"
fi

# 3. Apache site engedélyezés
echo "[3/4] Apache site aktiválása..."
cp /var/www/html/fitnessmgr/database/fitnessmgr-9448.conf /etc/apache2/sites-available/
a2ensite fitnessmgr-9448.conf
apache2ctl configtest && systemctl reload apache2
echo "    OK: Apache újratöltve"

# 4. Auth center regisztráció
echo "[4/4] Modul regisztrálása Auth Center-ben..."
mysql -uppdb -pabrakadabra auth_db -e "
  INSERT IGNORE INTO modules (module_key, module_name, port, path, is_enabled)
  VALUES ('fitnessmgr', 'Fitness napló', 9448, '/', 1);
"

# Az admin usernek jogot adunk
ADMIN_ID=$(mysql -uppdb -pabrakadabra auth_db -N -e "SELECT id FROM users WHERE username='kalamar.janos' OR username='admin' LIMIT 1;" 2>/dev/null || echo "")
if [ -n "$ADMIN_ID" ]; then
  MODULE_ID=$(mysql -uppdb -pabrakadabra auth_db -N -e "SELECT id FROM modules WHERE module_key='fitnessmgr' LIMIT 1;")
  ROLE_ID=$(mysql -uppdb -pabrakadabra auth_db -N -e "SELECT id FROM roles WHERE role_key='admin' LIMIT 1;")
  mysql -uppdb -pabrakadabra auth_db -e "
    INSERT IGNORE INTO user_module_roles (user_id, module_id, role_id)
    VALUES ($ADMIN_ID, $MODULE_ID, $ROLE_ID);
  "
  echo "    OK: admin jogosultság beállítva"
fi

echo ""
echo "=== Telepítés kész! ==="
echo "Fitness napló: http://$(hostname -I | awk '{print $1}'):9448"
echo ""
echo "Következő lépések:"
echo "  1. Profil: http://localhost:9448/goals.php"
echo "  2. Mattermost webhook beállítása: app/config.php → mattermost.incoming_webhook_url"
echo "  3. Claude API kulcs (opcionális): app/config.php → claude.api_key"
echo "  4. Cron job-ok: crontab -e (lásd goals.php oldal)"

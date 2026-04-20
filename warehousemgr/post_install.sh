#!/usr/bin/env bash
set -e
BASE="${1:-/var/www/html/time_tracker_8788}"
WWW_USER="${2:-www-data}"
WWW_GROUP="${3:-www-data}"
mkdir -p "$BASE/storage/mail" "$BASE/storage/logs" "$BASE/storage/mpdf"
chown -R "$WWW_USER":"$WWW_GROUP" "$BASE"
find "$BASE" -type d -exec chmod 755 {} \;
find "$BASE" -type f -exec chmod 644 {} \;
find "$BASE/storage" -type d -exec chmod 775 {} \;
find "$BASE/storage" -type f -exec chmod 664 {} \;
echo "Jogosultságok beállítva: $BASE"

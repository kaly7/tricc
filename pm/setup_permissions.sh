#!/bin/bash
# ============================================================
# Pannon Mechanika – PM rendszer jogosultság-beállító
#
# Használat:
#   sudo bash setup_permissions.sh [PM_KÖNYVTÁR]
#
# Ha nincs megadva könyvtár, a script saját könyvtárát használja.
# Példa:
#   sudo bash setup_permissions.sh /opt/pm
#   sudo bash setup_permissions.sh /var/www/html/pm
#
# Folyamatok és írási igények:
#   Apache/PHP (www-data): tmp/newfile.txt, tmp/pp_log.txt,
#                          tmp/timer_*.fleet, tmp/timer_pp_*.fleet
#   go.pl (www-data exec): tmp/talk.fleet
#   timer.pl (cron):       tmp/talk.fleet_timer, tmp/talk_timer.log,
#                          tmp/*.fleet olvasás + törlés
# ============================================================

set -e

if [ "$(id -u)" -ne 0 ]; then
    echo "HIBA: root jogosultság szükséges. Futtasd: sudo bash $0"
    exit 1
fi

# Könyvtár: első argumentum, vagy a script saját helye
if [ -n "$1" ]; then
    PM_DIR="$(realpath "$1")"
else
    PM_DIR="$(cd "$(dirname "$0")" && pwd)"
fi

if [ ! -d "$PM_DIR" ]; then
    echo "HIBA: A könyvtár nem létezik: $PM_DIR"
    exit 1
fi

echo "PM könyvtár: $PM_DIR"
echo ""
read -r -p "Folytatod? [i/N] " CONFIRM
if [[ ! "$CONFIRM" =~ ^[iI]$ ]]; then
    echo "Megszakítva."
    exit 0
fi

# ------------------------------------------------------------
# 1. Könyvtárak
# ------------------------------------------------------------

# Főkönyvtár és alkönyvtárak: Apache olvashatja, www-data futtathatja
chmod 755 "$PM_DIR"
chmod 755 "$PM_DIR/napok"
chmod 755 "$PM_DIR/days"
chmod 755 "$PM_DIR/pictures"

# tmp/: PHP (www-data) és cron (root/kaly) is ír → 777
# A webszerver és a cron különböző userként ír ugyanide,
# ezért szükséges a teljes írási jog.
chmod 777 "$PM_DIR/tmp"

echo "[OK] Könyvtár jogosultságok beállítva"

# ------------------------------------------------------------
# 2. Minden PHP, CSS, JS, képfájl: 644 (olvasható, nem futtatható)
# ------------------------------------------------------------
find "$PM_DIR" -type f \( \
    -name "*.php" -o \
    -name "*.html" -o \
    -name "*.css" -o \
    -name "*.js" -o \
    -name "*.jpg" -o \
    -name "*.jpeg" -o \
    -name "*.png" -o \
    -name "*.sql" -o \
    -name "*.txt" -o \
    -name "*.old" \
\) -exec chmod 644 {} \;

echo "[OK] PHP/web fájlok: 644"

# ------------------------------------------------------------
# 3. Shell és Perl scriptek: 755 (futtatható)
# ------------------------------------------------------------
chmod 755 \
    "$PM_DIR/timer.sh" \
    "$PM_DIR/timer.pl" \
    "$PM_DIR/go.pl" \
    "$PM_DIR/goals.pl" \
    "$PM_DIR/goal_indit.sh" \
    "$PM_DIR/ntp_check.sh" \
    "$PM_DIR/query_multi.pl"

echo "[OK] Shell/Perl scriptek: 755"

# ------------------------------------------------------------
# 4. Fleet fájlok
#    goals.fleet: system("goals.fleet > ...") hívással futtatják → 755
#    talk.fleet:  expect <fájl> paranccsal értelmezik → 644
# ------------------------------------------------------------
chmod 755 "$PM_DIR/goals.fleet"
chmod 644 "$PM_DIR/talk.fleet"

echo "[OK] Fleet fájlok jogosultságai beállítva"

# ------------------------------------------------------------
# 5. tmp/ fájlok: 666 (PHP + cron mindkettő írhatja)
#    A dynamikusan generált fájlok jogosultsága az umask-tól
#    függhet, ezért a meglévőket explicit beállítjuk.
# ------------------------------------------------------------
find "$PM_DIR/tmp" -type f -exec chmod 666 {} \;

# Státuszfájlok (GYURI, MARCI): PHP olvassa, cron írja/olvassa
chmod 666 "$PM_DIR/tmp/GYURI" 2>/dev/null || true
chmod 666 "$PM_DIR/tmp/MARCI" 2>/dev/null || true

echo "[OK] tmp/ fájlok: 666"

# ------------------------------------------------------------
# 6. Tulajdonos: www-data:www-data az egész pm/ fára
#    Kivétel: scriptek maradhatnak kaly:www-data-k is,
#    a lényeg, hogy www-data olvasni/írni tudja a szükséges helyeket.
# ------------------------------------------------------------
chown -R www-data:www-data "$PM_DIR/tmp"
chown www-data:www-data "$PM_DIR/goals.fleet" "$PM_DIR/talk.fleet"

echo "[OK] Tulajdonos: www-data:www-data a tmp/ könyvtárra"

# ------------------------------------------------------------
# 7. Ellenőrzés
# ------------------------------------------------------------
echo ""
echo "=== Ellenőrzés ==="
echo "--- tmp/ könyvtár ---"
ls -la "$PM_DIR/tmp/"
echo ""
echo "--- Scriptek ---"
ls -la "$PM_DIR/"*.sh "$PM_DIR/"*.pl "$PM_DIR/"*.fleet
echo ""
echo "KÉSZ. Ellenőrizd a crontab bejegyzést is:"
echo "  crontab -e   (a cront futtató felhasználóként)"
echo "  pl.: * * * * * $PM_DIR/timer.sh >> /var/log/pm_timer.log 2>&1"

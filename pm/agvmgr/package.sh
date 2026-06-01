#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  agvmgr – csomagoló script
#  Létrehoz egy telepíthető tar.gz-t a /tmp könyvtárban.
#  Futtatás: bash package.sh
# ═══════════════════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VER=$(tr -d '[:space:]' < "$SCRIPT_DIR/version.txt" 2>/dev/null || echo "unknown")
DATE=$(date '+%Y%m%d')
OUT="/tmp/agvmgr-v${VER}-${DATE}.zip"

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   agvmgr – csomagolás                                        ║"
echo "║   Verzió : $VER                                              ║"
echo "║   Kimenet: $OUT"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Ellenőrzés: a setup script létezik-e
if [ ! -f "$SCRIPT_DIR/agvmgr_setup.sh" ]; then
    echo "  HIBA: agvmgr_setup.sh nem található: $SCRIPT_DIR"
    exit 1
fi

# Csomag tartalom – kizárások:
#   .git és git-fájlok
#   Python worker (nem szükséges, PHP worker használatos)
#   IDE fájlok

echo "  Csomagolás..."
cd "$(dirname "$SCRIPT_DIR")"
zip -r "$OUT" "$(basename "$SCRIPT_DIR")" \
    --exclude="*/.git/*" \
    --exclude="*/.gitignore" \
    --exclude="*/worker/mqtt_worker.py" \
    --exclude="*/worker/requirements.txt" \
    --exclude="*/__pycache__/*" \
    --exclude="*.pyc" \
    > /dev/null

if [ $? -eq 0 ]; then
    SIZE=$(du -sh "$OUT" | cut -f1)
    echo ""
    echo "  ✓ Csomag elkészült: $OUT ($SIZE)"
    echo ""
    echo "  Átvitel SCP-vel:"
    echo "    scp $OUT felhasznalo@cel-gep:/tmp/"
    echo ""
    echo "  Telepítés a célgépen:"
    echo "    unzip /tmp/$(basename $OUT) -d /var/www/html/pm/"
    echo "    sudo bash /var/www/html/pm/agvmgr/agvmgr_setup.sh"
    echo ""
else
    echo "  HIBA: csomagolás sikertelen."
    exit 1
fi

#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  agvmgr – csomagoló script
#  Létrehoz egy telepíthető tar.gz-t a /tmp könyvtárban.
#  Futtatás: bash package.sh
# ═══════════════════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VER=$(tr -d '[:space:]' < "$SCRIPT_DIR/version.txt" 2>/dev/null || echo "unknown")
DATE=$(date '+%Y%m%d')
PKG_NAME="agvmgr-v${VER}-${DATE}"
OUT="/tmp/${PKG_NAME}.zip"

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   agvmgr – csomagolás                                        ║"
echo "║   Verzió : $VER                                              ║"
echo "║   Kimenet: $OUT"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

if [ ! -f "$SCRIPT_DIR/agvmgr_setup.sh" ]; then
    echo "  HIBA: agvmgr_setup.sh nem található"
    exit 1
fi

echo "  Csomagolás..."

# A pm/ könyvtárból csomagolunk, hogy a kicsomagolás
# /var/www/html/pm/agvmgr/ alá kerüljön.
cd "$(dirname "$SCRIPT_DIR")"

zip -r "$OUT" "$(basename "$SCRIPT_DIR")" \
    --exclude "*/.git/*" \
    --exclude "*/.gitignore" \
    --exclude "*/docs/plans/*" \
    --exclude "*/worker/mqtt_worker.py" \
    --exclude "*/worker/requirements.txt" \
    --exclude "*/__pycache__/*" \
    --exclude "*.pyc" \
    > /dev/null

if [ $? -ne 0 ]; then
    echo "  HIBA: csomagolás sikertelen."
    exit 1
fi

SIZE=$(du -sh "$OUT" | cut -f1)
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')

echo ""
echo "  ✓ Csomag elkészült: $OUT ($SIZE)"
echo ""
echo "  ┌─────────────────────────────────────────────────────────┐"
echo "  │  Átvitel a célgépre                                     │"
echo "  └─────────────────────────────────────────────────────────┘"
echo "    scp $OUT felhasznalo@cel-gep:/tmp/"
echo ""
echo "  ┌─────────────────────────────────────────────────────────┐"
echo "  │  Telepítés a célgépen                                   │"
echo "  └─────────────────────────────────────────────────────────┘"
echo "    # Kicsomagolás a pm/ könyvtárba:"
echo "    mkdir -p /var/www/html/pm"
echo "    unzip /tmp/$(basename "$OUT") -d /var/www/html/pm/"
echo ""
echo "    # Telepítő futtatása (sudo jelszót kéri):"
echo "    bash /var/www/html/pm/agvmgr/agvmgr_setup.sh"
echo ""
echo "  ┌─────────────────────────────────────────────────────────┐"
echo "  │  Tartalom ellenőrzése                                   │"
echo "  └─────────────────────────────────────────────────────────┘"
echo "    unzip -l $OUT | head -30"
echo ""

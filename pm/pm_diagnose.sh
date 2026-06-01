#!/bin/bash
# pm_diagnose.sh – PM modul v1.0.5 diagnosztika és automatikus javítás
# Futtatás: bash /var/www/html/pm/pm_diagnose.sh

PM_DIR="/var/www/html/pm"
TMP_DIR="$PM_DIR/tmp"
DB_HOST="localhost"
DB_USER="robot"
DB_PASS="abrakadabra"
DB_NAME="Robot"
EXPECTED_VER="1.0.5"

OK=0; WARN=0; ERR=0; FIX=0

_ok()   { echo "  [OK]      $1"; OK=$((OK+1)); }
_warn() { echo "  [WARN]    $1"; WARN=$((WARN+1)); }
_err()  { echo "  [HIBA]    $1"; ERR=$((ERR+1)); }
_fix()  { echo "  [JAVÍTÁS] $1"; FIX=$((FIX+1)); }
_info() { echo "            $1"; }
_head() { echo ""; echo "=== $1 ==="; }

DB="mysql -h$DB_HOST -u$DB_USER -p$DB_PASS $DB_NAME -s --skip-column-names"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║   PM modul – diagnosztika v$EXPECTED_VER                         ║"
echo "║   $(date '+%Y-%m-%d %H:%M:%S')                                ║"
echo "╚══════════════════════════════════════════════════════════╝"

# ─────────────────────────────────────────────
_head "1. Verzió"

VER_FILE="$PM_DIR/version.txt"
if [ -f "$VER_FILE" ]; then
    VER=$(cat "$VER_FILE" | tr -d '[:space:]')
    if [ "$VER" = "$EXPECTED_VER" ]; then
        _ok "Verzió: $VER (elvárás: $EXPECTED_VER)"
    else
        _err "Verzió: $VER – elvárás: $EXPECTED_VER"
        _info "→ A telepített verzió RÉGI. Töltsd fel az pm_1.0.5.zip-et és csomagold ki."
    fi
else
    _err "version.txt nem található ($VER_FILE)"
fi

# ─────────────────────────────────────────────
_head "2. Adatbázis kapcsolat"

if $DB -e "SELECT 1" > /dev/null 2>&1; then
    _ok "MySQL: $DB_USER@$DB_HOST/$DB_NAME"
else
    _err "Nem sikerült csatlakozni: $DB_USER@$DB_HOST/$DB_NAME"
    echo ""; echo "MEGÁLLÍTVA: DB nélkül a többi ellenőrzés értelmetlen."; exit 1
fi

# ─────────────────────────────────────────────
_head "3. fm_jobs_live tábla (1.0.x újítás – jobok sorrendje és láthatósága)"

if $DB -e "DESCRIBE fm_jobs_live" > /dev/null 2>&1; then
    _ok "fm_jobs_live tábla létezik"

    # Kötelező oszlopok
    for col in pickup_id job_id goal robot status fm_kezdes; do
        if $DB -e "SELECT $col FROM fm_jobs_live LIMIT 1" > /dev/null 2>&1; then
            _ok "  fm_jobs_live.$col oszlop megvan"
        else
            _err "  fm_jobs_live.$col oszlop HIÁNYZIK"
            _info "→ Futtasd: mysql -u$DB_USER -p$DB_PASS $DB_NAME < $PM_DIR/migrate_fm_jobs_live.sql"
        fi
    done

    CNT=$($DB -e "SELECT COUNT(*) FROM fm_jobs_live" 2>/dev/null)
    _info "Jelenleg $CNT aktív pickup az FM-ben"
else
    _err "fm_jobs_live tábla HIÁNYZIK – létrehozás..."
    $DB -e "
CREATE TABLE IF NOT EXISTS fm_jobs_live (
    pickup_id  VARCHAR(50)  NOT NULL PRIMARY KEY,
    job_id     VARCHAR(50)  NOT NULL,
    goal       VARCHAR(100) DEFAULT '',
    robot      VARCHAR(50)  DEFAULT '',
    status     VARCHAR(30)  DEFAULT '',
    fm_kezdes  DATETIME     NULL,
    frissitve  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_job_id (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" 2>/dev/null \
    && { _fix "fm_jobs_live tábla létrehozva"; } \
    || _err "fm_jobs_live tábla létrehozása SIKERTELEN"
fi

# ─────────────────────────────────────────────
_head "4. Button_Goals tábla kiegészítő oszlopok"

for col in pickup_id pickup_status robot_nev fm_kezdes fm_vegzes; do
    if $DB -e "SELECT $col FROM Button_Goals LIMIT 1" > /dev/null 2>&1; then
        _ok "Button_Goals.$col megvan"
    else
        _warn "Button_Goals.$col HIÁNYZIK – hozzáadás..."
        case $col in
            pickup_id|pickup_status|robot_nev)
                $DB -e "ALTER TABLE Button_Goals ADD COLUMN $col VARCHAR(100) DEFAULT NULL" 2>/dev/null \
                && _fix "Button_Goals.$col hozzáadva" || _err "Button_Goals.$col hozzáadása SIKERTELEN" ;;
            fm_kezdes|fm_vegzes)
                $DB -e "ALTER TABLE Button_Goals ADD COLUMN $col VARCHAR(30) DEFAULT NULL" 2>/dev/null \
                && _fix "Button_Goals.$col hozzáadva" || _err "Button_Goals.$col hozzáadása SIKERTELEN" ;;
        esac
    fi
done

# ─────────────────────────────────────────────
_head "5. Robots tábla kiegészítő oszlopok"

for col in availability fm_status frissitve; do
    if $DB -e "SELECT $col FROM Robots LIMIT 1" > /dev/null 2>&1; then
        _ok "Robots.$col megvan"
    else
        _warn "Robots.$col HIÁNYZIK – hozzáadás..."
        case $col in
            availability|fm_status)
                $DB -e "ALTER TABLE Robots ADD COLUMN $col VARCHAR(50) DEFAULT '' AFTER Active" 2>/dev/null \
                && _fix "Robots.$col hozzáadva" || _err "Robots.$col hozzáadása SIKERTELEN" ;;
            frissitve)
                $DB -e "ALTER TABLE Robots ADD COLUMN $col DATETIME NULL" 2>/dev/null \
                && _fix "Robots.$col hozzáadva" || _err "Robots.$col hozzáadása SIKERTELEN" ;;
        esac
    fi
done

# ─────────────────────────────────────────────
_head "6. pm_konfig tábla (pont_pont és robot_ide job láthatóság)"

if $DB -e "SELECT * FROM pm_konfig LIMIT 1" > /dev/null 2>&1; then
    _ok "pm_konfig tábla létezik"

    PP_VIS=$($DB -e "SELECT ertek FROM pm_konfig WHERE kulcs='pp_job_lathatosag' LIMIT 1" 2>/dev/null)
    if [ -z "$PP_VIS" ]; then
        _warn "pp_job_lathatosag bejegyzés HIÁNYZIK – létrehozás 'sajat' értékkel..."
        $DB -e "INSERT INTO pm_konfig (kulcs, ertek) VALUES ('pp_job_lathatosag', 'sajat')" 2>/dev/null \
        && _fix "pp_job_lathatosag='sajat' beállítva" \
        || _err "pm_konfig INSERT SIKERTELEN"
    elif [ "$PP_VIS" = "semmi" ]; then
        _err "pp_job_lathatosag='semmi' → pont_pont menüben NEM látszanak a jobok!"
        _info "→ Javítás: UPDATE pm_konfig SET ertek='sajat' WHERE kulcs='pp_job_lathatosag';"
        $DB -e "UPDATE pm_konfig SET ertek='sajat' WHERE kulcs='pp_job_lathatosag'" 2>/dev/null \
        && _fix "pp_job_lathatosag='sajat'-ra javítva" \
        || _err "pm_konfig UPDATE SIKERTELEN"
    else
        _ok "pp_job_lathatosag='$PP_VIS'"
    fi
else
    _warn "pm_konfig tábla HIÁNYZIK – létrehozás..."
    $DB -e "
CREATE TABLE IF NOT EXISTS pm_konfig (
    id    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kulcs VARCHAR(50) NOT NULL UNIQUE,
    ertek VARCHAR(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO pm_konfig (kulcs, ertek) VALUES ('pp_job_lathatosag', 'sajat');
" 2>/dev/null && _fix "pm_konfig tábla létrehozva, pp_job_lathatosag='sajat'" \
            || _err "pm_konfig tábla létrehozása SIKERTELEN"
fi

# ─────────────────────────────────────────────
_head "7. CSS: badge színek (1.0.5 újítás – szürke/kék/zöld goal-ok)"

STYLES="$PM_DIR/styles.css"
if [ -f "$STYLES" ]; then
    if grep -q "bg-secondary" "$STYLES"; then
        _ok "styles.css tartalmazza a bg-secondary osztályt"
    else
        _err "styles.css-ben HIÁNYZIK a bg-secondary – régi fájl! (badge-ek mindig kékek lesznek)"
        _info "→ Töltsd fel az 1.0.5-ös ZIP-et (styles.css frissítve)"
    fi
    if grep -q "bg-primary" "$STYLES"; then
        _ok "styles.css tartalmazza a bg-primary osztályt"
    else
        _warn "styles.css-ben hiányzik a bg-primary (badges lehet hogy nem jelennek meg helyesen)"
    fi
else
    _err "styles.css nem található: $STYLES"
fi

# ─────────────────────────────────────────────
_head "8. Kulcsfontosságú PHP fájlok ellenőrzése"

# jobok_api.php: fm_status visszaadása (1.0.x)
JOBOK="$PM_DIR/jobok_api.php"
if [ -f "$JOBOK" ]; then
    if grep -q "fm_jobs_live" "$JOBOK"; then
        _ok "jobok_api.php: fm_jobs_live JOIN megvan (1.0.x verzió)"
    else
        _err "jobok_api.php: fm_jobs_live JOIN HIÁNYZIK – régi fájl! (pickup_status-t használ, ami nem frissül)"
        _info "→ Töltsd fel az 1.0.5-ös ZIP-et"
    fi
else
    _err "jobok_api.php nem található"
fi

# status_api.php: UNION lekérdezés (frissen indított jobok láthatósága)
STATUS="$PM_DIR/status_api.php"
if [ -f "$STATUS" ]; then
    if grep -q "NOT EXISTS" "$STATUS"; then
        _ok "status_api.php: UNION lekérdezés megvan (friss jobok azonnal látszanak)"
    else
        _err "status_api.php: UNION lekérdezés HIÁNYZIK – frissen indított jobok csak 15mp múlva látszanak"
        _info "→ Töltsd fel az 1.0.5-ös ZIP-et"
    fi
else
    _err "status_api.php nem található"
fi

# cron_poll.php: fm_responded check (FM nélküli stabilitás)
CRON="$PM_DIR/cron_poll.php"
if [ -f "$CRON" ]; then
    if grep -q "fm_responded" "$CRON"; then
        _ok "cron_poll.php: fm_responded check megvan (FM nélkül nem zárja le a jobokat)"
    else
        _err "cron_poll.php: fm_responded check HIÁNYZIK – FM elérhetetlenség esetén a jobok törlődhetnek!"
        _info "→ Töltsd fel az 1.0.5-ös ZIP-et"
    fi
else
    _err "cron_poll.php nem található"
fi

# query_multi.pl: @fm_lines log (tömör comm log)
QM="$PM_DIR/query_multi.pl"
if [ -f "$QM" ]; then
    if grep -q '@fm_lines' "$QM"; then
        _ok "query_multi.pl: tömör comm_log (@fm_lines) megvan"
    else
        _warn "query_multi.pl: régi log formátum (help lista is belekerül a comm_log-ba)"
    fi
    if [ -x "$QM" ]; then
        _ok "query_multi.pl futtatható"
    else
        _warn "query_multi.pl nem futtatható – javítás..."
        chmod +x "$QM" && _fix "query_multi.pl +x beállítva" || _err "chmod SIKERTELEN"
    fi
else
    _err "query_multi.pl nem található"
fi

# ─────────────────────────────────────────────
_head "9. Fájl jogosultságok"

for f in go.pl timer.pl query_multi.pl; do
    fp="$PM_DIR/$f"
    if [ -f "$fp" ]; then
        if [ -x "$fp" ]; then
            _ok "$f futtatható"
        else
            _warn "$f nem futtatható – javítás..."
            chmod +x "$fp" && _fix "$f +x beállítva" || _err "$f chmod SIKERTELEN"
        fi
    else
        _err "$f HIÁNYZIK ($fp)"
    fi
done

# tmp/ könyvtár
if [ -d "$TMP_DIR" ]; then
    if [ -w "$TMP_DIR" ]; then
        _ok "tmp/ könyvtár írható"
    else
        _warn "tmp/ NEM írható – javítás..."
        chmod 777 "$TMP_DIR" && _fix "tmp/ chmod 777" || _err "tmp/ chmod SIKERTELEN"
    fi
else
    _err "tmp/ könyvtár HIÁNYZIK – létrehozás..."
    mkdir -p "$TMP_DIR" && chmod 777 "$TMP_DIR" && _fix "tmp/ létrehozva" || _err "tmp/ mkdir SIKERTELEN"
fi

# Lock fájl
LOCK="$TMP_DIR/query.lock"
if [ -f "$LOCK" ]; then
    AGE=$(( $(date +%s) - $(stat -c %Y "$LOCK") ))
    if [ $AGE -gt 60 ]; then
        _warn "query.lock elakadt ($AGE mp) – törlés..."
        rm -f "$LOCK" && _fix "query.lock törölve" || _err "lock törlése SIKERTELEN"
    else
        _ok "query.lock megvan (${AGE}mp régi, normális)"
    fi
else
    _ok "Nincs elakadt lock"
fi

# ─────────────────────────────────────────────
_head "10. Cron ellenőrzés"

if crontab -l 2>/dev/null | grep -q "cron_poll.php"; then
    _ok "Cron bejegyzés megvan"
    crontab -l 2>/dev/null | grep "cron_poll.php" | sed 's/^/            /'
else
    _err "Cron bejegyzés HIÁNYZIK"
    echo ""
    echo "  Szükséges cron sor (crontab -e):"
    echo "  * * * * * /bin/bash -c 'for i in 1 2 3 4; do php $PM_DIR/cron_poll.php >> $TMP_DIR/cron_poll.log 2>&1; sleep 14; done'"
fi

LOG="$TMP_DIR/cron_poll.log"
if [ -f "$LOG" ]; then
    LOG_SIZE=$(du -h "$LOG" | cut -f1)
    LAST_RUN=$(stat -c %y "$LOG" | cut -d. -f1)
    _ok "cron_poll.log: $LOG_SIZE, utolsó futás: $LAST_RUN"
    echo ""
    echo "  Utolsó 8 sor:"
    tail -8 "$LOG" | sed 's/^/    /'
else
    _warn "cron_poll.log nem található (még nem futott a cron?)"
fi

# ─────────────────────────────────────────────
_head "11. Aktuális jobok állapota"

ACT=$($DB -e "SELECT COUNT(DISTINCT Megjegyzes) FROM Button_Goals WHERE akcio='aktiv'" 2>/dev/null)
LIVE=$($DB -e "SELECT COUNT(*) FROM fm_jobs_live" 2>/dev/null)
[ -z "$ACT" ]  && ACT=0
[ -z "$LIVE" ] && LIVE=0

if [ "$ACT" -eq 0 ]; then
    _ok "Nincs aktív job a Button_Goals-ban"
else
    _info "$ACT aktív job ID a Button_Goals-ban:"
    $DB -e "SELECT DISTINCT Megjegyzes FROM Button_Goals WHERE akcio='aktiv'" 2>/dev/null | sed 's/^/    /'
fi
_info "fm_jobs_live: $LIVE aktív pickup"

# ─────────────────────────────────────────────
_head "12. Robot státuszok"

$DB -e "SELECT Robot_name, availability, fm_status, frissitve FROM Robots WHERE Active != 'N'" 2>/dev/null \
    | while IFS=$'\t' read -r name avail fmst friss; do
        printf "  %-30s  %-15s %-15s  %s\n" "$name" "${avail:-(üres)}" "${fmst:-(üres)}" "${friss:-(soha)}"
      done

# ─────────────────────────────────────────────
_head "13. FM hálózati elérhetőség"

FM_HOST="10.146.126.156"
FM_PORT="7171"
FM_RESULT=$(php -r "
\$s = @fsockopen('$FM_HOST', $FM_PORT, \$e, \$em, 3);
if (\$s) { fclose(\$s); echo 'OK'; } else { echo 'HIBA: '.\$em; }
" 2>/dev/null)

if [ "$FM_RESULT" = "OK" ]; then
    _ok "Fleet Manager elérhető: $FM_HOST:$FM_PORT"
else
    _warn "Fleet Manager NEM elérhető: $FM_HOST:$FM_PORT ($FM_RESULT)"
    _info "→ Ez önmagában nem hiba, ha az FM szerver más hálózaton van"
fi

# ─────────────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════════════╗"
printf  "║  Összesítés:  ✓ OK: %-3d  ⚠ Warn: %-3d  ✗ Hiba: %-3d  🔧 Fix: %-3d ║\n" $OK $WARN $ERR $FIX
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

if [ $ERR -gt 0 ]; then
    echo "  EREDMÉNY: HIBÁK TALÁLHATÓK – nézd át a fentieket."
    echo ""
    echo "  Leggyakoribb megoldás: töltsd fel és csomagold ki a pm_1.0.5.zip-et,"
    echo "  majd futtasd újra ezt a scriptet."
    exit 2
elif [ $WARN -gt 0 ]; then
    echo "  EREDMÉNY: Kisebb figyelmeztetések – ellenőrizd a fentieket."
    exit 1
else
    echo "  EREDMÉNY: Minden rendben."
    exit 0
fi

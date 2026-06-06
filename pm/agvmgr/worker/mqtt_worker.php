#!/usr/bin/env php
<?php
declare(strict_types=1);
set_time_limit(0);

define('DB_HOST',          'localhost');
define('DB_USER',          'robot');
define('DB_PASS',          'abrakadabra');
define('DB_NAME',          'agvmgr');
define('LOG_FILE',         '/var/log/agvmgr_worker.log');
define('CONF_TTL',         300);
define('RECONNECT_SEC',    5);
define('HISTORY_INTERVAL', 10);
define('OFFLINE_TIMEOUT',  90);
define('BAT_LOW',          20.0);
define('BAT_CRITICAL',     10.0);
define('BAT_OK',           25.0);

$pdo              = null;
$topic_map        = [];
$agv_meta         = [];
$running          = true;
$prev_state       = [];
$last_history     = [];
$last_offline_chk = 0;
$last_reload      = 0;

if (function_exists('pcntl_signal')) {
    $h = function () use (&$running): void { $running = false; };
    pcntl_signal(SIGTERM, $h);
    pcntl_signal(SIGINT,  $h);
}

function wlog(string $level, string $msg): void {
    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function db(): PDO {
    global $pdo;
    if ($pdo !== null) {
        try { $pdo->query('SELECT 1'); return $pdo; } catch (Exception $e) { $pdo = null; }
    }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true]
    );
    wlog('INFO', 'DB kapcsolat megnyitva');
    return $pdo;
}

function load_config(): array {
    global $topic_map, $agv_meta;
    $rows      = db()->query("SELECT id, name, serial_no, topic FROM agv WHERE enabled=1")->fetchAll(PDO::FETCH_ASSOC);
    $topic_map = [];
    $agv_meta  = [];
    foreach ($rows as $r) {
        $base = rtrim((string)$r['topic'], '/');
        $topic_map[$base . '/visualization'] = (int)$r['id'];
        $topic_map[$base . '/state']         = (int)$r['id'];
        $agv_meta[(int)$r['id']] = [
            'name'      => (string)($r['name'] ?: $r['serial_no']),
            'serial_no' => (string)$r['serial_no'],
        ];
    }
    wlog('INFO', 'Betöltve ' . count($rows) . ' AGV, ' . count($topic_map) . ' topic figyelve');

    $broker = db()->query("SELECT ip, port, username, password FROM mqtt_broker WHERE id=1 AND enabled=1")->fetch(PDO::FETCH_ASSOC);
    return $broker ?: [];
}

function build_command(array $broker): ?string {
    if (empty($broker['ip'])) {
        wlog('ERROR', 'Nincs broker IP beállítva.');
        return null;
    }
    $args = [
        'mosquitto_sub',
        '-h', $broker['ip'],
        '-p', (string)(int)($broker['port'] ?? 1883),
        '-t', '#',
        '-v',
        '--keepalive', '30',
    ];
    if (!empty($broker['username'])) {
        $args[] = '-u'; $args[] = $broker['username'];
        $args[] = '-P'; $args[] = $broker['password'] ?? '';
    }
    return implode(' ', array_map('escapeshellarg', $args));
}

function save_position(int $agv_id, string $raw, string $source): ?array {
    $p = json_decode($raw, true);
    if (!$p) { wlog('WARNING', "JSON parse hiba (agv $agv_id)"); return null; }

    $pos = $p['agvPosition']  ?? [];
    $vel = $p['velocity']     ?? [];
    $bat = $p['batteryState'] ?? [];

    $x         = isset($pos['x'])                   ? (float)$pos['x']                       : null;
    $y         = isset($pos['y'])                   ? (float)$pos['y']                       : null;
    $theta     = isset($pos['theta'])               ? (float)$pos['theta']                   : null;
    $map_id    = (string)($pos['mapId']             ?? '');
    $pos_init  = isset($pos['positionInitialized']) ? (int)(bool)$pos['positionInitialized'] : null;
    $loc_score = isset($pos['localizationScore'])   ? (float)$pos['localizationScore']       : null;
    $dev_range = isset($pos['deviationRange'])      ? (float)$pos['deviationRange']          : null;
    $vx        = isset($vel['vx'])                  ? (float)$vel['vx']                      : null;
    $vy        = isset($vel['vy'])                  ? (float)$vel['vy']                      : null;
    $omega     = isset($vel['omega'])               ? (float)$vel['omega']                   : null;
    $bat_chg   = isset($bat['batteryCharge'])       ? (float)$bat['batteryCharge']           : null;
    $bat_volt  = isset($bat['batteryVoltage'])      ? (float)$bat['batteryVoltage']          : null;
    $op_mode   = (string)($p['operatingMode']       ?? '');
    $driving   = isset($p['driving'])               ? (int)(bool)$p['driving']               : null;
    $paused    = isset($p['paused'])                ? (int)(bool)$p['paused']                : null;

    $deg = $theta !== null ? sprintf('%.1f°', rad2deg($theta)) : '–';
    $spd = ($vx !== null && $vy !== null) ? sprintf('  v=%.2fm/s', sqrt($vx ** 2 + $vy ** 2)) : '';
    wlog('INFO', "AGV $agv_id [$source]  x=$x y=$y θ=$deg$spd  bat={$bat_chg}%  mode=" . ($op_mode ?: '–'));

    try {
        $stmt = db()->prepare("
            INSERT INTO agv_coords
              (agv_id, x, y, theta, map_id, position_initialized, localization_score,
               deviation_range, vx, vy, omega, battery_charge, battery_voltage,
               operating_mode, driving, paused, source, raw_payload, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(3))
            ON DUPLICATE KEY UPDATE
              x                    = COALESCE(?,x),
              y                    = COALESCE(?,y),
              theta                = COALESCE(?,theta),
              map_id               = IF(?<>'',?,map_id),
              position_initialized = COALESCE(?,position_initialized),
              localization_score   = COALESCE(?,localization_score),
              deviation_range      = COALESCE(?,deviation_range),
              vx                   = COALESCE(?,vx),
              vy                   = COALESCE(?,vy),
              omega                = COALESCE(?,omega),
              battery_charge       = COALESCE(?,battery_charge),
              battery_voltage      = COALESCE(?,battery_voltage),
              operating_mode       = IF(?<>'',?,operating_mode),
              driving              = COALESCE(?,driving),
              paused               = COALESCE(?,paused),
              source               = ?,
              raw_payload          = ?,
              updated_at           = NOW(3)
        ");
        $stmt->execute([
            $agv_id, $x, $y, $theta, $map_id, $pos_init, $loc_score,
            $dev_range, $vx, $vy, $omega, $bat_chg, $bat_volt,
            $op_mode, $driving, $paused, $source, $raw,
            $x, $y, $theta, $map_id, $map_id, $pos_init, $loc_score, $dev_range,
            $vx, $vy, $omega, $bat_chg, $bat_volt, $op_mode, $op_mode,
            $driving, $paused, $source, $raw,
        ]);
    } catch (Exception $e) {
        wlog('ERROR', "DB mentési hiba (agv $agv_id): " . $e->getMessage());
        global $pdo; $pdo = null;
    }

    return [
        'x' => $x, 'y' => $y, 'theta' => $theta, 'map_id' => $map_id,
        'pos_init' => $pos_init, 'loc_score' => $loc_score, 'dev_range' => $dev_range,
        'vx' => $vx, 'vy' => $vy, 'omega' => $omega,
        'speed'    => ($vx !== null && $vy !== null) ? round(sqrt($vx ** 2 + $vy ** 2), 4) : null,
        'bat_chg'  => $bat_chg, 'bat_volt' => $bat_volt,
        'op_mode'  => $op_mode, 'driving' => $driving, 'paused' => $paused,
    ];
}

function detect_events(int $agv_id, array $p): void {
    global $prev_state, $agv_meta;
    $name = $agv_meta[$agv_id]['name'] ?? "AGV#$agv_id";
    $prev = $prev_state[$agv_id] ?? [
        'battery' => null, 'mode' => '', 'driving' => null,
        'paused'  => null, 'pos_init' => null, 'offline' => false,
    ];

    if (!empty($prev['offline'])) {
        log_event($agv_id, 'online', 'info', "$name visszatért online");
    }

    $bat = $p['bat_chg']; $pbat = $prev['battery'];
    if ($bat !== null && $pbat !== null) {
        if ($bat < BAT_CRITICAL && $pbat >= BAT_CRITICAL)
            log_event($agv_id, 'battery_critical', 'error',   "$name kritikus: " . round($bat, 1) . '%');
        elseif ($bat < BAT_LOW && $pbat >= BAT_LOW)
            log_event($agv_id, 'battery_low',      'warning', "$name alacsony: " . round($bat, 1) . '%');
        elseif ($bat >= BAT_OK && $pbat < BAT_OK)
            log_event($agv_id, 'battery_ok',       'info',    "$name feltöltve: " . round($bat, 1) . '%');
    }

    $mode = $p['op_mode']; $pmode = $prev['mode'];
    if ($mode && $pmode !== null && $mode !== $pmode && $pmode !== '') {
        log_event($agv_id, 'mode_change', 'info', "$name üzemmód: $pmode → $mode");
    }

    $drv = $p['driving']; $pdrv = $prev['driving'];
    if ($drv !== null && $pdrv !== null && $drv !== $pdrv) {
        log_event($agv_id, $drv ? 'driving_start' : 'driving_stop', 'info',
                  $drv ? "$name mozgás indult" : "$name megállt");
    }

    $pau = $p['paused']; $ppau = $prev['paused'];
    if ($pau !== null && $ppau !== null && $pau !== $ppau) {
        log_event($agv_id, $pau ? 'paused' : 'resumed', $pau ? 'warning' : 'info',
                  $pau ? "$name szünet" : "$name folytat");
    }

    $pi = $p['pos_init']; $ppi = $prev['pos_init'];
    if ($pi !== null && $ppi !== null && $pi !== $ppi) {
        log_event($agv_id, $pi ? 'pos_init' : 'pos_lost', $pi ? 'info' : 'warning',
                  $pi ? "$name pozíció init" : "$name pozíció elveszett");
    }

    $prev_state[$agv_id] = [
        'battery'  => $bat,
        'mode'     => $mode ?: $pmode,
        'driving'  => $drv  ?? $pdrv,
        'paused'   => $pau  ?? $ppau,
        'pos_init' => $pi   ?? $ppi,
        'offline'  => false,
    ];
}

function log_event(int $agv_id, string $type, string $severity, string $detail): void {
    try {
        db()->prepare("INSERT INTO agv_events (agv_id, event_type, severity, detail) VALUES (?,?,?,?)")
           ->execute([$agv_id, $type, $severity, $detail]);
        wlog('INFO', "Esemény [$severity] agv=$agv_id $type: $detail");
    } catch (Exception $e) {
        wlog('WARNING', "Esemény mentési hiba: " . $e->getMessage());
    }
}

function maybe_save_history(int $agv_id, array $p): void {
    global $last_history;
    $now = time();
    if (isset($last_history[$agv_id]) && $now - $last_history[$agv_id] < HISTORY_INTERVAL) return;
    $last_history[$agv_id] = $now;
    try {
        db()->prepare("INSERT INTO agv_coords_history (agv_id, x, y, theta, map_id, speed, battery, source) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$agv_id, $p['x'], $p['y'], $p['theta'], $p['map_id'] ?? '', $p['speed'], $p['bat_chg'], 'worker']);
    } catch (Exception $e) {
        wlog('WARNING', "History mentési hiba (agv $agv_id): " . $e->getMessage());
    }
}

function check_offline(): void {
    global $prev_state, $agv_meta;
    $rows = db()->query(
        "SELECT agv_id, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_sec
         FROM agv_coords WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) > " . OFFLINE_TIMEOUT
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $id = (int)$r['agv_id'];
        if (empty($prev_state[$id]['offline'])) {
            $name = $agv_meta[$id]['name'] ?? "AGV#$id";
            log_event($id, 'offline', 'warning', "$name offline – " . (int)$r['age_sec'] . " mp");
            $prev_state[$id]['offline'] = true;
        }
    }
}

function process_line(string $line): void {
    global $topic_map;
    if ($line === '') return;
    $sp = strpos($line, ' ');
    if ($sp === false) return;
    $topic   = substr($line, 0, $sp);
    $payload = substr($line, $sp + 1);
    $agv_id  = $topic_map[$topic] ?? null;
    if ($agv_id === null) return;
    $source = (substr($topic, -14) === '/visualization') ? 'visualization' : 'state';
    $parsed = save_position($agv_id, $payload, $source);
    if ($parsed) {
        detect_events($agv_id, $parsed);
        maybe_save_history($agv_id, $parsed);
    }
}

// ── Főprogram ─────────────────────────────────────────────────────────────────
wlog('INFO', 'agvmgr MQTT worker indul (mosquitto_sub alapú)');

$broker      = load_config();
$last_reload = time();

if (empty($broker['ip'])) {
    wlog('ERROR', 'Nincs AGV MQTT broker IP beállítva. Kilépés.');
    exit(1);
}

$proc  = null;
$pipes = [];

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    $now = time();

    // Konfig újratöltés
    if ($now - $last_reload >= CONF_TTL) {
        wlog('INFO', 'Konfig újratöltés...');
        $new_broker = load_config();
        if ($new_broker !== $broker) {
            wlog('INFO', 'Broker konfig megváltozott, újracsatlakozás...');
            $broker = $new_broker;
            if ($proc) { proc_terminate($proc); proc_close($proc); $proc = null; }
        }
        $last_reload = $now;
    }

    // Offline ellenőrzés 60 mp-enként
    if ($now - $last_offline_chk >= 60) {
        check_offline();
        $last_offline_chk = $now;
    }

    // Folyamat indítása / újraindítása
    if ($proc === null) {
        $cmd = build_command($broker);
        if ($cmd === null) { sleep(RECONNECT_SEC); continue; }

        wlog('INFO', "Csatlakozás: mosquitto_sub -h {$broker['ip']} -p {$broker['port']}");
        $desc = [1 => ['pipe', 'r'], 2 => ['pipe', 'r']];
        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            wlog('ERROR', 'proc_open sikertelen, újrapróbálás ' . RECONNECT_SEC . ' mp múlva...');
            $proc = null;
            sleep(RECONNECT_SEC);
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        wlog('INFO', 'mosquitto_sub folyamat elindítva');
    }

    // Státusz ellenőrzés
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $stderr = stream_get_contents($pipes[2]);
        wlog('WARNING', 'mosquitto_sub kilépett (exit=' . $status['exitcode'] . ')'
            . ($stderr ? ': ' . trim($stderr) : ''));
        proc_close($proc);
        $proc = null;
        sleep(RECONNECT_SEC);
        continue;
    }

    // Sorok olvasása
    $line = fgets($pipes[1]);
    if ($line !== false) {
        process_line(trim($line));
    } else {
        usleep(10000); // 10 ms várakozás ha nincs adat
    }
}

wlog('INFO', 'Worker leállítás (signal fogadva)');
if ($proc) { proc_terminate($proc); proc_close($proc); }

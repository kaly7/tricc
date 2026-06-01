#!/usr/bin/env php
<?php
/**
 * agvmgr MQTT worker – VDA5050 v2.0 pozíció rögzítő + Omron továbbítás
 * PHP implementáció – nincs Python/pip függőség
 */
declare(strict_types=1);
set_time_limit(0);

require_once __DIR__ . '/phpMQTT.php';

// ── Konfiguráció ──────────────────────────────────────────────────────────────
define('DB_HOST',        'localhost');
define('DB_USER',        'robot');
define('DB_PASS',        'abrakadabra');
define('DB_NAME',        'agvmgr');
define('LOG_FILE',       '/var/log/agvmgr_worker.log');
define('CONF_TTL',       300);   // konfig újratöltés másodpercenként
define('RECONNECT_SEC',  5);     // újracsatlakozás várakozás

// ── Globális állapot ──────────────────────────────────────────────────────────
$pdo       = null;
$topic_map = [];   // full_topic -> agv_id
$agv_meta  = [];   // agv_id -> ['name', 'serial_no']
$omron_fwd = [];   // agv_id -> ['topic', 'fields', 'enabled']
$omron_cli = null; // PhpMQTT|null
$running   = true;

// ── Signal kezelés ────────────────────────────────────────────────────────────
if (function_exists('pcntl_signal')) {
    $handler = function () use (&$running): void { $running = false; };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT,  $handler);
}

// ── Logging ───────────────────────────────────────────────────────────────────
function wlog(string $level, string $msg): void {
    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ── DB kapcsolat (PDO, lazy reconnect) ───────────────────────────────────────
function db(): PDO {
    global $pdo;
    if ($pdo !== null) {
        try { $pdo->query('SELECT 1'); return $pdo; } catch (Exception $e) { $pdo = null; }
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT       => false,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
    ]);
    wlog('INFO', 'DB kapcsolat megnyitva');
    return $pdo;
}

function db_rows(string $sql): array {
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ── Konfig betöltés ───────────────────────────────────────────────────────────
function load_agvs(): array {
    global $topic_map, $agv_meta;
    $rows      = db_rows("SELECT id, name, serial_no, topic FROM agv WHERE enabled=1");
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
    return array_keys($topic_map);
}

function load_omron_fwd(): void {
    global $omron_fwd;
    $rows = db_rows("SELECT f.agv_id, f.topic_template, f.fields, f.enabled
        FROM omron_forward f JOIN agv a ON a.id=f.agv_id WHERE a.enabled=1");
    $omron_fwd = [];
    foreach ($rows as $r) {
        $omron_fwd[(int)$r['agv_id']] = [
            'topic'   => (string)$r['topic_template'],
            'fields'  => $r['fields'] ? (json_decode((string)$r['fields'], true) ?? []) : [],
            'enabled' => (bool)$r['enabled'],
        ];
    }
    wlog('INFO', 'Omron forward konfig betöltve: ' . count($omron_fwd) . ' AGV');
}

function get_broker(string $table): ?array {
    $rows = db_rows("SELECT ip, port, username, password, enabled FROM $table WHERE id=1");
    return $rows[0] ?? null;
}

// ── Payload mentése DB-be ─────────────────────────────────────────────────────
function save_position(int $agv_id, string $raw, string $source): ?array {
    $p = json_decode($raw, true);
    if (!$p) { wlog('WARNING', "JSON parse hiba (agv $agv_id)"); return null; }

    $pos = $p['agvPosition']  ?? [];
    $vel = $p['velocity']     ?? [];
    $bat = $p['batteryState'] ?? [];

    $x         = isset($pos['x'])                   ? (float)$pos['x']                   : null;
    $y         = isset($pos['y'])                   ? (float)$pos['y']                   : null;
    $theta     = isset($pos['theta'])               ? (float)$pos['theta']               : null;
    $map_id    = (string)($pos['mapId']             ?? '');
    $pos_init  = isset($pos['positionInitialized']) ? (int)(bool)$pos['positionInitialized'] : null;
    $loc_score = isset($pos['localizationScore'])   ? (float)$pos['localizationScore']   : null;
    $dev_range = isset($pos['deviationRange'])      ? (float)$pos['deviationRange']      : null;
    $vx        = isset($vel['vx'])                  ? (float)$vel['vx']                  : null;
    $vy        = isset($vel['vy'])                  ? (float)$vel['vy']                  : null;
    $omega     = isset($vel['omega'])               ? (float)$vel['omega']               : null;
    $bat_chg   = isset($bat['batteryCharge'])       ? (float)$bat['batteryCharge']       : null;
    $bat_volt  = isset($bat['batteryVoltage'])      ? (float)$bat['batteryVoltage']      : null;
    $op_mode   = (string)($p['operatingMode']       ?? '');
    $driving   = isset($p['driving'])               ? (int)(bool)$p['driving']           : null;
    $paused    = isset($p['paused'])                ? (int)(bool)$p['paused']            : null;

    $spd = ($vx !== null && $vy !== null) ? sprintf('  v=%.2fm/s', sqrt($vx * $vx + $vy * $vy)) : '';
    $deg = $theta !== null ? sprintf('%.1f°', rad2deg($theta)) : '–';
    wlog('INFO', "AGV $agv_id [$source]  x=$x y=$y θ=$deg$spd  bat={$bat_chg}%  mode=" . ($op_mode ?: '–'));

    try {
        $stmt = db()->prepare("
            INSERT INTO agv_coords
              (agv_id, x, y, theta, map_id, position_initialized,
               localization_score, deviation_range,
               vx, vy, omega, battery_charge, battery_voltage,
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
            // INSERT értékek
            $agv_id, $x, $y, $theta, $map_id, $pos_init,
            $loc_score, $dev_range, $vx, $vy, $omega, $bat_chg, $bat_volt,
            $op_mode, $driving, $paused, $source, $raw,
            // ON DUPLICATE UPDATE értékek
            $x, $y, $theta,
            $map_id, $map_id,
            $pos_init, $loc_score, $dev_range,
            $vx, $vy, $omega, $bat_chg, $bat_volt,
            $op_mode, $op_mode,
            $driving, $paused,
            $source, $raw,
        ]);
    } catch (Exception $e) {
        wlog('ERROR', "DB mentési hiba (agv $agv_id): " . $e->getMessage());
        global $pdo; $pdo = null;
    }

    $speed = ($vx !== null && $vy !== null) ? round(sqrt($vx * $vx + $vy * $vy), 4) : null;
    return [
        'x' => $x, 'y' => $y, 'theta' => $theta,
        'theta_deg' => $theta !== null ? round(rad2deg($theta), 4) : null,
        'map_id'    => $map_id ?: null,
        'pos_init'  => $pos_init, 'loc_score' => $loc_score, 'dev_range' => $dev_range,
        'vx' => $vx, 'vy' => $vy, 'omega' => $omega, 'speed' => $speed,
        'battery' => $bat_chg, 'voltage' => $bat_volt,
        'mode' => $op_mode ?: null, 'driving' => $driving, 'paused' => $paused,
    ];
}

// ── Omron forwarding ──────────────────────────────────────────────────────────
function forward_to_omron(int $agv_id, array $parsed): void {
    global $omron_cli, $omron_fwd, $agv_meta;
    if (!$omron_cli || !$omron_cli->isConnected()) return;

    $cfg = $omron_fwd[$agv_id] ?? null;
    if (!$cfg || !$cfg['enabled'] || !$cfg['fields']) return;

    $meta  = $agv_meta[$agv_id] ?? [];
    $topic = str_replace(
        ['{serial_no}', '{name}'],
        [$meta['serial_no'] ?? (string)$agv_id, $meta['name'] ?? (string)$agv_id],
        $cfg['topic']
    );

    $now  = microtime(true);
    $ms   = sprintf('%03d', (int)($now * 1000) % 1000);
    $ts   = gmdate('Y-m-d\TH:i:s.') . $ms . 'Z';

    $field_map = [
        'x'         => ['x',                   $parsed['x']],
        'y'         => ['y',                   $parsed['y']],
        'theta'     => ['theta',               $parsed['theta']],
        'theta_deg' => ['theta_deg',           $parsed['theta_deg']],
        'map_id'    => ['mapId',               $parsed['map_id']],
        'pos_init'  => ['positionInitialized', $parsed['pos_init']],
        'loc_score' => ['localizationScore',   $parsed['loc_score']],
        'dev_range' => ['deviationRange',      $parsed['dev_range']],
        'speed'     => ['speed',               $parsed['speed']],
        'vx'        => ['vx',                  $parsed['vx']],
        'vy'        => ['vy',                  $parsed['vy']],
        'omega'     => ['omega',               $parsed['omega']],
        'battery'   => ['batteryCharge',       $parsed['battery']],
        'voltage'   => ['batteryVoltage',      $parsed['voltage']],
        'mode'      => ['operatingMode',       $parsed['mode']],
        'driving'   => ['driving',             $parsed['driving']],
        'paused'    => ['paused',              $parsed['paused']],
        'timestamp' => ['timestamp',           $ts],
        'agv_name'  => ['agvName',             $meta['name']  ?? null],
        'serial_no' => ['serialNo',            $meta['serial_no'] ?? null],
    ];

    $out = [];
    foreach ($cfg['fields'] as $key) {
        if (!isset($field_map[$key])) continue;
        [$json_key, $value] = $field_map[$key];
        if ($value !== null) $out[$json_key] = $value;
    }
    if (!$out) return;

    try {
        $omron_cli->publish($topic, json_encode($out), 1, false);
        wlog('DEBUG', "Omron forward: $topic → " . implode(', ', array_keys($out)));
    } catch (Exception $e) {
        wlog('WARNING', "Omron publish hiba (agv $agv_id): " . $e->getMessage());
    }
}

// ── Omron kliens setup ────────────────────────────────────────────────────────
function setup_omron(): void {
    global $omron_cli;
    $cfg = get_broker('omron_broker');
    if (!$cfg || !$cfg['enabled'] || !$cfg['ip']) {
        wlog('INFO', 'Omron forwarding kikapcsolva');
        $omron_cli = null;
        return;
    }
    $c = new PhpMQTT($cfg['ip'], (int)$cfg['port'], 'agvmgr_omron_fwd');
    if ($cfg['username']) $c->setAuth((string)$cfg['username'], (string)($cfg['password'] ?? ''));
    if ($c->connect()) {
        wlog('INFO', "Omron MQTT broker kapcsolódva ({$cfg['ip']}:{$cfg['port']})");
        $omron_cli = $c;
    } else {
        wlog('ERROR', "Omron MQTT csatlakozási hiba ({$cfg['ip']}:{$cfg['port']})");
        $omron_cli = null;
    }
}

// ── Főprogram ─────────────────────────────────────────────────────────────────
wlog('INFO', 'agvmgr MQTT worker indul (PHP)');

$topics      = load_agvs();
load_omron_fwd();

$broker = get_broker('mqtt_broker');
if (!$broker || !$broker['ip']) {
    wlog('ERROR', 'Nincs AGV MQTT broker IP beállítva. Kilépés.');
    exit(1);
}

setup_omron();

$agv_client  = null;
$last_reload = time();
$omron_tick  = 0;

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    // Konfig újratöltés CONF_TTL másodpercenként
    if (time() - $last_reload >= CONF_TTL) {
        $topics = load_agvs();
        load_omron_fwd();
        setup_omron();
        $last_reload = time();
        if ($agv_client && $agv_client->isConnected()) {
            $agv_client->subscribe(array_fill_keys($topics, 1));
        }
    }

    // Omron keepalive (~1 mp-enként elegendő)
    if (++$omron_tick >= 1000 && $omron_cli) {
        $omron_cli->proc();
        $omron_tick = 0;
    }

    // AGV broker kapcsolódás / újracsatlakozás
    if (!$agv_client || !$agv_client->isConnected()) {
        if ($agv_client) wlog('WARNING', 'AGV MQTT kapcsolat megszakadt, újracsatlakozás...');

        $agv_client = new PhpMQTT($broker['ip'], (int)$broker['port'], 'agvmgr_worker');
        if ($broker['username']) {
            $agv_client->setAuth((string)$broker['username'], (string)($broker['password'] ?? ''));
        }
        $agv_client->callback = function (string $topic, string $payload): void {
            global $topic_map;
            $agv_id = $topic_map[$topic] ?? null;
            if ($agv_id === null) return;
            $source = (substr($topic, -14) === '/visualization') ? 'visualization' : 'state';
            $parsed = save_position($agv_id, $payload, $source);
            if ($parsed) forward_to_omron($agv_id, $parsed);
        };

        if ($agv_client->connect()) {
            wlog('INFO', "AGV MQTT broker kapcsolódva ({$broker['ip']}:{$broker['port']})");
            $subs = array_fill_keys($topics, 1);
            $agv_client->subscribe($subs);
            foreach ($topics as $t) wlog('INFO', "  → feliratkozva: $t");
        } else {
            wlog('ERROR', "AGV MQTT csatlakozási hiba ({$broker['ip']}:{$broker['port']})");
            sleep(RECONNECT_SEC);
            continue;
        }
    }

    $agv_client->proc();
    usleep(1000); // 1 ms – nem blokkolja a CPU-t
}

wlog('INFO', 'Worker leállítás (signal fogadva)');
if ($agv_client) $agv_client->disconnect();
if ($omron_cli)  $omron_cli->disconnect();

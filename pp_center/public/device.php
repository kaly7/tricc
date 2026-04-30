<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\CommandService;
use App\Services\ConfigService;
use App\Services\DeviceService;

function h($value): string
{
    return e((string) $value);
}


function raw_pick(array $raw, array $paths, mixed $default = null): mixed
{
    foreach ($paths as $path) {
        $value = $raw;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            $value = null;
            break;
        }
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
}


function contact_cfg(array $contacts, string $key, int $index): array
{
    $mode = (string) ($contacts[$key . '_mode'] ?? 'nc');
    $name = trim((string) ($contacts[$key . '_name'] ?? ('Kontakt ' . $index)));
    $openLabel = trim((string) ($contacts[$key . '_open_label'] ?? 'Nyitva'));
    $closedLabel = trim((string) ($contacts[$key . '_closed_label'] ?? 'Zárva'));

    return [
        'key' => $key,
        'mode' => $mode,
        'index' => $index,
        'name' => $name !== '' ? $name : ('Kontakt ' . $index),
        'open_label' => $openLabel !== '' ? $openLabel : 'Nyitva',
        'closed_label' => $closedLabel !== '' ? $closedLabel : 'Zárva',
    ];
}

function action_to_ui(array|string $action): array
{
    $raw = is_array($action) ? json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $action;
    $raw = trim((string) $raw);
    if ($raw === 'mattermost') {
        return ['type' => 'mattermost', 'target' => ''];
    }
    if (str_starts_with($raw, 'sms:group_')) {
        return ['type' => 'sms_group', 'target' => substr($raw, 4)];
    }
    if (str_starts_with($raw, 'sms:')) {
        return ['type' => 'sms_phone', 'target' => substr($raw, 4)];
    }
    if (str_starts_with($raw, 'call:group_')) {
        return ['type' => 'call_group', 'target' => substr($raw, 5)];
    }
    if (str_starts_with($raw, 'call:')) {
        return ['type' => 'call_phone', 'target' => substr($raw, 5)];
    }
    return ['type' => 'custom', 'target' => $raw];
}

function route_to_ui(string $event, array $builtinLabels, array $ruleIdLabels): array
{
    if (isset($builtinLabels[$event])) {
        return ['select' => 'builtin:' . $event, 'custom' => ''];
    }
    if (isset($ruleIdLabels[$event])) {
        return ['select' => 'rule:' . $event, 'custom' => ''];
    }
    return ['select' => 'custom', 'custom' => $event];
}

function rule_to_ui(array $rule, array $contactsMeta): array
{
    $type = (string) ($rule['type'] ?? 'threshold');
    $sensor = (string) ($rule['sensor'] ?? 'temperature');
    $mode = 'custom';
    $contactKey = 'c1';
    $contactState = 'open';

    if (in_array($type, ['threshold', 'trend_up', 'trend_down'], true)) {
        $mode = $type;
    } elseif (in_array($type, ['contact_state', 'contact'], true) || str_starts_with($sensor, 'contact_')) {
        $mode = 'contact_state';
        if (preg_match('/contact_(\d+)/', $sensor, $m)) {
            $contactKey = 'c' . $m[1];
        }
        $value = strtolower((string) ($rule['value'] ?? 'open'));
        $contactState = in_array($value, ['1', 'closed', 'close', 'zart', 'zárva'], true) ? 'closed' : 'open';
    }

    if (!isset($contactsMeta[$contactKey])) {
        $contactKey = 'c1';
    }

    return [
        'mode' => $mode,
        'contact_key' => $contactKey,
        'contact_state' => $contactState,
    ];
}

function render_action_row_html(string $prefix, int $actionIndex, string $rawAction, array $groupNames): string
{
    $ui = action_to_ui($rawAction);
    $type = $ui['type'];
    $target = $ui['target'];
    $groupOptions = '';
    foreach ($groupNames as $groupName) {
        $selected = $groupName === $target ? ' selected' : '';
        $groupOptions .= '<option value="' . e($groupName) . '"' . $selected . '>' . e($groupName) . '</option>';
    }

    ob_start();
    ?>
    <div class="action-builder-row" data-action-row>
        <input type="hidden" name="__unused_action_raw[<?= e($prefix) ?>][<?= $actionIndex ?>]" data-action-hidden value="<?= e($rawAction) ?>">
        <label>
            <span>Akció</span>
            <select data-action-type>
                <option value="mattermost" <?= $type === 'mattermost' ? 'selected' : '' ?>>Mattermost üzenet</option>
                <option value="sms_group" <?= $type === 'sms_group' ? 'selected' : '' ?>>SMS csoportnak</option>
                <option value="sms_phone" <?= $type === 'sms_phone' ? 'selected' : '' ?>>SMS telefonszámra</option>
                <option value="call_group" <?= $type === 'call_group' ? 'selected' : '' ?>>Hívás csoportnak</option>
                <option value="call_phone" <?= $type === 'call_phone' ? 'selected' : '' ?>>Hívás telefonszámra</option>
                <option value="custom" <?= $type === 'custom' ? 'selected' : '' ?>>Egyedi technikai akció</option>
            </select>
        </label>
        <label class="action-target-group<?= in_array($type, ['sms_group', 'call_group'], true) ? '' : ' is-hidden' ?>">
            <span>Csoport</span>
            <select data-action-group>
                <option value="">Válassz csoportot…</option>
                <?= $groupOptions ?>
            </select>
        </label>
        <label class="action-target-phone<?= in_array($type, ['sms_phone', 'call_phone'], true) ? '' : ' is-hidden' ?>">
            <span>Telefonszám</span>
            <input type="text" data-action-phone value="<?= e(in_array($type, ['sms_phone', 'call_phone'], true) ? $target : '') ?>" placeholder="+36301234567">
        </label>
        <label class="action-target-custom<?= $type === 'custom' ? '' : ' is-hidden' ?>">
            <span>Egyedi érték</span>
            <input type="text" data-action-custom value="<?= e($type === 'custom' ? $target : '') ?>" placeholder="példa: sms:group_1">
        </label>
        <div class="action-row-buttons">
            <button type="button" class="btn btn-outline-danger btn-sm" data-remove-action>Akció törlése</button>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_route_row_html(int $index, string $eventName, array $actions, array $builtinEventLabels, array $ruleIdLabels, array $groupNames): string
{
    $routeUi = route_to_ui($eventName, $builtinEventLabels, $ruleIdLabels);
    $builtInOptions = '';
    foreach ($builtinEventLabels as $eventKey => $label) {
        $selected = $routeUi['select'] === 'builtin:' . $eventKey ? ' selected' : '';
        $builtInOptions .= '<option value="builtin:' . e($eventKey) . '"' . $selected . '>' . e($label) . '</option>';
    }
    $ruleOptions = '';
    foreach ($ruleIdLabels as $ruleId => $label) {
        $selected = $routeUi['select'] === 'rule:' . $ruleId ? ' selected' : '';
        $ruleOptions .= '<option value="rule:' . e($ruleId) . '"' . $selected . '>' . e($label) . '</option>';
    }

    ob_start();
    ?>
    <div class="dynamic-row route-builder" data-row data-route-row>
        <input type="hidden" name="routes[<?= $index ?>][event]" data-route-event-hidden value="<?= e($eventName) ?>">
        <input type="hidden" name="routes[<?= $index ?>][actions]" data-route-actions-hidden value="<?= e(implode(',', $actions)) ?>">
        <div class="dynamic-row-grid route-builder-grid">
            <label>
                <span>Esemény</span>
                <select data-route-event-select>
                    <optgroup label="Rendszer események">
                        <?= $builtInOptions ?>
                    </optgroup>
                    <optgroup label="Egyedi szabályok">
                        <?= $ruleOptions ?>
                    </optgroup>
                    <option value="custom" <?= $routeUi['select'] === 'custom' ? 'selected' : '' ?>>Egyedi technikai kulcs</option>
                </select>
            </label>
            <label class="route-custom-event-wrap<?= $routeUi['select'] === 'custom' ? '' : ' is-hidden' ?>">
                <span>Egyedi kulcs</span>
                <input type="text" data-route-custom-event value="<?= e($routeUi['custom']) ?>" placeholder="példa: temp_warn">
            </label>
            <div class="full-span action-builder-box">
                <div class="action-builder-head">
                    <span>Akciók</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-add-action>Akció hozzáadása</button>
                </div>
                <div class="action-builder-list" data-action-list>
                    <?php foreach (array_values($actions) as $actionIndex => $action): ?>
                        <?= render_action_row_html('route_' . $index, $actionIndex, (string) $action, $groupNames) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Route törlése</button></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_rule_row_html(int $index, array $rule, array $contactsMeta, array $groupNames): string
{
    $ui = rule_to_ui($rule, $contactsMeta);
    $ruleId = (string) ($rule['rule_id'] ?? '');
    $type = (string) ($rule['type'] ?? 'threshold');
    $sensor = (string) ($rule['sensor'] ?? 'temperature');
    $operator = (string) ($rule['operator'] ?? '>=');
    $value = (string) ($rule['value'] ?? '');
    $forSec = (string) ($rule['for_sec'] ?? '');
    $delta = (string) ($rule['delta'] ?? '');
    $windowSec = (string) ($rule['window_sec'] ?? '');

    $thresholdSensors = [
        'temperature' => 'Hőmérséklet',
        'humidity' => 'Páratartalom',
        'battery_pct' => 'Akkumulátor %',
    ];
    $trendSensors = [
        'temperature' => 'Hőmérséklet',
        'humidity' => 'Páratartalom',
        'battery_pct' => 'Akkumulátor %',
    ];
    $operatorOptions = [
        '>=' => 'nagyobb vagy egyenlő',
        '>' => 'nagyobb mint',
        '<=' => 'kisebb vagy egyenlő',
        '<' => 'kisebb mint',
        '==' => 'egyenlő',
        '!=' => 'nem egyenlő',
    ];

    ob_start();
    ?>
    <div class="dynamic-row rule-builder" data-row data-rule-row>
        <input type="hidden" name="rules[<?= $index ?>][rule_id]" data-rule-hidden="rule_id" value="<?= e($ruleId) ?>">
        <input type="hidden" name="rules[<?= $index ?>][type]" data-rule-hidden="type" value="<?= e($type) ?>">
        <input type="hidden" name="rules[<?= $index ?>][sensor]" data-rule-hidden="sensor" value="<?= e($sensor) ?>">
        <input type="hidden" name="rules[<?= $index ?>][operator]" data-rule-hidden="operator" value="<?= e($operator) ?>">
        <input type="hidden" name="rules[<?= $index ?>][value]" data-rule-hidden="value" value="<?= e($value) ?>">
        <input type="hidden" name="rules[<?= $index ?>][for_sec]" data-rule-hidden="for_sec" value="<?= e($forSec) ?>">
        <input type="hidden" name="rules[<?= $index ?>][delta]" data-rule-hidden="delta" value="<?= e($delta) ?>">
        <input type="hidden" name="rules[<?= $index ?>][window_sec]" data-rule-hidden="window_sec" value="<?= e($windowSec) ?>">
        <input type="hidden" name="rules[<?= $index ?>][actions]" data-rule-hidden="actions" value="<?= e(implode(',', (array) ($rule['actions'] ?? []))) ?>">

        <div class="dynamic-row-grid rule-builder-grid">
            <label>
                <span>Szabály azonosító</span>
                <input type="text" class="rule-id-input" data-rule-ui="rule_id" value="<?= e($ruleId) ?>" placeholder="példa: temp_warn">
            </label>
            <label>
                <span>Szabály típusa</span>
                <select data-rule-ui="mode">
                    <option value="threshold" <?= $ui['mode'] === 'threshold' ? 'selected' : '' ?>>Küszöbérték</option>
                    <option value="trend_up" <?= $ui['mode'] === 'trend_up' ? 'selected' : '' ?>>Emelkedő trend</option>
                    <option value="trend_down" <?= $ui['mode'] === 'trend_down' ? 'selected' : '' ?>>Csökkenő trend</option>
                    <option value="contact_state" <?= $ui['mode'] === 'contact_state' ? 'selected' : '' ?>>Kontakt állapot</option>
                    <option value="custom" <?= $ui['mode'] === 'custom' ? 'selected' : '' ?>>Haladó / egyedi</option>
                </select>
            </label>

            <div class="rule-mode-block<?= $ui['mode'] === 'threshold' ? '' : ' is-hidden' ?>" data-rule-block="threshold">
                <label>
                    <span>Mért érték</span>
                    <select data-rule-ui="threshold_sensor">
                        <?php foreach ($thresholdSensors as $sensorKey => $sensorLabel): ?>
                            <option value="<?= e($sensorKey) ?>" <?= $sensor === $sensorKey ? 'selected' : '' ?>><?= e($sensorLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Feltétel</span>
                    <select data-rule-ui="threshold_operator">
                        <?php foreach ($operatorOptions as $opKey => $opLabel): ?>
                            <option value="<?= e($opKey) ?>" <?= $operator === $opKey ? 'selected' : '' ?>><?= e($opLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Érték</span>
                    <input type="text" data-rule-ui="threshold_value" value="<?= e($value) ?>" placeholder="példa: 28.5">
                </label>
                <label>
                    <span>Legyen fenn legalább (sec)</span>
                    <input type="number" min="0" step="1" data-rule-ui="threshold_for_sec" value="<?= e($forSec) ?>" placeholder="példa: 60">
                </label>
            </div>

            <div class="rule-mode-block<?= in_array($ui['mode'], ['trend_up', 'trend_down'], true) ? '' : ' is-hidden' ?>" data-rule-block="trend">
                <label>
                    <span>Mért érték</span>
                    <select data-rule-ui="trend_sensor">
                        <?php foreach ($trendSensors as $sensorKey => $sensorLabel): ?>
                            <option value="<?= e($sensorKey) ?>" <?= $sensor === $sensorKey ? 'selected' : '' ?>><?= e($sensorLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Változás mértéke</span>
                    <input type="text" data-rule-ui="trend_delta" value="<?= e($delta) ?>" placeholder="példa: 3">
                </label>
                <label>
                    <span>Ablak (sec)</span>
                    <input type="number" min="0" step="1" data-rule-ui="trend_window_sec" value="<?= e($windowSec) ?>" placeholder="példa: 600">
                </label>
            </div>

            <div class="rule-mode-block<?= $ui['mode'] === 'contact_state' ? '' : ' is-hidden' ?>" data-rule-block="contact_state">
                <label>
                    <span>Kontakt</span>
                    <select data-rule-ui="contact_key">
                        <?php foreach ($contactsMeta as $contactKey => $meta): ?>
                            <option value="<?= e($contactKey) ?>" data-mode="<?= e($meta['mode']) ?>" <?= $ui['contact_key'] === $contactKey ? 'selected' : '' ?> <?= $meta['mode'] === 'unused' ? 'disabled' : '' ?>><?= e($meta['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Kért állapot</span>
                    <select data-rule-ui="contact_state">
                        <option value="open" <?= $ui['contact_state'] === 'open' ? 'selected' : '' ?>><?= e($contactsMeta[$ui['contact_key']]['open_label'] ?? 'Nyitva') ?></option>
                        <option value="closed" <?= $ui['contact_state'] === 'closed' ? 'selected' : '' ?>><?= e($contactsMeta[$ui['contact_key']]['closed_label'] ?? 'Zárva') ?></option>
                    </select>
                </label>
                <label>
                    <span>Legyen fenn legalább (sec)</span>
                    <input type="number" min="0" step="1" data-rule-ui="contact_for_sec" value="<?= e($forSec) ?>" placeholder="példa: 10">
                </label>
            </div>

            <div class="rule-mode-block<?= $ui['mode'] === 'custom' ? '' : ' is-hidden' ?> full-span" data-rule-block="custom">
                <div class="rule-custom-grid">
                    <label><span>Típus kulcs</span><input type="text" data-rule-ui="custom_type" value="<?= e($type) ?>" placeholder="példa: threshold"></label>
                    <label><span>Szenzor kulcs</span><input type="text" data-rule-ui="custom_sensor" value="<?= e($sensor) ?>" placeholder="példa: temperature"></label>
                    <label><span>Operátor</span><input type="text" data-rule-ui="custom_operator" value="<?= e($operator) ?>" placeholder=">="></label>
                    <label><span>Érték</span><input type="text" data-rule-ui="custom_value" value="<?= e($value) ?>"></label>
                    <label><span>for_sec</span><input type="number" data-rule-ui="custom_for_sec" value="<?= e($forSec) ?>"></label>
                    <label><span>delta</span><input type="text" data-rule-ui="custom_delta" value="<?= e($delta) ?>"></label>
                    <label><span>window_sec</span><input type="number" data-rule-ui="custom_window_sec" value="<?= e($windowSec) ?>"></label>
                </div>
            </div>

            <div class="full-span action-builder-box">
                <div class="action-builder-head">
                    <span>Akciók</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-add-action>Akció hozzáadása</button>
                </div>
                <div class="action-builder-list" data-action-list>
                    <?php foreach (array_values((array) ($rule['actions'] ?? [])) as $actionIndex => $action): ?>
                        <?= render_action_row_html('rule_' . $index, $actionIndex, (string) $action, $groupNames) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Szabály törlése</button></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

$deviceId = $_GET['device_id'] ?? null;
$deviceService = new DeviceService();
$configService = new ConfigService();
$commandService = new CommandService();
$device = $deviceId ? $deviceService->find($deviceId) : null;
$state = $deviceId ? $deviceService->lastState($deviceId) : null;
$stateRaw = is_array($state) ? json_decode((string) ($state['raw_json'] ?? ''), true) : null;
if (!is_array($stateRaw)) {
    $stateRaw = [];
}
$telemetryTransport = raw_pick($stateRaw, ['telemetry_transport', 'meta.telemetry_transport', 'signal.transport']);
$wifiOk = raw_pick($stateRaw, ['wifi_ok', 'signal.wifi_ok']);
$wifiRssi = raw_pick($stateRaw, ['wifi_rssi', 'signal.wifi_rssi', 'signal.rssi'], $state['rssi'] ?? null);
$wifiIp      = raw_pick($stateRaw, ['wifi_ip', 'details.wifi_ip', 'details.ip']);
$wifiMac     = raw_pick($stateRaw, ['wifi_mac']);
$wifiChannel = raw_pick($stateRaw, ['wifi_channel']);
$gsmOk = raw_pick($stateRaw, ['gsm_ok', 'signal.gsm_ok']);
$gsmRssi = raw_pick($stateRaw, ['gsm_rssi', 'signal.gsm_rssi']);
$gsmOperator = raw_pick($stateRaw, ['gsm_operator', 'signal.gsm_operator', 'meta.gsm_operator', 'details.gsm_operator']);
$currentConfig = $deviceId ? $configService->getCurrent($deviceId) : null;
$detailPerPage = resolve_per_page($_GET['per_page'] ?? 20);
$alertsPage = resolve_page($_GET['alerts_page'] ?? 1);
$presencePage = resolve_page($_GET['presence_page'] ?? 1);
$alertsTotal = $deviceId ? $deviceService->countAlerts($deviceId) : 0;
$presenceTotal = $deviceId ? $deviceService->countPresence($deviceId) : 0;
$alerts = $deviceId ? $deviceService->recentAlertsPage($deviceId, $alertsPage, $detailPerPage) : [];
$presence = $deviceId ? $deviceService->recentPresencePage($deviceId, $presencePage, $detailPerPage) : [];
$commands = $deviceId ? $commandService->recentByDevice($deviceId, 10) : [];
$queueStats = $deviceId ? $deviceService->queueStats($deviceId) : ['queued' => 0, 'sent' => 0, 'acked' => 0, 'failed' => 0];
$defaultConfig = $configService->buildDefaultConfig();
$configJson = $currentConfig['config_json'] ?? json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$configData = json_decode((string) $configJson, true);
if (!is_array($configData)) {
    $configData = $defaultConfig;
}
$thresholds = (array) ($configData['thresholds'] ?? []);
$contacts = (array) ($configData['contacts'] ?? []);
$rules = array_values((array) ($configData['rules'] ?? []));
$contactGroups = (array) ($configData['contact_group'] ?? ($configData['contact_groups'] ?? []));
$routes = (array) ($configData['routes'] ?? []);

// ── ESP-NOW relay slave státusz ─────────────────────────────────────────────
$relaySlaves = [];
if ($deviceId && ($device['device_type'] ?? 'master') === 'master') {
    $db = \App\Core\Database::connection();
    $slaveStmt = $db->prepare("
        SELECT dls.device_id, dls.last_seen_at,
               TIMESTAMPDIFF(SECOND, dls.last_seen_at, NOW()) AS secs_ago,
               COALESCE(d.name, dls.device_id) AS display_name
        FROM device_last_state dls
        LEFT JOIN devices d ON d.device_id = dls.device_id
        WHERE JSON_UNQUOTE(JSON_EXTRACT(dls.raw_json, '$.relay_source')) = :master_id
        ORDER BY dls.last_seen_at DESC
    ");
    $slaveStmt->execute([':master_id' => $deviceId]);
    $relaySlaves = $slaveStmt->fetchAll();
}

// ── ESP-NOW relay konfig ────────────────────────────────────────────────────
$espnowStored   = is_array($configData['espnow'] ?? null) ? $configData['espnow'] : [];
$espnowEnabled  = (bool) ($espnowStored['enabled'] ?? false);
$espnowSlaves   = is_array($espnowStored['slaves'] ?? null) ? $espnowStored['slaves'] : [];
$espnowSlaveTxt = implode("\n", array_map('strval', $espnowSlaves));  // textarea: soronként egy ID

// ── Hibaállapot-figyelő (health checks) ────────────────────────────────────
$healthCheckDefs = [
    'no_wifi'         => 'Nincs WiFi kapcsolat',
    'no_gsm_modem'    => 'Nincs GSM modem (nem válaszol)',
    'no_gsm_operator' => 'Nincs GSM hálózati regisztráció',
    'no_usb_power'    => 'Nincs USB tápfeszültség (akkuról megy)',
    'no_sensor'       => 'Szenzor nem elérhető (BME280 / I2C hiba)',
    'mqtt_offline'    => 'MQTT offline / eszköz nem érhető el',
    'battery_low'     => 'Alacsony akkumulátor szint (küszöb alatt)',
];
// Alapértelmezett alarm flags (true = hibának számít)
$hcAlarmDefaults = [
    'no_wifi' => true, 'no_gsm_modem' => false, 'no_gsm_operator' => false,
    'no_usb_power' => false, 'no_sensor' => true, 'mqtt_offline' => true, 'battery_low' => true,
];
$hcStored = (array) ($configData['health_checks'] ?? []);
// $healthChecks[key] = ['alarm'=>bool, 'mattermost'=>bool, 'sms_target'=>string, 'call_target'=>string]
$healthChecks = [];
foreach ($healthCheckDefs as $key => $label) {
    $stored = is_array($hcStored[$key] ?? null) ? $hcStored[$key] : [];
    // Visszafelé-kompatibilitás: régi bool érték
    $oldBool = is_bool($hcStored[$key] ?? null) ? $hcStored[$key] : null;
    $healthChecks[$key] = [
        'alarm'       => $oldBool ?? (bool) ($stored['alarm'] ?? $hcAlarmDefaults[$key]),
        'mattermost'  => (bool) ($stored['mattermost'] ?? false),
        'sms_target'  => trim((string) ($stored['sms_target'] ?? '')),
        'call_target' => trim((string) ($stored['call_target'] ?? '')),
    ];
}

$builtinEventLabels = [
    'device_boot' => 'Eszköz elindult',
    'device_offline' => 'MQTT / eszköz offline',
    'device_online' => 'MQTT / eszköz online',
    'power_loss' => 'Tápellátás kiesett (akkura váltott)',
    'power_restored' => 'Tápellátás visszaállt (USB tápra váltott)',
    'temp_high' => 'Magas hőmérséklet',
    'temp_high_cleared' => 'Magas hőmérséklet megszűnt',
    'temp_low' => 'Alacsony hőmérséklet',
    'temp_low_cleared' => 'Alacsony hőmérséklet megszűnt',
    'contact_active' => 'Kontakt riasztás aktív',
    'contact_cleared' => 'Kontakt riasztás megszűnt',
];
$contactsMeta = [];
for ($contactIndex = 1; $contactIndex <= 4; $contactIndex++) {
    $contactKey = 'c' . $contactIndex;
    $contactsMeta[$contactKey] = contact_cfg($contacts, $contactKey, $contactIndex);
}
$ruleIdLabels = [];
foreach ($rules as $rule) {
    if (!is_array($rule)) {
        continue;
    }
    $ruleId = trim((string) ($rule['rule_id'] ?? ''));
    if ($ruleId === '') {
        continue;
    }
    $ruleIdLabels[$ruleId] = 'Egyedi szabály: ' . $ruleId;
}
$contactGroupNames = array_values(array_filter(array_map('strval', array_keys($contactGroups))));

// Aktív riasztás típusonként (temp_high vs temp_low vs contact)
$activeAlarmFamilies = [];
if ($deviceId) {
    try {
        $db = \App\Core\Database::connection();
        $astmt = $db->prepare("
            SELECT a.event_type
            FROM alerts a
            INNER JOIN (
                SELECT CASE
                           WHEN event_type IN ('temp_high','temp_high_cleared') THEN 'temp_high'
                           WHEN event_type IN ('temp_low','temp_low_cleared') THEN 'temp_low'
                           WHEN event_type IN ('contact_active','contact_cleared') THEN 'contact'
                       END AS alert_family,
                       MAX(id) AS max_id
                FROM alerts
                WHERE device_id = :did
                  AND event_type IN ('temp_high','temp_high_cleared','temp_low','temp_low_cleared','contact_active','contact_cleared')
                GROUP BY alert_family
            ) li ON li.max_id = a.id
            WHERE a.event_type IN ('temp_high','temp_low','contact_active')
        ");
        $astmt->execute(['did' => $deviceId]);
        foreach ($astmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $activeAlarmFamilies[] = (string) $row['event_type'];
        }
    } catch (\Throwable) {}
}

$pageTitle = $device ? $device['name'] : 'Új eszköz';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Eszköz adatlap</div>
        <h1><?= h($device['name'] ?? 'Új eszköz') ?></h1>
        <?php if ($device): ?><div class="muted small"><code><?= h($device['device_id']) ?></code></div><?php endif; ?>
    </div>
    <?php if ($device): ?>
    <div class="d-flex gap-2 flex-wrap">
        <form method="post" action="<?= e(app_url('save_config.php')) ?>">
            <input type="hidden" name="device_id" value="<?= h($device['device_id']) ?>">
            <input type="hidden" name="action" value="queue_push">
            <button class="btn btn-primary" type="submit">Csak push MQTT-re</button>
        </form>
        <a class="btn btn-outline-primary" href="<?= e(app_url('device_charts.php?device_id=' . urlencode((string) $device['device_id']))) ?>">Grafikonok</a>
        <a class="btn btn-outline-secondary" href="#raw-json">Raw JSON</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($device && $state): ?>
<?php
// ── LED állapotok kiszámítása ──────────────────────────────────────────────
// LED 0 – WiFi
$led0 = 'off';
if ($wifiOk !== null)     { $led0 = $wifiOk ? 'green' : ($healthChecks['no_wifi']['alarm'] ? 'blink-red' : 'red'); }
elseif ($state['online']) { $led0 = $telemetryTransport === 'wifi' ? 'green' : 'yellow'; }
else                      { $led0 = $healthChecks['no_wifi']['alarm'] ? 'blink-red' : 'red'; }

// LED 1 – MQTT
$mqttConn = raw_pick($stateRaw, ['mqtt_connected']);
if ($mqttConn !== null)       { $led1 = $mqttConn ? 'green' : ($healthChecks['mqtt_offline']['alarm'] ? 'blink-red' : 'red'); }
elseif ($state['online'] && $telemetryTransport === 'wifi') { $led1 = 'green'; }
else  { $led1 = $state['online'] ? 'yellow' : ($healthChecks['mqtt_offline']['alarm'] ? 'blink-red' : 'red'); }

// LED 2 – GSM
$gsmReg = raw_pick($stateRaw, ['gsm_registered', 'signal.gsm_registered']);
$gsmEnabled = raw_pick($stateRaw, ['gsm_enabled', 'gsm_ok']) ?? $gsmOk;
if (!$gsmEnabled && !$gsmReg) { $led2 = 'off'; }
elseif ($gsmOk && $gsmReg)    { $led2 = 'green'; }
elseif ($gsmOk)               { $led2 = $healthChecks['no_gsm_operator']['alarm'] ? 'blink-red' : 'yellow'; }
else                          { $led2 = $healthChecks['no_gsm_modem']['alarm']    ? 'blink-red' : 'red'; }

// LED 3 – BME280 szenzor
$sensorOk = raw_pick($stateRaw, ['sensor_ok']);
$led3 = $sensorOk === null ? 'off' : ($sensorOk ? 'green' : ($healthChecks['no_sensor']['alarm'] ? 'blink-red' : 'red'));

// LED 4 – Kontakt bemenet
$c1Mode = strtolower(trim((string) ($contacts['c1_mode'] ?? 'nc')));
$contactAlarmActive = in_array('contact_active', $activeAlarmFamilies, true);
if ($c1Mode === 'unused') { $led4 = 'off'; }
elseif ($contactAlarmActive) { $led4 = 'blink-red'; }
else { $led4 = 'green'; }

// LED 5 – Hőmérséklet riasztás
$tempHighActive = in_array('temp_high', $activeAlarmFamilies, true);
$tempLowActive  = in_array('temp_low',  $activeAlarmFamilies, true);
if ($tempHighActive)     { $led5 = 'blink-red'; }
elseif ($tempLowActive)  { $led5 = 'blink-blue'; }
else                     { $led5 = 'green'; }

// LED 6 – USB táp / töltés
$pm = strtolower(trim((string) ($state['power_mode'] ?? '')));
$battPct = $state['battery_pct'] !== null ? (float) $state['battery_pct'] : null;
if ($pm === 'usb_charging' || $pm === 'charging') { $led6 = 'teal'; }
elseif ($pm === 'usb')                            { $led6 = 'green'; }
elseif ($pm === 'battery')                        { $led6 = $healthChecks['no_usb_power']['alarm'] ? 'blink-red' : 'orange'; }
else                                              { $led6 = 'off'; }

// LED 7 – Akkumulátor töltöttség
$battLowPct = (float) ($thresholds['battery_low_pct'] ?? 20);
if ($battPct === null)                                              { $led7 = 'off'; }
elseif ($healthChecks['battery_low']['alarm'] && $battPct <= $battLowPct)  { $led7 = 'blink-red'; }
elseif ($battPct <= 15)     { $led7 = 'blink-red'; }
elseif ($battPct <= 25)     { $led7 = 'red'; }
elseif ($battPct <= 50)     { $led7 = 'yellow'; }
elseif ($battPct <= 75)     { $led7 = 'blue'; }
else                        { $led7 = 'green'; }

$leds = [
    ['color' => $led0, 'num' => '0', 'label' => 'WiFi',     'desc' => $wifiOk ? 'Csatlakozva' : ($state['online'] ? 'Aktív' : 'Offline')],
    ['color' => $led1, 'num' => '1', 'label' => 'MQTT',     'desc' => ($mqttConn ? 'Csatlakozva' : ($state['online'] ? 'Aktív' : 'Offline'))],
    ['color' => $led2, 'num' => '2', 'label' => 'GSM',      'desc' => ($gsmOk && $gsmReg ? 'Hálózaton' : ($gsmOk ? 'SIM OK' : ($led2 === 'off' ? 'Letiltva' : 'Hiba')))],
    ['color' => $led3, 'num' => '3', 'label' => 'Szenzor',  'desc' => ($sensorOk === null ? 'Ismeretlen' : ($sensorOk ? 'OK' : 'I2C hiba'))],
    ['color' => $led4, 'num' => '4', 'label' => 'Kontakt',  'desc' => ($c1Mode === 'unused' ? 'Nem figyelt' : ($contactAlarmActive ? 'Riasztás!' : 'Normál'))],
    ['color' => $led5, 'num' => '5', 'label' => 'Hőmérs.',  'desc' => ($tempHighActive ? 'Magas!' : ($tempLowActive ? 'Alacsony!' : 'Normál'))],
    ['color' => $led6, 'num' => '6', 'label' => 'Táp',      'desc' => power_mode_label($state['power_mode'] ?? null)],
    ['color' => $led7, 'num' => '7', 'label' => 'Akku',     'desc' => ($battPct !== null ? round($battPct) . ' %' : 'Ismeretlen')],
];
?>
<div class="device-led-strip" id="device-led-strip">
    <?php foreach ($leds as $led): ?>
    <div class="device-led" title="<?= e($led['label'] . ': ' . $led['desc']) ?>">
        <div class="device-led__dot device-led__dot--<?= e($led['color']) ?>"></div>
        <div class="device-led__num"><?= e($led['num']) ?></div>
        <div class="device-led__label"><?= e($led['label']) ?></div>
        <div class="device-led__desc"><?= e($led['desc']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="content-grid two-col">
    <section class="panel">
        <div class="section-head"><h2>Alapadatok</h2></div>
        <form method="post" action="<?= e(app_url('save_device.php')) ?>" class="form-grid">
            <input type="hidden" name="original_device_id" value="<?= h($device['device_id'] ?? '') ?>">
            <label>
                <span>Eszköz azonosító</span>
                <input type="text" name="device_id" value="<?= h($device['device_id'] ?? '') ?>" required>
            </label>
            <label>
                <span>Név</span>
                <input type="text" name="name" value="<?= h($device['name'] ?? '') ?>" required>
            </label>
            <label>
                <span>Hely</span>
                <input type="text" name="location" value="<?= h($device['location'] ?? '') ?>">
            </label>
            <label>
                <span>SIM telefonszám</span>
                <input type="text" name="sim_phone" value="<?= h($device['sim_phone'] ?? '') ?>">
            </label>
            <label>
                <span>Firmware</span>
                <input type="text" name="fw_version" value="<?= h($device['fw_version'] ?? '') ?>">
            </label>
            <label>
                <span>Eszköz típus</span>
                <select name="device_type">
                    <option value="master" <?= ($device['device_type'] ?? 'master') === 'master' ? 'selected' : '' ?>>Master (saját WiFi/MQTT)</option>
                    <option value="slave"  <?= ($device['device_type'] ?? 'master') === 'slave'  ? 'selected' : '' ?>>Slave (ESP-NOW relay)</option>
                </select>
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="active" <?= !isset($device['active']) || (int) $device['active'] === 1 ? 'checked' : '' ?>>
                <span>Aktív eszköz</span>
            </label>
            <div class="form-actions full-row">
                <button type="submit" class="btn btn-primary">Mentés</button>
            </div>
        </form>
    </section>

    <?php
    // Utolsó ismert állapot – hibás sorok kiemelése health_checks alapján
    $rowErrPower  = !empty($healthChecks['no_usb_power']['alarm'])  && $pm === 'battery';
    $rowErrBatt   = !empty($healthChecks['battery_low']['alarm'])   && $battPct !== null && $battPct <= $battLowPct;
    $rowErrWifi   = !empty($healthChecks['no_wifi']['alarm'])       && $wifiOk === false;
    $rowErrGsmMod = !empty($healthChecks['no_gsm_modem']['alarm'])  && $gsmOk === false;
    $rowErrGsmOp  = !empty($healthChecks['no_gsm_operator']['alarm']) && $gsmOk === true && empty($gsmOperator);
    $rowErrSensor = !empty($healthChecks['no_sensor']['alarm'])     && $sensorOk === false;
    $rowErrClass  = static fn(bool $err): string => $err ? ' class="kv-row--error"' : '';
    ?>
    <section class="panel" id="state-panel">
        <div class="section-head"><h2>Utolsó ismert állapot</h2><span class="muted small" title="Az oldal 5 másodpercenként kérdezi le a szervert. Az eszköz adatainak frissessége a mintavételi intervallumtól függ.">JS lekérdezés: <span id="state-refresh-countdown">…</span> &nbsp;|&nbsp; Eszköz: <span id="sv-device-age">…</span></span></div>
        <div class="kv-grid" id="state-kv-grid">
            <div><span>Online</span><strong id="sv-online"><?= $state ? ((int) $state['online'] === 1 ? 'Igen' : 'Nem') : '—' ?></strong></div>
            <div><span>Utolsó kapcsolat (szerver idő)</span><strong id="sv-last-seen"><?= h($state['last_seen_at'] ?? '—') ?></strong></div>
            <div><span>Kívánt konfiguráció</span><strong id="sv-desired-cfg"><?= h($currentConfig['config_version'] ?? '—') ?></strong></div>
            <div><span>Jelentett konfiguráció</span><strong id="sv-reported-cfg"><?= h($state['reported_config_version'] ?? '—') ?></strong></div>
            <div id="sv-sensor-temp-row"<?= $rowErrClass($rowErrSensor) ?>><span>Hőmérséklet</span><strong id="sv-temp"><?= h(isset($state['temperature']) ? $state['temperature'] . ' °C' : '—') ?></strong></div>
            <div id="sv-sensor-hum-row"<?= $rowErrClass($rowErrSensor) ?>><span>Páratartalom</span><strong id="sv-hum"><?= h(isset($state['humidity']) ? $state['humidity'] . ' %' : '—') ?></strong></div>
            <div id="sv-sensor-pres-row"<?= $rowErrClass($rowErrSensor) ?>><span>Légnyomás</span><strong id="sv-pressure"><?= h(isset($state['pressure_hpa']) ? $state['pressure_hpa'] . ' hPa' : '—') ?></strong></div>
            <div id="sv-batt-row"<?= $rowErrClass($rowErrBatt) ?>><span>Akku</span><strong id="sv-batt"><?= h(isset($state['battery_pct']) ? $state['battery_pct'] . ' %' : '—') ?></strong></div>
            <div id="sv-power-row"<?= $rowErrClass($rowErrPower) ?>><span>Táp mód</span><strong id="sv-power"><?= h(power_mode_label($state['power_mode'] ?? null)) ?></strong></div>
            <div><span>Átviteli út</span><strong id="sv-transport"><?= h($telemetryTransport ?? '—') ?></strong></div>
            <div id="sv-wifi-ok-row"<?= $rowErrClass($rowErrWifi) ?>><span>Wi‑Fi OK</span><strong id="sv-wifi-ok"><?= h($wifiOk === null ? '—' : ((bool) $wifiOk ? 'Igen' : 'Nem')) ?></strong></div>
            <div><span>Wi‑Fi RSSI</span><strong id="sv-wifi-rssi"><?= h($wifiRssi !== null ? (string) $wifiRssi . ' dBm' : '—') ?></strong></div>
            <div><span>Wi‑Fi IP</span><strong id="sv-wifi-ip"><?= h($wifiIp ?: '—') ?></strong></div>
            <div><span>Wi‑Fi MAC</span><strong id="sv-wifi-mac"><?= h($wifiMac ?: '—') ?></strong></div>
            <div><span>Wi‑Fi csatorna</span><strong id="sv-wifi-ch"><?= h($wifiChannel !== null ? (string) $wifiChannel : '—') ?></strong></div>
            <div id="sv-gsm-ok-row"<?= $rowErrClass($rowErrGsmMod) ?>><span>GSM OK</span><strong id="sv-gsm-ok"><?= h($gsmOk === null ? '—' : ((bool) $gsmOk ? 'Igen' : 'Nem')) ?></strong></div>
            <div><span>GSM RSSI</span><strong id="sv-gsm-rssi"><?= h($gsmRssi !== null ? (string) $gsmRssi . ' dBm' : '—') ?></strong></div>
            <div id="sv-gsm-op-row"<?= $rowErrClass($rowErrGsmOp) ?>><span>GSM szolgáltató</span><strong id="sv-gsm-op"><?= h($gsmOperator ?: '—') ?></strong></div>
        </div>
        <div class="mini-stats mt-3" id="sv-alert-badges">
            <span class="badge-status status-warn">Queue: <?= h((string) $queueStats['queued']) ?></span>
            <span class="badge-status status-info">Sent: <?= h((string) $queueStats['sent']) ?></span>
            <span class="badge-status status-online">Acked: <?= h((string) $queueStats['acked']) ?></span>
            <?php if ($queueStats['failed'] > 0): ?><span class="badge-status status-offline">Failed: <?= h((string) $queueStats['failed']) ?></span><?php endif; ?>
        </div>
    </section>
</div>

<?php if (($device['device_type'] ?? 'master') === 'master'): ?>
<section class="panel" id="relay-slaves-panel">
    <div class="section-head">
        <h2>Kapcsolódó ESP-NOW slave-ek</h2>
        <span class="muted small">Zöld = aktív (≤ 5 perc), piros = inaktív (> 5 perc)</span>
    </div>
    <?php if (empty($relaySlaves)): ?>
        <p class="muted small" style="margin:.5rem 0">Még nem érkezett relayelt adat ettől a mestertől.</p>
    <?php else: ?>
        <div class="kv-grid" style="grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:.6rem">
            <?php foreach ($relaySlaves as $sl):
                $secsAgo = (int)($sl['secs_ago'] ?? PHP_INT_MAX);
                $active  = $secsAgo <= 300;
                $statusClass = $active ? 'status-online' : 'status-offline';
                $statusLabel = $active ? 'Aktív' : 'Inaktív';
                $lastSeen = $sl['last_seen_at'] ?? '—';
                $slUrl = app_url('device.php?device_id=' . urlencode($sl['device_id']));
            ?>
            <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;background:var(--bg-card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:6px">
                <span class="badge-status <?= $statusClass ?>" style="min-width:60px;text-align:center"><?= $statusLabel ?></span>
                <div style="overflow:hidden">
                    <a href="<?= e($slUrl) ?>" style="font-weight:600;font-size:.9rem;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($sl['display_name']) ?></a>
                    <span class="muted small" style="font-size:.75rem"><?= h($lastSeen) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="section-head">
        <div>
            <h2>Új konfiguráció</h2>
            <div class="muted small">Magyar, választólistás szerkesztő. A háttérben ugyanaz a JSON formátum marad az ESP felé.</div>
        </div>
    </div>
    <form method="post" action="<?= e(app_url('save_config.php')) ?>" id="device-config-form">
        <input type="hidden" name="device_id" value="<?= h($device['device_id'] ?? '') ?>">
        <input type="hidden" name="action" value="save_config_form">

        <div class="config-grid">
            <section class="config-card">
                <div class="config-card-head"><h3>Általános</h3></div>
                <div class="form-grid single-col compact-grid">
                    <label>
                        <span>Mintavételezés (sec)</span>
                        <input type="number" name="sampling_sec" min="30" step="1" value="<?= h($configData['sampling_sec'] ?? 180) ?>">
                    </label>
                    <label>
                        <span>Életjel / heartbeat (sec)</span>
                        <input type="number" name="heartbeat_sec" min="30" step="1" value="<?= h($configData['heartbeat_sec'] ?? 180) ?>">
                    </label>
                </div>
            </section>

            <section class="config-card">
                <div class="config-card-head"><h3>Küszöbök</h3></div>
                <?php
                // Aktuális értékek + LED-szerű színezés
                $curTemp    = isset($state['temperature']) ? (float) $state['temperature'] : null;
                $curHum     = isset($state['humidity'])    ? (float) $state['humidity']    : null;
                $curBatt    = isset($state['battery_pct']) ? (float) $state['battery_pct'] : null;
                $tMin = isset($thresholds['temp_min'])     ? (float) $thresholds['temp_min']     : null;
                $tMax = isset($thresholds['temp_max'])     ? (float) $thresholds['temp_max']     : null;
                $hMin = isset($thresholds['humidity_min']) ? (float) $thresholds['humidity_min'] : null;
                $hMax = isset($thresholds['humidity_max']) ? (float) $thresholds['humidity_max'] : null;
                $bLow = isset($thresholds['battery_low_pct']) ? (float) $thresholds['battery_low_pct'] : null;

                // Chip CSS osztály LED logika alapján
                $tempClass = 'chip-ok';
                if ($curTemp !== null) {
                    if (($tMax !== null && $curTemp >= $tMax) || ($tMin !== null && $curTemp <= $tMin)) {
                        $tempClass = 'chip-alarm';
                    } elseif (($tMax !== null && $curTemp >= $tMax - 1.5) || ($tMin !== null && $curTemp <= $tMin + 1.5)) {
                        $tempClass = 'chip-warn';
                    }
                }
                $humClass = 'chip-ok';
                if ($curHum !== null) {
                    if (($hMax !== null && $curHum >= $hMax) || ($hMin !== null && $curHum <= $hMin)) {
                        $humClass = 'chip-alarm';
                    } elseif (($hMax !== null && $curHum >= $hMax - 5) || ($hMin !== null && $curHum <= $hMin + 5)) {
                        $humClass = 'chip-warn';
                    }
                }
                $battClass = 'chip-ok';
                if ($curBatt !== null) {
                    if ($curBatt <= 15) {
                        $battClass = 'chip-alarm';
                    } elseif ($bLow !== null && $curBatt <= $bLow) {
                        $battClass = 'chip-warn';
                    } elseif ($curBatt <= 50) {
                        $battClass = 'chip-mid';
                    }
                }
                ?>
                <div class="live-value-strip" id="live-value-strip">
                    <span class="live-chip <?= $tempClass ?>">
                        <span class="live-chip__label">Hő</span>
                        <span class="live-chip__val"><?= $curTemp !== null ? number_format($curTemp, 1) . ' °C' : '—' ?></span>
                        <?php if ($tMin !== null || $tMax !== null): ?>
                            <span class="live-chip__range"><?= h(($tMin ?? '?') . '…' . ($tMax ?? '?') . ' °C') ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="live-chip <?= $humClass ?>">
                        <span class="live-chip__label">Pára</span>
                        <span class="live-chip__val"><?= $curHum !== null ? number_format($curHum, 1) . ' %' : '—' ?></span>
                        <?php if ($hMin !== null || $hMax !== null): ?>
                            <span class="live-chip__range"><?= h(($hMin ?? '?') . '…' . ($hMax ?? '?') . ' %') ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="live-chip <?= $battClass ?>">
                        <span class="live-chip__label">Akku</span>
                        <span class="live-chip__val"><?= $curBatt !== null ? number_format($curBatt, 0) . ' %' : '—' ?></span>
                        <?php if ($bLow !== null): ?>
                            <span class="live-chip__range">low: <?= h((string) $bLow) ?> %</span>
                        <?php endif; ?>
                    </span>
                    <?php if ($state): ?>
                    <span class="live-chip <?= (int) ($state['online'] ?? 0) === 1 ? 'chip-ok' : 'chip-alarm' ?>">
                        <span class="live-chip__label">Online</span>
                        <span class="live-chip__val"><?= (int) ($state['online'] ?? 0) === 1 ? 'Igen' : 'Nem' ?></span>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="form-grid compact-grid">
                    <label><span>Temp min</span><input type="number" step="0.1" name="thresholds[temp_min]" value="<?= h($thresholds['temp_min'] ?? '') ?>"></label>
                    <label><span>Temp max</span><input type="number" step="0.1" name="thresholds[temp_max]" value="<?= h($thresholds['temp_max'] ?? '') ?>"></label>
                    <label><span>Páratartalom min</span><input type="number" step="0.1" name="thresholds[humidity_min]" value="<?= h($thresholds['humidity_min'] ?? '') ?>"></label>
                    <label><span>Páratartalom max</span><input type="number" step="0.1" name="thresholds[humidity_max]" value="<?= h($thresholds['humidity_max'] ?? '') ?>"></label>
                    <label><span>Akku low %</span><input type="number" step="1" name="thresholds[battery_low_pct]" value="<?= h($thresholds['battery_low_pct'] ?? '') ?>"></label>
                </div>
            </section>

            <section class="config-card">
                <div class="config-card-head">
                    <div>
                        <h3>Hibaállapot-figyelő</h3>
                        <div class="muted small">✓ Hiba = LED pirosan villog. Mattermost/SMS/Hívás: melyik értesítés menjen hiba esetén.</div>
                    </div>
                </div>
                <div class="hc-table">
                    <div class="hc-table-head">
                        <span>Feltétel</span>
                        <span title="Hibának számít">Hiba</span>
                        <span>MM</span>
                        <span>SMS cél</span>
                        <span>Hívás cél</span>
                    </div>
                    <?php foreach ($healthCheckDefs as $hcKey => $hcLabel):
                        $hc = $healthChecks[$hcKey];
                    ?>
                    <div class="hc-table-row">
                        <span class="hc-label"><?= h($hcLabel) ?></span>
                        <span class="hc-cell-center">
                            <input type="checkbox" name="health_checks[<?= h($hcKey) ?>][alarm]" value="1"<?= $hc['alarm'] ? ' checked' : '' ?>>
                        </span>
                        <span class="hc-cell-center">
                            <input type="checkbox" name="health_checks[<?= h($hcKey) ?>][mattermost]" value="1"<?= $hc['mattermost'] ? ' checked' : '' ?> title="Mattermost üzenet">
                        </span>
                        <span>
                            <input type="text" class="hc-target-input" name="health_checks[<?= h($hcKey) ?>][sms_target]" value="<?= h($hc['sms_target']) ?>" placeholder="csoport / +36…" title="SMS célcím (üres = nem küld)">
                        </span>
                        <span>
                            <input type="text" class="hc-target-input" name="health_checks[<?= h($hcKey) ?>][call_target]" value="<?= h($hc['call_target']) ?>" placeholder="csoport / +36…" title="Hívás célcím (üres = nem hív)">
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="config-card">
                <div class="config-card-head">
                    <div>
                        <h3>ESP-NOW relay</h3>
                        <div class="muted small">A master ESP32 begyűjti a slave eszközök adatait ESP-NOW-on keresztül, és továbbítja MQTT-en. SMS/hívás a saját SIM800L-en keresztül kezelt.</div>
                    </div>
                </div>
                <div class="form-grid">
                    <label class="label-row">
                        <input type="hidden" name="espnow[enabled]" value="0">
                        <input type="checkbox" name="espnow[enabled]" value="1"<?= $espnowEnabled ? ' checked' : '' ?>>
                        <span>Relay engedélyezve</span>
                    </label>
                    <label>
                        <span>Engedélyezett slave ID-k <span class="muted">(soronként egy; üres = mindenki elfogadva)</span></span>
                        <textarea name="espnow[slaves_txt]" rows="4" placeholder="slave01&#10;slave02&#10;teraszszenzor" style="font-family:monospace;width:100%"><?= h($espnowSlaveTxt) ?></textarea>
                    </label>
                </div>
            </section>

            <section class="config-card config-card--wide">
                <div class="config-card-head">
                    <div>
                        <h3>Kontaktok</h3>
                        <div class="muted small">Mind a négy kontakt külön neve, módja és két állapotának emberi jelentése állítható.</div>
                    </div>
                </div>
                <div class="contact-config-grid">
                    <?php foreach ($contactsMeta as $contactKey => $meta): ?>
                        <article class="contact-config-card" data-contact-key="<?= e($contactKey) ?>">
                            <div class="contact-config-card__head">
                                <strong><?= e('Kontakt ' . $meta['index']) ?></strong>
                                <span class="muted small">Kulcs: <?= e($contactKey) ?></span>
                            </div>
                            <label>
                                <span>Megnevezés</span>
                                <input type="text" name="contacts[<?= e($contactKey) ?>_name]" data-contact-field="name" value="<?= e($meta['name']) ?>" placeholder="példa: Bejárati ajtó">
                            </label>
                            <label>
                                <span>Működési mód</span>
                                <select name="contacts[<?= e($contactKey) ?>_mode]" data-contact-field="mode">
                                    <option value="nc" <?= $meta['mode'] === 'nc' ? 'selected' : '' ?>>Normally Closed</option>
                                    <option value="no" <?= $meta['mode'] === 'no' ? 'selected' : '' ?>>Normally Open</option>
                                    <option value="unused" <?= $meta['mode'] === 'unused' ? 'selected' : '' ?>>Nem használt</option>
                                </select>
                            </label>
                            <label>
                                <span>Nyitott állapot jelentése</span>
                                <input type="text" name="contacts[<?= e($contactKey) ?>_open_label]" data-contact-field="open_label" value="<?= e($meta['open_label']) ?>" placeholder="példa: Ajtó nyitva">
                            </label>
                            <label>
                                <span>Zárt állapot jelentése</span>
                                <input type="text" name="contacts[<?= e($contactKey) ?>_closed_label]" data-contact-field="closed_label" value="<?= e($meta['closed_label']) ?>" placeholder="példa: Ajtó zárva">
                            </label>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="content-grid two-col mt-3">
            <section class="config-card">
                <div class="config-card-head">
                    <div>
                        <h3>Egyedi szabályok</h3>
                        <div class="muted small">A feltétel és az akciók magyarul választhatók. A háttérben továbbra is a meglévő technikai mezők mentődnek.</div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="button" id="add-rule-row">Új szabály</button>
                </div>
                <div id="rules-rows" class="dynamic-stack">
                    <?php foreach ($rules as $index => $rule): ?>
                        <?= render_rule_row_html($index, (array) $rule, $contactsMeta, $contactGroupNames) ?>
                    <?php endforeach; ?>
                    <?php if (!$rules): ?>
                        <?= render_rule_row_html(0, ['rule_id' => '', 'type' => 'threshold', 'sensor' => 'temperature', 'operator' => '>=', 'value' => '', 'for_sec' => 60, 'actions' => ['mattermost']], $contactsMeta, $contactGroupNames) ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="config-card">
                <div class="config-card-head">
                    <div>
                        <h3>Kontakt csoportok</h3>
                        <div class="muted small">A csoportok használhatók SMS-hez és híváshoz. Telefonszámok vesszővel elválasztva.</div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-add-row="tpl-group-row" data-target="#groups-rows">Új csoport</button>
                </div>
                <div id="groups-rows" class="dynamic-stack">
                    <?php $groupIndex = 0; foreach ($contactGroups as $groupName => $phones): ?>
                        <div class="dynamic-row" data-row>
                            <div class="dynamic-row-grid two-col-compact">
                                <label><span>Csoport neve</span><input data-field="name" type="text" name="contact_groups[<?= $groupIndex ?>][name]" value="<?= h($groupName) ?>"></label>
                                <label><span>Telefonszámok</span><input data-field="phones" type="text" name="contact_groups[<?= $groupIndex ?>][phones]" value="<?= h(implode(',', (array) $phones)) ?>"></label>
                            </div>
                            <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Csoport törlése</button></div>
                        </div>
                    <?php $groupIndex++; endforeach; ?>
                </div>
            </section>
        </div>

        <section class="config-card mt-3">
            <div class="config-card-head">
                <div>
                    <h3>Route-ok</h3>
                    <div class="muted small">Esemény → akciók. Rendszeresemény és egyedi szabály is választható, nem kell technikai kulcsokat gépelni.</div>
                </div>
                <button class="btn btn-outline-primary btn-sm" type="button" id="add-route-row">Új route</button>
            </div>
            <div id="routes-rows" class="dynamic-stack">
                <?php $routeIndex = 0; foreach ($routes as $eventName => $actions): ?>
                    <?= render_route_row_html($routeIndex, (string) $eventName, (array) $actions, $builtinEventLabels, $ruleIdLabels, $contactGroupNames) ?>
                <?php $routeIndex++; endforeach; ?>
            </div>
        </section>

        <div class="form-actions mt-3">
            <button class="btn btn-primary" type="submit">Strukturált konfiguráció mentése</button>
            <button class="btn btn-outline-primary" type="submit" name="action" value="save_and_push_form">Strukturált mentés + push</button>
        </div>
    </form>
</section>

<template id="tpl-group-row">
    <div class="dynamic-row" data-row>
        <div class="dynamic-row-grid two-col-compact">
            <label><span>Csoport neve</span><input data-field="name" type="text" name="contact_groups[__INDEX__][name]" value=""></label>
            <label><span>Telefonszámok</span><input data-field="phones" type="text" name="contact_groups[__INDEX__][phones]" value=""></label>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Csoport törlése</button></div>
    </div>
</template>

<script>
window.DEVICE_CONFIG_UI = <?= json_encode([
    'builtinEvents' => $builtinEventLabels,
    'contactsMeta' => $contactsMeta,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.DEVICE_HEALTH_CHECKS = <?= json_encode(array_map(fn($hc) => $hc['alarm'], $healthChecks), JSON_UNESCAPED_UNICODE) ?>;
window.DEVICE_BATT_LOW_PCT  = <?= json_encode((float) ($thresholds['battery_low_pct'] ?? 20)) ?>;
</script>
<section class="panel" id="raw-json">
    <div class="section-head"><h2>Raw JSON tartalék szerkesztés</h2></div>
    <p class="muted mb-3">Ha valami speciális mezőt kell gyorsan módosítani, itt továbbra is kézzel szerkeszthető a teljes payload.</p>
    <form method="post" action="<?= e(app_url('save_config.php')) ?>">
        <input type="hidden" name="device_id" value="<?= h($device['device_id'] ?? '') ?>">
        <input type="hidden" name="action" value="save_config">
        <textarea name="config_json" class="code-area" rows="24"><?= h($configJson) ?></textarea>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" name="action" value="save_config_raw" class="btn btn-primary">Raw mentés</button>
            <button type="submit" name="action" value="save_and_push_raw" class="btn btn-outline-primary">Raw mentés + push</button>
        </div>
    </form>
</section>

<div class="content-grid two-col">
<section class="panel">
    <div class="section-head">
        <h2>Jelenlét / LWT napló</h2>
        <form method="get" class="d-flex gap-2">
            <input type="hidden" name="device_id" value="<?= h($device['device_id'] ?? '') ?>">
            <input type="hidden" name="alerts_page" value="<?= h((string) $alertsPage) ?>">
            <select class="form-select" name="per_page" onchange="this.form.submit()">
                <?php foreach (per_page_options() as $opt): ?>
                    <option value="<?= $opt ?>" <?= $detailPerPage === $opt ? 'selected' : '' ?>><?= $opt ?>/oldal</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="stack-list">
        <?php foreach ($presence as $row): ?>
            <article class="alert-item severity-<?= h($row['status'] === 'online' ? 'info' : 'warning') ?>">
                <div><strong><?= h($row['status']) ?></strong><div class="muted small"><?= h($row['happened_at']) ?></div></div>
                <p><?= h($row['payload_json']) ?></p>
            </article>
        <?php endforeach; ?>
        <?php if (!$presence): ?><p class="muted">Ehhez az eszközhöz még nincs jelenlét esemény.</p><?php endif; ?>
    </div>
    <?= render_pagination($presencePage, $detailPerPage, $presenceTotal, 'device.php', 'presence_page', ['device_id' => $device['device_id'] ?? '', 'alerts_page' => $alertsPage]) ?>
</section>

<section class="panel">
    <div class="section-head"><h2>Friss riasztások</h2></div>
    <div class="stack-list">
        <?php foreach ($alerts as $alert): ?>
            <article class="alert-item severity-<?= h($alert['severity']) ?>">
                <div><strong><?= h($alert['event_type']) ?></strong><div class="muted small">Szerver idő: <?= h($alert['ts']) ?></div><?php $raw = json_decode((string) ($alert['raw_json'] ?? ''), true); if (is_array($raw) && !empty($raw['_device_ts_normalized'])): ?><div class="muted small">Eszköz idő: <?= h((string) $raw['_device_ts_normalized']) ?></div><?php endif; ?></div>
                <p><?= h($alert['message']) ?></p>
            </article>
        <?php endforeach; ?>
        <?php if (!$alerts): ?><p class="muted">Ehhez az eszközhöz még nincs riasztás.</p><?php endif; ?>
    </div>
    <?= render_pagination($alertsPage, $detailPerPage, $alertsTotal, 'device.php', 'alerts_page', ['device_id' => $device['device_id'] ?? '', 'presence_page' => $presencePage]) ?>
</section>
</div>

<section class="panel">
    <div class="section-head"><h2>Legutóbbi parancsok</h2></div>
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Idő</th>
                <th>Típus</th>
                <th>Állapot</th>
                <th>Request ID</th>
                <th>Eredmény</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($commands as $row): ?>
                <tr>
                    <td><?= h($row['created_at']) ?></td>
                    <td><?= h($row['command_type']) ?></td>
                    <td><?= command_status_badge((string) $row['status']) ?></td>
                    <td><code><?= h($row['request_id']) ?></code></td>
                    <td>
                        <?= h($row['result_message'] ?: '—') ?>
                        <?php if (!empty($row['result_payload_json'])): ?>
                            <details class="mt-2">
                                <summary>JSON</summary>
                                <pre class="json-box"><?= h(pretty_json($row['result_payload_json'])) ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$commands): ?>
                <tr><td colspan="5" class="text-center text-muted">Nincs még parancs előzmény.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<template id="tpl-rule-row">
    <div class="dynamic-row" data-row>
        <div class="dynamic-row-grid rules-grid">
            <label><span>Rule ID</span><input data-field="rule_id" type="text" name="rules[__INDEX__][rule_id]" value=""></label>
            <label><span>Típus</span><input data-field="type" type="text" name="rules[__INDEX__][type]" value="threshold"></label>
            <label><span>Szenzor</span><input data-field="sensor" type="text" name="rules[__INDEX__][sensor]" value="temperature"></label>
            <label><span>Operátor</span><input data-field="operator" type="text" name="rules[__INDEX__][operator]" value=">="></label>
            <label><span>Érték</span><input data-field="value" type="text" name="rules[__INDEX__][value]" value=""></label>
            <label><span>for_sec</span><input data-field="for_sec" type="number" name="rules[__INDEX__][for_sec]" value="60"></label>
            <label><span>delta</span><input data-field="delta" type="text" name="rules[__INDEX__][delta]" value=""></label>
            <label><span>window_sec</span><input data-field="window_sec" type="number" name="rules[__INDEX__][window_sec]" value=""></label>
            <label class="full-span"><span>Akciók</span><input data-field="actions" type="text" name="rules[__INDEX__][actions]" value="mattermost"></label>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Szabály törlése</button></div>
    </div>
</template>

<template id="tpl-group-row">
    <div class="dynamic-row" data-row>
        <div class="dynamic-row-grid two-col-compact">
            <label><span>Csoport neve</span><input data-field="name" type="text" name="contact_groups[__INDEX__][name]" value=""></label>
            <label><span>Telefonszámok</span><input data-field="phones" type="text" name="contact_groups[__INDEX__][phones]" value=""></label>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Csoport törlése</button></div>
    </div>
</template>

<template id="tpl-route-row">
    <div class="dynamic-row" data-row>
        <div class="dynamic-row-grid two-col-compact">
            <label><span>Esemény</span><input data-field="event" type="text" name="routes[__INDEX__][event]" value=""></label>
            <label><span>Akciók</span><input data-field="actions" type="text" name="routes[__INDEX__][actions]" value="mattermost"></label>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Route törlése</button></div>
    </div>
</template>

<script src="<?= e(app_url('assets/js/device-config.js')) ?>"></script>
<script>
(function () {
    var deviceId = <?= json_encode($device['device_id'] ?? '') ?>;
    var countdownEl = document.getElementById('state-refresh-countdown');

    function pm(mode) {
        var map = { 'usb_charging': 'USB/töltés', 'charging': 'USB/töltés', 'usb': 'USB', 'battery': 'Akku' };
        return map[(mode || '').toLowerCase()] || (mode || '—');
    }
    function set(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }

    // LED sáv frissítése – a firmware által küldött leds[] tömb alapján (pontos!)
    var LED_LABELS = ['WiFi', 'MQTT', 'GSM', 'Szenzor', 'Kontakt', 'Hőmérs.', 'Táp', 'Akku'];
    var LED_DESCS = {
        'green':'OK', 'yellow':'Figyelem', 'orange':'Akku', 'red':'Hiba',
        'teal':'Tölt', 'blue':'Jó', 'off':'—',
        'blink-red':'Riasztás!', 'blink-blue':'Riasztás!', 'blink-orange':'Riasztás!'
    };

    function setLed(idx, color, desc) {
        var strip = document.getElementById('device-led-strip');
        if (!strip) return;
        var leds = strip.querySelectorAll('.device-led');
        if (!leds[idx]) return;
        var dot = leds[idx].querySelector('.device-led__dot');
        var descEl = leds[idx].querySelector('.device-led__desc');
        if (dot) dot.className = 'device-led__dot device-led__dot--' + color;
        if (descEl && desc !== undefined) descEl.textContent = desc;
    }

    function applyState(d) {
        if (!d || d.error) return;

        // Értékek frissítése
        set('sv-online',       d.online ? 'Igen' : 'Nem');
        set('sv-last-seen',    d.last_seen_at || '—');
        set('sv-reported-cfg', d.reported_config_version || '—');
        set('sv-temp',         d.temperature !== null ? d.temperature + ' °C' : '—');
        set('sv-hum',          d.humidity    !== null ? d.humidity    + ' %'  : '—');
        set('sv-pressure',     d.pressure_hpa !== null ? d.pressure_hpa + ' hPa' : '—');
        set('sv-batt',         d.battery_pct !== null ? d.battery_pct + ' %' : '—');
        set('sv-power',        pm(d.power_mode));
        set('sv-transport',    d.telemetry_transport || '—');
        set('sv-wifi-ok',      d.wifi_ok === null ? '—' : (d.wifi_ok ? 'Igen' : 'Nem'));
        set('sv-wifi-rssi',    d.wifi_rssi !== null ? d.wifi_rssi + ' dBm' : '—');
        set('sv-wifi-ip',      d.wifi_ip || '—');
        set('sv-gsm-ok',       d.gsm_ok === null ? '—' : (d.gsm_ok ? 'Igen' : 'Nem'));
        set('sv-gsm-rssi',     d.gsm_rssi !== null ? d.gsm_rssi + ' dBm' : '—');
        set('sv-gsm-op',       d.gsm_operator || '—');

        // KV-grid sor hibakiemelések (health_checks alapján)
        function rowErr(id, isErr) {
            var el = document.getElementById(id);
            if (el) el.classList.toggle('kv-row--error', !!isErr);
        }
        var hcCfg = window.DEVICE_HEALTH_CHECKS || {};
        var battLowPct2 = window.DEVICE_BATT_LOW_PCT || 20;
        var pModeNow = (d.power_mode || '').toLowerCase();
        var bpNow = d.battery_pct;
        rowErr('sv-power-row',       pModeNow === 'battery' && !!hcCfg.no_usb_power);
        rowErr('sv-batt-row',        bpNow !== null && bpNow !== undefined && !!hcCfg.battery_low && +bpNow <= battLowPct2);
        rowErr('sv-wifi-ok-row',     d.wifi_ok === false && !!hcCfg.no_wifi);
        rowErr('sv-gsm-ok-row',      d.gsm_ok === false && !!hcCfg.no_gsm_modem);
        rowErr('sv-gsm-op-row',      d.gsm_ok === true && (!d.gsm_operator || d.gsm_operator === '—') && !!hcCfg.no_gsm_operator);
        var sOkNow = (d.sensor_ok === false);
        rowErr('sv-sensor-temp-row', sOkNow && !!hcCfg.no_sensor);
        rowErr('sv-sensor-hum-row',  sOkNow && !!hcCfg.no_sensor);
        rowErr('sv-sensor-pres-row', sOkNow && !!hcCfg.no_sensor);

        // LED sáv frissítése – értékekből deriválva, health_checks figyelembevételével
        var leds = d.leds || [];
        var hc = window.DEVICE_HEALTH_CHECKS || {};
        var battLowPct = window.DEVICE_BATT_LOW_PCT || 20;

        // LED 0 – WiFi
        var wifiOk = d.wifi_ok;
        var w0, wd0;
        if (wifiOk) { w0 = 'green'; wd0 = 'Csatlakozva'; }
        else if (d.online && d.telemetry_transport === 'wifi') { w0 = 'green'; wd0 = 'Aktív'; }
        else if (d.online) { w0 = 'yellow'; wd0 = 'GSM útvonal'; }
        else { w0 = hc.no_wifi ? 'blink-red' : 'red'; wd0 = hc.no_wifi ? 'Hiba!' : 'Offline'; }
        setLed(0, w0, wd0);

        // LED 1 – MQTT
        var w1 = d.online ? 'green' : (hc.mqtt_offline ? 'blink-red' : 'red');
        setLed(1, w1, d.online ? 'Csatlakozva' : (hc.mqtt_offline ? 'Hiba!' : 'Offline'));

        // LED 2 – GSM
        var g2, gd2;
        if (d.gsm_ok) { g2 = 'green'; gd2 = 'Hálózaton'; }
        else if (d.gsm_operator) { g2 = hc.no_gsm_operator ? 'blink-red' : 'yellow'; gd2 = hc.no_gsm_operator ? 'Hiba!' : 'SIM OK'; }
        else { g2 = hc.no_gsm_modem ? 'blink-red' : 'off'; gd2 = hc.no_gsm_modem ? 'Hiba!' : 'Letiltva'; }
        setLed(2, g2, gd2);

        // LED 3 – Szenzor: sensor_ok flag > leds[3] > hőm. érvényességéből
        var s3, sd3;
        if (d.sensor_ok !== null && d.sensor_ok !== undefined) {
            s3 = d.sensor_ok ? 'green' : (hc.no_sensor ? 'blink-red' : 'red');
        } else if (leds[3]) {
            s3 = leds[3];
        } else {
            s3 = (d.temperature !== null && d.temperature !== undefined) ? 'green' : 'off';
        }
        sd3 = s3 === 'green' ? 'OK' : (s3.indexOf('red') >= 0 ? (hc.no_sensor ? 'Hiba!' : 'I2C hiba') : '—');
        setLed(3, s3, sd3);

        // LED 4 – Kontakt
        var c4 = d.active_contact_alert_count > 0 ? 'blink-orange' : 'green';
        setLed(4, c4, d.active_contact_alert_count > 0 ? 'Riasztás!' : 'Normál');

        // LED 5 – Hőmérséklet
        var alarms = d.active_alarm_types || [];
        var l5 = alarms.indexOf('temp_high') >= 0 ? 'blink-red' : (alarms.indexOf('temp_low') >= 0 ? 'blink-blue' : 'green');
        var d5 = alarms.indexOf('temp_high') >= 0 ? 'Magas!' : (alarms.indexOf('temp_low') >= 0 ? 'Alacsony!' : 'Normál');
        setLed(5, l5, d5);

        // LED 6 – Táp
        var pMode = (d.power_mode || '').toLowerCase();
        var l6, ld6;
        if (pMode === 'usb_charging' || pMode === 'charging') { l6 = 'teal';  ld6 = 'USB/töltés'; }
        else if (pMode === 'usb')    { l6 = 'green'; ld6 = 'USB táp'; }
        else if (pMode === 'battery'){ l6 = hc.no_usb_power ? 'blink-red' : 'orange'; ld6 = hc.no_usb_power ? 'Hiba! Akku' : 'Akku'; }
        else                         { l6 = 'off'; ld6 = '—'; }
        setLed(6, l6, ld6);

        // LED 7 – Akku
        var bp = d.battery_pct;
        var l7;
        if (bp === null || bp === undefined) { l7 = 'off'; }
        else if (hc.battery_low && bp <= battLowPct) { l7 = 'blink-red'; }
        else if (bp <= 15)  { l7 = 'blink-red'; }
        else if (bp <= 25)  { l7 = 'red'; }
        else if (bp <= 50)  { l7 = 'yellow'; }
        else if (bp <= 75)  { l7 = 'blue'; }
        else                { l7 = 'green'; }
        setLed(7, l7, bp !== null && bp !== undefined ? Math.round(bp) + ' %' : 'Ismeretlen');

        // Live chip értékek + színek
        var lv = document.getElementById('live-value-strip');
        if (lv) {
            var chips = lv.querySelectorAll('.live-chip__val');
            var vals = [
                (d.temperature !== null && d.temperature !== undefined) ? (+d.temperature).toFixed(1) + ' °C' : '—',
                (d.humidity    !== null && d.humidity    !== undefined) ? (+d.humidity).toFixed(1)    + ' %'  : '—',
                (d.battery_pct !== null && d.battery_pct !== undefined) ? Math.round(+d.battery_pct) + ' %'  : '—',
                d.online ? 'Igen' : 'Nem'
            ];
            for (var j = 0; j < chips.length && j < vals.length; j++) {
                chips[j].textContent = vals[j];
            }
            var chipEls = lv.querySelectorAll('.live-chip');
            if (chipEls[0]) chipEls[0].className = 'live-chip ' + (d.active_temp_alert_count > 0 ? 'chip-alarm' : 'chip-ok');
            if (chipEls[2]) {
                chipEls[2].className = 'live-chip ' + (bp === null || bp === undefined ? 'chip-ok' : bp <= 15 ? 'chip-alarm' : bp <= 25 ? 'chip-warn' : bp <= 50 ? 'chip-mid' : 'chip-ok');
            }
            if (chipEls[3]) chipEls[3].className = 'live-chip ' + (d.online ? 'chip-ok' : 'chip-alarm');
        }

        // JS lekérdezés időbélyeg + eszköz kor
        var now = new Date();
        var hm = now.getHours().toString().padStart(2,'0') + ':' +
                 now.getMinutes().toString().padStart(2,'0') + ':' +
                 now.getSeconds().toString().padStart(2,'0');
        if (countdownEl) countdownEl.textContent = hm;

        var ageEl = document.getElementById('sv-device-age');
        if (ageEl && d.last_seen_at) {
            var seenMs = new Date(d.last_seen_at.replace(' ','T') + 'Z').getTime();
            // A szerver UTC-ben tárolja, de PHP date() localtime → próbáljuk helyi időként is
            if (isNaN(seenMs)) seenMs = new Date(d.last_seen_at.replace(' ','T')).getTime();
            var ageSec = Math.round((Date.now() - seenMs) / 1000);
            if (ageSec < 0) ageSec = 0;
            ageEl.textContent = ageSec < 60 ? ageSec + 's' : Math.floor(ageSec/60) + 'm ' + (ageSec%60) + 's';
            ageEl.style.color = ageSec > 300 ? '#f87171' : ageSec > 120 ? '#fbbf24' : '#4ade80';
        }
    }

    // ── 5 másodperces polling ────────────────────────────────────────────────
    if (!deviceId) return;

    var fetchTimer = 5;
    function fetchState() {
        if (countdownEl) countdownEl.textContent = '…';
        fetch('api_device_state.php?device_id=' + encodeURIComponent(deviceId))
            .then(function (r) { return r.ok ? r.json() : Promise.reject('http ' + r.status); })
            .then(function (d) {
                if (d && !d.error) applyState(d);
                else if (countdownEl) countdownEl.textContent = 'nincs adat';
            })
            .catch(function (e) {
                if (countdownEl) countdownEl.textContent = 'hiba';
                console.warn('fetchState hiba:', e);
            });
    }

    fetchState();
    setInterval(fetchState, 5000);
})();
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

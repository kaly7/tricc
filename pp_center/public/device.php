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
$wifiIp = raw_pick($stateRaw, ['wifi_ip', 'details.wifi_ip', 'details.ip']);
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
$contactGroups = (array) ($configData['contact_groups'] ?? []);
$routes = (array) ($configData['routes'] ?? []);

$builtinEventLabels = [
    'device_boot' => 'Eszköz elindult',
    'device_offline' => 'MQTT / eszköz offline',
    'device_online' => 'MQTT / eszköz online',
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
            <label class="checkbox-row">
                <input type="checkbox" name="active" <?= !isset($device['active']) || (int) $device['active'] === 1 ? 'checked' : '' ?>>
                <span>Aktív eszköz</span>
            </label>
            <div class="form-actions full-row">
                <button type="submit" class="btn btn-primary">Mentés</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="section-head"><h2>Utolsó ismert állapot</h2></div>
        <div class="kv-grid">
            <div><span>Online</span><strong><?= $state ? ((int) $state['online'] === 1 ? 'Igen' : 'Nem') : '—' ?></strong></div>
            <div><span>Utolsó kapcsolat (szerver idő)</span><strong><?= h($state['last_seen_at'] ?? '—') ?></strong></div>
            <div><span>Kívánt konfiguráció</span><strong><?= h($currentConfig['config_version'] ?? '—') ?></strong></div>
            <div><span>Jelentett konfiguráció</span><strong><?= h($state['reported_config_version'] ?? '—') ?></strong></div>
            <div><span>Hőmérséklet</span><strong><?= h(isset($state['temperature']) ? $state['temperature'] . ' °C' : '—') ?></strong></div>
            <div><span>Páratartalom</span><strong><?= h(isset($state['humidity']) ? $state['humidity'] . ' %' : '—') ?></strong></div>
            <div><span>Akku</span><strong><?= h(isset($state['battery_pct']) ? $state['battery_pct'] . ' %' : '—') ?></strong></div>
            <div><span>Táp mód</span><strong><?= h($state['power_mode'] ?? '—') ?></strong></div>
            <div><span>Átviteli út</span><strong><?= h($telemetryTransport ?? '—') ?></strong></div>
            <div><span>Wi‑Fi OK</span><strong><?= h($wifiOk === null ? '—' : ((bool) $wifiOk ? 'Igen' : 'Nem')) ?></strong></div>
            <div><span>Wi‑Fi RSSI</span><strong><?= h($wifiRssi !== null ? (string) $wifiRssi . ' dBm' : '—') ?></strong></div>
            <div><span>Wi‑Fi IP</span><strong><?= h($wifiIp ?: '—') ?></strong></div>
            <div><span>GSM OK</span><strong><?= h($gsmOk === null ? '—' : ((bool) $gsmOk ? 'Igen' : 'Nem')) ?></strong></div>
            <div><span>GSM RSSI</span><strong><?= h($gsmRssi !== null ? (string) $gsmRssi . ' dBm' : '—') ?></strong></div>
            <div><span>GSM szolgáltató</span><strong><?= h($gsmOperator ?: '—') ?></strong></div>
        </div>
        <div class="mini-stats mt-3">
            <span class="badge-status status-warn">Queue: <?= h((string) $queueStats['queued']) ?></span>
            <span class="badge-status status-info">Sent: <?= h((string) $queueStats['sent']) ?></span>
            <span class="badge-status status-online">Acked: <?= h((string) $queueStats['acked']) ?></span>
            <?php if ($queueStats['failed'] > 0): ?><span class="badge-status status-offline">Failed: <?= h((string) $queueStats['failed']) ?></span><?php endif; ?>
        </div>
    </section>
</div>

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
                <div class="form-grid compact-grid">
                    <label><span>Temp min</span><input type="number" step="0.1" name="thresholds[temp_min]" value="<?= h($thresholds['temp_min'] ?? '') ?>"></label>
                    <label><span>Temp max</span><input type="number" step="0.1" name="thresholds[temp_max]" value="<?= h($thresholds['temp_max'] ?? '') ?>"></label>
                    <label><span>Páratartalom min</span><input type="number" step="0.1" name="thresholds[humidity_min]" value="<?= h($thresholds['humidity_min'] ?? '') ?>"></label>
                    <label><span>Páratartalom max</span><input type="number" step="0.1" name="thresholds[humidity_max]" value="<?= h($thresholds['humidity_max'] ?? '') ?>"></label>
                    <label><span>Air quality max</span><input type="number" step="1" name="thresholds[airq_max]" value="<?= h($thresholds['airq_max'] ?? '') ?>"></label>
                    <label><span>Akku low %</span><input type="number" step="1" name="thresholds[battery_low_pct]" value="<?= h($thresholds['battery_low_pct'] ?? '') ?>"></label>
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
<?php include __DIR__ . '/../templates/footer.php'; ?>

<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\ConfigService;
use App\Services\AuditService;

if (!is_post()) {
    redirect_to(app_url('devices.php'));
}

function csv_list_to_array(string $value): array
{
    $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
}

function build_payload_from_form(array $post): array
{
    $payload = [
        'sampling_sec' => (int) ($post['sampling_sec'] ?? 180),
        'heartbeat_sec' => (int) ($post['heartbeat_sec'] ?? 180),
        'thresholds' => [],
        'contacts' => [],
        'rules' => [],
        'contact_groups' => [],
        'routes' => [],
    ];

    foreach (['temp_min', 'temp_max', 'humidity_min', 'humidity_max', 'airq_max', 'battery_low_pct'] as $key) {
        $value = trim((string) ($post['thresholds'][$key] ?? ''));
        $payload['thresholds'][$key] = ($value === '') ? null : (float) $value;
    }

    foreach (['c1', 'c2', 'c3', 'c4'] as $contactKey) {
        $modeKey = $contactKey . '_mode';
        $mode = strtolower(trim((string) ($post['contacts'][$modeKey] ?? 'nc')));
        if (!in_array($mode, ['nc', 'no', 'unused'], true)) {
            $mode = 'nc';
        }
        $payload['contacts'][$modeKey] = $mode;

        $name = trim((string) ($post['contacts'][$contactKey . '_name'] ?? ''));
        $openLabel = trim((string) ($post['contacts'][$contactKey . '_open_label'] ?? ''));
        $closedLabel = trim((string) ($post['contacts'][$contactKey . '_closed_label'] ?? ''));
        if ($name !== '') {
            $payload['contacts'][$contactKey . '_name'] = $name;
        }
        if ($openLabel !== '') {
            $payload['contacts'][$contactKey . '_open_label'] = $openLabel;
        }
        if ($closedLabel !== '') {
            $payload['contacts'][$contactKey . '_closed_label'] = $closedLabel;
        }
    }

    foreach ((array) ($post['rules'] ?? []) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $ruleId = trim((string) ($rule['rule_id'] ?? ''));
        if ($ruleId === '') {
            continue;
        }
        $row = [
            'rule_id' => $ruleId,
            'type' => trim((string) ($rule['type'] ?? 'threshold')),
            'sensor' => trim((string) ($rule['sensor'] ?? 'temperature')),
            'actions' => csv_list_to_array((string) ($rule['actions'] ?? '')),
        ];
        foreach (['operator', 'value', 'for_sec', 'delta', 'window_sec'] as $key) {
            $value = trim((string) ($rule[$key] ?? ''));
            if ($value !== '') {
                $row[$key] = is_numeric($value) ? ($value + 0) : $value;
            }
        }
        $payload['rules'][] = $row;
    }

    foreach ((array) ($post['contact_groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }
        $name = trim((string) ($group['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $payload['contact_groups'][$name] = csv_list_to_array((string) ($group['phones'] ?? ''));
    }

    foreach ((array) ($post['routes'] ?? []) as $route) {
        if (!is_array($route)) {
            continue;
        }
        $event = trim((string) ($route['event'] ?? ''));
        if ($event === '') {
            continue;
        }
        $payload['routes'][$event] = csv_list_to_array((string) ($route['actions'] ?? ''));
    }

    return $payload;
}

$deviceId = trim((string) ($_POST['device_id'] ?? ''));
$action = $_POST['action'] ?? 'save_config';
$configService = new ConfigService();
$audit = new AuditService();
$actor = current_user_name() ?: 'web';

try {
    if ($action === 'queue_push') {
        $requestId = $configService->queuePush($deviceId, 'web:' . $actor);
        $audit->log('web', $actor, 'config_push_queued', $deviceId, ['request_id' => $requestId]);
        flash_set('success', 'A konfiguráció MQTT push sorba került.');
    } elseif ($action === 'save_and_push_form') {
        $payload = build_payload_from_form($_POST);
        $version = $configService->save($deviceId, $payload, 'web:' . $actor);
        $requestId = $configService->queuePush($deviceId, 'web:' . $actor);
        $audit->log('web', $actor, 'config_saved_and_pushed_form', $deviceId, ['config_version' => $version, 'request_id' => $requestId]);
        flash_set('success', 'A konfiguráció mentve és MQTT push sorba állítva. Verzió: ' . $version);
    } elseif ($action === 'save_config_form') {
        $payload = build_payload_from_form($_POST);
        $version = $configService->save($deviceId, $payload, 'web:' . $actor);
        $audit->log('web', $actor, 'config_saved_form', $deviceId, ['config_version' => $version]);
        flash_set('success', 'A strukturált konfiguráció mentése sikeres. Verzió: ' . $version);
    } elseif ($action === 'save_and_push_raw') {
        $json = trim((string) ($_POST['config_json'] ?? '{}'));
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $version = $configService->save($deviceId, $payload, 'web:' . $actor);
        $requestId = $configService->queuePush($deviceId, 'web:' . $actor);
        $audit->log('web', $actor, 'config_saved_and_pushed_raw', $deviceId, ['config_version' => $version, 'request_id' => $requestId]);
        flash_set('success', 'A raw JSON konfiguráció mentve és MQTT push sorba állítva. Verzió: ' . $version);
    } else {
        $json = trim((string) ($_POST['config_json'] ?? '{}'));
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $version = $configService->save($deviceId, $payload, 'web:' . $actor);
        $audit->log('web', $actor, 'config_saved_raw', $deviceId, ['config_version' => $version]);
        flash_set('success', 'A raw JSON konfiguráció mentése sikeres. Verzió: ' . $version);
    }
} catch (Throwable $e) {
    flash_set('error', 'Hiba: ' . $e->getMessage());
}

redirect_to(app_url('device.php?device_id=' . urlencode($deviceId)));

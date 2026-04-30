<?php

namespace App\Services;

final class PayloadNormalizer
{
    public static function normalizeTelemetry(string $deviceId, array $payload): array
    {
        $env = self::arr($payload['env'] ?? null);
        $battery = self::arr($payload['battery'] ?? null);
        $power = self::arr($payload['power'] ?? null);
        $signal = self::arr($payload['signal'] ?? ($payload['modem'] ?? null));
        $contacts = self::normalizeContacts($payload['contacts'] ?? null, $payload);
        $state = self::arr($payload['state'] ?? null);
        $meta = self::arr($payload['meta'] ?? null);

        $resolvedDeviceId = self::firstString([
            $payload['device_id'] ?? null,
            $meta['device_id'] ?? null,
            $meta['id'] ?? null,
            $deviceId,
        ], $deviceId);

        $powerMode = self::firstString([
            $payload['power_mode'] ?? null,
            $power['mode'] ?? null,
            $power['source'] ?? null,
        ], null);

        if ($powerMode === null) {
            $usbPresent = self::firstBool([
                $power['usb_present'] ?? null,
                $power['usb'] ?? null,
                $payload['usb_present'] ?? null,
            ], null);
            if ($usbPresent !== null) {
                $powerMode = $usbPresent ? 'usb' : 'battery';
            }
        }

        return [
            'device_id' => $resolvedDeviceId,
            'ts' => self::firstValue([
                $payload['ts'] ?? null,
                $payload['timestamp'] ?? null,
                $payload['measured_at'] ?? null,
                $meta['ts'] ?? null,
                $meta['timestamp'] ?? null,
            ]),
            'temperature' => self::firstFloat([
                $payload['temperature'] ?? null,
                $payload['temp_c'] ?? null,
                $env['temperature'] ?? null,
                $env['temp_c'] ?? null,
                $env['temp'] ?? null,
            ]),
            'humidity' => self::firstFloat([
                $payload['humidity'] ?? null,
                $env['humidity'] ?? null,
                $env['rh'] ?? null,
            ]),
            'air_quality' => self::firstFloat([
                $payload['air_quality'] ?? null,
                $payload['airq'] ?? null,
                $env['air_quality'] ?? null,
                $env['airq'] ?? null,
                $env['co2'] ?? null,
                $env['voc'] ?? null,
            ]),
            'pressure_hpa' => self::firstFloat([
                $payload['pressure_hpa'] ?? null,
                $payload['pressure'] ?? null,
                $env['pressure_hpa'] ?? null,
                $env['pressure'] ?? null,
            ]),
            'battery_pct' => self::firstFloat([
                $payload['battery_pct'] ?? null,
                $battery['pct'] ?? null,
                $battery['percent'] ?? null,
                $battery['level'] ?? null,
                $power['battery_pct'] ?? null,   // firmware: power.battery_pct
            ]),
            'battery_voltage' => self::firstFloat([
                $battery['voltage'] ?? null,
                $battery['vbat'] ?? null,
                $payload['battery_voltage'] ?? null,
                $power['battery_v'] ?? null,     // firmware: power.battery_v
            ]),
            'power_mode' => $powerMode,
            'rssi' => self::firstInt([
                $payload['wifi_rssi'] ?? null,
                $payload['rssi'] ?? null,
                $signal['wifi_rssi'] ?? null,
                $signal['rssi'] ?? null,
                $signal['csq'] ?? null,
            ]),
            'reported_config_version' => self::firstInt([
                $payload['config_version'] ?? null,
                $payload['reported_config_version'] ?? null,
                $state['config_version'] ?? null,
                $state['reported_config_version'] ?? null,
                $meta['config_version'] ?? null,
            ]),
            'contacts' => $contacts,
            'meta' => [
                'telemetry_transport' => self::firstString([
                    $payload['telemetry_transport'] ?? null,
                    $signal['transport'] ?? null,
                    $meta['telemetry_transport'] ?? null,
                ], null),
                'fw' => self::firstString([
                    $payload['fw'] ?? null,
                    $meta['fw'] ?? null,
                    $meta['firmware'] ?? null,
                    $state['fw'] ?? null,
                ], null),
                'uptime_sec' => self::firstInt([
                    $payload['uptime_sec'] ?? null,
                    $meta['uptime_sec'] ?? null,
                    $state['uptime_sec'] ?? null,
                ]),
            ],
            'raw' => $payload,
        ];
    }

    public static function normalizeReportedState(string $deviceId, array $payload): array
    {
        $state = self::arr($payload['state'] ?? null);
        $meta = self::arr($payload['meta'] ?? null);
        $power = self::arr($payload['power'] ?? null);
        $contacts = self::normalizeContacts($payload['contacts'] ?? null, $payload);

        $powerMode = self::firstString([
            $payload['power_mode'] ?? null,
            $power['mode'] ?? null,
            $state['power_mode'] ?? null,
        ], null);

        if ($powerMode === null) {
            $usbPresent = self::firstBool([
                $power['usb_present'] ?? null,
                $payload['usb_present'] ?? null,
                $state['usb_present'] ?? null,
            ], null);
            if ($usbPresent !== null) {
                $powerMode = $usbPresent ? 'usb' : 'battery';
            }
        }

        return [
            'device_id' => self::firstString([
                $payload['device_id'] ?? null,
                $meta['device_id'] ?? null,
                $deviceId,
            ], $deviceId),
            'ts' => self::firstValue([
                $payload['ts'] ?? null,
                $payload['timestamp'] ?? null,
                $payload['reported_at'] ?? null,
            ]),
            'config_version' => self::firstInt([
                $payload['config_version'] ?? null,
                $payload['reported_config_version'] ?? null,
                $state['config_version'] ?? null,
            ]),
            'power_mode' => $powerMode,
            'battery_pct' => self::firstFloat([
                $payload['battery_pct'] ?? null,
                $payload['battery']['pct'] ?? null,
                $state['battery_pct'] ?? null,
            ]),
            'temperature' => self::firstFloat([
                $payload['temperature'] ?? null,
                $state['temperature'] ?? null,
            ]),
            'humidity' => self::firstFloat([
                $payload['humidity'] ?? null,
                $state['humidity'] ?? null,
            ]),
            'air_quality' => self::firstFloat([
                $payload['air_quality'] ?? null,
                $state['air_quality'] ?? null,
            ]),
            'pressure_hpa' => self::firstFloat([
                $payload['pressure_hpa'] ?? null,
                $payload['pressure'] ?? null,
                $state['pressure_hpa'] ?? null,
            ]),
            'rssi' => self::firstInt([
                $payload['wifi_rssi'] ?? null,
                $payload['rssi'] ?? null,
                $state['wifi_rssi'] ?? null,
                $state['rssi'] ?? null,
            ]),
            'contacts' => $contacts,
            'fw' => self::firstString([
                $payload['fw'] ?? null,
                $state['fw'] ?? null,
                $meta['fw'] ?? null,
            ], null),
            'applied' => self::firstBool([
                $payload['applied'] ?? null,
                $state['applied'] ?? null,
            ], null),
            'raw' => $payload,
        ];
    }

    public static function normalizeAlert(string $deviceId, array $payload): array
    {
        $event = self::arr($payload['event'] ?? null);
        $rule = self::arr($payload['rule'] ?? null);
        $meta = self::arr($payload['meta'] ?? null);

        $actions = $payload['actions_taken'] ?? $payload['actions'] ?? $payload['actions_performed'] ?? [];
        if (!is_array($actions)) {
            $actions = [$actions];
        }
        $actions = array_values(array_map('strval', array_filter($actions, static fn ($v) => trim((string) $v) !== '')));

        return [
            'device_id' => self::firstString([
                $payload['device_id'] ?? null,
                $meta['device_id'] ?? null,
                $deviceId,
            ], $deviceId),
            'ts' => self::firstValue([
                $payload['ts'] ?? null,
                $payload['timestamp'] ?? null,
                $event['ts'] ?? null,
                $event['timestamp'] ?? null,
            ]),
            'event_type' => self::firstString([
                $payload['event_type'] ?? null,
                $payload['type'] ?? null,
                $payload['code'] ?? null,
                $event['type'] ?? null,
                $rule['rule_id'] ?? null,
            ], 'unknown'),
            'severity' => self::normalizeSeverity(self::firstString([
                $payload['severity'] ?? null,
                $event['severity'] ?? null,
                $payload['level'] ?? null,
            ], 'info')),
            'message' => self::firstString([
                $payload['message'] ?? null,
                $payload['text'] ?? null,
                $payload['title'] ?? null,
                $event['message'] ?? null,
                $event['text'] ?? null,
            ], ''),
            'actions_taken' => $actions,
            'rule_id' => self::firstString([
                $payload['rule_id'] ?? null,
                $rule['rule_id'] ?? null,
            ], null),
            'value' => self::firstFloat([
                $payload['value'] ?? null,
                $event['value'] ?? null,
                $payload['current_value'] ?? null,
            ]),
            'threshold' => self::firstFloat([
                $payload['threshold'] ?? null,
                $rule['value'] ?? null,
                $event['threshold'] ?? null,
            ]),
            'raw' => $payload,
        ];
    }

    public static function normalizeCommandReply(string $deviceId, array $payload): array
    {
        $meta = self::arr($payload['meta'] ?? null);
        return [
            'device_id' => self::firstString([
                $payload['device_id'] ?? null,
                $meta['device_id'] ?? null,
                $deviceId,
            ], $deviceId),
            'request_id' => self::firstString([
                $payload['request_id'] ?? null,
                $payload['req_id'] ?? null,
            ], ''),
            'ok' => self::firstBool([
                $payload['ok'] ?? null,
                $payload['success'] ?? null,
            ], true),
            'message' => self::firstString([
                $payload['message'] ?? null,
                $payload['result'] ?? null,
                $payload['status'] ?? null,
            ], ''),
            'ts' => self::firstValue([
                $payload['ts'] ?? null,
                $payload['timestamp'] ?? null,
            ]),
            'raw' => $payload,
        ];
    }

    public static function normalizePresence(string $deviceId, array $payload): array
    {
        $status = strtolower(self::firstString([
            $payload['status'] ?? null,
            $payload['state'] ?? null,
        ], 'offline'));
        if (!in_array($status, ['online', 'offline'], true)) {
            $status = 'offline';
        }
        return [
            'device_id' => self::firstString([
                $payload['device_id'] ?? null,
                $deviceId,
            ], $deviceId),
            'status' => $status,
            'ts' => self::firstValue([
                $payload['ts'] ?? null,
                $payload['timestamp'] ?? null,
            ]),
            'raw' => $payload,
        ];
    }

    private static function normalizeContacts(mixed $contacts, array $fallbackPayload = []): array
    {
        $map = ['c1' => null, 'c2' => null, 'c3' => null, 'c4' => null];
        $source = [];
        if (is_array($contacts)) {
            $source = $contacts;
        } elseif (is_array($fallbackPayload['contact_states'] ?? null)) {
            $source = $fallbackPayload['contact_states'];
        }

        foreach ($map as $key => $_) {
            $idx = substr($key, 1);
            $value = $source[$key]
                ?? $source['contact_' . $idx]
                ?? $source[(string) $idx]
                ?? $fallbackPayload[$key]
                ?? $fallbackPayload['contact_' . $idx]
                ?? null;
            $map[$key] = self::normalizeContactValue($value);
        }

        return $map;
    }

    private static function normalizeContactValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'open' : 'closed';
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 'open' : 'closed';
        }
        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'high', 'open', 'opened', 'nyitott', 'on' => 'open',
            '0', 'false', 'low', 'closed', 'zart', 'zárt', 'off' => 'closed',
            default => $normalized,
        };
    }

    private static function normalizeSeverity(string $value): string
    {
        $normalized = strtolower(trim($value));
        return match ($normalized) {
            'warn' => 'warning',
            'crit', 'fatal' => 'critical',
            'ok' => 'info',
            default => $normalized !== '' ? $normalized : 'info',
        };
    }

    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function firstValue(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function firstString(array $values, ?string $default = null): ?string
    {
        $value = self::firstValue($values);
        if ($value === null) {
            return $default;
        }
        $string = trim((string) $value);
        return $string === '' ? $default : $string;
    }

    private static function firstFloat(array $values): ?float
    {
        $value = self::firstValue($values);
        return is_numeric($value) ? (float) $value : null;
    }

    private static function firstInt(array $values): ?int
    {
        $value = self::firstValue($values);
        return is_numeric($value) ? (int) $value : null;
    }

    private static function firstBool(array $values, ?bool $default = null): ?bool
    {
        foreach ($values as $value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return ((int) $value) !== 0;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'on', 'usb', 'online'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'off', 'battery', 'offline'], true)) {
                    return false;
                }
            }
        }
        return $default;
    }
}

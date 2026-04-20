#!/usr/bin/env bash
set -euo pipefail

HOST="${MQTT_HOST:-127.0.0.1}"
PORT="${MQTT_PORT:-1883}"
USER="${MQTT_USER:-pp_center}"
PASS="${MQTT_PASS:-abrakadabra}"
DEVICE="${1:-esp001}"

pub() {
  local topic="$1"
  local payload="$2"
  mosquitto_pub -h "$HOST" -p "$PORT" -u "$USER" -P "$PASS" -t "$topic" -m "$payload"
}

pub "pp/${DEVICE}/telemetry" '{"device_id":"'"${DEVICE}"'","ts":"2026-04-04T20:01:00Z","env":{"temperature":24.8,"humidity":52.1,"air_quality":410},"battery":{"pct":88,"voltage":4.08},"power":{"mode":"usb","usb_present":true},"contacts":{"c1":"closed","c2":"open","c3":"closed","c4":"closed"},"signal":{"rssi":-70},"meta":{"fw":"1.0.7","uptime_sec":81234,"config_version":12}}'
pub "pp/${DEVICE}/alert" '{"device_id":"'"${DEVICE}"'","ts":"2026-04-04T20:02:00Z","event_type":"temp_trend_warn","severity":"warning","message":"Gyors homerseklet-emelkedes","rule_id":"temp_trend","value":27.4,"threshold":28.5,"actions_taken":["mattermost"]}'
pub "pp/${DEVICE}/state/reported" '{"device_id":"'"${DEVICE}"'","ts":"2026-04-04T20:03:00Z","config_version":12,"applied":true,"fw":"1.0.7","power_mode":"battery","battery_pct":87,"contacts":{"c1":"closed","c2":"closed"}}'
pub "pp/${DEVICE}/cmd/out" '{"device_id":"'"${DEVICE}"'","request_id":"demo-ack-001","ok":true,"message":"status_ok","ts":"2026-04-04T20:04:00Z"}'
pub "pp/${DEVICE}/lwt" '{"status":"online"}'

echo "ESP payload minták elküldve: ${DEVICE}"

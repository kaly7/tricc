USE pp_center;

INSERT INTO devices (device_id, name, location, sim_phone, fw_version, active, created_at, updated_at)
VALUES
('esp001', 'Kazánház szenzor', 'Kazánház', '+36301234567', '1.0.0', 1, NOW(), NOW()),
('esp002', 'Raktár szenzor', 'Hátsó raktár', '+36307654321', '1.0.0', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO device_config (device_id, config_version, sampling_sec, heartbeat_sec, temp_min, temp_max, humidity_min, humidity_max, airq_max, battery_low_pct, contact_1_mode, contact_2_mode, contact_3_mode, contact_4_mode, config_json, updated_by, updated_at)
VALUES
('esp001', 1, 180, 180, 5, 28.5, 20, 70, 900, 20, 'nc', 'nc', 'nc', 'nc', '{"device_id":"esp001","config_version":1,"sampling_sec":180,"heartbeat_sec":180,"thresholds":{"temp_min":5,"temp_max":28.5,"humidity_min":20,"humidity_max":70,"airq_max":900,"battery_low_pct":20},"contacts":{"c1_mode":"nc","c2_mode":"nc","c3_mode":"nc","c4_mode":"nc"},"rules":[{"rule_id":"temp_warn","type":"threshold","sensor":"temperature","operator":">=","value":28.5,"for_sec":60,"actions":["mattermost"]},{"rule_id":"battery_low","type":"threshold","sensor":"battery_pct","operator":"<=","value":20,"for_sec":120,"actions":["mattermost","sms:group_1"]}],"contact_groups":{"group_1":["+36301234567"],"group_2":["+36301111111"]},"routes":{"temp_warn":["mattermost"],"battery_low":["mattermost","sms:group_1"]}}', 'seed', NOW()),
('esp002', 1, 180, 180, 5, 26.0, 20, 75, 950, 20, 'nc', 'no', 'nc', 'nc', '{"device_id":"esp002","config_version":1,"sampling_sec":180,"heartbeat_sec":180,"thresholds":{"temp_min":5,"temp_max":26.0,"humidity_min":20,"humidity_max":75,"airq_max":950,"battery_low_pct":20},"contacts":{"c1_mode":"nc","c2_mode":"no","c3_mode":"nc","c4_mode":"nc"},"rules":[{"rule_id":"door_warn","type":"contact_open","sensor":"c2","for_sec":30,"actions":["mattermost"]}],"contact_groups":{"group_1":["+36307654321"]},"routes":{"door_warn":["mattermost"]}}', 'seed', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO device_last_state (device_id, last_seen_at, online, power_mode, battery_pct, temperature, humidity, air_quality, contact_1, contact_2, contact_3, contact_4, rssi, reported_config_version, raw_json, updated_at)
VALUES
('esp001', NOW(), 1, 'usb', 91, 23.8, 48.5, 420, 'closed', 'closed', 'closed', 'closed', -68, 1, '{}', NOW()),
('esp002', NOW(), 0, 'battery', 52, 18.2, 55.0, 390, 'closed', 'open', 'closed', 'closed', -85, 1, '{}', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO alerts (device_id, ts, event_type, severity, message, actions_taken_json, raw_json)
VALUES
('esp002', NOW(), 'door_open_warn', 'warning', 'A raktár ajtó nyitott állapotban maradt.', '["mattermost"]', '{}'),
('esp001', NOW(), 'temp_trend_warn', 'info', 'Gyors melegedési trend észlelve.', '["mattermost"]', '{}');

INSERT INTO device_presence_log (device_id, status, payload_json, happened_at)
VALUES
('esp001', 'online', '{"status":"online"}', NOW()),
('esp002', 'offline', '{"status":"offline"}', NOW())
ON DUPLICATE KEY UPDATE happened_at = VALUES(happened_at);

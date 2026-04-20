CREATE DATABASE IF NOT EXISTS pp_center CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pp_center;

CREATE TABLE IF NOT EXISTS devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    sim_phone VARCHAR(32) DEFAULT NULL,
    fw_version VARCHAR(50) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_devices_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_config (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    config_version INT UNSIGNED NOT NULL,
    sampling_sec INT UNSIGNED NOT NULL DEFAULT 180,
    heartbeat_sec INT UNSIGNED NOT NULL DEFAULT 180,
    temp_min DECIMAL(8,2) DEFAULT NULL,
    temp_max DECIMAL(8,2) DEFAULT NULL,
    humidity_min DECIMAL(8,2) DEFAULT NULL,
    humidity_max DECIMAL(8,2) DEFAULT NULL,
    airq_max DECIMAL(10,2) DEFAULT NULL,
    battery_low_pct DECIMAL(8,2) DEFAULT NULL,
    contact_1_mode VARCHAR(16) DEFAULT 'nc',
    contact_2_mode VARCHAR(16) DEFAULT 'nc',
    contact_3_mode VARCHAR(16) DEFAULT 'nc',
    contact_4_mode VARCHAR(16) DEFAULT 'nc',
    config_json LONGTEXT NOT NULL,
    updated_by VARCHAR(120) NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_device_config_device (device_id),
    INDEX idx_device_config_version (device_id, config_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_config_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    config_version INT UNSIGNED NOT NULL,
    config_json LONGTEXT NOT NULL,
    changed_by VARCHAR(120) NOT NULL,
    changed_at DATETIME NOT NULL,
    INDEX idx_device_config_history_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_last_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL UNIQUE,
    last_seen_at DATETIME DEFAULT NULL,
    online TINYINT(1) NOT NULL DEFAULT 0,
    power_mode VARCHAR(16) DEFAULT NULL,
    battery_pct DECIMAL(8,2) DEFAULT NULL,
    temperature DECIMAL(8,2) DEFAULT NULL,
    humidity DECIMAL(8,2) DEFAULT NULL,
    air_quality DECIMAL(10,2) DEFAULT NULL,
    contact_1 VARCHAR(16) DEFAULT NULL,
    contact_2 VARCHAR(16) DEFAULT NULL,
    contact_3 VARCHAR(16) DEFAULT NULL,
    contact_4 VARCHAR(16) DEFAULT NULL,
    rssi INT DEFAULT NULL,
    reported_config_version INT UNSIGNED DEFAULT NULL,
    raw_json LONGTEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_device_last_state_online (online),
    INDEX idx_device_last_state_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telemetry_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    ts DATETIME NOT NULL,
    temperature DECIMAL(8,2) DEFAULT NULL,
    humidity DECIMAL(8,2) DEFAULT NULL,
    air_quality DECIMAL(10,2) DEFAULT NULL,
    battery_pct DECIMAL(8,2) DEFAULT NULL,
    power_mode VARCHAR(16) DEFAULT NULL,
    contact_1 VARCHAR(16) DEFAULT NULL,
    contact_2 VARCHAR(16) DEFAULT NULL,
    contact_3 VARCHAR(16) DEFAULT NULL,
    contact_4 VARCHAR(16) DEFAULT NULL,
    rssi INT DEFAULT NULL,
    raw_json LONGTEXT DEFAULT NULL,
    INDEX idx_telemetry_device_ts (device_id, ts),
    INDEX idx_telemetry_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    ts DATETIME NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    actions_taken_json TEXT DEFAULT NULL,
    raw_json LONGTEXT DEFAULT NULL,
    INDEX idx_alerts_device_ts (device_id, ts),
    INDEX idx_alerts_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS command_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    request_id VARCHAR(64) NOT NULL UNIQUE,
    command_type VARCHAR(50) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    created_by VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    acked_at DATETIME DEFAULT NULL,
    INDEX idx_command_queue_status (status),
    INDEX idx_command_queue_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS command_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    result_ok TINYINT(1) NOT NULL DEFAULT 0,
    result_message TEXT DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    received_at DATETIME NOT NULL,
    INDEX idx_command_results_device (device_id),
    INDEX idx_command_results_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(32) NOT NULL,
    actor VARCHAR(120) NOT NULL,
    action VARCHAR(120) NOT NULL,
    device_id VARCHAR(64) DEFAULT NULL,
    details_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_log_device (device_id),
    INDEX idx_audit_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS device_presence_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    happened_at DATETIME NOT NULL,
    INDEX idx_device_presence_device (device_id, happened_at),
    INDEX idx_device_presence_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mattermost_command_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    command_text TEXT NOT NULL,
    command_name VARCHAR(64) NOT NULL,
    actor VARCHAR(120) NOT NULL,
    device_id VARCHAR(64) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ok',
    response_text TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_mm_command_created (created_at),
    INDEX idx_mm_command_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS worker_status (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_name VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    heartbeat_at DATETIME DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    details_json LONGTEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_worker_status_heartbeat (heartbeat_at),
    INDEX idx_worker_status_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


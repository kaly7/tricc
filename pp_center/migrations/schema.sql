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
    device_type ENUM('master','slave') NOT NULL DEFAULT 'master',
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


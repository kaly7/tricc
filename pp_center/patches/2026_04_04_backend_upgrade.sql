USE pp_center;

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


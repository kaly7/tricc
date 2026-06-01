-- agvmgr – AGV MQTT koordináta figyelő
-- Futtatás: mysql -u root -p < setup.sql

CREATE DATABASE IF NOT EXISTS agvmgr DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agvmgr;

CREATE TABLE IF NOT EXISTS users (
    id       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created  DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alapértelmezett admin: admin / admin1234
INSERT INTO users (username, password, is_admin)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1)
ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS mqtt_broker (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(100) NOT NULL DEFAULT '',
    port       INT NOT NULL DEFAULT 1883,
    username   VARCHAR(100) NOT NULL DEFAULT '',
    password   VARCHAR(255) NOT NULL DEFAULT '',
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    updated    DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mqtt_broker (ip, port) VALUES ('', 1883)
ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS agv (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    manufacturer VARCHAR(100) NOT NULL DEFAULT '',
    type         VARCHAR(100) NOT NULL DEFAULT '',
    serial_no    VARCHAR(50)  NOT NULL DEFAULT '',
    name         VARCHAR(100) NOT NULL DEFAULT '',
    topic        VARCHAR(255) NOT NULL DEFAULT '',
    enabled      TINYINT(1) NOT NULL DEFAULT 1,
    created      DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agv_coords (
    id                   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agv_id               INT NOT NULL,
    -- VDA5050 agvPosition
    x                    DECIMAL(12,4)  NULL COMMENT 'méter',
    y                    DECIMAL(12,4)  NULL COMMENT 'méter',
    theta                DECIMAL(10,6)  NULL COMMENT 'radián, -π..+π',
    map_id               VARCHAR(100)   NOT NULL DEFAULT '',
    position_initialized TINYINT(1)     NULL,
    localization_score   DECIMAL(5,4)   NULL COMMENT '0.0–1.0',
    deviation_range      DECIMAL(10,4)  NULL COMMENT 'méter',
    -- VDA5050 velocity
    vx                   DECIMAL(10,4)  NULL COMMENT 'm/s',
    vy                   DECIMAL(10,4)  NULL COMMENT 'm/s',
    omega                DECIMAL(10,6)  NULL COMMENT 'rad/s',
    -- batteryState
    battery_charge       DECIMAL(5,2)   NULL COMMENT '%',
    battery_voltage      DECIMAL(7,3)   NULL COMMENT 'V',
    -- státusz
    operating_mode       VARCHAR(20)    NOT NULL DEFAULT '',
    driving              TINYINT(1)     NULL,
    paused               TINYINT(1)     NULL,
    -- meta
    source               VARCHAR(15)    NOT NULL DEFAULT 'state',
    raw_payload          MEDIUMTEXT     NULL,
    updated_at           DATETIME(3)    NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
    UNIQUE KEY uk_agv (agv_id),
    FOREIGN KEY (agv_id) REFERENCES agv(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Omron MQTT broker (továbbítás célállomása)
CREATE TABLE IF NOT EXISTS omron_broker (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(100) NOT NULL DEFAULT '',
    port       INT NOT NULL DEFAULT 1883,
    username   VARCHAR(100) NOT NULL DEFAULT '',
    password   VARCHAR(255) NOT NULL DEFAULT '',
    enabled    TINYINT(1) NOT NULL DEFAULT 0,
    updated    DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO omron_broker (ip, port, enabled) VALUES ('', 1883, 0)
ON DUPLICATE KEY UPDATE id=id;

-- AGV-szintű Omron forwarding konfig
CREATE TABLE IF NOT EXISTS omron_forward (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agv_id         INT NOT NULL,
    topic_template VARCHAR(255) NOT NULL DEFAULT 'agv/{serial_no}/position',
    fields         JSON         NULL COMMENT 'Kiválasztott mezők tömbje',
    enabled        TINYINT(1)  NOT NULL DEFAULT 0,
    updated        DATETIME    NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    UNIQUE KEY uk_agv (agv_id),
    FOREIGN KEY (agv_id) REFERENCES agv(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

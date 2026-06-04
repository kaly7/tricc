-- Tricc – belső chat alkalmazás
-- Futtatás: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS tricc DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tricc;

CREATE TABLE IF NOT EXISTS users (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(150) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    avatar_url   VARCHAR(500) NOT NULL DEFAULT '',
    invite_code  VARCHAR(32)  NOT NULL DEFAULT '',
    is_admin     TINYINT(1)   NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invite_codes (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(32)  NOT NULL UNIQUE,
    created_by   INT          NULL,
    used_by      INT          NULL,
    used_at      DATETIME     NULL,
    expires_at   DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_tokens (
    user_id      INT          NOT NULL PRIMARY KEY,
    token        VARCHAR(200) NOT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rooms (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL DEFAULT '',
    type         ENUM('direct','group') NOT NULL DEFAULT 'group',
    created_by   INT          NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT NOW(),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_members (
    room_id      INT          NOT NULL,
    user_id      INT          NOT NULL,
    role         ENUM('admin','member') NOT NULL DEFAULT 'member',
    joined_at    DATETIME     NOT NULL DEFAULT NOW(),
    last_read_at DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (room_id, user_id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id           BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id      INT    NOT NULL,
    sender_id    INT    NOT NULL,
    type         ENUM('text','image','file','link') NOT NULL DEFAULT 'text',
    content      TEXT   NOT NULL DEFAULT '',
    file_url     VARCHAR(500) NOT NULL DEFAULT '',
    created_at   DATETIME(3)  NOT NULL DEFAULT NOW(3),
    INDEX idx_room_time (room_id, created_at),
    FOREIGN KEY (room_id)   REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alapértelmezett admin
INSERT INTO invite_codes (code, created_by, expires_at)
VALUES ('TRICC-ADMIN-0001', NULL, DATE_ADD(NOW(), INTERVAL 30 DAY))
ON DUPLICATE KEY UPDATE id=id;

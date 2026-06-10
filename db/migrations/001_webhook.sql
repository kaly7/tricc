-- Migration 001: webhook támogatás
-- Futtatás: mysql -u tricc_user -p tricc < db/migrations/001_webhook.sql

USE tricc;

CREATE TABLE IF NOT EXISTS webhook_keys (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    api_key     VARCHAR(64)  NOT NULL UNIQUE,
    room_id     INT          NOT NULL,
    label       VARCHAR(100) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT NOW(),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhook bot user: az ő nevében jelennek meg a webhook üzenetek az appban
INSERT INTO users (name, email, password, avatar_url, invite_code, is_admin, is_active)
VALUES ('Értesítő', 'bot@tricc.internal', '', '', '', 0, 1)
ON DUPLICATE KEY UPDATE id=id;

-- A bot user ID-ját jegyezd fel és írd bele a WebhookController::BOT_USER_ID konstansba
SELECT id, name FROM users WHERE email = 'bot@tricc.internal';

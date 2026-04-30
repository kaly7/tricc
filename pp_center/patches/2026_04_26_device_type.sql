-- Eszköz típus: master (saját WiFi/MQTT) vagy slave (ESP-NOW relay)
ALTER TABLE devices
    ADD COLUMN device_type ENUM('master','slave') NOT NULL DEFAULT 'master' AFTER active;

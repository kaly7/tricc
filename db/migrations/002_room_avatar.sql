-- Migration 002: szoba avatar támogatás
-- Futtatás: mysql -u tricc_user -p tricc < db/migrations/002_room_avatar.sql

USE tricc;

ALTER TABLE rooms ADD COLUMN avatar_url VARCHAR(500) NOT NULL DEFAULT '';

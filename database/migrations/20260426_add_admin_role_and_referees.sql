-- Migration: add staff role support and seed referee accounts
-- Date: 2026-04-26

USE bible_master;

ALTER TABLE admins
    ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password_hash;

UPDATE admins
SET role = 'admin'
WHERE role IS NULL OR role = '';

UPDATE admins
SET role = 'arbitre'
WHERE username IN ('arbitre1', 'arbitre2');

INSERT IGNORE INTO admins (username, password_hash, role) VALUES
('arbitre1', '$2y$12$DpkDYxk5TldDUs21/bQVY.sYdWvSG/H4/mOaFyq0TH7vM9/78s98i', 'arbitre'),
('arbitre2', '$2y$12$DpkDYxk5TldDUs21/bQVY.sYdWvSG/H4/mOaFyq0TH7vM9/78s98i', 'arbitre');

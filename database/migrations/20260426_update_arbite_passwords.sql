-- Migration: Update referee passwords to arbitre123
-- Date: 2026-04-26

USE bible_master;

UPDATE admins 
SET password_hash = '$2y$12$DpkDYxk5TldDUs21/bQVY.sYdWvSG/H4/mOaFyq0TH7vM9/78s98i' 
WHERE username IN ('arbitre1', 'arbitre2');

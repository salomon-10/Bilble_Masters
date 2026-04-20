-- Migration: add audit log table for match score/status/publication changes
-- Date: 2026-04-17

USE bible_master;

CREATE TABLE IF NOT EXISTS match_change_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    admin_username VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL DEFAULT 'update_match_state',
    old_status ENUM('Programme', 'En cours', 'Termine') NULL,
    new_status ENUM('Programme', 'En cours', 'Termine') NULL,
    old_score_team1 INT UNSIGNED NULL,
    new_score_team1 INT UNSIGNED NULL,
    old_score_team2 INT UNSIGNED NULL,
    new_score_team2 INT UNSIGNED NULL,
    old_published TINYINT(1) NULL,
    new_published TINYINT(1) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_match_change_logs_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_change_logs_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_match_change_logs_match_id (match_id),
    INDEX idx_match_change_logs_created_at (created_at)
);

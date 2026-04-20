-- Migration: add per-trial score table for match pilot page
-- Date: 2026-04-17

USE bible_master;

CREATE TABLE IF NOT EXISTS match_trials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    trial_order TINYINT UNSIGNED NOT NULL,
    trial_name VARCHAR(80) NOT NULL,
    team1_points INT NOT NULL DEFAULT 0,
    team2_points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_match_trials_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_match_trial_order (match_id, trial_order),
    INDEX idx_match_trials_match_id (match_id)
);

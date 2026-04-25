-- Bible Master: clean, deterministic rebuild for production/local.
-- Safe to import repeatedly because tables are dropped in dependency order.

CREATE DATABASE IF NOT EXISTS bible_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bible_master;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS match_change_logs;
DROP TABLE IF EXISTS match_trials;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS pool_teams;
DROP TABLE IF EXISTS pools;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS tournaments;

CREATE TABLE tournaments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tournament_name (name)
) ENGINE=InnoDB;

CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    logo_path VARCHAR(255) NULL,
    logo_mime VARCHAR(100) NULL,
    logo_blob LONGBLOB NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teams_tournament_name (tournament_id, name),
    INDEX idx_teams_tournament_id (tournament_id),
    CONSTRAINT fk_teams_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pools_tournament_name (tournament_id, name),
    CONSTRAINT fk_pools_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pool_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pool_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pool_team (pool_id, team_id),
    UNIQUE KEY uq_pool_teams_team_id (team_id),
    CONSTRAINT fk_pool_teams_pool
        FOREIGN KEY (pool_id) REFERENCES pools(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pool_teams_team
        FOREIGN KEY (team_id) REFERENCES teams(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    team1_id INT UNSIGNED NOT NULL,
    team2_id INT UNSIGNED NOT NULL,
    match_date DATE NOT NULL,
    match_time TIME NOT NULL DEFAULT '00:00:00',
    status ENUM('Programme', 'En cours', 'Termine') NOT NULL DEFAULT 'Programme',
    phase ENUM('Poule', 'Quart', 'Demi', 'PetiteFinale', 'Finale') NOT NULL DEFAULT 'Poule',
    score_team1 INT UNSIGNED NULL,
    score_team2 INT UNSIGNED NULL,
    published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_different_teams CHECK (team1_id <> team2_id),
    CONSTRAINT fk_matches_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_team1
        FOREIGN KEY (team1_id) REFERENCES teams(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_match_team2
        FOREIGN KEY (team2_id) REFERENCES teams(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_match_status (status),
    INDEX idx_match_datetime (match_date, match_time),
    INDEX idx_matches_tournament_id (tournament_id),
    INDEX idx_matches_phase (phase)
) ENGINE=InnoDB;

CREATE TABLE match_trials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    trial_order TINYINT UNSIGNED NOT NULL,
    trial_name VARCHAR(80) NOT NULL,
    team1_points INT NOT NULL DEFAULT 0,
    team2_points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match_trial_order (match_id, trial_order),
    INDEX idx_match_trials_match_id (match_id),
    CONSTRAINT fk_match_trials_match
        FOREIGN KEY (match_id) REFERENCES matches(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE match_change_logs (
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
    INDEX idx_match_change_logs_match_id (match_id),
    INDEX idx_match_change_logs_created_at (created_at),
    CONSTRAINT fk_match_change_logs_match
        FOREIGN KEY (match_id) REFERENCES matches(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_change_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT INTO tournaments (name, is_active)
VALUES ('Tournoi principal', 1);

SET FOREIGN_KEY_CHECKS = 1;

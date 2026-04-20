CREATE DATABASE IF NOT EXISTS bible_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bible_master;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team1_id INT UNSIGNED NOT NULL,
    team2_id INT UNSIGNED NOT NULL,
    match_date DATE NOT NULL,
    match_time TIME NOT NULL,
    status ENUM('Programme', 'En cours', 'Termine') NOT NULL DEFAULT 'Programme',
    score_team1 INT UNSIGNED NULL,
    score_team2 INT UNSIGNED NULL,
    published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_different_teams CHECK (team1_id <> team2_id),
    CONSTRAINT fk_match_team1 FOREIGN KEY (team1_id) REFERENCES teams(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_match_team2 FOREIGN KEY (team2_id) REFERENCES teams(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_match_status (status),
    INDEX idx_match_datetime (match_date, match_time)
);

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

INSERT IGNORE INTO teams (name) VALUES
('Petites fourmis de Dieu '),
('Guerriers de Dieu'),
('Missionnaires de Dieu'),
('Victorious '),
('Flamme de Vie'),
('Sky Warriors'),
('Barouck'),
('God Avengers'),

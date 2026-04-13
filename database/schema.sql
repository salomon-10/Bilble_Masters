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

INSERT IGNORE INTO teams (name) VALUES
('Flammes de Jerusalem'),
('Harpe de David'),
('Etoiles de Bethleem'),
('Guerriers de Capharnaum'),
('Lumiere de Galilee');

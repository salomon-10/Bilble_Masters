-- Add multi-tournament support, pools, team logos, and match phases.

CREATE TABLE IF NOT EXISTS tournaments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tournament_name (name)
);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS tournament_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER name;

ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS tournament_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS phase ENUM('Poule', 'Quart', 'Demi', 'Finale') NOT NULL DEFAULT 'Poule' AFTER status;

CREATE TABLE IF NOT EXISTS pools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pools_tournament_name (tournament_id, name),
    CONSTRAINT fk_pools_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS pool_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pool_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pool_team (pool_id, team_id),
    CONSTRAINT fk_pool_teams_pool FOREIGN KEY (pool_id)
        REFERENCES pools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pool_teams_team FOREIGN KEY (team_id)
        REFERENCES teams(id) ON DELETE CASCADE ON UPDATE CASCADE
);

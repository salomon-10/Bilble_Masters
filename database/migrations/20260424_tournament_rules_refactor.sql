-- Tournament business rules refactor (24/04/2026)
-- 1) One team can belong to one single pool.
-- 2) Introduce PetiteFinale phase.
-- 3) Match time is now optional in UI and defaults to 00:00:00.

ALTER TABLE matches
    MODIFY COLUMN phase ENUM('Poule', 'Quart', 'Demi', 'PetiteFinale', 'Finale') NOT NULL DEFAULT 'Poule';

ALTER TABLE matches
    MODIFY COLUMN match_time TIME NOT NULL DEFAULT '00:00:00';

ALTER TABLE pool_teams
    ADD UNIQUE KEY uq_pool_teams_team_id (team_id);

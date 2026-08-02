-- Freeze a trial template on each match so historical matches keep their original events.
ALTER TABLE matches
    ADD COLUMN trial_template VARCHAR(40) NOT NULL DEFAULT 'legacy' AFTER phase;

-- Existing matches deliberately remain on the legacy template.
git
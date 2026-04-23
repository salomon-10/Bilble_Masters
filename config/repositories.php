<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function appBasePath(): string
{
    $fromEnv = trim((string) getenv('APP_BASE_PATH'));
    if ($fromEnv !== '') {
        $normalized = '/' . trim($fromEnv, '/');
        return $normalized === '/' ? '' : $normalized;
    }

    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $isLocalHost = $hostName === ''
        || str_contains($hostName, 'localhost')
        || str_contains($hostName, '127.0.0.1');

    return $isLocalHost ? '/Bible_Master' : '';
}

function buildAssetPath(string $relative): string
{
    $base = appBasePath();
    $clean = ltrim($relative, '/');

    if ($clean === '') {
        return $base !== '' ? $base : '/';
    }

    return ($base !== '' ? $base : '') . '/' . $clean;
}

function buildAbsoluteAssetPath(string $relative): string
{
    $assetPath = buildAssetPath($relative);
    if (preg_match('#^https?://#i', $assetPath)) {
        return $assetPath;
    }

    $scheme = 'http';
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if (($https !== '' && $https !== 'off') || $forwardedProto === 'https') {
        $scheme = 'https';
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        return $assetPath;
    }

    // InfinityFree 42web domains support HTTPS; prefer it to avoid mixed-content blocks.
    if (str_contains(strtolower($host), '.42web.io')) {
        $scheme = 'https';
    }

    return $scheme . '://' . $host . (str_starts_with($assetPath, '/') ? $assetPath : '/' . $assetPath);
}

function normalizeLogoPath(?string $logoPath): string
{
    $fallback = defaultTeamLogoPath();
    $raw = trim((string) ($logoPath ?? ''));
    $sharedLogoBaseUrl = rtrim((string) (getenv('TEAM_LOGO_BASE_URL') ?: getenv('LOGO_BASE_URL') ?: 'https://biblemasteradmin.42web.io/'), '/');

    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $raw)) {
        if (preg_match('#^http://([^/]*\.42web\.io)(/.*)?$#i', $raw, $matches)) {
            $suffix = $matches[2] ?? '';
            return 'https://' . $matches[1] . $suffix;
        }

        return $raw;
    }

    $base = appBasePath();
    $baseWithSlash = $base === '' ? '' : $base . '/';

    if ($base !== '' && ($raw === $base || str_starts_with($raw, $baseWithSlash))) {
        return $raw;
    }

    if (preg_match('#(?:^|/)(img/(?:teams/[^?]+|team1\.png))$#i', $raw, $matches)) {
        if ($sharedLogoBaseUrl !== '') {
            return $sharedLogoBaseUrl . '/' . ltrim($matches[1], '/');
        }

        return buildAssetPath($matches[1]);
    }

    if (preg_match('#(?:^|/)(img/teams/[^?]+)$#i', $raw, $matches)) {
        if ($sharedLogoBaseUrl !== '') {
            return $sharedLogoBaseUrl . '/' . ltrim($matches[1], '/');
        }

        return buildAssetPath($matches[1]);
    }

    $legacyPath = ltrim($raw, '/');
    if (str_starts_with($legacyPath, 'Bible_Master/')) {
        $legacyPath = substr($legacyPath, strlen('Bible_Master/'));
    }

    if (str_starts_with($legacyPath, 'img/')) {
        if ($sharedLogoBaseUrl !== '') {
            return $sharedLogoBaseUrl . '/' . ltrim($legacyPath, '/');
        }

        return buildAssetPath($legacyPath);
    }

    if (str_starts_with($raw, '/img/')) {
        return buildAssetPath($raw);
    }

    if (str_starts_with($raw, 'img/')) {
        return buildAssetPath($raw);
    }

    return $fallback;
}

function teamLogoEndpointPath(int $teamId): string
{
    $adminDomain = 'biblemasteradmin.42web.io';
    $scheme = 'https';
    
    return $scheme . '://' . $adminDomain . '/team_logo.php?id=' . $teamId;
}

function resolveTeamLogoPath(int $teamId, ?string $logoPath, bool $hasBlob): string
{
    if ($hasBlob && $teamId > 0) {
        return teamLogoEndpointPath($teamId);
    }

    return normalizeLogoPath($logoPath);
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => $tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function constraintExists(PDO $pdo, string $tableName, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND constraint_name = :constraint_name
           AND constraint_type = "FOREIGN KEY"'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':constraint_name' => $constraintName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function countRows(PDO $pdo, string $tableName): int
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($safeTable === '') {
        return 0;
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $safeTable);

    return (int) $stmt->fetchColumn();
}

function ensureDefaultTournamentForLegacyData(PDO $pdo): ?int
{
    $stmt = $pdo->query('SELECT id FROM tournaments ORDER BY id ASC LIMIT 1');
    $first = $stmt->fetch();
    if ($first) {
        return (int) $first['id'];
    }

    $legacyTeams = tableExists($pdo, 'teams') ? countRows($pdo, 'teams') : 0;
    $legacyMatches = tableExists($pdo, 'matches') ? countRows($pdo, 'matches') : 0;

    if ($legacyTeams === 0 && $legacyMatches === 0) {
        return null;
    }

    $insert = $pdo->prepare('INSERT INTO tournaments (name, is_active) VALUES (:name, 1)');
    $insert->execute([':name' => 'Tournoi principal']);

    return (int) $pdo->lastInsertId();
}

function ensureTournamentSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tournaments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tournament_name (name)
        )'
    );

    if (!columnExists($pdo, 'teams', 'tournament_id')) {
        $pdo->exec('ALTER TABLE teams ADD COLUMN tournament_id INT UNSIGNED NULL AFTER id');
    }

    if (!columnExists($pdo, 'teams', 'logo_path')) {
        $pdo->exec('ALTER TABLE teams ADD COLUMN logo_path VARCHAR(255) NULL AFTER name');
    }

    if (!columnExists($pdo, 'teams', 'logo_mime')) {
        $pdo->exec('ALTER TABLE teams ADD COLUMN logo_mime VARCHAR(100) NULL AFTER logo_path');
    }

    if (!columnExists($pdo, 'teams', 'logo_blob')) {
        $pdo->exec('ALTER TABLE teams ADD COLUMN logo_blob LONGBLOB NULL AFTER logo_mime');
    }

    if (!columnExists($pdo, 'matches', 'tournament_id')) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN tournament_id INT UNSIGNED NULL AFTER id');
    }

    if (!columnExists($pdo, 'matches', 'phase')) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN phase ENUM('Poule', 'Quart', 'Demi', 'Finale') NOT NULL DEFAULT 'Poule' AFTER status");
    }

    $legacyTournamentId = ensureDefaultTournamentForLegacyData($pdo);
    if ($legacyTournamentId !== null) {
        $fillTeams = $pdo->prepare('UPDATE teams SET tournament_id = :tournament_id WHERE tournament_id IS NULL');
        $fillTeams->execute([':tournament_id' => $legacyTournamentId]);

        $fillMatches = $pdo->prepare('UPDATE matches SET tournament_id = :tournament_id WHERE tournament_id IS NULL');
        $fillMatches->execute([':tournament_id' => $legacyTournamentId]);
    }

    if (!indexExists($pdo, 'teams', 'idx_teams_tournament_id')) {
        $pdo->exec('CREATE INDEX idx_teams_tournament_id ON teams (tournament_id)');
    }

    if (!indexExists($pdo, 'matches', 'idx_matches_tournament_id')) {
        $pdo->exec('CREATE INDEX idx_matches_tournament_id ON matches (tournament_id)');
    }

    if (!indexExists($pdo, 'matches', 'idx_matches_phase')) {
        $pdo->exec('CREATE INDEX idx_matches_phase ON matches (phase)');
    }

    if (indexExists($pdo, 'teams', 'name')) {
        $pdo->exec('ALTER TABLE teams DROP INDEX name');
    }

    if (!indexExists($pdo, 'teams', 'uq_teams_tournament_name')) {
        $pdo->exec('ALTER TABLE teams ADD UNIQUE KEY uq_teams_tournament_name (tournament_id, name)');
    }

    if (!constraintExists($pdo, 'teams', 'fk_teams_tournament')) {
        $pdo->exec(
            'ALTER TABLE teams
             ADD CONSTRAINT fk_teams_tournament FOREIGN KEY (tournament_id)
             REFERENCES tournaments(id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    if (!constraintExists($pdo, 'matches', 'fk_matches_tournament')) {
        $pdo->exec(
            'ALTER TABLE matches
             ADD CONSTRAINT fk_matches_tournament FOREIGN KEY (tournament_id)
             REFERENCES tournaments(id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    if (!tableExists($pdo, 'pools')) {
        $pdo->exec(
            'CREATE TABLE pools (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tournament_id INT UNSIGNED NOT NULL,
                name VARCHAR(40) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pools_tournament_name (tournament_id, name),
                CONSTRAINT fk_pools_tournament FOREIGN KEY (tournament_id)
                    REFERENCES tournaments(id) ON DELETE CASCADE ON UPDATE CASCADE
            )'
        );
    }

    if (!tableExists($pdo, 'pool_teams')) {
        $pdo->exec(
            'CREATE TABLE pool_teams (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pool_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pool_team (pool_id, team_id),
                CONSTRAINT fk_pool_teams_pool FOREIGN KEY (pool_id)
                    REFERENCES pools(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_pool_teams_team FOREIGN KEY (team_id)
                    REFERENCES teams(id) ON DELETE CASCADE ON UPDATE CASCADE
            )'
        );
    }

    if (!tableExists($pdo, 'match_change_logs')) {
        $pdo->exec(
            "CREATE TABLE match_change_logs (
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
                CONSTRAINT fk_match_change_logs_match FOREIGN KEY (match_id)
                    REFERENCES matches(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_match_change_logs_admin FOREIGN KEY (admin_id)
                    REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE,
                INDEX idx_match_change_logs_match_id (match_id),
                INDEX idx_match_change_logs_created_at (created_at)
            )"
        );
    }

    ensureMatchTrialsTable($pdo);
}

function resolveTournamentId(PDO $pdo, ?int $requestedTournamentId = null): ?int
{
    ensureTournamentSchema($pdo);

    if ($requestedTournamentId !== null && $requestedTournamentId > 0) {
        $check = $pdo->prepare('SELECT id FROM tournaments WHERE id = :id LIMIT 1');
        $check->execute([':id' => $requestedTournamentId]);
        $row = $check->fetch();
        if ($row) {
            return (int) $row['id'];
        }
    }

    $stmt = $pdo->query('SELECT id FROM tournaments ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();

    return $row ? (int) $row['id'] : null;
}

function fetchTournamentById(PDO $pdo, int $tournamentId): ?array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare('SELECT id, name, is_active, created_at FROM tournaments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tournamentId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function fetchTournaments(PDO $pdo): array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->query(
        'SELECT t.id, t.name, t.is_active, t.created_at,
                (SELECT COUNT(*) FROM teams team WHERE team.tournament_id = t.id) AS teams_count,
                (SELECT COUNT(*) FROM pools p WHERE p.tournament_id = t.id) AS pools_count,
                (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.id) AS matches_count
         FROM tournaments t
         ORDER BY t.id DESC'
    );

    return $stmt->fetchAll();
}

function createTournament(PDO $pdo, string $name): ?int
{
    ensureTournamentSchema($pdo);

    $safeName = trim($name);
    if ($safeName === '') {
        return null;
    }

    $stmt = $pdo->prepare('INSERT INTO tournaments (name, is_active) VALUES (:name, 1)');
    $stmt->execute([':name' => $safeName]);

    return (int) $pdo->lastInsertId();
}

function defaultTeamLogoPath(): string
{
    return buildAssetPath('img/team1.png');
}

function fetchTeams(PDO $pdo, ?int $tournamentId = null): array
{
    ensureTournamentSchema($pdo);

    $sql = 'SELECT id, name, logo_path, tournament_id,
                   (logo_blob IS NOT NULL AND OCTET_LENGTH(logo_blob) > 0) AS has_logo_blob
            FROM teams';

    $params = [];

    if ($tournamentId !== null) {
        $sql .= ' WHERE tournament_id = :tournament_id';
        $params[':tournament_id'] = $tournamentId;
    }

    $sql .= ' ORDER BY name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $teamId = (int) ($row['id'] ?? 0);
        $hasLogoBlob = (int) ($row['has_logo_blob'] ?? 0) === 1;
        $resolvedLogo = resolveTeamLogoPath($teamId, (string) ($row['logo_path'] ?? ''), $hasLogoBlob);
        $row['logo_path'] = $resolvedLogo;
        $row['logo_url'] = $resolvedLogo;
    }
    unset($row);

    return $rows;
}

function createTeam(
    PDO $pdo,
    int $tournamentId,
    string $name,
    ?string $logoPath = null,
    ?string $logoBlob = null,
    ?string $logoMime = null
): ?int
{
    ensureTournamentSchema($pdo);

    $safeName = trim($name);
    if ($tournamentId <= 0 || $safeName === '') {
        return null;
    }

    $stmt = $pdo->prepare(
           'INSERT INTO teams (tournament_id, name, logo_path, logo_mime, logo_blob)
            VALUES (:tournament_id, :name, :logo_path, :logo_mime, :logo_blob)'
    );
    $stmt->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
    $stmt->bindValue(':name', $safeName, PDO::PARAM_STR);
    $stmt->bindValue(':logo_path', $logoPath, $logoPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':logo_mime', $logoMime, $logoMime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':logo_blob', $logoBlob, $logoBlob === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

function teamBelongsToTournament(PDO $pdo, int $teamId, int $tournamentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teams
         WHERE id = :team_id AND tournament_id = :tournament_id
         LIMIT 1'
    );
    $stmt->execute([
        ':team_id' => $teamId,
        ':tournament_id' => $tournamentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function poolBelongsToTournament(PDO $pdo, int $poolId, int $tournamentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM pools
         WHERE id = :pool_id AND tournament_id = :tournament_id
         LIMIT 1'
    );
    $stmt->execute([
        ':pool_id' => $poolId,
        ':tournament_id' => $tournamentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function fetchPools(PDO $pdo, int $tournamentId): array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare('SELECT id, tournament_id, name, created_at FROM pools WHERE tournament_id = :tournament_id ORDER BY name ASC');
    $stmt->execute([':tournament_id' => $tournamentId]);

    return $stmt->fetchAll();
}

function createPool(PDO $pdo, int $tournamentId, string $name): ?int
{
    ensureTournamentSchema($pdo);

    $safeName = strtoupper(trim($name));
    if ($tournamentId <= 0 || $safeName === '') {
        return null;
    }

    $stmt = $pdo->prepare('INSERT INTO pools (tournament_id, name) VALUES (:tournament_id, :name)');
    $stmt->execute([
        ':tournament_id' => $tournamentId,
        ':name' => $safeName,
    ]);

    return (int) $pdo->lastInsertId();
}

function attachTeamToPool(PDO $pdo, int $poolId, int $teamId): bool
{
    ensureTournamentSchema($pdo);

    if ($poolId <= 0 || $teamId <= 0) {
        return false;
    }

    $check = $pdo->prepare(
        'SELECT p.tournament_id AS pool_tournament_id, t.tournament_id AS team_tournament_id
         FROM pools p
         INNER JOIN teams t ON t.id = :team_id
         WHERE p.id = :pool_id
         LIMIT 1'
    );
    $check->execute([
        ':pool_id' => $poolId,
        ':team_id' => $teamId,
    ]);
    $row = $check->fetch();
    if (!$row || (int) ($row['pool_tournament_id'] ?? 0) !== (int) ($row['team_tournament_id'] ?? -1)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO pool_teams (pool_id, team_id)
         VALUES (:pool_id, :team_id)'
    );

    return $stmt->execute([
        ':pool_id' => $poolId,
        ':team_id' => $teamId,
    ]);
}

function fetchTeamsGroupedByPool(PDO $pdo, int $tournamentId): array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT p.id AS pool_id,
                p.name AS pool_name,
                t.id AS team_id,
                t.name AS team_name,
                t.logo_path,
                (t.logo_blob IS NOT NULL AND OCTET_LENGTH(t.logo_blob) > 0) AS has_logo_blob
         FROM pools p
         LEFT JOIN pool_teams pt ON pt.pool_id = p.id
         LEFT JOIN teams t ON t.id = pt.team_id
         WHERE p.tournament_id = :tournament_id
         ORDER BY p.name ASC, t.name ASC'
    );
    $stmt->execute([':tournament_id' => $tournamentId]);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $poolName = (string) ($row['pool_name'] ?? 'Sans poule');
        if (!isset($grouped[$poolName])) {
            $grouped[$poolName] = [];
        }

        if (!isset($row['team_id']) || $row['team_id'] === null) {
            continue;
        }

        $grouped[$poolName][] = [
            'id' => (int) $row['team_id'],
            'name' => (string) $row['team_name'],
            'logo_path' => resolveTeamLogoPath(
                (int) ($row['team_id'] ?? 0),
                (string) ($row['logo_path'] ?? ''),
                (int) ($row['has_logo_blob'] ?? 0) === 1
            ),
        ];
    }

    return $grouped;
}

function fetchMatchById(PDO $pdo, int $matchId): ?array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT m.id, m.tournament_id, m.team1_id, m.team2_id, m.match_date, m.match_time, m.status, m.phase,
                m.score_team1, m.score_team2, m.published,
                t1.name AS team1_name, t2.name AS team2_name,
                t1.logo_path AS team1_logo,
                t2.logo_path AS team2_logo,
                (t1.logo_blob IS NOT NULL AND OCTET_LENGTH(t1.logo_blob) > 0) AS team1_has_logo_blob,
                (t2.logo_blob IS NOT NULL AND OCTET_LENGTH(t2.logo_blob) > 0) AS team2_has_logo_blob
         FROM matches m
         INNER JOIN teams t1 ON t1.id = m.team1_id
         INNER JOIN teams t2 ON t2.id = m.team2_id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $matchId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $row['team1_logo'] = resolveTeamLogoPath(
        (int) ($row['team1_id'] ?? 0),
        (string) ($row['team1_logo'] ?? ''),
        (int) ($row['team1_has_logo_blob'] ?? 0) === 1
    );
    $row['team2_logo'] = resolveTeamLogoPath(
        (int) ($row['team2_id'] ?? 0),
        (string) ($row['team2_logo'] ?? ''),
        (int) ($row['team2_has_logo_blob'] ?? 0) === 1
    );

    return $row;
}

function fetchMatches(PDO $pdo, ?string $status = null, bool $onlyPublished = false, ?int $tournamentId = null, ?string $phase = null): array
{
    ensureTournamentSchema($pdo);

    $sql = 'SELECT m.id, m.tournament_id, m.team1_id, m.team2_id, m.match_date, m.match_time, m.status, m.phase,
                   m.score_team1, m.score_team2, m.published,
                   t1.name AS team1_name, t2.name AS team2_name,
                   t1.logo_path AS team1_logo,
                   t2.logo_path AS team2_logo,
                   (t1.logo_blob IS NOT NULL AND OCTET_LENGTH(t1.logo_blob) > 0) AS team1_has_logo_blob,
                   (t2.logo_blob IS NOT NULL AND OCTET_LENGTH(t2.logo_blob) > 0) AS team2_has_logo_blob
            FROM matches m
            INNER JOIN teams t1 ON t1.id = m.team1_id
            INNER JOIN teams t2 ON t2.id = m.team2_id';

    $conditions = [];
    $params = [];

    if ($status !== null) {
        $conditions[] = 'm.status = :status';
        $params[':status'] = $status;
    }

    if ($phase !== null) {
        $conditions[] = 'm.phase = :phase';
        $params[':phase'] = $phase;
    }

    if ($onlyPublished) {
        $conditions[] = 'm.published = 1';
    }

    if ($tournamentId !== null) {
        $conditions[] = 'm.tournament_id = :tournament_id';
        $params[':tournament_id'] = $tournamentId;
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY m.match_date DESC, m.match_time DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['team1_logo'] = resolveTeamLogoPath(
            (int) ($row['team1_id'] ?? 0),
            (string) ($row['team1_logo'] ?? ''),
            (int) ($row['team1_has_logo_blob'] ?? 0) === 1
        );
        $row['team2_logo'] = resolveTeamLogoPath(
            (int) ($row['team2_id'] ?? 0),
            (string) ($row['team2_logo'] ?? ''),
            (int) ($row['team2_has_logo_blob'] ?? 0) === 1
        );
    }
    unset($row);

    return $rows;
}

function countMatchesByStatus(PDO $pdo, ?int $tournamentId = null): array
{
    ensureTournamentSchema($pdo);

    $sql = 'SELECT status, COUNT(*) AS total FROM matches';
    $params = [];

    if ($tournamentId !== null) {
        $sql .= ' WHERE tournament_id = :tournament_id';
        $params[':tournament_id'] = $tournamentId;
    }

    $sql .= ' GROUP BY status';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [
        'Programme' => 0,
        'En cours' => 0,
        'Termine' => 0,
    ];

    foreach ($stmt->fetchAll() as $row) {
        $status = (string) ($row['status'] ?? '');
        if (array_key_exists($status, $result)) {
            $result[$status] = (int) $row['total'];
        }
    }

    return $result;
}

function createMatch(
    PDO $pdo,
    int $tournamentId,
    int $team1Id,
    int $team2Id,
    string $matchDate,
    string $matchTime,
    string $status,
    string $phase = 'Poule'
): ?int {
    ensureTournamentSchema($pdo);

    $allowedStatuses = ['Programme', 'En cours', 'Termine'];
    $allowedPhases = ['Poule', 'Quart', 'Demi', 'Finale'];

    if ($team1Id <= 0 || $team2Id <= 0 || $team1Id === $team2Id || $tournamentId <= 0) {
        return null;
    }

    if (!in_array($status, $allowedStatuses, true) || !in_array($phase, $allowedPhases, true)) {
        return null;
    }

    if (!teamBelongsToTournament($pdo, $team1Id, $tournamentId) || !teamBelongsToTournament($pdo, $team2Id, $tournamentId)) {
        return null;
    }

    $scoreTeam1 = $status === 'Programme' ? null : 0;
    $scoreTeam2 = $status === 'Programme' ? null : 0;

    $stmt = $pdo->prepare(
        'INSERT INTO matches (tournament_id, team1_id, team2_id, match_date, match_time, status, phase, score_team1, score_team2, published)
         VALUES (:tournament_id, :team1_id, :team2_id, :match_date, :match_time, :status, :phase, :score_team1, :score_team2, 1)'
    );

    $stmt->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
    $stmt->bindValue(':team1_id', $team1Id, PDO::PARAM_INT);
    $stmt->bindValue(':team2_id', $team2Id, PDO::PARAM_INT);
    $stmt->bindValue(':match_date', $matchDate, PDO::PARAM_STR);
    $stmt->bindValue(':match_time', $matchTime, PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':phase', $phase, PDO::PARAM_STR);
    $stmt->bindValue(':score_team1', $scoreTeam1, $scoreTeam1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':score_team2', $scoreTeam2, $scoreTeam2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

function updateMatchState(
    PDO $pdo,
    int $matchId,
    string $status,
    ?int $scoreTeam1,
    ?int $scoreTeam2,
    bool $published,
    int $adminId,
    string $adminUsername
): bool {
    ensureTournamentSchema($pdo);

    $current = fetchMatchById($pdo, $matchId);
    if (!$current) {
        return false;
    }

    $oldStatus = (string) $current['status'];
    $oldScoreTeam1 = $current['score_team1'] !== null ? (int) $current['score_team1'] : null;
    $oldScoreTeam2 = $current['score_team2'] !== null ? (int) $current['score_team2'] : null;
    $oldPublished = (int) $current['published'];
    $newPublished = $published ? 1 : 0;

    $hasChanged = $oldStatus !== $status
        || $oldScoreTeam1 !== $scoreTeam1
        || $oldScoreTeam2 !== $scoreTeam2
        || $oldPublished !== $newPublished;

    if (!$hasChanged) {
        return true;
    }

    $pdo->beginTransaction();

    try {
        $update = $pdo->prepare(
            'UPDATE matches
             SET status = :status,
                 score_team1 = :score_team1,
                 score_team2 = :score_team2,
                 published = :published
             WHERE id = :id'
        );

        $update->bindValue(':status', $status, PDO::PARAM_STR);
        $update->bindValue(':score_team1', $scoreTeam1, $scoreTeam1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':score_team2', $scoreTeam2, $scoreTeam2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':published', $newPublished, PDO::PARAM_INT);
        $update->bindValue(':id', $matchId, PDO::PARAM_INT);
        $update->execute();

        $log = $pdo->prepare(
            'INSERT INTO match_change_logs
            (match_id, admin_id, admin_username, action, old_status, new_status, old_score_team1, new_score_team1, old_score_team2, new_score_team2, old_published, new_published)
            VALUES
            (:match_id, :admin_id, :admin_username, :action, :old_status, :new_status, :old_score_team1, :new_score_team1, :old_score_team2, :new_score_team2, :old_published, :new_published)'
        );

        $log->bindValue(':match_id', $matchId, PDO::PARAM_INT);
        $log->bindValue(':admin_id', $adminId, PDO::PARAM_INT);
        $log->bindValue(':admin_username', $adminUsername, PDO::PARAM_STR);
        $log->bindValue(':action', 'update_match_state', PDO::PARAM_STR);
        $log->bindValue(':old_status', $oldStatus, PDO::PARAM_STR);
        $log->bindValue(':new_status', $status, PDO::PARAM_STR);
        $log->bindValue(':old_score_team1', $oldScoreTeam1, $oldScoreTeam1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $log->bindValue(':new_score_team1', $scoreTeam1, $scoreTeam1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $log->bindValue(':old_score_team2', $oldScoreTeam2, $oldScoreTeam2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $log->bindValue(':new_score_team2', $scoreTeam2, $scoreTeam2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $log->bindValue(':old_published', $oldPublished, PDO::PARAM_INT);
        $log->bindValue(':new_published', $newPublished, PDO::PARAM_INT);
        $log->execute();

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return true;
}

function fetchMatchChangeLogs(PDO $pdo, int $limit = 50): array
{
    ensureTournamentSchema($pdo);

    $safeLimit = max(1, min($limit, 200));
    $stmt = $pdo->prepare(
        'SELECT l.id, l.match_id, l.admin_username, l.action, l.old_status, l.new_status,
                l.old_score_team1, l.new_score_team1, l.old_score_team2, l.new_score_team2,
                l.old_published, l.new_published, l.created_at,
                t1.name AS team1_name, t2.name AS team2_name
         FROM match_change_logs l
         INNER JOIN matches m ON m.id = l.match_id
         INNER JOIN teams t1 ON t1.id = m.team1_id
         INNER JOIN teams t2 ON t2.id = m.team2_id
         ORDER BY l.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function fetchMatchChangeLogsForMatch(PDO $pdo, int $matchId, int $limit = 80): array
{
    ensureTournamentSchema($pdo);

    $safeLimit = max(1, min($limit, 200));
    $stmt = $pdo->prepare(
        'SELECT id, admin_username, action, old_status, new_status,
                old_score_team1, new_score_team1, old_score_team2, new_score_team2,
                old_published, new_published, created_at
         FROM match_change_logs
         WHERE match_id = :match_id
         ORDER BY id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':match_id', $matchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function ensureMatchTrialsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS match_trials (
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
        )'
    );
}

function defaultTrials(): array
{
    return [
        ['order' => 1, 'name' => 'Tiree de l epee'],
        ['order' => 2, 'name' => 'Collectives'],
        ['order' => 3, 'name' => 'Identification'],
        ['order' => 4, 'name' => 'Cascades'],
        ['order' => 5, 'name' => 'Vrai ou Faux'],
    ];
}

function fetchOrInitMatchTrials(PDO $pdo, int $matchId): array
{
    ensureTournamentSchema($pdo);

    $existingStmt = $pdo->prepare(
        'SELECT trial_order, trial_name, team1_points, team2_points
         FROM match_trials
         WHERE match_id = :match_id
         ORDER BY trial_order ASC'
    );
    $existingStmt->execute([':match_id' => $matchId]);
    $rows = $existingStmt->fetchAll();

    if (count($rows) === 0) {
        $insert = $pdo->prepare(
            'INSERT INTO match_trials (match_id, trial_order, trial_name, team1_points, team2_points)
             VALUES (:match_id, :trial_order, :trial_name, 0, 0)'
        );

        foreach (defaultTrials() as $trial) {
            $insert->execute([
                ':match_id' => $matchId,
                ':trial_order' => (int) $trial['order'],
                ':trial_name' => (string) $trial['name'],
            ]);
        }

        $existingStmt->execute([':match_id' => $matchId]);
        $rows = $existingStmt->fetchAll();
    }

    return $rows;
}

function fetchMatchTrials(PDO $pdo, int $matchId): array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT trial_order, trial_name, team1_points, team2_points
         FROM match_trials
         WHERE match_id = :match_id
         ORDER BY trial_order ASC'
    );
    $stmt->execute([':match_id' => $matchId]);

    return $stmt->fetchAll();
}

function updateMatchTrial(PDO $pdo, int $matchId, int $trialOrder, int $team1Points, int $team2Points): bool
{
    ensureTournamentSchema($pdo);

    if ($trialOrder <= 0 || $team1Points < 0 || $team2Points < 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE match_trials
         SET team1_points = :team1_points,
             team2_points = :team2_points
         WHERE match_id = :match_id AND trial_order = :trial_order'
    );
    $stmt->bindValue(':team1_points', $team1Points, PDO::PARAM_INT);
    $stmt->bindValue(':team2_points', $team2Points, PDO::PARAM_INT);
    $stmt->bindValue(':match_id', $matchId, PDO::PARAM_INT);
    $stmt->bindValue(':trial_order', $trialOrder, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        return true;
    }

    $check = $pdo->prepare(
        'SELECT 1
         FROM match_trials
         WHERE match_id = :match_id AND trial_order = :trial_order
         LIMIT 1'
    );
    $check->bindValue(':match_id', $matchId, PDO::PARAM_INT);
    $check->bindValue(':trial_order', $trialOrder, PDO::PARAM_INT);
    $check->execute();

    return (bool) $check->fetchColumn();
}

function computeMatchTotalsFromTrials(PDO $pdo, int $matchId): array
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(team1_points), 0) AS total_team1,
                COALESCE(SUM(team2_points), 0) AS total_team2
         FROM match_trials
         WHERE match_id = :match_id'
    );
    $stmt->execute([':match_id' => $matchId]);
    $row = $stmt->fetch() ?: ['total_team1' => 0, 'total_team2' => 0];

    return [
        'team1' => (int) ($row['total_team1'] ?? 0),
        'team2' => (int) ($row['total_team2'] ?? 0),
    ];
}

function syncMatchTotalsFromTrials(PDO $pdo, int $matchId): bool
{
    $totals = computeMatchTotalsFromTrials($pdo, $matchId);
    $safeTeam1 = max(0, (int) ($totals['team1'] ?? 0));
    $safeTeam2 = max(0, (int) ($totals['team2'] ?? 0));

    $stmt = $pdo->prepare(
        'UPDATE matches
         SET score_team1 = :score_team1,
             score_team2 = :score_team2
         WHERE id = :id'
    );
    $stmt->bindValue(':score_team1', $safeTeam1, PDO::PARAM_INT);
    $stmt->bindValue(':score_team2', $safeTeam2, PDO::PARAM_INT);
    $stmt->bindValue(':id', $matchId, PDO::PARAM_INT);
    $stmt->execute();

    return true;
}

function fetchTournamentBracket(PDO $pdo, int $tournamentId): array
{
    ensureTournamentSchema($pdo);

    $phases = ['Poule', 'Quart', 'Demi', 'Finale'];
    $bracket = [];

    foreach ($phases as $phase) {
        $bracket[$phase] = fetchMatches($pdo, null, true, $tournamentId, $phase);
    }

    return $bracket;
}

function fetchTeamLogoPayload(PDO $pdo, int $teamId): ?array
{
    ensureTournamentSchema($pdo);

    if ($teamId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, logo_path, logo_mime, logo_blob
         FROM teams
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->bindValue(':id', $teamId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $blob = $row['logo_blob'] ?? null;
    $hasBlob = is_string($blob) && $blob !== '';
    $mime = trim((string) ($row['logo_mime'] ?? ''));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'has_blob' => $hasBlob,
        'mime' => $mime !== '' ? $mime : 'image/png',
        'blob' => $hasBlob ? $blob : null,
        'logo_path' => normalizeLogoPath((string) ($row['logo_path'] ?? '')),
    ];
}

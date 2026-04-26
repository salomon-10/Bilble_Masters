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

function isLocalHostEnvironment(): bool
{
    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

    return $hostName === ''
        || str_contains($hostName, 'localhost')
        || str_contains($hostName, '127.0.0.1');
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
    $configuredSharedLogoBaseUrl = trim((string) (getenv('TEAM_LOGO_BASE_URL') ?: getenv('LOGO_BASE_URL') ?: ''));
    $sharedLogoBaseUrl = $configuredSharedLogoBaseUrl !== ''
        ? rtrim($configuredSharedLogoBaseUrl, '/')
        : (isLocalHostEnvironment() ? '' : 'https://biblemasteradmin.42web.io');

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
    $configuredEndpointBase = trim((string) (getenv('TEAM_LOGO_ENDPOINT_BASE_URL') ?: getenv('LOGO_ENDPOINT_BASE_URL') ?: ''));
    if ($configuredEndpointBase !== '') {
        return rtrim($configuredEndpointBase, '/') . '/team_logo.php?id=' . $teamId;
    }

    // Default to current app host/path so user app can serve blobs from its own DB.
    return buildAssetPath('team_logo.php?id=' . $teamId);
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

function isValidIsoDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));

    return checkdate($month, $day, $year);
}

function enumColumnValues(PDO $pdo, string $tableName, string $columnName): array
{
    static $cache = [];
    $cacheKey = strtolower($tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        $columnType = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable) {
        $cache[$cacheKey] = [];

        return [];
    }

    if (!preg_match('/^enum\((.*)\)$/i', $columnType, $matches)) {
        $cache[$cacheKey] = [];

        return [];
    }

    $rawValues = (string) ($matches[1] ?? '');
    preg_match_all("/'((?:\\\\'|[^'])*)'/", $rawValues, $parts);
    $values = array_map(
        static fn(string $v): string => str_replace("\\'", "'", $v),
        $parts[1] ?? []
    );

    $cache[$cacheKey] = $values;

    return $values;
}

function matchesColumnSupportsValue(PDO $pdo, string $columnName, string $value): bool
{
    $values = enumColumnValues($pdo, 'matches', $columnName);
    if ($values === []) {
        // If schema metadata is unavailable, don't block valid flows here.
        return true;
    }

    return in_array($value, $values, true);
}

function supportedMatchPhases(PDO $pdo): array
{
    $enumValues = enumColumnValues($pdo, 'matches', 'phase');
    if ($enumValues !== []) {
        return array_values(array_unique($enumValues));
    }

    return ['Poule', 'Quart', 'Demi', 'PetiteFinale', 'Finale'];
}

function tournamentPoolCount(PDO $pdo, int $tournamentId): int
{
    if ($tournamentId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pools WHERE tournament_id = :tournament_id');
    $stmt->execute([':tournament_id' => $tournamentId]);

    return (int) $stmt->fetchColumn();
}

function exceptionMessages(Throwable $exception): string
{
    $messages = [];
    $cursor = $exception;

    while ($cursor instanceof Throwable) {
        $messages[] = (string) $cursor->getMessage();
        $cursor = $cursor->getPrevious();
    }

    return strtolower(trim(implode(' | ', $messages)));
}

function isDatabaseConnectionException(Throwable $exception): bool
{
    $messages = exceptionMessages($exception);

    return str_contains($messages, 'impossible de se connecter a la base')
        || str_contains($messages, 'sqlstate[hy000] [2002]')
        || str_contains($messages, 'sqlstate[hy000] [1045]')
        || str_contains($messages, 'connection refused')
        || str_contains($messages, 'access denied')
        || str_contains($messages, 'unknown database')
        || str_contains($messages, 'getaddrinfo')
        || str_contains($messages, 'name or service not known');
}

function isDatabaseSchemaException(Throwable $exception): bool
{
    $messages = exceptionMessages($exception);

    return str_contains($messages, 'sqlstate[42s22]')
        || str_contains($messages, 'sqlstate[42s02]')
        || str_contains($messages, 'sqlstate[42000]')
        || str_contains($messages, 'unknown column')
        || str_contains($messages, 'base table or view not found')
        || str_contains($messages, 'doesn\'t exist')
        || str_contains($messages, 'syntax error or access violation')
        || str_contains($messages, 'schema_mismatch');
}

function isDatabaseDataException(Throwable $exception): bool
{
    $messages = exceptionMessages($exception);

    return str_contains($messages, 'sqlstate[23000]')
        || str_contains($messages, 'sqlstate[22007]')
        || str_contains($messages, 'sqlstate[hy093]')
        || str_contains($messages, 'integrity constraint violation')
        || str_contains($messages, 'cannot add or update a child row')
        || str_contains($messages, 'foreign key constraint fails')
        || str_contains($messages, 'check constraint')
        || str_contains($messages, 'invalid parameter number')
        || str_contains($messages, 'data truncated')
        || str_contains($messages, 'incorrect date value')
        || str_contains($messages, 'incorrect datetime value');
}

function publicDatabaseErrorMessage(Throwable $exception, string $default = 'Erreur base de donnees.'): string
{
    if (isDatabaseConnectionException($exception)) {
        return 'Connexion impossible a la base de donnees. Verifiez les variables DB_HOST/DB_NAME/DB_USER/DB_PASS (ou MYSQLHOST/MYSQLDATABASE/MYSQLUSER/MYSQLPASSWORD).';
    }

    if (isDatabaseSchemaException($exception)) {
        return 'Schema SQL incomplet ou obsolete. Importez database/reinstall_clean.sql (rebuild complet) puis reessayez.';
    }

    if (isDatabaseDataException($exception)) {
        return 'Donnees invalides pour ce match (date, equipes, phase ou contraintes SQL).';
    }

    return $default;
}

function assertCoreTournamentSchemaReady(PDO $pdo): void
{
    $requiredTables = ['admins', 'tournaments', 'teams', 'matches', 'pools', 'pool_teams', 'match_change_logs', 'match_trials'];
    foreach ($requiredTables as $tableName) {
        if (!tableExists($pdo, $tableName)) {
            throw new RuntimeException('schema_mismatch: missing table ' . $tableName);
        }
    }

    $requiredColumns = [
        ['teams', 'tournament_id'],
        ['teams', 'logo_path'],
        ['teams', 'logo_mime'],
        ['teams', 'logo_blob'],
        ['matches', 'tournament_id'],
        ['matches', 'phase'],
        ['matches', 'published'],
    ];

    foreach ($requiredColumns as $entry) {
        [$tableName, $columnName] = $entry;
        if (!columnExists($pdo, $tableName, $columnName)) {
            throw new RuntimeException('schema_mismatch: missing column ' . $tableName . '.' . $columnName);
        }
    }
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
    static $schemaChecked = false;
    if ($schemaChecked) {
        return;
    }

    try {
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

        // Keep legacy values compatible while enabling the new small-final phase.
        $pdo->exec("ALTER TABLE matches MODIFY COLUMN phase ENUM('Poule', 'Quart', 'Demi', 'PetiteFinale', 'Finale') NOT NULL DEFAULT 'Poule'");

        // Match time is no longer entered in the UI, keep a safe default for legacy schema compatibility.
        $pdo->exec("ALTER TABLE matches MODIFY COLUMN match_time TIME NOT NULL DEFAULT '00:00:00'");

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

        if (!indexExists($pdo, 'pool_teams', 'uq_pool_teams_team_id')) {
            $pdo->exec('ALTER TABLE pool_teams ADD UNIQUE KEY uq_pool_teams_team_id (team_id)');
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
    } catch (Throwable $exception) {
        // User/read-only DB accounts may not have DDL privileges.
        // Do not fail page rendering if schema already exists.
        error_log('[Bible_Master] ensureTournamentSchema skipped: ' . $exception->getMessage());
    }

    assertCoreTournamentSchemaReady($pdo);

    $schemaChecked = true;
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

    try {
        $stmt = $pdo->prepare('INSERT INTO tournaments (name, is_active) VALUES (:name, 1)');
        $stmt->execute([':name' => $safeName]);
    } catch (Throwable) {
        return null;
    }

    return (int) $pdo->lastInsertId();
}

function deleteTournament(PDO $pdo, int $tournamentId): bool
{
    ensureTournamentSchema($pdo);

    if ($tournamentId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM tournaments WHERE id = :id');
    $stmt->execute([':id' => $tournamentId]);

    return $stmt->rowCount() > 0;
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

    try {
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
    } catch (Throwable) {
        return null;
    }

    return (int) $pdo->lastInsertId();
}

function deleteTeam(PDO $pdo, int $tournamentId, int $teamId): bool
{
    ensureTournamentSchema($pdo);

    if ($tournamentId <= 0 || $teamId <= 0 || !teamBelongsToTournament($pdo, $teamId, $tournamentId)) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $deleteMatches = $pdo->prepare(
            'DELETE FROM matches
             WHERE tournament_id = :tournament_id
               AND (team1_id = :team_id OR team2_id = :team_id)'
        );
        $deleteMatches->execute([
            ':tournament_id' => $tournamentId,
            ':team_id' => $teamId,
        ]);

        $deletePoolLinks = $pdo->prepare('DELETE FROM pool_teams WHERE team_id = :team_id');
        $deletePoolLinks->execute([':team_id' => $teamId]);

        $deleteTeamStmt = $pdo->prepare('DELETE FROM teams WHERE id = :team_id AND tournament_id = :tournament_id');
        $deleteTeamStmt->execute([
            ':team_id' => $teamId,
            ':tournament_id' => $tournamentId,
        ]);

        $pdo->commit();

        return $deleteTeamStmt->rowCount() > 0;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}

function fetchUnassignedTeams(PDO $pdo, int $tournamentId): array
{
    ensureTournamentSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.logo_path,
                (t.logo_blob IS NOT NULL AND OCTET_LENGTH(t.logo_blob) > 0) AS has_logo_blob
         FROM teams t
         LEFT JOIN pool_teams pt ON pt.team_id = t.id
         WHERE t.tournament_id = :tournament_id
           AND pt.team_id IS NULL
         ORDER BY t.name ASC'
    );
    $stmt->execute([':tournament_id' => $tournamentId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['logo_path'] = resolveTeamLogoPath(
            (int) ($row['id'] ?? 0),
            (string) ($row['logo_path'] ?? ''),
            (int) ($row['has_logo_blob'] ?? 0) === 1
        );
    }
    unset($row);

    return $rows;
}

function fetchPoolIdForTeam(PDO $pdo, int $tournamentId, int $teamId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT pt.pool_id
         FROM pool_teams pt
         INNER JOIN pools p ON p.id = pt.pool_id
         WHERE p.tournament_id = :tournament_id
           AND pt.team_id = :team_id
         LIMIT 1'
    );
    $stmt->execute([
        ':tournament_id' => $tournamentId,
        ':team_id' => $teamId,
    ]);
    $poolId = $stmt->fetchColumn();

    return $poolId === false ? null : (int) $poolId;
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

    try {
        $stmt = $pdo->prepare('INSERT INTO pools (tournament_id, name) VALUES (:tournament_id, :name)');
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':name' => $safeName,
        ]);
    } catch (Throwable) {
        return null;
    }

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

    $existing = $pdo->prepare('SELECT pool_id FROM pool_teams WHERE team_id = :team_id LIMIT 1');
    $existing->execute([':team_id' => $teamId]);
    $existingPool = $existing->fetchColumn();
    if ($existingPool !== false) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO pool_teams (pool_id, team_id)
         VALUES (:pool_id, :team_id)'
    );

    return $stmt->execute([
        ':pool_id' => $poolId,
        ':team_id' => $teamId,
    ]);
}

function areTeamsInSamePool(PDO $pdo, int $tournamentId, int $team1Id, int $team2Id): bool
{
    $pool1 = fetchPoolIdForTeam($pdo, $tournamentId, $team1Id);
    $pool2 = fetchPoolIdForTeam($pdo, $tournamentId, $team2Id);

    return $pool1 !== null && $pool2 !== null && $pool1 === $pool2;
}

function countPoolPhaseMatchesForPool(PDO $pdo, int $tournamentId, int $poolId, ?string $status = null): int
{
    $sql =
        'SELECT COUNT(*)
         FROM matches m
         INNER JOIN pool_teams a ON a.team_id = m.team1_id AND a.pool_id = :pool_id_a
         INNER JOIN pool_teams b ON b.team_id = m.team2_id AND b.pool_id = :pool_id_b
         WHERE m.tournament_id = :tournament_id
           AND m.phase = "Poule"';
    $params = [
        ':pool_id_a' => $poolId,
        ':pool_id_b' => $poolId,
        ':tournament_id' => $tournamentId,
    ];

    if ($status !== null) {
        $sql .= ' AND m.status = :status';
        $params[':status'] = $status;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function fetchPoolStandings(PDO $pdo, int $tournamentId): array
{
    ensureTournamentSchema($pdo);

    $poolStmt = $pdo->prepare('SELECT id, name FROM pools WHERE tournament_id = :tournament_id ORDER BY name ASC');
    $poolStmt->execute([':tournament_id' => $tournamentId]);
    $pools = $poolStmt->fetchAll();

    $standings = [];
    $poolNames = [];

    $teamStmt = $pdo->prepare(
        'SELECT t.id, t.name, pt.pool_id
         FROM pool_teams pt
         INNER JOIN pools p ON p.id = pt.pool_id
         INNER JOIN teams t ON t.id = pt.team_id
         WHERE p.tournament_id = :tournament_id
         ORDER BY p.name ASC, t.name ASC'
    );
    $teamStmt->execute([':tournament_id' => $tournamentId]);

    foreach ($pools as $pool) {
        $poolId = (int) ($pool['id'] ?? 0);
        $poolName = (string) ($pool['name'] ?? 'Sans poule');
        $poolNames[$poolId] = $poolName;
        $standings[$poolName] = [];
    }

    foreach ($teamStmt->fetchAll() as $row) {
        $poolId = (int) ($row['pool_id'] ?? 0);
        if (!isset($poolNames[$poolId])) {
            continue;
        }

        $poolName = $poolNames[$poolId];
        $teamId = (int) ($row['id'] ?? 0);
        $standings[$poolName][$teamId] = [
            'team_id' => $teamId,
            'team' => (string) ($row['name'] ?? ''),
            'played' => 0,
            'won' => 0,
            'drawn' => 0,
            'lost' => 0,
            'gf' => 0,
            'ga' => 0,
            'gd' => 0,
            'points' => 0,
        ];
    }

    $matchesStmt = $pdo->prepare(
        'SELECT team1_id, team2_id, score_team1, score_team2
         FROM matches
         WHERE tournament_id = :tournament_id
           AND phase = "Poule"
           AND status = "Termine"'
    );
    $matchesStmt->execute([':tournament_id' => $tournamentId]);

    foreach ($matchesStmt->fetchAll() as $match) {
        $team1Id = (int) ($match['team1_id'] ?? 0);
        $team2Id = (int) ($match['team2_id'] ?? 0);
        $poolId1 = fetchPoolIdForTeam($pdo, $tournamentId, $team1Id);
        $poolId2 = fetchPoolIdForTeam($pdo, $tournamentId, $team2Id);

        if ($poolId1 === null || $poolId2 === null || $poolId1 !== $poolId2 || !isset($poolNames[$poolId1])) {
            continue;
        }

        $poolName = $poolNames[$poolId1];
        if (!isset($standings[$poolName][$team1Id], $standings[$poolName][$team2Id])) {
            continue;
        }

        $score1 = (int) ($match['score_team1'] ?? 0);
        $score2 = (int) ($match['score_team2'] ?? 0);

        $standings[$poolName][$team1Id]['played']++;
        $standings[$poolName][$team2Id]['played']++;
        $standings[$poolName][$team1Id]['gf'] += $score1;
        $standings[$poolName][$team1Id]['ga'] += $score2;
        $standings[$poolName][$team2Id]['gf'] += $score2;
        $standings[$poolName][$team2Id]['ga'] += $score1;

        if ($score1 > $score2) {
            $standings[$poolName][$team1Id]['won']++;
            $standings[$poolName][$team1Id]['points'] += 3;
            $standings[$poolName][$team2Id]['lost']++;
        } elseif ($score2 > $score1) {
            $standings[$poolName][$team2Id]['won']++;
            $standings[$poolName][$team2Id]['points'] += 3;
            $standings[$poolName][$team1Id]['lost']++;
        } else {
            $standings[$poolName][$team1Id]['drawn']++;
            $standings[$poolName][$team2Id]['drawn']++;
            $standings[$poolName][$team1Id]['points']++;
            $standings[$poolName][$team2Id]['points']++;
        }

        $standings[$poolName][$team1Id]['gd'] = $standings[$poolName][$team1Id]['gf'] - $standings[$poolName][$team1Id]['ga'];
        $standings[$poolName][$team2Id]['gd'] = $standings[$poolName][$team2Id]['gf'] - $standings[$poolName][$team2Id]['ga'];
    }

    foreach ($standings as $poolName => $rows) {
        $rows = array_values($rows);
        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['points'] !== $b['points']) {
                    return $b['points'] <=> $a['points'];
                }
                if ($a['gd'] !== $b['gd']) {
                    return $b['gd'] <=> $a['gd'];
                }
                if ($a['gf'] !== $b['gf']) {
                    return $b['gf'] <=> $a['gf'];
                }

                return strcmp((string) $a['team'], (string) $b['team']);
            }
        );

        foreach ($rows as $idx => &$row) {
            $row['rank'] = $idx + 1;
        }
        unset($row);

        $standings[$poolName] = $rows;
    }

    return $standings;
}

function fetchTournamentQualification(PDO $pdo, int $tournamentId): array
{
    $standings = fetchPoolStandings($pdo, $tournamentId);
    if (!$standings) {
        return [
            'ready' => false,
            'qualified_ids' => [],
            'eliminated_ids' => [],
            'standings' => [],
        ];
    }

    $qualifiedIds = [];
    $eliminatedIds = [];
    $ready = true;

    $poolStmt = $pdo->prepare('SELECT id, name FROM pools WHERE tournament_id = :tournament_id');
    $poolStmt->execute([':tournament_id' => $tournamentId]);
    $poolRows = $poolStmt->fetchAll();

    $poolIdByName = [];
    foreach ($poolRows as $poolRow) {
        $poolIdByName[(string) ($poolRow['name'] ?? '')] = (int) ($poolRow['id'] ?? 0);
    }

    foreach ($standings as $poolName => $rows) {
        if (count($rows) < 4) {
            $ready = false;
            continue;
        }

        $poolId = $poolIdByName[$poolName] ?? 0;
        if ($poolId <= 0 || countPoolPhaseMatchesForPool($pdo, $tournamentId, $poolId, 'Termine') < 6) {
            $ready = false;
            continue;
        }

        $qualifiedIds[] = (int) ($rows[0]['team_id'] ?? 0);
        $qualifiedIds[] = (int) ($rows[1]['team_id'] ?? 0);
        $eliminatedIds[] = (int) ($rows[count($rows) - 1]['team_id'] ?? 0);
        $eliminatedIds[] = (int) ($rows[count($rows) - 2]['team_id'] ?? 0);
    }

    $qualifiedIds = array_values(array_unique(array_filter($qualifiedIds, static fn(int $id): bool => $id > 0)));
    $eliminatedIds = array_values(array_unique(array_filter($eliminatedIds, static fn(int $id): bool => $id > 0)));

    return [
        'ready' => $ready,
        'qualified_ids' => $qualifiedIds,
        'eliminated_ids' => $eliminatedIds,
        'standings' => $standings,
    ];
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

function fetchMatches(
    PDO $pdo,
    ?string $status = null,
    bool $onlyPublished = false,
    ?int $tournamentId = null,
    ?string $phase = null,
    bool $orderByNewestFirst = false
): array
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

    if ($orderByNewestFirst) {
        $sql .= ' ORDER BY m.id DESC';
    } else {
        $sql .= ' ORDER BY m.match_date DESC, m.match_time DESC';
    }

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
    string $phase = 'Poule',
    ?string &$errorMessage = null
): ?int {
    ensureTournamentSchema($pdo);

    $allowedStatuses = ['Programme', 'En cours', 'Termine'];
    $allowedPhases = supportedMatchPhases($pdo);
    $errorMessage = '';

    if ($tournamentId <= 0) {
        $errorMessage = 'Tournoi invalide pour la creation du match.';
        return null;
    }

    if ($team1Id <= 0 || $team2Id <= 0) {
        $errorMessage = 'Veuillez selectionner deux equipes valides.';
        return null;
    }

    if ($team1Id === $team2Id) {
        $errorMessage = 'Les deux equipes doivent etre differentes.';
        return null;
    }

    if (!isValidIsoDate($matchDate)) {
        $errorMessage = 'Date de match invalide. Format attendu: YYYY-MM-DD.';
        return null;
    }

    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', trim($matchTime))) {
        $errorMessage = 'Heure de match invalide. Format attendu: HH:MM:SS.';
        return null;
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errorMessage = 'Statut de match invalide.';
        return null;
    }

    if (!in_array($phase, $allowedPhases, true)) {
        $errorMessage = 'Phase de match invalide.';
        return null;
    }

    if (!teamBelongsToTournament($pdo, $team1Id, $tournamentId) || !teamBelongsToTournament($pdo, $team2Id, $tournamentId)) {
        $errorMessage = 'Les equipes selectionnees ne sont pas rattachees a ce tournoi.';
        return null;
    }

    if ($phase === 'Poule') {
        $poolCount = tournamentPoolCount($pdo, $tournamentId);
        $legacyNoPoolMode = $poolCount === 0;

        if (!$legacyNoPoolMode && !areTeamsInSamePool($pdo, $tournamentId, $team1Id, $team2Id)) {
            $errorMessage = 'En phase Poule, les deux equipes doivent appartenir a la meme poule.';
            return null;
        }

        $poolId = fetchPoolIdForTeam($pdo, $tournamentId, $team1Id);
        if (!$legacyNoPoolMode && $poolId === null) {
            $errorMessage = 'Poule introuvable pour les equipes selectionnees.';
            return null;
        }

        if (!$legacyNoPoolMode && countPoolPhaseMatchesForPool($pdo, $tournamentId, $poolId) >= 6) {
            $errorMessage = 'Le calendrier de cette poule est deja complet (6 matchs).';
            return null;
        }

        if (!$legacyNoPoolMode) {
            $existingPair = $pdo->prepare(
                'SELECT 1
                 FROM matches
                 WHERE tournament_id = :tournament_id
                   AND phase = "Poule"
                   AND ((team1_id = :team1_id_a AND team2_id = :team2_id_a)
                        OR (team1_id = :team1_id_b AND team2_id = :team2_id_b))
                 LIMIT 1'
            );
            $existingPair->execute([
                ':tournament_id' => $tournamentId,
                ':team1_id_a' => $team1Id,
                ':team2_id_a' => $team2Id,
                ':team1_id_b' => $team2Id,
                ':team2_id_b' => $team1Id,
            ]);
            if ((bool) $existingPair->fetchColumn()) {
                $errorMessage = 'Cette affiche existe deja dans la phase de poule.';
                return null;
            }
        }
    }

    if ($phase === 'Demi') {
        $qualification = fetchTournamentQualification($pdo, $tournamentId);
        $qualified = $qualification['qualified_ids'] ?? [];

        if (!$qualification['ready']) {
            $errorMessage = 'Les demi-finales ne sont pas disponibles: terminez la phase de poules.';
            return null;
        }

        if (!in_array($team1Id, $qualified, true) || !in_array($team2Id, $qualified, true)) {
            $errorMessage = 'Seules les equipes qualifiees peuvent jouer les demi-finales.';
            return null;
        }

        $semiCount = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND phase = "Demi"');
        $semiCount->execute([':tournament_id' => $tournamentId]);
        if ((int) $semiCount->fetchColumn() >= 2) {
            $errorMessage = 'Le tableau des demi-finales est deja complet.';
            return null;
        }

        $alreadyInSemi = $pdo->prepare(
            'SELECT 1
             FROM matches
             WHERE tournament_id = :tournament_id
               AND phase = "Demi"
                             AND (
                                        team1_id IN (:team1a, :team2a)
                                        OR team2_id IN (:team1b, :team2b)
                             )
             LIMIT 1'
        );
        $alreadyInSemi->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
                $alreadyInSemi->bindValue(':team1a', $team1Id, PDO::PARAM_INT);
                $alreadyInSemi->bindValue(':team2a', $team2Id, PDO::PARAM_INT);
                $alreadyInSemi->bindValue(':team1b', $team1Id, PDO::PARAM_INT);
                $alreadyInSemi->bindValue(':team2b', $team2Id, PDO::PARAM_INT);
        $alreadyInSemi->execute();
        if ((bool) $alreadyInSemi->fetchColumn()) {
                    $errorMessage = 'Une des equipes est deja engagee dans une demi-finale.';
            return null;
        }
    }

    if ($phase === 'Finale' || $phase === 'PetiteFinale') {
        $semiStmt = $pdo->prepare(
            'SELECT team1_id, team2_id, score_team1, score_team2
             FROM matches
             WHERE tournament_id = :tournament_id AND phase = "Demi" AND status = "Termine"'
        );
        $semiStmt->execute([':tournament_id' => $tournamentId]);
        $semiMatches = $semiStmt->fetchAll();
        if (count($semiMatches) !== 2) {
            $errorMessage = 'Finale/Petite finale indisponible: 2 demi-finales terminees sont requises.';
            return null;
        }

        $winners = [];
        $losers = [];
        foreach ($semiMatches as $semi) {
            $s1 = (int) ($semi['score_team1'] ?? 0);
            $s2 = (int) ($semi['score_team2'] ?? 0);
            if ($s1 === $s2) {
                $errorMessage = 'Impossible de generer finale/petite finale: une demi-finale est terminee sur egalite.';
                return null;
            }

            $winner = $s1 > $s2 ? (int) $semi['team1_id'] : (int) $semi['team2_id'];
            $loser = $s1 > $s2 ? (int) $semi['team2_id'] : (int) $semi['team1_id'];
            $winners[] = $winner;
            $losers[] = $loser;
        }

        $expected = $phase === 'Finale' ? $winners : $losers;
        sort($expected);
        $selected = [$team1Id, $team2Id];
        sort($selected);
        if ($expected !== $selected) {
            $errorMessage = $phase === 'Finale'
                ? 'La finale doit opposer les deux vainqueurs des demi-finales.'
                : 'La petite finale doit opposer les deux perdants des demi-finales.';
            return null;
        }

        $phaseCount = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND phase = :phase');
        $phaseCount->execute([
            ':tournament_id' => $tournamentId,
            ':phase' => $phase,
        ]);
        if ((int) $phaseCount->fetchColumn() >= 1) {
            $errorMessage = $phase === 'Finale'
                ? 'La finale existe deja pour ce tournoi.'
                : 'La petite finale existe deja pour ce tournoi.';
            return null;
        }
    }

    $scoreTeam1 = $status === 'Programme' ? null : 0;
    $scoreTeam2 = $status === 'Programme' ? null : 0;
    $safeMatchTime = trim($matchTime) === '' ? '00:00:00' : $matchTime;

    $stmt = $pdo->prepare(
        'INSERT INTO matches (tournament_id, team1_id, team2_id, match_date, match_time, status, phase, score_team1, score_team2, published)
         VALUES (:tournament_id, :team1_id, :team2_id, :match_date, :match_time, :status, :phase, :score_team1, :score_team2, 1)'
    );

    $stmt->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
    $stmt->bindValue(':team1_id', $team1Id, PDO::PARAM_INT);
    $stmt->bindValue(':team2_id', $team2Id, PDO::PARAM_INT);
    $stmt->bindValue(':match_date', $matchDate, PDO::PARAM_STR);
    $stmt->bindValue(':match_time', $safeMatchTime, PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':phase', $phase, PDO::PARAM_STR);
    $stmt->bindValue(':score_team1', $scoreTeam1, $scoreTeam1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':score_team2', $scoreTeam2, $scoreTeam2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    try {
        $stmt->execute();
    } catch (Throwable $exception) {
        if (isDatabaseSchemaException($exception)) {
            $errorMessage = 'Schema SQL obsolete/incomplet: importez database/reinstall_clean.sql puis reessayez.';
            return null;
        }

        if (isDatabaseDataException($exception)) {
            $errorMessage = 'Donnees SQL invalides pour ce match (contraintes FK/ENUM/date).';
            return null;
        }

        throw $exception;
    }

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
        ['order' => 2, 'name' => 'Collective avant mi-temps'],
        ['order' => 3, 'name' => 'Identification'],
        ['order' => 4, 'name' => 'Cascades'],
        ['order' => 5, 'name' => 'Collective apres mi-temps'],
        ['order' => 6, 'name' => 'Vrai ou Faux'],
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

    $defaults = defaultTrials();

    if (count($rows) === 0) {
        $insert = $pdo->prepare(
            'INSERT INTO match_trials (match_id, trial_order, trial_name, team1_points, team2_points)
             VALUES (:match_id, :trial_order, :trial_name, 0, 0)'
        );

        foreach ($defaults as $trial) {
            $insert->execute([
                ':match_id' => $matchId,
                ':trial_order' => (int) $trial['order'],
                ':trial_name' => (string) $trial['name'],
            ]);
        }

        $existingStmt->execute([':match_id' => $matchId]);
        $rows = $existingStmt->fetchAll();
    }

    $byOrder = [];
    foreach ($rows as $row) {
        $byOrder[(int) ($row['trial_order'] ?? 0)] = true;
    }

    $insertMissing = $pdo->prepare(
        'INSERT INTO match_trials (match_id, trial_order, trial_name, team1_points, team2_points)
         VALUES (:match_id, :trial_order, :trial_name, 0, 0)'
    );

    $hasInserted = false;
    foreach ($defaults as $trial) {
        $order = (int) ($trial['order'] ?? 0);
        if ($order <= 0 || isset($byOrder[$order])) {
            continue;
        }

        $insertMissing->execute([
            ':match_id' => $matchId,
            ':trial_order' => $order,
            ':trial_name' => (string) ($trial['name'] ?? ''),
        ]);
        $hasInserted = true;
    }

    if ($hasInserted) {
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

    $phases = supportedMatchPhases($pdo);
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

    $blobValue = $row['logo_blob'] ?? null;
    $blob = null;

    if (is_resource($blobValue)) {
        $streamData = stream_get_contents($blobValue);
        if (is_string($streamData) && $streamData !== '') {
            $blob = $streamData;
        }
    } elseif (is_string($blobValue) && $blobValue !== '') {
        $blob = $blobValue;
    }

    $hasBlob = $blob !== null;
    $mime = trim((string) ($row['logo_mime'] ?? ''));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'has_blob' => $hasBlob,
        'mime' => $mime !== '' ? $mime : 'image/png',
        'blob' => $blob,
        'logo_path' => normalizeLogoPath((string) ($row['logo_path'] ?? '')),
    ];
}

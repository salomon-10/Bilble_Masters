<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function fetchTeams(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC');
    return $stmt->fetchAll();
}

function fetchMatchById(PDO $pdo, int $matchId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.team1_id, m.team2_id, m.match_date, m.match_time, m.status, m.score_team1, m.score_team2, m.published,
                t1.name AS team1_name, t2.name AS team2_name
         FROM matches m
         INNER JOIN teams t1 ON t1.id = m.team1_id
         INNER JOIN teams t2 ON t2.id = m.team2_id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $matchId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function fetchMatches(PDO $pdo, ?string $status = null, bool $onlyPublished = false): array
{
    $sql = 'SELECT m.id, m.match_date, m.match_time, m.status, m.score_team1, m.score_team2, m.published,
                   t1.name AS team1_name, t2.name AS team2_name
            FROM matches m
            INNER JOIN teams t1 ON t1.id = m.team1_id
            INNER JOIN teams t2 ON t2.id = m.team2_id';

    $conditions = [];
    $params = [];

    if ($status !== null) {
        $conditions[] = 'm.status = :status';
        $params[':status'] = $status;
    }

    if ($onlyPublished) {
        $conditions[] = 'm.published = 1';
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY m.match_date DESC, m.match_time DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function countMatchesByStatus(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM matches GROUP BY status');
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
    ensureMatchTrialsTable($pdo);

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
    ensureMatchTrialsTable($pdo);

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
    ensureMatchTrialsTable($pdo);

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

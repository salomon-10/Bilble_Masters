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

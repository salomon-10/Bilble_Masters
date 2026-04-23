<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/repositories.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = db();
    $requestedTournamentId = (int) ($_GET['tournament_id'] ?? 0);
    $resolved = resolveTournamentId($pdo, $requestedTournamentId > 0 ? $requestedTournamentId : null);

    if ($resolved === null) {
        echo json_encode([
            'ok' => true,
            'tournament' => null,
            'teams' => [],
            'pools' => [],
            'bracket' => [],
        ]);
        exit;
    }

    $tournament = fetchTournamentById($pdo, $resolved);
    $teams = fetchTeams($pdo, $resolved);
    $poolGroups = fetchTeamsGroupedByPool($pdo, $resolved);
    $bracket = fetchTournamentBracket($pdo, $resolved);

    echo json_encode([
        'ok' => true,
        'tournament' => $tournament,
        'teams' => $teams,
        'pools' => $poolGroups,
        'bracket' => $bracket,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[Bible_Master] user/team_data.php failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erreur lors du chargement des donnees equipes.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

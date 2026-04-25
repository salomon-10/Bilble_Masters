<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/repositories.php';

function exceptionChainMessages(Throwable $exception): array
{
    $messages = [];
    $cursor = $exception;

    while ($cursor instanceof Throwable) {
        $messages[] = [
            'type' => get_class($cursor),
            'message' => $cursor->getMessage(),
        ];
        $cursor = $cursor->getPrevious();
    }

    return $messages;
}

$result = [
    'ok' => false,
    'host' => (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
    'steps' => [],
    'error' => null,
];

try {
    $result['steps'][] = 'db:start';
    $pdo = db();
    $result['steps'][] = 'db:ok';

    $result['steps'][] = 'fetchTournaments:start';
    $tournaments = fetchTournaments($pdo);
    $result['steps'][] = 'fetchTournaments:ok:' . count($tournaments);

    $requestedTournamentId = (int) ($_GET['tournament_id'] ?? 0);
    $result['steps'][] = 'resolveTournamentId:start:' . $requestedTournamentId;
    $resolved = resolveTournamentId($pdo, $requestedTournamentId > 0 ? $requestedTournamentId : null);
    $result['steps'][] = 'resolveTournamentId:ok:' . (string) ($resolved ?? 'null');

    if ($resolved !== null) {
        $result['steps'][] = 'fetchTournamentById:start';
        $tournament = fetchTournamentById($pdo, $resolved);
        $result['steps'][] = 'fetchTournamentById:ok:' . (string) (($tournament['name'] ?? '')); // phpcs:ignore

        $result['steps'][] = 'fetchMatches:start';
        $matches = fetchMatches($pdo, null, true, $resolved);
        $result['steps'][] = 'fetchMatches:ok:' . count($matches);

        $result['steps'][] = 'fetchTournamentQualification:start';
        $qualification = fetchTournamentQualification($pdo, $resolved);
        $result['steps'][] = 'fetchTournamentQualification:ok:ready=' . ((bool) ($qualification['ready'] ?? false) ? '1' : '0');
    }

    $result['ok'] = true;
} catch (Throwable $exception) {
    $result['ok'] = false;
    $result['error'] = [
        'public_message' => publicDatabaseErrorMessage($exception, 'Erreur user flow.'),
        'chain' => exceptionChainMessages($exception),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

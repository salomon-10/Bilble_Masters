<?php

declare(strict_types=1);

require_once __DIR__ . '/config/repositories.php';

$teamId = (int) ($_GET['id'] ?? 0);

if ($teamId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid team id.';
    exit;
}

try {
    $pdo = db();
    $payload = fetchTeamLogoPayload($pdo, $teamId);
} catch (Throwable $exception) {
    error_log('[Bible_Master] team_logo.php failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to load logo.';
    exit;
}

if (!$payload) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Logo not found.';
    exit;
}

if ((bool) ($payload['has_blob'] ?? false) && isset($payload['blob']) && is_string($payload['blob'])) {
    $mime = trim((string) ($payload['mime'] ?? 'image/png'));
    if ($mime === '') {
        $mime = 'image/png';
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    echo $payload['blob'];
    exit;
}

$fallback = (string) ($payload['logo_path'] ?? defaultTeamLogoPath());
header('Location: ' . $fallback, true, 302);
exit;

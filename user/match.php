<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/repositories.php';

$matchId = (int) ($_GET['id'] ?? 0);
$dbError = '';
$match = null;

if ($matchId <= 0) {
    $dbError = 'Match invalide.';
} else {
    try {
        $pdo = db();
        $match = fetchMatchById($pdo, $matchId);

        if (!$match || (int) $match['published'] !== 1) {
            $match = null;
            $dbError = 'Ce match est introuvable ou non publie.';
        }
    } catch (Throwable $exception) {
        $dbError = 'Impossible de charger le detail du match.';
    }
}

function statusLabel(string $status): string
{
    return match ($status) {
        'En cours' => 'LIVE',
        'Termine' => 'Termine',
        default => 'Programme',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'En cours' => 'live',
        'Termine' => 'done',
        default => 'upcoming',
    };
}

function teamInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 3) {
            break;
        }
    }

    return $letters !== '' ? $letters : 'EQ';
}

function logoCandidates(?string $path): array
{
    $raw = trim((string) ($path ?? ''));
    if ($raw === '') {
        return [];
    }

    if (preg_match('#^https?://#i', $raw)) {
        return [$raw];
    }

    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $isLocalHost = $hostName === ''
        || str_contains($hostName, 'localhost')
        || str_contains($hostName, '127.0.0.1');

    $normalized = '/' . ltrim($raw, '/');
    if (preg_match('#(?:^|/)(img/teams/[^?]+)$#i', $normalized, $matches)) {
        $normalized = '/' . $matches[1];
    }

    $candidates = [$normalized];

    if (str_starts_with($normalized, '/Bible_Master/')) {
        $trimmed = substr($normalized, strlen('/Bible_Master'));
        if ($trimmed !== '' && $trimmed !== '/') {
            if ($isLocalHost) {
                $candidates[] = $trimmed;
            } else {
                // On production hosting like InfinityFree, /img/... should be tried first.
                $candidates = [$trimmed, $normalized];
            }
        }
    } elseif (str_starts_with($normalized, '/img/')) {
        $candidates[] = '/Bible_Master' . $normalized;
    }

    return array_values(array_unique(array_filter($candidates, static fn(string $v): bool => $v !== '')));
}

$trials = [];

if ($match) {
    try {
        $trials = fetchMatchTrials($pdo, $matchId);
    } catch (Throwable $exception) {
        $trials = [];
    }

    if (!$trials) {
        foreach (defaultTrials() as $trial) {
            $trials[] = [
                'trial_order' => (int) $trial['order'],
                'trial_name' => (string) $trial['name'],
                'team1_points' => null,
                'team2_points' => null,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail match | Bible Master</title>
    <link rel="stylesheet" href="./match.css">
</head>
<body>
    <main class="page">
        <header class="top-actions">
            <a class="back-link" href="index.php">Retour dashboard</a>
        </header>

        <?php if ($dbError !== ''): ?>
            <section class="error-card">
                <h1>Match indisponible</h1>
                <p><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p>
            </section>
        <?php elseif ($match): ?>
            <section class="scoreboard-card">
                <div class="top-zone">
                    <article class="team-zone left-zone">
                        <div>
                            <p class="team-label">Team B</p>
                            <h2 class="team-name"><?php echo htmlspecialchars((string) $match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        </div>
                        <div class="logo-emblem">
                            <?php $team2LogoCandidates = logoCandidates((string) ($match['team2_logo'] ?? '')); ?>
                            <?php if ($team2LogoCandidates): ?>
                                <img
                                    src="<?php echo htmlspecialchars((string) $team2LogoCandidates[0], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="Logo <?php echo htmlspecialchars((string) $match['team2_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    loading="lazy"
                                    data-alt-src="<?php echo htmlspecialchars((string) ($team2LogoCandidates[1] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    onerror="if(this.dataset.altSrc && this.src.indexOf(this.dataset.altSrc) === -1){ this.src = this.dataset.altSrc; this.dataset.altSrc=''; return; } this.style.display='none'; if(this.nextElementSibling){ this.nextElementSibling.style.display='grid'; }"
                                    onload="if(this.nextElementSibling){ this.nextElementSibling.style.display='none'; }"
                                >
                            <?php endif; ?>
                            <span class="logo-fallback"><?php echo htmlspecialchars(teamInitials((string) $match['team2_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </article>

                    <div class="versus-zone">
                        <div class="slash"></div>
                        <p class="vs-text">VS</p>
                        <div class="slash"></div>
                    </div>

                    <article class="team-zone right-zone">
                            <div class="logo-emblem warm">
                                <?php $team1LogoCandidates = logoCandidates((string) ($match['team1_logo'] ?? '')); ?>
                                <?php if ($team1LogoCandidates): ?>
                                    <img
                                        src="<?php echo htmlspecialchars((string) $team1LogoCandidates[0], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="Logo <?php echo htmlspecialchars((string) $match['team1_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        loading="lazy"
                                        data-alt-src="<?php echo htmlspecialchars((string) ($team1LogoCandidates[1] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="if(this.dataset.altSrc && this.src.indexOf(this.dataset.altSrc) === -1){ this.src = this.dataset.altSrc; this.dataset.altSrc=''; return; } this.style.display='none'; if(this.nextElementSibling){ this.nextElementSibling.style.display='grid'; }"
                                        onload="if(this.nextElementSibling){ this.nextElementSibling.style.display='none'; }"
                                    >
                                <?php endif; ?>
                                <span class="logo-fallback"><?php echo htmlspecialchars(teamInitials((string) $match['team1_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <div>
                            <p class="team-label">Team A</p>
                            <h2 class="team-name"><?php echo htmlspecialchars((string) $match['team1_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        </div>

                    </article>
                </div>

                <div class="score-strip">
                    <div class="score-box score-left"><?php echo $match['score_team2'] === null ? '-' : (int) $match['score_team2']; ?></div>
                    <div class="middle-box">
                        <span class="status-pill <?php echo htmlspecialchars(statusClass((string) $match['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(statusLabel((string) $match['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="date-text"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="score-box score-right"><?php echo $match['score_team1'] === null ? '-' : (int) $match['score_team1']; ?></div>
                </div>

                <div class="details-grid">
                    <div class="column points-col">
                        <?php foreach ($trials as $trial): ?>
                            <p><?php echo $trial['team2_points'] === null ? '-' : (int) $trial['team2_points']; ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="column trials-col">
                        <?php foreach ($trials as $trial): ?>
                            <p><?php echo htmlspecialchars((string) $trial['trial_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="column points-col right-points">
                        <?php foreach ($trials as $trial): ?>
                            <p><?php echo $trial['team1_points'] === null ? '-' : (int) $trial['team1_points']; ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="note">Page en lecture seule pour le public. Les points par epreuve affichent les valeurs enregistrees pendant le match.</p>
            </section>
        <?php endif; ?>
    </main>
</body>
<script>
    setInterval(() => {
        window.location.reload();
    }, 5000);
</script>
</html>

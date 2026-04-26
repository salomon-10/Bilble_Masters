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
        error_log('[Bible_Master] user/match.php failed: ' . $exception->getMessage());
        $dbError = publicDatabaseErrorMessage($exception, 'Impossible de charger le detail du match.');
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;700;800&family=Archivo+Black&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-1: #081225;
            --bg-2: #0d1931;
            --panel: rgba(12, 25, 49, 0.78);
            --line: rgba(255, 255, 255, 0.12);
            --text: #f2f6ff;
            --muted: #b7c5df;
            --accent-a: #ffb347;
            --accent-b: #62dbff;
            --ok: #29d391;
            --done: #f59e0b;
            --upcoming: #93a4c9;
            --shadow: 0 20px 70px rgba(0, 0, 0, 0.42);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--text);
            font-family: "Bricolage Grotesque", "Segoe UI", sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(1200px 500px at -5% -10%, rgba(255, 179, 71, 0.15), transparent 60%),
                radial-gradient(1200px 600px at 110% -20%, rgba(98, 219, 255, 0.14), transparent 55%),
                linear-gradient(180deg, var(--bg-2), var(--bg-1));
            padding: 22px;
        }

        .arena {
            width: min(1100px, 100%);
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }

        .toolbar,
        .match-shell,
        .error-shell {
            border: 1px solid var(--line);
            background: var(--panel);
            border-radius: 18px;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
        }

        .back-link {
            text-decoration: none;
            color: var(--text);
            font-weight: 700;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 14px;
            background: rgba(255, 255, 255, 0.04);
            transition: transform .2s ease, border-color .2s ease;
        }

        .back-link:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.28);
        }

        .refresh-chip {
            font-size: .85rem;
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.03);
        }

        .pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--ok);
            box-shadow: 0 0 0 0 rgba(41, 211, 145, .55);
            animation: pulse 1.6s infinite;
        }

        @keyframes pulse {
            70% {
                box-shadow: 0 0 0 8px rgba(41, 211, 145, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(41, 211, 145, 0);
            }
        }

        .error-shell {
            padding: 26px;
        }

        .error-shell h1 {
            margin: 0 0 8px;
            font-size: 1.5rem;
            font-family: "Archivo Black", sans-serif;
            letter-spacing: .4px;
        }

        .error-shell p {
            margin: 0;
            color: #ffd5d5;
        }

        .match-shell {
            padding: 18px;
            display: grid;
            gap: 16px;
        }

        .stage {
            border-radius: 16px;
            border: 1px solid var(--line);
            padding: 16px;
            background:
                linear-gradient(160deg, rgba(255, 179, 71, 0.08), transparent 35%),
                linear-gradient(20deg, rgba(98, 219, 255, 0.08), transparent 35%),
                rgba(11, 21, 41, 0.6);
        }

        .status-row {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
        }

        .status-pill {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--muted);
            background: rgba(255, 255, 255, 0.06);
        }

        .status-pill.live {
            color: #bcffe4;
            border-color: rgba(41, 211, 145, .45);
            background: rgba(41, 211, 145, .16);
        }

        .status-pill.done {
            color: #ffe3b8;
            border-color: rgba(245, 158, 11, .45);
            background: rgba(245, 158, 11, .14);
        }

        .duel {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 12px;
        }

        .team-card {
            display: grid;
            justify-items: center;
            gap: 10px;
            text-align: center;
        }

        .team-name {
            margin: 0;
            font-size: clamp(1rem, 2vw, 1.3rem);
            font-weight: 800;
        }

        .logo-emblem {
            width: 86px;
            height: 86px;
            border-radius: 18px;
            border: 1px solid var(--line);
            overflow: hidden;
            background: rgba(255, 255, 255, 0.04);
            display: grid;
            place-items: center;
            position: relative;
        }

        .logo-emblem img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .logo-fallback {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            font-weight: 800;
            color: #e8f1ff;
            font-size: 1.1rem;
            background: linear-gradient(140deg, rgba(255, 179, 71, .2), rgba(98, 219, 255, .2));
        }

        .score-center {
            min-width: 210px;
            text-align: center;
            border-left: 1px solid var(--line);
            border-right: 1px solid var(--line);
            padding: 4px 16px;
        }

        .score-line {
            margin: 0;
            font-family: "Archivo Black", sans-serif;
            letter-spacing: .6px;
            font-size: clamp(2rem, 5vw, 3rem);
            line-height: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
        }

        .score-a {
            color: var(--accent-b);
        }

        .score-b {
            color: var(--accent-a);
        }

        .dash {
            color: rgba(255, 255, 255, .6);
            font-size: .9em;
        }

        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: .9rem;
        }

        .trials-shell {
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            background: rgba(7, 14, 28, 0.55);
        }

        .trials-head,
        .trial-row {
            display: grid;
            grid-template-columns: 90px 1fr 90px;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
        }

        .trials-head {
            background: rgba(255, 255, 255, 0.06);
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .9px;
            font-weight: 700;
        }

        .trial-row {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            min-height: 46px;
        }

        .trial-row:last-child {
            border-bottom: 0;
        }

        .halftime-row {
            text-align: center;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .95px;
            color: #f8d4aa;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 179, 71, 0.08);
            padding: 9px 14px;
        }

        .trial-name {
            font-weight: 700;
            color: #e8efff;
            text-align: center;
        }

        .trial-points {
            font-family: "Archivo Black", sans-serif;
            text-align: center;
            font-size: 1.15rem;
        }

        .trial-points.left {
            color: var(--accent-b);
        }

        .trial-points.right {
            color: var(--accent-a);
        }

        .foot-note {
            margin: 0;
            padding: 8px 2px 0;
            color: var(--muted);
            font-size: .9rem;
            text-align: center;
        }

        @media (max-width: 860px) {
            .duel {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .score-center {
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 10px;
            }
        }

        @media (max-width: 560px) {
            body {
                padding: 12px;
            }

            .toolbar,
            .match-shell,
            .error-shell {
                border-radius: 14px;
            }

            .trials-head,
            .trial-row {
                grid-template-columns: 72px 1fr 72px;
                padding: 10px;
            }

            .trial-name {
                font-size: .9rem;
            }
        }
    </style>
</head>
<body>
    <main class="arena">
        <header class="toolbar">
            <a class="back-link" href="index.php">Retour dashboard</a>
            <div class="refresh-chip"><span class="pulse"></span>Rafraichissement auto: <strong id="refreshCountdown">5s</strong></div>
        </header>

        <?php if ($dbError !== ''): ?>
            <section class="error-shell">
                <h1>Match indisponible</h1>
                <p><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p>
            </section>
        <?php elseif ($match): ?>
            <section class="match-shell">
                <section class="stage">
                    <div class="status-row">
                        <span class="status-pill <?php echo htmlspecialchars(statusClass((string) $match['status']), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(statusLabel((string) $match['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="duel">
                        <article class="team-card">
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
                            <h2 class="team-name"><?php echo htmlspecialchars((string) $match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        </article>

                        <div class="score-center">
                            <p class="score-line">
                                <span class="score-a"><?php echo $match['score_team2'] === null ? '-' : (int) $match['score_team2']; ?></span>
                                <span class="dash">:</span>
                                <span class="score-b"><?php echo $match['score_team1'] === null ? '-' : (int) $match['score_team1']; ?></span>
                            </p>
                            <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>

                        <article class="team-card">
                            <div class="logo-emblem">
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
                            <h2 class="team-name"><?php echo htmlspecialchars((string) $match['team1_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        </article>
                    </div>
                </section>

                <section class="trials-shell" aria-label="Detail des epreuves">
                    <div class="trials-head">
                        <span><?php echo htmlspecialchars((string) $match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span style="text-align:center;">Epreuve</span>
                        <span style="text-align:right;"><?php echo htmlspecialchars((string) $match['team1_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <?php foreach ($trials as $trial): ?>
                        <div class="trial-row">
                            <div class="trial-points left"><?php echo $trial['team2_points'] === null ? '-' : (int) $trial['team2_points']; ?></div>
                            <div class="trial-name"><?php echo htmlspecialchars((string) $trial['trial_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="trial-points right"><?php echo $trial['team1_points'] === null ? '-' : (int) $trial['team1_points']; ?></div>
                        </div>
                        <?php if ((int) ($trial['trial_order'] ?? 0) === 3): ?>
                            <div class="halftime-row">Mi-temps</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>

                <p class="foot-note">Affichage public en lecture seule. Les points par epreuve reflètent les valeurs enregistrees en temps reel.</p>
            </section>
        <?php endif; ?>
    </main>
</body>
<script>
    const refreshLabel = document.getElementById('refreshCountdown');
    let seconds = 5;

    const tick = () => {
        seconds -= 1;
        if (seconds <= 0) {
            window.location.reload();
            return;
        }

        if (refreshLabel) {
            refreshLabel.textContent = `${seconds}s`;
        }
    };

    setInterval(tick, 1000);

    setInterval(() => {
        window.location.reload();
    }, 5000);
</script>
</html>

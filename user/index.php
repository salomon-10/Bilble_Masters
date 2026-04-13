<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/repositories.php';

$dbError = '';
$matches = [];

try {
    $pdo = db();
    $matches = fetchMatches($pdo, null, true);
} catch (Throwable $exception) {
    $dbError = 'Connexion impossible a la base de donnees. Verifiez les parametres InfinityFree.';
}

$live = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'En cours'));
$upcoming = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'Programme'));
$past = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'Termine'));

function scoreText(array $match): string
{
    if ($match['score_team1'] === null || $match['score_team2'] === null) {
        return 'Score non disponible';
    }

    return $match['score_team1'] . ' - ' . $match['score_team2'];
}

function statusClass(string $status): string
{
    if ($status === 'En cours') {
        return 'live';
    }

    if ($status === 'Termine') {
        return 'done';
    }

    return 'upcoming';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bible Master | Matchs en direct</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <main class="shell">
        <header class="hero card">
            <div>
                <p class="eyebrow">Bible Master</p>
                <h1>Matchs et scores en direct</h1>
                <p class="subtitle">Suivez les matchs en direct et les derniers resultats</p>
            </div>
            <button id="themeToggle" class="btn ghost" type="button" aria-label="Activer le mode nuit">Mode nuit</button>
        </header>

        <section class="live-zone card">
            <div class="section-head">
                <h2>Match en cours</h2>
                <span class="badge"><?php echo count($live); ?></span>
            </div>
            <div class="list">
                <?php if ($dbError !== ''): ?><p class="empty"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <?php if (!$live): ?><p class="empty">Aucun match en cours.</p><?php endif; ?>
                <?php foreach ($live as $match): ?>
                    <article class="match-row">
                        <div class="match-main">
                            <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="match-side">
                            <p class="score"><?php echo htmlspecialchars(scoreText($match), ENT_QUOTES, 'UTF-8'); ?></p>
                            <span class="status-pill <?php echo statusClass((string) $match['status']); ?>"><?php echo htmlspecialchars((string) $match['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <div class="section-head"><h2>A venir</h2><span class="badge"><?php echo count($upcoming); ?></span></div>
                <div class="list compact">
                    <?php if (!$upcoming): ?><p class="empty">Aucun match a venir.</p><?php endif; ?>
                    <?php foreach ($upcoming as $match): ?>
                        <article class="match-row">
                            <div class="match-main">
                                <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="match-side">
                                <p class="score"><?php echo htmlspecialchars(scoreText($match), ENT_QUOTES, 'UTF-8'); ?></p>
                                <span class="status-pill <?php echo statusClass((string) $match['status']); ?>"><?php echo htmlspecialchars((string) $match['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card">
                <div class="section-head"><h2>Passes</h2><span class="badge"><?php echo count($past); ?></span></div>
                <div class="list compact">
                    <?php if (!$past): ?><p class="empty">Aucun match passe.</p><?php endif; ?>
                    <?php foreach ($past as $match): ?>
                        <article class="match-row">
                            <div class="match-main">
                                <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="match-side">
                                <p class="score"><?php echo htmlspecialchars(scoreText($match), ENT_QUOTES, 'UTF-8'); ?></p>
                                <span class="status-pill <?php echo statusClass((string) $match['status']); ?>"><?php echo htmlspecialchars((string) $match['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>
    </main>

    <script>
    const THEME_KEY = 'bm_theme';
    const themeToggle = document.getElementById('themeToggle');

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        themeToggle.textContent = theme === 'dark' ? 'Mode jour' : 'Mode nuit';
    }

    const saved = localStorage.getItem(THEME_KEY);
    applyTheme(saved === 'dark' ? 'dark' : 'light');

    themeToggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const next = current === 'light' ? 'dark' : 'light';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    });
    </script>
</body>
</html>

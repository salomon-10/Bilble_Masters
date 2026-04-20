<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/repositories.php';

$dbError = '';
$allPublishedMatches = [];

try {
    $pdo = db();
    $allPublishedMatches = fetchMatches($pdo, null, true);
} catch (Throwable $exception) {
    $dbError = 'Connexion impossible a la base de donnees. Verifiez les parametres InfinityFree.';
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$dateFilter = (string) ($_GET['match_date'] ?? '');

$allowedStatusFilters = ['all', 'Programme', 'En cours', 'Termine'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$matches = array_values(array_filter(
    $allPublishedMatches,
    static function (array $match) use ($search, $statusFilter, $dateFilter): bool {
        if ($statusFilter !== 'all' && (string) $match['status'] !== $statusFilter) {
            return false;
        }

        if ($dateFilter !== '' && (string) $match['match_date'] !== $dateFilter) {
            return false;
        }

        if ($search !== '') {
            $haystack = strtolower((string) $match['team1_name'] . ' ' . (string) $match['team2_name']);
            if (!str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        return true;
    }
));

$live = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'En cours'));
$upcoming = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'Programme'));
$past = array_values(array_filter($matches, static fn(array $m): bool => $m['status'] === 'Termine'));

$standings = buildStandings($allPublishedMatches);
$nowTime = date('H:i:s');

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

/**
 * Build a simple ranking table from finished matches.
 * Rules: win=3, draw=1, loss=0, then GD, GF, team name.
 */
function buildStandings(array $matches): array
{
    $table = [];

    foreach ($matches as $match) {
        if ((string) $match['status'] !== 'Termine') {
            continue;
        }

        if ($match['score_team1'] === null || $match['score_team2'] === null) {
            continue;
        }

        $teamA = (string) $match['team1_name'];
        $teamB = (string) $match['team2_name'];

        foreach ([$teamA, $teamB] as $teamName) {
            if (!isset($table[$teamName])) {
                $table[$teamName] = [
                    'team' => $teamName,
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
        }

        $scoreA = (int) $match['score_team1'];
        $scoreB = (int) $match['score_team2'];

        $table[$teamA]['played']++;
        $table[$teamB]['played']++;

        $table[$teamA]['gf'] += $scoreA;
        $table[$teamA]['ga'] += $scoreB;
        $table[$teamB]['gf'] += $scoreB;
        $table[$teamB]['ga'] += $scoreA;

        if ($scoreA > $scoreB) {
            $table[$teamA]['won']++;
            $table[$teamA]['points'] += 3;
            $table[$teamB]['lost']++;
        } elseif ($scoreA < $scoreB) {
            $table[$teamB]['won']++;
            $table[$teamB]['points'] += 3;
            $table[$teamA]['lost']++;
        } else {
            $table[$teamA]['drawn']++;
            $table[$teamB]['drawn']++;
            $table[$teamA]['points']++;
            $table[$teamB]['points']++;
        }

        $table[$teamA]['gd'] = $table[$teamA]['gf'] - $table[$teamA]['ga'];
        $table[$teamB]['gd'] = $table[$teamB]['gf'] - $table[$teamB]['ga'];
    }

    $rows = array_values($table);

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

    return $rows;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bible Master | Matchs en direct</title>
    <link rel="stylesheet" href="./index.css">
</head>
<body>
    <main class="shell">
        <header class="hero card">
            <div>
                <p class="eyebrow">Bible Master</p>
                <h1>Matchs et scores en direct</h1>
                <p class="subtitle">Suivez les matchs, appliquez des filtres, et consultez le classement automatique.</p>
            </div>
            <div class="hero-actions">
                <button id="themeToggle" class="btn ghost" type="button" aria-label="Activer le mode nuit">Mode nuit</button>
                <button id="refreshNow" class="btn ghost" type="button" aria-label="Rafraichir">Rafraichir</button>
<button class="btn ghost" aria-label="Teams" onclick="window.location.href='team.html'">
    Voir les équipes
</button>            </div>
        </header>
<!-- 
        <section class="card filters-card">
            <form method="get" class="filters-grid">
                <div class="filter-field">
                    <label for="q">Recherche equipe</label>
                    <input id="q" name="q" type="text" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: Guerriers" />
                </div>

                <div class="filter-field">
                    <label for="status">Statut</label>
                    <select id="status" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tous</option>
                        <option value="Programme" <?php echo $statusFilter === 'Programme' ? 'selected' : ''; ?>>Programme</option>
                        <option value="En cours" <?php echo $statusFilter === 'En cours' ? 'selected' : ''; ?>>En cours</option>
                        <option value="Termine" <?php echo $statusFilter === 'Termine' ? 'selected' : ''; ?>>Termine</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="match_date">Date</label>
                    <input id="match_date" name="match_date" type="date" value="<?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>" />
                </div>

                <div class="filter-actions">
                    <button class="btn ghost" type="submit">Appliquer</button>
                    <a class="btn ghost" href="index.php">Reset</a>
                </div>
            </form>
            <p class="refresh-meta">Derniere mise a jour: <?php echo htmlspecialchars($nowTime, ENT_QUOTES, 'UTF-8'); ?> | Auto-refresh: 20s</p>
        </section> -->

        <section class="live-zone card">
            <div class="section-head">
                <h2>Match en cours</h2>
                <span class="badge"><?php echo count($live); ?></span>
            </div>
            <div class="list">
                <?php if ($dbError !== ''): ?><p class="empty"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <?php if (!$live): ?><p class="empty">Aucun match en cours.</p><?php endif; ?>
                <?php foreach ($live as $match): ?>
                    <a class="match-link" href="match.php?id=<?php echo (int) $match['id']; ?>">
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
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <div class="section-head"><h2>A venir</h2><span class="badge"><?php echo count($upcoming); ?></span></div>
                <div class="list compact">
                    <?php if (!$upcoming): ?><p class="empty">Aucun match a venir.</p><?php endif; ?>
                    <?php foreach ($upcoming as $match): ?>
                        <a class="match-link" href="match.php?id=<?php echo (int) $match['id']; ?>">
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
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card">
                <div class="section-head"><h2>Passes</h2><span class="badge"><?php echo count($past); ?></span></div>
                <div class="list compact">
                    <?php if (!$past): ?><p class="empty">Aucun match passe.</p><?php endif; ?>
                    <?php foreach ($past as $match): ?>
                        <a class="match-link" href="match.php?id=<?php echo (int) $match['id']; ?>">
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
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section class="card standings-card">
            <div class="section-head">
                <h2>Classement automatique</h2>
                <span class="badge"><?php echo count($standings); ?></span>
            </div>

            <?php if (!$standings): ?>
                <p class="empty">Aucune equipe disponible pour le classement.</p>
            <?php else: ?>
                <div class="standings-wrap">
                    <table class="standings-table" aria-label="Classement des equipes">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Equipe</th>
                                <th>Pts</th>
                                <th>J</th>
                                <th>V</th>
                                <th>N</th>
                                <th>D</th>
                                <th>BP</th>
                                <th>BC</th>
                                <th>Diff</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $index => $row): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars((string) $row['team'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong><?php echo (int) $row['points']; ?></strong></td>
                                    <td><?php echo (int) $row['played']; ?></td>
                                    <td><?php echo (int) $row['won']; ?></td>
                                    <td><?php echo (int) $row['drawn']; ?></td>
                                    <td><?php echo (int) $row['lost']; ?></td>
                                    <td><?php echo (int) $row['gf']; ?></td>
                                    <td><?php echo (int) $row['ga']; ?></td>
                                    <td><?php echo (int) $row['gd']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
    const THEME_KEY = 'bm_theme';
    const themeToggle = document.getElementById('themeToggle');
    const refreshNow = document.getElementById('refreshNow');

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

    refreshNow.addEventListener('click', () => {
        window.location.reload();
    });

    setInterval(() => {
        window.location.reload();
    }, 3000);
    </script>
</body>
</html>

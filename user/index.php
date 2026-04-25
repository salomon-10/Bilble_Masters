<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/repositories.php';

$dbError = '';
$allPublishedMatches = [];
$tournaments = [];
$selectedTournamentId = (int) ($_GET['tournament_id'] ?? 0);
$selectedTournament = null;
$poolStandings = [];
$eliminatedTeamIds = [];

try {
    $pdo = db();
    $tournaments = fetchTournaments($pdo);
    $resolved = resolveTournamentId($pdo, $selectedTournamentId > 0 ? $selectedTournamentId : null);
    if ($resolved !== null) {
        $selectedTournamentId = $resolved;
        $selectedTournament = fetchTournamentById($pdo, $selectedTournamentId);
        $allPublishedMatches = fetchMatches($pdo, null, true, $selectedTournamentId);
        $qualification = fetchTournamentQualification($pdo, $selectedTournamentId);
        $poolStandings = $qualification['standings'] ?? [];
        $eliminatedTeamIds = $qualification['eliminated_ids'] ?? [];
    }
} catch (Throwable $exception) {
    error_log('[Bible_Master] user/index.php failed: ' . $exception->getMessage());
    $dbError = publicDatabaseErrorMessage($exception, 'Erreur de chargement des matchs.');
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

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bible Master | Matchs en direct</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <main class="shell">
        <header class="hero card">
            <div>
                <p class="eyebrow">Bible Master</p>
                <h1>Matchs et scores en direct</h1>
                <p class="subtitle">Suivez les matchs du tournoi <?php echo htmlspecialchars((string) ($selectedTournament['name'] ?? 'actif'), ENT_QUOTES, 'UTF-8'); ?>, appliquez des filtres, et consultez le classement automatique.</p>
            </div>
            <div class="hero-actions">
                <button id="themeToggle" class="btn ghost" type="button" aria-label="Activer le mode nuit">Mode nuit</button>
                <button id="refreshNow" class="btn ghost" type="button" aria-label="Rafraichir">Rafraichir</button>
<button class="btn ghost" aria-label="Teams" onclick="window.location.href='/user/team.html?tournament_id=<?php echo (int) $selectedTournamentId; ?>'">
    Voir les équipes
</button>            </div>
        </header>

        <section class="card" style="padding:14px 16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <strong>Tournois:</strong>
            <?php if (!$tournaments): ?>
                <span class="empty">Aucun tournoi</span>
            <?php endif; ?>
            <?php foreach ($tournaments as $t): ?>
                <a class="btn ghost" href="/user/index.php?tournament_id=<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </section>


        <section class="live-zone card">
            <div class="section-head">
                <h2>Match en cours</h2>
                <span class="badge"><?php echo count($live); ?></span>
            </div>
            <div class="list">
                <?php if ($dbError !== ''): ?><p class="empty"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <?php if (!$live): ?><p class="empty">Aucun match en cours.</p><?php endif; ?>
                <?php foreach ($live as $match): ?>
                    <a class="match-link" href="/user/match.php?id=<?php echo (int) $match['id']; ?>&tournament_id=<?php echo (int) $selectedTournamentId; ?>">
                    <article class="match-row">
                        <div class="match-main">
                            <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?></p>
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
                        <a class="match-link" href="match.php?id=<?php echo (int) $match['id']; ?>&tournament_id=<?php echo (int) $selectedTournamentId; ?>">
                        <article class="match-row">
                            <div class="match-main">
                                <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?></p>
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
                        <a class="match-link" href="match.php?id=<?php echo (int) $match['id']; ?>&tournament_id=<?php echo (int) $selectedTournamentId; ?>">
                        <article class="match-row">
                            <div class="match-main">
                                <p class="teams"><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?></p>
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
                <h2>Classement par poule</h2>
                <span class="badge"><?php echo count($poolStandings); ?></span>
            </div>

            <?php if (!$poolStandings): ?>
                <p class="empty">Aucune poule disponible pour le classement.</p>
            <?php else: ?>
                <div class="standings-wrap" style="display:grid;gap:16px;">
                    <?php foreach ($poolStandings as $poolName => $poolRows): ?>
                        <div>
                            <h3 style="margin:0 0 8px;">Poule <?php echo htmlspecialchars((string) $poolName, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <table class="standings-table" aria-label="Classement poule <?php echo htmlspecialchars((string) $poolName, ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <?php foreach ($poolRows as $index => $row): ?>
                                        <?php $isEliminated = in_array((int) ($row['team_id'] ?? 0), $eliminatedTeamIds, true); ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars((string) $row['team'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($isEliminated): ?>
                                                    <span style="display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.35);font-size:.75rem;">Elimine</span>
                                                <?php endif; ?>
                                            </td>
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
                    <?php endforeach; ?>
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
    }, 10000);
    </script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth();

$allowedStatuses = ['Programme', 'En cours', 'Termine'];
$message = '';
$messageType = '';
$dbError = '';
$matches = [];
$tournament = null;
$tournamentId = (int) ($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);

try {
    $pdo = db();
    $resolved = resolveTournamentId($pdo, $tournamentId > 0 ? $tournamentId : null);
    if ($resolved === null) {
        $dbError = 'Aucun tournoi disponible. Creez un tournoi d abord.';
    } else {
        $tournamentId = $resolved;
        $tournament = fetchTournamentById($pdo, $tournamentId);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tournamentId > 0) {
        validateCsrfOrFail($_POST['csrf_token'] ?? null);

        $matchId = (int) ($_POST['match_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'Programme');
        $published = ((string) ($_POST['published'] ?? '0')) === '1';

        if ($matchId <= 0) {
            $messageType = 'error';
            $message = 'Match invalide.';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $messageType = 'error';
            $message = 'Statut invalide.';
        } else {
            $current = fetchMatchById($pdo, $matchId);
            if (!$current) {
                $messageType = 'error';
                $message = 'Match introuvable.';
            } else {
                $updated = updateMatchState(
                    $pdo,
                    $matchId,
                    $status,
                    $current['score_team1'] !== null ? (int) $current['score_team1'] : null,
                    $current['score_team2'] !== null ? (int) $current['score_team2'] : null,
                    $published,
                    (int) ($_SESSION['admin_id'] ?? 0),
                    (string) ($_SESSION['admin_username'] ?? 'admin')
                );

                if ($updated) {
                    $messageType = 'success';
                    $message = 'Statut/visibilite mis a jour.';
                } else {
                    $messageType = 'error';
                    $message = 'Mise a jour impossible.';
                }
            }
        }
    }

    if ($tournamentId > 0) {
        $matches = fetchMatches($pdo, null, false, $tournamentId);
    }
} catch (Throwable $exception) {
    error_log('[Bible_Master] admin/visibilite.php failed: ' . $exception->getMessage());
    $dbError = 'Erreur de base de donnees: impossible de charger ou mettre a jour les matchs.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visibilite des matchs | Bible Master Admin</title>
    <style>
        :root {
            --bg: #0b1220;
            --card: #151e32;
            --line: rgba(255, 255, 255, 0.14);
            --text: #f4f7fb;
            --muted: #b5c3d9;
            --btn: #ffd166;
            --btn-ink: #1f2937;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Manrope", "Segoe UI", sans-serif;
            background: radial-gradient(circle at top left, rgba(255, 209, 102, 0.16), transparent 35%), var(--bg);
            color: var(--text);
            padding: 20px;
        }

        .shell {
            width: min(1200px, 100%);
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .meta { color: var(--muted); font-size: 0.92rem; }

        .row {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            display: grid;
            grid-template-columns: 1.5fr 1fr auto;
            gap: 12px;
            align-items: center;
            margin-top: 10px;
        }

        .row form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            align-items: center;
        }

        select, .toggle {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
        }

        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .btn.primary { background: var(--btn); color: var(--btn-ink); }
        .btn.secondary { background: rgba(255, 255, 255, 0.1); color: var(--text); }

        .alert { border-radius: 10px; padding: 10px 12px; font-size: 0.95rem; }
        .alert.success { border: 1px solid rgba(48, 207, 159, 0.5); background: rgba(48, 207, 159, 0.12); }
        .alert.error { border: 1px solid rgba(255, 143, 143, 0.5); background: rgba(255, 143, 143, 0.12); }

        @media (max-width: 980px) {
            .row { grid-template-columns: 1fr; }
            .row form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="card topbar">
            <div>
                <h1>Visibilite des matchs</h1>
                <p class="meta">Cette page gere seulement le statut et la publication. Le pilotage des epreuves se fait match par match.</p>
                <p class="meta">Tournoi: <?php echo htmlspecialchars((string) ($tournament['name'] ?? 'Non defini'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div>
                <a class="btn secondary" href="/admin/tournament_dashboard.php?tournament_id=<?php echo (int) $tournamentId; ?>">Retour dashboard</a>
                <a class="btn secondary" href="/admin/create_match.php?tournament_id=<?php echo (int) $tournamentId; ?>">Creer un match</a>
            </div>
        </section>

        <?php if ($message !== ''): ?>
            <section class="card alert <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </section>
        <?php endif; ?>

        <?php if ($dbError !== ''): ?>
            <section class="card alert error"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></section>
        <?php endif; ?>

        <section class="card">
            <h2>Matchs</h2>
            <?php if (!$matches): ?>
                <p class="meta">Aucun match disponible.</p>
            <?php endif; ?>

            <?php foreach ($matches as $match): ?>
                <div class="row">
                    <div>
                        <strong><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div class="meta"><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="meta">Score actuel: <?php echo $match['score_team1'] === null ? '-' : (int) $match['score_team1']; ?> - <?php echo $match['score_team2'] === null ? '-' : (int) $match['score_team2']; ?></div>
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="match_id" value="<?php echo (int) $match['id']; ?>">
                        <input type="hidden" name="tournament_id" value="<?php echo (int) $tournamentId; ?>">

                        <select name="status">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $match['status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="toggle">
                            <input type="hidden" name="published" value="0">
                            <input type="checkbox" name="published" value="1" <?php echo ((int) $match['published'] === 1) ? 'checked' : ''; ?>> Publie
                        </label>

                        <button class="btn primary" type="submit">Sauver</button>
                    </form>

                    <div>
                        <a class="btn primary" href="set_score.php?match_id=<?php echo (int) $match['id']; ?>&tournament_id=<?php echo (int) $tournamentId; ?>">Gestion des scores</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>

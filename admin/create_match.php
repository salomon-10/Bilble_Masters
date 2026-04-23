<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth();

$pdo = null;
$teams = [];
$tournament = null;
$tournamentId = (int) ($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$dbError = '';
$messageType = '';
$message = '';
$createdMatch = null;

try {
    $pdo = db();
    $resolved = resolveTournamentId($pdo, $tournamentId > 0 ? $tournamentId : null);
    if ($resolved === null) {
        $dbError = 'Aucun tournoi disponible. Creez un tournoi depuis le dashboard index.';
    } else {
        $tournamentId = $resolved;
        $tournament = fetchTournamentById($pdo, $tournamentId);
        $teams = fetchTeams($pdo, $tournamentId);
    }
} catch (Throwable $exception) {
    error_log('[Bible_Master] admin/create_match.php failed: ' . $exception->getMessage());
    $dbError = 'Connexion impossible a la base de donnees.';
}

$allowedStatuses = ['Programme', 'En cours', 'Termine'];
$allowedPhases = ['Poule', 'Quart', 'Demi', 'Finale'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && $tournamentId > 0) {
    validateCsrfOrFail($_POST['csrf_token'] ?? null);

    $team1 = (int) ($_POST['team1'] ?? 0);
    $team2 = (int) ($_POST['team2'] ?? 0);
    $matchDate = (string) ($_POST['matchDate'] ?? '');
    $matchTime = (string) ($_POST['matchTime'] ?? '');
    $status = (string) ($_POST['status'] ?? 'Programme');
    $phase = (string) ($_POST['phase'] ?? 'Poule');

    if ($team1 <= 0 || $team2 <= 0 || $matchDate === '' || $matchTime === '') {
        $messageType = 'error';
        $message = 'Veuillez completer tous les champs obligatoires.';
    } elseif ($team1 === $team2) {
        $messageType = 'error';
        $message = 'Les deux equipes doivent etre differentes.';
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $messageType = 'error';
        $message = 'Le statut fourni est invalide.';
    } elseif (!in_array($phase, $allowedPhases, true)) {
        $messageType = 'error';
        $message = 'La phase fournie est invalide.';
    } else {
        $newMatchId = createMatch($pdo, $tournamentId, $team1, $team2, $matchDate, $matchTime, $status, $phase);
        if ($newMatchId === null) {
            $messageType = 'error';
            $message = 'Creation du match impossible.';
        } else {
            $createdMatch = fetchMatchById($pdo, $newMatchId);
            $messageType = 'success';
            $message = 'Match cree avec succes.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Creer un match | Bible Master Admin</title>
    <link rel="stylesheet" href="tournois/create_match.css" />
</head>
<body>
    <main class="page">
        <section class="hero">
            <h1>Ajouter un match</h1>
            <p><a class="btn btn-secondary" href="/admin/tournament_dashboard.php?tournament_id=<?php echo (int) $tournamentId; ?>">Retour dashboard</a></p>
            <p style="margin-top:6px;opacity:.85;">Tournoi: <?php echo htmlspecialchars((string) ($tournament['name'] ?? 'Non defini'), ENT_QUOTES, 'UTF-8'); ?></p>
        </section>

        <div class="grid">
            <section class="card form-card">
                <h2 class="section-title">Configuration du match</h2>

                <?php if ($message !== ''): ?>
                    <div class="message <?php echo $messageType === 'success' ? 'success' : 'error'; ?>" style="display:block;" role="alert">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($dbError !== ''): ?>
                    <div class="message error" style="display:block;" role="alert">
                        <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="tournament_id" value="<?php echo (int) $tournamentId; ?>">

                    <div class="field-row">
                        <div class="field-group">
                            <label class="required" for="team1">Selection de l equipe 1</label>
                            <select id="team1" name="team1" required>
                                <option value="">Selectionnez une equipe</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo (int) $team['id']; ?>"><?php echo htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group">
                            <label class="required" for="team2">Selection de l equipe 2</label>
                            <select id="team2" name="team2" required>
                                <option value="">Selectionnez une equipe</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo (int) $team['id']; ?>"><?php echo htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field-group">
                            <label class="required" for="matchDate">Date du match</label>
                            <input id="matchDate" name="matchDate" type="date" required />
                        </div>

                        <div class="field-group">
                            <label class="required" for="matchTime">Heure du match</label>
                            <input id="matchTime" name="matchTime" type="time" required />
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="required">Statut du match</label>
                        <div class="status-options">
                            <div class="radio-pill"><input type="radio" id="statusPending" name="status" value="Programme" checked /><label for="statusPending">Programme</label></div>
                            <div class="radio-pill"><input type="radio" id="statusProgress" name="status" value="En cours" /><label for="statusProgress">En cours</label></div>
                            <div class="radio-pill"><input type="radio" id="statusCompleted" name="status" value="Termine" /><label for="statusCompleted">Termine</label></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="required" for="phase">Phase</label>
                        <select id="phase" name="phase" required>
                            <option value="Poule">Poule</option>
                            <option value="Quart">Quart de finale</option>
                            <option value="Demi">Demi-finale</option>
                            <option value="Finale">Finale</option>
                        </select>
                    </div>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit">Creer le match</button>
                        <a class="btn btn-secondary" href="visibilite.php?tournament_id=<?php echo (int) $tournamentId; ?>">Gerer les scores</a>
                    </div>
                </form>
            </section>

            <aside class="card result-card">
                <h2 class="section-title">Match cree</h2>
                <div class="result-box">
                    <?php if ($createdMatch): ?>
                        <ul class="data-map">
                            <li><span class="key">Equipe 1</span><span class="value"><?php echo htmlspecialchars($createdMatch['team1_name'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <li><span class="key">Equipe 2</span><span class="value"><?php echo htmlspecialchars($createdMatch['team2_name'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <li><span class="key">Date</span><span class="value"><?php echo htmlspecialchars((string) $createdMatch['match_date'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <li><span class="key">Heure</span><span class="value"><?php echo htmlspecialchars(substr((string) $createdMatch['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <li><span class="key">Statut</span><span class="value"><?php echo htmlspecialchars((string) $createdMatch['status'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">Aucun match cree dans cette session.</div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </main>
</body>
</html>

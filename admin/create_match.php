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
    $dbError = publicDatabaseErrorMessage($exception, 'Erreur base de donnees lors du chargement de la page.');
}

$allowedStatuses = ['Programme', 'En cours', 'Termine'];
$allowedPhases = ['Poule', 'Quart', 'Demi', 'PetiteFinale', 'Finale'];
$phaseLabels = [
    'Poule' => 'Poule',
    'Quart' => 'Quart de finale',
    'Demi' => 'Demi-finale',
    'PetiteFinale' => 'Petite finale',
    'Finale' => 'Finale',
];
$hasAdvancedRules = function_exists('areTeamsInSamePool') && function_exists('fetchTournamentQualification');

if ($pdo instanceof PDO && function_exists('supportedMatchPhases')) {
    $resolvedPhases = supportedMatchPhases($pdo);
    if ($resolvedPhases !== []) {
        $allowedPhases = $resolvedPhases;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && $tournamentId > 0) {
    try {
        validateCsrfOrFail($_POST['csrf_token'] ?? null);

        $team1 = (int) ($_POST['team1'] ?? 0);
        $team2 = (int) ($_POST['team2'] ?? 0);
        $matchDate = (string) ($_POST['matchDate'] ?? '');
        $status = (string) ($_POST['status'] ?? 'Programme');
        $phase = (string) ($_POST['phase'] ?? 'Poule');

        if ($team1 <= 0 || $team2 <= 0 || $matchDate === '') {
            $messageType = 'error';
            $message = 'Veuillez completer tous les champs obligatoires.';
        } elseif (!isValidIsoDate($matchDate)) {
            $messageType = 'error';
            $message = 'La date du match est invalide. Utilisez le format YYYY-MM-DD.';
        } elseif ($team1 === $team2) {
            $messageType = 'error';
            $message = 'Les deux equipes doivent etre differentes.';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $messageType = 'error';
            $message = 'Le statut fourni est invalide.';
        } elseif (!in_array($phase, $allowedPhases, true)) {
            $messageType = 'error';
            $message = 'La phase fournie est invalide.';
        } elseif (!matchesColumnSupportsValue($pdo, 'status', $status)) {
            $messageType = 'error';
            $message = 'Schema SQL obsolete: la colonne matches.status ne supporte pas cette valeur. Reimportez database/reinstall_clean.sql.';
        } elseif (!matchesColumnSupportsValue($pdo, 'phase', $phase)) {
            $messageType = 'error';
            $message = 'Schema SQL obsolete: la colonne matches.phase ne supporte pas cette phase. Reimportez database/reinstall_clean.sql.';
        } elseif (!$hasAdvancedRules) {
            $messageType = 'error';
            $message = 'Deploiement incomplet: mettez a jour config/repositories.php sur le serveur.';
        } else {
            if ($phase === 'Poule') {
                $poolCount = function_exists('tournamentPoolCount') ? tournamentPoolCount($pdo, $tournamentId) : 0;
                $legacyNoPoolMode = $poolCount === 0;

                if (!$legacyNoPoolMode && !areTeamsInSamePool($pdo, $tournamentId, $team1, $team2)) {
                    $messageType = 'error';
                    $message = 'En phase Poule, les deux equipes doivent appartenir a la meme poule.';
                }
            } elseif ($phase === 'Demi') {
                $qualification = fetchTournamentQualification($pdo, $tournamentId);
                if (!($qualification['ready'] ?? false)) {
                    $messageType = 'error';
                    $message = 'Les demi-finales ne sont pas disponibles: terminez la phase de poules (6 matchs termines par poule).';
                }
            }

            if ($messageType !== 'error') {
                try {
                    $createError = '';
                    $newMatchId = createMatch($pdo, $tournamentId, $team1, $team2, $matchDate, '00:00:00', $status, $phase, $createError);
                } catch (Throwable $createException) {
                    error_log(
                        '[Bible_Master] createMatch() failed: tournament_id=' . $tournamentId
                        . ', team1=' . $team1
                        . ', team2=' . $team2
                        . ', phase=' . $phase
                        . ', status=' . $status
                        . ' | ' . $createException->getMessage()
                    );
                    $messageType = 'error';
                    $mappedMessage = publicDatabaseErrorMessage($createException, '');
                    $message = $mappedMessage !== ''
                        ? $mappedMessage
                        : 'Erreur applicative pendant la creation du match (CM-500).';
                    $newMatchId = null;
                }

                if ($messageType !== 'error') {
                    if ($newMatchId === null) {
                        $messageType = 'error';
                        $message = $createError !== ''
                            ? $createError
                            : 'Creation du match impossible (regles de phase/poule/qualification non satisfaites).';
                    } else {
                        $messageType = 'success';
                        $message = 'Match cree avec succes.';

                        // Do not turn a successful insert into a global failure if the preview query fails.
                        try {
                            $createdMatch = fetchMatchById($pdo, $newMatchId);
                        } catch (Throwable $previewException) {
                            error_log('[Bible_Master] create_match preview fetch failed for match_id=' . $newMatchId . ': ' . $previewException->getMessage());
                            $createdMatch = null;
                        }
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('[Bible_Master] create_match submit failed: ' . $exception->getMessage());
        $messageType = 'error';
        $mappedMessage = publicDatabaseErrorMessage($exception, '');
        $message = $mappedMessage !== ''
            ? $mappedMessage
            : 'Erreur applicative pendant la creation du match (CM-501).';
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

                    <div class="field-group">
                        <label class="required" for="matchDate">Date du match</label>
                        <input id="matchDate" name="matchDate" type="date" required />
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
                            <?php foreach ($allowedPhases as $phaseValue): ?>
                                <option value="<?php echo htmlspecialchars($phaseValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($phaseLabels[$phaseValue] ?? $phaseValue, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
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
                            <li><span class="key">Phase</span><span class="value"><?php echo htmlspecialchars((string) $createdMatch['phase'], ENT_QUOTES, 'UTF-8'); ?></span></li>
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

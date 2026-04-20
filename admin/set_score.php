<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth();

$matchId = (int) ($_GET['match_id'] ?? $_POST['match_id'] ?? 0);
$message = '';
$messageType = '';
$dbError = '';
$match = null;
$trials = [];
$logs = [];

if ($matchId <= 0) {
    $dbError = 'Match invalide.';
} else {
    try {
        $pdo = db();
        $match = fetchMatchById($pdo, $matchId);

        if (!$match) {
            $dbError = 'Match introuvable.';
        } else {
            $trials = fetchOrInitMatchTrials($pdo, $matchId);

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                validateCsrfOrFail($_POST['csrf_token'] ?? null);
                $action = (string) ($_POST['action'] ?? '');

                if ($action === 'start_match') {
                    updateMatchState(
                        $pdo,
                        $matchId,
                        'En cours',
                        $match['score_team1'] !== null ? (int) $match['score_team1'] : 0,
                        $match['score_team2'] !== null ? (int) $match['score_team2'] : 0,
                        (int) $match['published'] === 1,
                        (int) ($_SESSION['admin_id'] ?? 0),
                        (string) ($_SESSION['admin_username'] ?? 'admin')
                    );
                    $messageType = 'success';
                    $message = 'Match demarre.';
                }

                if ($action === 'end_match') {
                    syncMatchTotalsFromTrials($pdo, $matchId);
                    $updated = fetchMatchById($pdo, $matchId);
                    if (!$updated) {
                        $messageType = 'error';
                        $message = 'Impossible de finaliser ce match.';
                    } else {
                        updateMatchState(
                            $pdo,
                            $matchId,
                            'Termine',
                            (int) ($updated['score_team1'] ?? 0),
                            (int) ($updated['score_team2'] ?? 0),
                            (int) $match['published'] === 1,
                            (int) ($_SESSION['admin_id'] ?? 0),
                            (string) ($_SESSION['admin_username'] ?? 'admin')
                        );
                        $messageType = 'success';
                        $message = 'Match termine.';
                    }
                }

                if ($action === 'save_trial') {
                    $trialOrder = (int) ($_POST['trial_order'] ?? 0);
                    $team1Raw = trim((string) ($_POST['team1_points'] ?? '0'));
                    $team2Raw = trim((string) ($_POST['team2_points'] ?? '0'));

                    if ($trialOrder <= 0 || !preg_match('/^[0-9]+$/', $team1Raw) || !preg_match('/^[0-9]+$/', $team2Raw)) {
                        $messageType = 'error';
                        $message = 'Valeurs d epreuve invalides.';
                    } else {
                        $team1Points = (int) $team1Raw;
                        $team2Points = (int) $team2Raw;

                        if (!updateMatchTrial($pdo, $matchId, $trialOrder, $team1Points, $team2Points)) {
                            $messageType = 'error';
                            $message = 'Epreuve introuvable ou valeurs invalides.';
                        } else {
                            syncMatchTotalsFromTrials($pdo, $matchId);
                            $messageType = 'success';
                            $message = 'Epreuve enregistree.';
                        }
                    }
                }
            }

            $match = fetchMatchById($pdo, $matchId);
            $trials = fetchOrInitMatchTrials($pdo, $matchId);
            $logs = fetchMatchChangeLogsForMatch($pdo, $matchId, 120);
        }
    } catch (Throwable $exception) {
        error_log('[Bible_Master] set_score.php failed for match_id=' . $matchId . ': ' . $exception->getMessage());
        $dbError = 'Erreur de base de donnees lors du chargement du match.';
    }
}

$teamAName = $match ? (string) $match['team1_name'] : 'Equipe A';
$teamBName = $match ? (string) $match['team2_name'] : 'Equipe B';
$scoreA = $match && $match['score_team1'] !== null ? (int) $match['score_team1'] : 0;
$scoreB = $match && $match['score_team2'] !== null ? (int) $match['score_team2'] : 0;
$status = $match ? (string) $match['status'] : 'Programme';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pilotage match | Bible Master</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --bg-main: #0c1422;
    --bg-soft: #111c30;
    --bg-card: #17233a;
    --line: rgba(255, 255, 255, 0.16);
    --text-main: #f7f9fc;
    --text-soft: #b9c6da;
    --team-a: #ffd166;
    --team-b: #6ee7ff;
    --ok: #22c55e;
    --warn: #ef4444;

    --shadow: 0 22px 56px rgba(0, 0, 0, 0.36);
    --r-xl: 24px;
    --r-lg: 16px;
    --r-md: 12px;
}

body {
    min-height: 100vh;
    font-family: "Manrope", sans-serif;
    color: var(--text-main);
    position: relative;
    isolation: isolate;
    background:
        radial-gradient(circle at 8% 12%, rgba(255, 209, 102, 0.14), transparent 30%),
        radial-gradient(circle at 88% 10%, rgba(110, 231, 255, 0.12), transparent 28%),
        linear-gradient(180deg, rgba(5, 8, 16, 0.54), rgba(5, 8, 16, 0.84));
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    z-index: -2;
    background:
        linear-gradient(180deg, rgba(6, 10, 18, 0.42), rgba(6, 10, 18, 0.74)),
        url("../img/background.png") center center / cover no-repeat;
    transform: scale(1.02);
}

body::after {
    content: "";
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background:
        radial-gradient(circle at 20% 16%, rgba(255, 255, 255, 0.08), transparent 24%),
        radial-gradient(circle at 82% 12%, rgba(255, 255, 255, 0.06), transparent 22%),
        linear-gradient(180deg, rgba(0, 0, 0, 0.12), rgba(0, 0, 0, 0.24));
}

.page {
    width: min(1200px, 94vw);
    margin: 24px auto 36px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 18px;
}

.panel {
    background: linear-gradient(180deg, rgba(23, 35, 58, 0.86) 0%, rgba(17, 28, 48, 0.9) 100%);
    border: 1px solid rgba(199, 210, 227, 0.2);
    border-radius: var(--r-xl);
    box-shadow: var(--shadow);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    gap: 10px;
}

.live {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #ffd8b8;
    font-weight: 800;
}

.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #ff8751;
    box-shadow: 0 0 0 0 rgba(255, 135, 81, 0.5);
    animation: pulse 1.4s infinite;
}

@keyframes pulse {
    70% {
        box-shadow: 0 0 0 8px rgba(255, 135, 81, 0);
    }

    100% {
        box-shadow: 0 0 0 0 rgba(255, 135, 81, 0);
    }
}

.controls {
    display: flex;
    gap: 10px;
}

.action {
    border: 1px solid transparent;
    color: var(--text-main);
    background: rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    padding: 9px 12px;
    cursor: pointer;
    transition: 0.2s ease;
}

.action:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.action.start {
    border-color: rgba(34, 197, 94, 0.45);
    color: #c9f4d8;
}

.action.start:hover:not(:disabled) {
    background: rgba(34, 197, 94, 0.2);
}

.action.end {
    border-color: rgba(239, 68, 68, 0.45);
    color: #ffd2d2;
}

.action.end:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.2);
}

.scoreboard {
    padding: 20px 22px 22px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    border-top: 1px solid rgba(199, 210, 227, 0.17);
}

.team {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.team.right {
    text-align: right;
    align-items: flex-end;
}

.team-meta {
    display: flex;
    align-items: center;
    gap: 10px;
}

.team.right .team-meta {
    flex-direction: row-reverse;
}

.badge-color {
    width: 12px;
    height: 42px;
    border-radius: 999px;
}

.team-name {
    font-family: "Sora", sans-serif;
    font-weight: 700;
    font-size: clamp(16px, 1.8vw, 21px);
    letter-spacing: 0.2px;
}

.team-sub {
    font-size: 12px;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 1.1px;
}

.score-main {
    text-align: center;
}

.score-main .numbers {
    font-family: "Sora", sans-serif;
    font-weight: 800;
    font-size: clamp(42px, 6vw, 86px);
    line-height: 0.95;
    letter-spacing: -2px;
}

.score-main .numbers .a {
    color: var(--team-a);
}

.score-main .numbers .b {
    color: var(--team-b);
}

.vs {
    margin-top: 8px;
    font-size: 12px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--text-soft);
    font-weight: 700;
}

.sections {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 18px;
}

.card {
    padding: 18px;
}

.card h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1.3px;
    color: var(--text-soft);
    margin-bottom: 14px;
}

.trials {
    display: grid;
    gap: 12px;
}

.trial {
    border: 1px solid rgba(199, 210, 227, 0.16);
    border-radius: var(--r-lg);
    background: linear-gradient(180deg, rgba(31, 42, 68, 0.5) 0%, rgba(31, 42, 68, 0.22) 100%);
    overflow: hidden;
}

.trial-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid rgba(199, 210, 227, 0.13);
}

.trial-title {
    font-size: 15px;
    font-weight: 700;
}

.trial-badge {
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 999px;
    color: #1f2937;
    background: rgba(255, 209, 102, 0.88);
    border: 1px solid rgba(255, 209, 102, 0.45);
}

.trial-body {
    padding: 12px 14px 14px;
    display: grid;
    gap: 12px;
}

.rows {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.row {
    border: 1px solid rgba(199, 210, 227, 0.16);
    border-radius: var(--r-md);
    background: rgba(31, 42, 68, 0.52);
    padding: 9px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 7px;
}

.row .value {
    text-align: center;
}

.row .value input {
    width: 100%;
    text-align: center;
    font-family: "Sora", sans-serif;
    font-size: 24px;
    font-weight: 700;
    border: 0;
    outline: none;
    background: transparent;
}

.row.a .value input {
    color: var(--team-a);
}

.row.b .value input {
    color: #f0b7cc;
}

.btn {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 1px solid rgba(199, 210, 227, 0.22);
    background: rgba(255, 255, 255, 0.07);
    color: var(--text-main);
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    transition: 0.2s ease;
}

.btn:hover:not(:disabled) {
    border-color: rgba(199, 210, 227, 0.42);
    transform: translateY(-1px);
}

.btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.save {
    border: 1px solid rgba(255, 209, 102, 0.45);
    color: #1f2937;
    background: rgba(255, 209, 102, 0.86);
    border-radius: 10px;
    padding: 9px 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.2s ease;
}

.save:hover:not(:disabled) {
    background: rgba(255, 209, 102, 0.98);
}

.save:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.log {
    min-height: 100%;
}

.log-list {
    border: 1px solid rgba(199, 210, 227, 0.15);
    border-radius: var(--r-lg);
    background: rgba(31, 42, 68, 0.4);
    min-height: 350px;
    max-height: 520px;
    overflow: auto;
}

.log-item {
    padding: 12px 13px;
    border-bottom: 1px solid rgba(199, 210, 227, 0.12);
    font-size: 13px;
    color: var(--text-soft);
}

.log-item strong {
    color: var(--text-main);
}

.log-item:last-child {
    border-bottom: 0;
}

.message {
    margin-top: 12px;
    font-size: 12px;
    border-radius: 10px;
    padding: 10px;
}

.message.ok {
    color: #cbf8e1;
    border: 1px solid rgba(34, 197, 94, 0.33);
    background: rgba(34, 197, 94, 0.14);
}

.message.error {
    color: #ffd4d4;
    border: 1px solid rgba(239, 68, 68, 0.33);
    background: rgba(239, 68, 68, 0.14);
}

.toast {
    position: fixed;
    bottom: 18px;
    left: 50%;
    transform: translate(-50%, 16px);
    opacity: 0;
    pointer-events: none;
    background: rgba(17, 28, 48, 0.94);
    color: #ecf3ff;
    border: 1px solid rgba(110, 231, 255, 0.4);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 700;
    transition: 0.25s ease;
    z-index: 99;
}

.toast.show {
    transform: translate(-50%, 0);
    opacity: 1;
}

.back {
    display: inline-flex;
    text-decoration: none;
    color: #e6eefc;
    font-size: 13px;
    padding: 7px 11px;
    border: 1px solid rgba(199, 210, 227, 0.2);
    border-radius: 10px;
    width: fit-content;
}

@media (max-width: 980px) {
    .sections {
        grid-template-columns: 1fr;
    }

    .log-list {
        min-height: 220px;
    }
}

@media (max-width: 760px) {
    .topbar {
        flex-wrap: wrap;
    }

    .controls {
        width: 100%;
    }

    .action {
        flex: 1;
    }

    .scoreboard {
        grid-template-columns: 1fr;
        text-align: center;
    }

    .team,
    .team.right {
        align-items: center;
        text-align: center;
    }

    .team-meta,
    .team.right .team-meta {
        flex-direction: row;
    }

    .badge-color {
        width: 38px;
        height: 10px;
    }

    .rows {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="page">
    <a class="back" href="visibilite.php">Retour visibilite</a>

    <?php if ($dbError !== ''): ?>
        <div class="message error"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="message <?php echo $messageType === 'success' ? 'ok' : 'error'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($match): ?>
    <section class="panel">
        <div class="topbar">
            <?php $isLive = $status === 'En cours'; ?>
            <div class="live">
                <?php if ($isLive): ?><span class="dot"></span><?php endif; ?>
                <?php echo $isLive ? 'LIVE MATCH' : ('STATUT: ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')); ?>
            </div>
            <div class="controls">
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="match_id" value="<?php echo (int) $matchId; ?>">
                    <input type="hidden" name="action" value="start_match">
                    <button class="action start" type="submit" <?php echo $status === 'En cours' || $status === 'Termine' ? 'disabled' : ''; ?>>Demarrer</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="match_id" value="<?php echo (int) $matchId; ?>">
                    <input type="hidden" name="action" value="end_match">
                    <button class="action end" type="submit" <?php echo $status !== 'En cours' ? 'disabled' : ''; ?> onclick="return confirm('Terminer le match ?');">Terminer</button>
                </form>
            </div>
        </div>

        <div class="scoreboard">
            <div class="team">
                <div class="team-sub">Equipe A</div>
                <div class="team-meta">
                    <div class="badge-color" style="background: var(--team-a)"></div>
                    <div class="team-name"><?php echo htmlspecialchars($teamAName, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            <div class="score-main">
                <div class="numbers"><span class="a" id="aScore"><?php echo $scoreA; ?></span> : <span class="b" id="bScore"><?php echo $scoreB; ?></span></div>
                <div class="vs"><?php echo $status === 'En cours' ? 'Match en cours' : 'Match pilote'; ?></div>
            </div>
            <div class="team right">
                <div class="team-sub">Equipe B</div>
                <div class="team-meta">
                    <div class="badge-color" style="background: var(--team-b)"></div>
                    <div class="team-name"><?php echo htmlspecialchars($teamBName, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="sections">
        <article class="panel card">
            <h2>Epreuves</h2>
            <div class="trials">
                <?php foreach ($trials as $trial): ?>
                    <div class="trial">
                        <div class="trial-head">
                            <div class="trial-title"><?php echo htmlspecialchars((string) $trial['trial_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="trial-badge">Ordre <?php echo (int) $trial['trial_order']; ?></div>
                        </div>
                        <div class="trial-body">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="match_id" value="<?php echo (int) $matchId; ?>">
                                <input type="hidden" name="action" value="save_trial">
                                <input type="hidden" name="trial_order" value="<?php echo (int) $trial['trial_order']; ?>">
                                <div class="rows">
                                    <div class="row a">
                                        <button class="btn" type="button" onclick="step(this, -10)">-</button>
                                        <div class="value"><input type="number" name="team1_points" value="<?php echo (int) $trial['team1_points']; ?>" min="0" step="10" required></div>
                                        <button class="btn" type="button" onclick="step(this, 10)">+</button>
                                    </div>
                                    <div class="row b">
                                        <button class="btn" type="button" onclick="step(this, -10)">-</button>
                                        <div class="value"><input type="number" name="team2_points" value="<?php echo (int) $trial['team2_points']; ?>" min="0" step="10" required></div>
                                        <button class="btn" type="button" onclick="step(this, 10)">+</button>
                                    </div>
                                </div>
                                <button class="save" type="submit">Valider l epreuve</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <aside class="panel card log">
            <h2>Journal du match</h2>
            <div class="log-list">
                <?php if (!$logs): ?>
                    <div class="log-item">Aucune action sur ce match.</div>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-item">
                        <strong><?php echo htmlspecialchars((string) $log['admin_username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        (<?php echo htmlspecialchars((string) $log['action'], ENT_QUOTES, 'UTF-8'); ?>)<br>
                        <?php echo htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                        | Score: <?php echo $log['new_score_team1'] === null ? '-' : (int) $log['new_score_team1']; ?> - <?php echo $log['new_score_team2'] === null ? '-' : (int) $log['new_score_team2']; ?>
                        | Statut: <?php echo htmlspecialchars((string) ($log['new_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </section>
    <?php endif; ?>
</div>
<div class="toast" id="toast">Score enregistre</div>
<script>
function step(button, delta) {
    const row = button.closest('.row');
    const input = row.querySelector('input[type="number"]');
    const current = Number(input.value || 0);
    const next = Math.max(0, current + delta);
    input.value = next;
    updateTotalPreview();
}

function updateTotalPreview() {
    let totalA = 0;
    let totalB = 0;

    document.querySelectorAll('input[name="team1_points"]').forEach((input) => {
        totalA += Number(input.value || 0);
    });

    document.querySelectorAll('input[name="team2_points"]').forEach((input) => {
        totalB += Number(input.value || 0);
    });

    const aScore = document.getElementById('aScore');
    const bScore = document.getElementById('bScore');
    if (aScore && bScore) {
        aScore.textContent = String(totalA);
        bScore.textContent = String(totalB);
    }
}

function showToast(text) {
    const toast = document.getElementById('toast');
    if (!toast) {
        return;
    }
    toast.textContent = text;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 2000);
}

document.querySelectorAll('input[type="number"]').forEach((input) => {
    input.addEventListener('input', updateTotalPreview);
    input.addEventListener('blur', () => {
        if (Number(input.value) < 0 || Number.isNaN(Number(input.value))) {
            input.value = '0';
        }
        updateTotalPreview();
    });
});

<?php if ($messageType === 'success' && $message !== ''): ?>
showToast('<?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>');
<?php endif; ?>
</script>
</body>
</html>

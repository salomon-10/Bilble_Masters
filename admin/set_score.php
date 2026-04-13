<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth();

$pdo = null;
$dbError = '';
$message = '';
$messageClass = '';
$allowedStatuses = ['Programme', 'En cours', 'Termine'];

try {
    $pdo = db();
} catch (Throwable $exception) {
    $dbError = 'Connexion impossible a la base de donnees. Verifiez vos parametres InfinityFree.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    validateCsrfOrFail($_POST['csrf_token'] ?? null);

    $matchId = (int) ($_POST['match_id'] ?? 0);
    $scoreA = filter_var($_POST['score_team1'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $scoreB = filter_var($_POST['score_team2'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $status = (string) ($_POST['status'] ?? 'Programme');
    $published = isset($_POST['published']) ? 1 : 0;

    if ($matchId <= 0 || $scoreA === false || $scoreB === false || !in_array($status, $allowedStatuses, true)) {
        $message = 'Donnees invalides. Mise a jour annulee.';
        $messageClass = 'error';
    } else {
        $scoreAValue = $status === 'Programme' ? null : (int) $scoreA;
        $scoreBValue = $status === 'Programme' ? null : (int) $scoreB;

        $stmt = $pdo->prepare(
            'UPDATE matches
             SET score_team1 = :score_team1,
                 score_team2 = :score_team2,
                 status = :status,
                 published = :published
             WHERE id = :id'
        );

        $stmt->bindValue(':score_team1', $scoreAValue, $scoreAValue === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':score_team2', $scoreBValue, $scoreBValue === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':published', $published, PDO::PARAM_INT);
        $stmt->bindValue(':id', $matchId, PDO::PARAM_INT);
        $stmt->execute();

        $message = 'Score/statut publies avec succes.';
        $messageClass = 'success';
    }
}

$matches = $pdo instanceof PDO ? fetchMatches($pdo) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scoring Match</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="set_Score.css">
<style>
.editor { margin-top: 16px; display: grid; gap: 12px; }
.editor-card { background: #101923; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 14px; }
.editor-card h3 { margin-bottom: 8px; }
.field-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; align-items: end; }
.field-grid label { display: grid; gap: 6px; font-size: 12px; }
.field-grid input, .field-grid select { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid rgba(255,255,255,.18); background: #1e2028; color: #e8e9ee; }
.msg.success { color: #4ade80; }
.msg.error { color: #fca5a5; }
@media (max-width: 960px) { .field-grid { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="topbar">
    <div class="live"><span class="dot"></span> LIVE MATCH</div>
    <a class="btn-start" href="dashboard.php">Dashboard</a>
    <a class="btn-end" href="logout.php">Deconnexion</a>
</div>

<div class="page">
    <div class="section-title">Gestion des scores</div>
    <?php if ($dbError !== ''): ?>
        <p class="msg error"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <p class="msg <?php echo $messageClass; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="editor">
    <?php if (!$matches): ?>
        <div class="editor-card">Aucun match disponible. Creez un match depuis la page de creation.</div>
    <?php endif; ?>

    <?php foreach ($matches as $match): ?>
        <form method="post" class="editor-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="match_id" value="<?php echo (int) $match['id']; ?>">
            <h3><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <div class="field-grid">
                <label>Score equipe 1
                    <input type="number" min="0" name="score_team1" value="<?php echo $match['score_team1'] === null ? 0 : (int) $match['score_team1']; ?>" required>
                </label>
                <label>Score equipe 2
                    <input type="number" min="0" name="score_team2" value="<?php echo $match['score_team2'] === null ? 0 : (int) $match['score_team2']; ?>" required>
                </label>
                <label>Statut
                    <select name="status">
                        <option value="Programme" <?php echo $match['status'] === 'Programme' ? 'selected' : ''; ?>>Programme</option>
                        <option value="En cours" <?php echo $match['status'] === 'En cours' ? 'selected' : ''; ?>>En cours</option>
                        <option value="Termine" <?php echo $match['status'] === 'Termine' ? 'selected' : ''; ?>>Termine</option>
                    </select>
                </label>
                <label>Visible sur user
                    <input type="checkbox" name="published" value="1" <?php echo (int) $match['published'] === 1 ? 'checked' : ''; ?>>
                </label>
                <button class="save" type="submit">Enregistrer</button>
            </div>
        </form>
    <?php endforeach; ?>
    </div>
</div>
</body>
</html>

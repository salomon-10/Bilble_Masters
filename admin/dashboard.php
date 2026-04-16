<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth();

$dbError = '';
$stats = [
    'Programme' => 0,
    'En cours' => 0,
    'Termine' => 0,
];
$recentMatches = [];

try {
    $pdo = db();
    $stats = countMatchesByStatus($pdo);
    $recentMatches = fetchMatches($pdo);
} catch (Throwable $exception) {
    $dbError = 'Connexion impossible a la base de donnees. Verifiez vos parametres InfinityFree.';
}

$totalMatches = $stats['Programme'] + $stats['En cours'] + $stats['Termine'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin | Bible Master</title>
<link rel="stylesheet" href="dashboard.css">
</head>
<body>
<main class="dashboard">
<section class="hero">
    <h1>Bienvenue <?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="badge-live"><span class="dot"></span>Session active</div>
</section>

<section class="stats">
    <div class="stat"><div class="k">Matchs</div><div class="v"><?php echo $totalMatches; ?></div></div>
    <div class="stat"><div class="k">En cours</div><div class="v"><?php echo $stats['En cours']; ?></div></div>
    <div class="stat"><div class="k">Programmes</div><div class="v"><?php echo $stats['Programme']; ?></div></div>
</section>

<section class="cards">
    <div class="card">
        <h3>Nouveau match</h3>
        <p>Creer et publier un match</p>
        <a class="btn primary" href="create_match.php">Creer</a>
    </div>
    <div class="card">
        <h3>Scores et publication</h3>
        <p>Modifier score, statut et visibilite</p>
        <a class="btn primary" href="visibilite.html">Gerer</a>
    </div>
</section>

<section>
<?php if ($dbError !== ''): ?>
    <div class="match"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if (!$recentMatches): ?>
    <div class="match">
        <div>Aucun match enregistre. Creez votre premier match.</div>
        <a class="btn primary" href="create_match.php">Commencer</a>
    </div>
<?php endif; ?>

<?php foreach (array_slice($recentMatches, 0, 8) as $match): ?>
    <div class="match">
        <div class="teams">
            <div class="team"><img src="https://via.placeholder.com/80" alt="Equipe A"><div><?php echo htmlspecialchars($match['team1_name'], ENT_QUOTES, 'UTF-8'); ?></div></div>
            VS
            <div class="team"><img src="https://via.placeholder.com/80" alt="Equipe B"><div><?php echo htmlspecialchars($match['team2_name'], ENT_QUOTES, 'UTF-8'); ?></div></div>
        </div>
        <div>
            <div><?php echo htmlspecialchars((string) $match['match_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string) $match['match_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></div>
            <span class="status <?php echo $match['status'] === 'En cours' ? 'live' : 'pending'; ?>">
                <?php echo htmlspecialchars((string) $match['status'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <div><?php echo ((int) $match['published'] === 1) ? 'Visible user: Oui' : 'Visible user: Non'; ?></div>
        </div>
        <a class="btn primary" href="visibilite.html">Gerer</a>
    </div>
<?php endforeach; ?>
</section>

<p><a class="btn secondary" href="logout.php">Deconnexion</a></p>
</main>

<script>
window.addEventListener('load', () => {
    document.body.classList.add('loaded');
});
</script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../config/repositories.php';

requireAdminAuth('admin');

$dbError = '';
$message = '';
$messageType = 'success';
$tournaments = [];
$selectedTournamentId = 0;
$selectedTournament = null;
$teams = [];
$unassignedTeams = [];
$pools = [];
$poolGroups = [];

function normalizeUploadError(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_OK => '',
        UPLOAD_ERR_NO_FILE => '',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux (max 2MB recommande).',
        default => 'Upload du logo impossible.',
    };
}

function readUploadedTeamLogo(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return null;
    }

    $maxSize = 2 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxSize) {
        return null;
    }

    $mime = (string) (mime_content_type($tmpName) ?: '');
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        return null;
    }

    $extension = $allowed[$mime];
    $blob = file_get_contents($tmpName);
    if (!is_string($blob) || $blob === '') {
        error_log('[Bible_Master] upload failed: unable to read uploaded logo binary');
        return null;
    }

    return [
        'mime' => $mime,
        'extension' => $extension,
        'blob' => $blob,
    ];
}

try {
    $pdo = db();
    ensureTournamentSchema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfOrFail($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_tournament') {
            $name = trim((string) ($_POST['tournament_name'] ?? ''));
            if ($name === '') {
                $messageType = 'error';
                $message = 'Le nom du tournoi est obligatoire.';
            } else {
                $createdId = createTournament($pdo, $name);
                if ($createdId === null) {
                    $messageType = 'error';
                    $message = 'Creation du tournoi impossible.';
                } else {
                    $selectedTournamentId = $createdId;
                    $messageType = 'success';
                    $message = 'Tournoi cree avec succes.';
                }
            }
        }

        if ($action === 'delete_tournament') {
            $targetTournamentId = (int) ($_POST['tournament_id'] ?? 0);
            if ($targetTournamentId <= 0) {
                $messageType = 'error';
                $message = 'Tournoi invalide.';
            } elseif (!deleteTournament($pdo, $targetTournamentId)) {
                $messageType = 'error';
                $message = 'Suppression du tournoi impossible.';
            } else {
                $selectedTournamentId = 0;
                $messageType = 'success';
                $message = 'Tournoi supprime avec succes.';
            }
        }

        if ($action === 'create_team') {
            $selectedTournamentId = (int) ($_POST['selected_tournament_id'] ?? 0);
            $teamName = trim((string) ($_POST['team_name'] ?? ''));
            $file = $_FILES['team_logo'] ?? [];
            $uploadErrorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadError = normalizeUploadError($uploadErrorCode);
            $uploadRequested = $uploadErrorCode !== UPLOAD_ERR_NO_FILE;

            if ($selectedTournamentId <= 0) {
                $messageType = 'error';
                $message = 'Selectionnez d abord un tournoi.';
            } elseif ($teamName === '') {
                $messageType = 'error';
                $message = 'Le nom de l equipe est obligatoire.';
            } elseif ($uploadError !== '') {
                $messageType = 'error';
                $message = $uploadError;
            } else {
                $logoPath = null;
                $logoMime = null;
                $logoBlob = null;
                $logoData = readUploadedTeamLogo($file);

                if ($uploadRequested && $logoData === null) {
                    $messageType = 'error';
                    $message = 'Le logo n a pas pu etre enregistre en base. Verifie le format et la taille, puis reessaie.';
                    error_log('[Bible_Master] create_team: upload requested but readUploadedTeamLogo returned null');
                }

                if ($logoData !== null) {
                    $logoMime = (string) ($logoData['mime'] ?? '');
                    $logoBlob = (string) ($logoData['blob'] ?? '');
                }

                if ($uploadRequested && $logoData === null) {
                    // Stop here to avoid silently creating team with default logo.
                    $createdTeamId = null;
                } else {
                $createdTeamId = createTeam($pdo, $selectedTournamentId, $teamName, $logoPath, $logoBlob, $logoMime);
                }
                if ($createdTeamId === null) {
                    if (!$uploadRequested || $logoData !== null) {
                        $messageType = 'error';
                        $message = 'Creation equipe impossible (nom deja utilise?).';
                    }
                } else {
                    $messageType = 'success';
                    $message = 'Equipe creee avec succes.';
                }
            }
        }

        if ($action === 'delete_team') {
            $selectedTournamentId = (int) ($_POST['selected_tournament_id'] ?? 0);
            $teamId = (int) ($_POST['team_id'] ?? 0);

            if ($selectedTournamentId <= 0 || $teamId <= 0) {
                $messageType = 'error';
                $message = 'Equipe invalide.';
            } elseif (!deleteTeam($pdo, $selectedTournamentId, $teamId)) {
                $messageType = 'error';
                $message = 'Suppression equipe impossible.';
            } else {
                $messageType = 'success';
                $message = 'Equipe supprimee avec succes.';
            }
        }

        if ($action === 'create_pool') {
            $selectedTournamentId = (int) ($_POST['selected_tournament_id'] ?? 0);
            $poolName = trim((string) ($_POST['pool_name'] ?? ''));

            if ($selectedTournamentId <= 0 || $poolName === '') {
                $messageType = 'error';
                $message = 'Selection tournoi et nom de poule requis.';
            } else {
                $createdPoolId = createPool($pdo, $selectedTournamentId, $poolName);
                if ($createdPoolId === null) {
                    $messageType = 'error';
                    $message = 'Creation poule impossible.';
                } else {
                    $messageType = 'success';
                    $message = 'Poule creee avec succes.';
                }
            }
        }

        if ($action === 'attach_team_pool') {
            $selectedTournamentId = (int) ($_POST['selected_tournament_id'] ?? 0);
            $poolId = (int) ($_POST['pool_id'] ?? 0);
            $teamId = (int) ($_POST['team_id'] ?? 0);

            if ($selectedTournamentId <= 0 || $poolId <= 0 || $teamId <= 0) {
                $messageType = 'error';
                $message = 'Selection de poule/equipe invalide.';
            } elseif (!attachTeamToPool($pdo, $poolId, $teamId)) {
                $messageType = 'error';
                $message = 'Affectation impossible: une equipe ne peut appartenir qu a une seule poule.';
            } else {
                $messageType = 'success';
                $message = 'Equipe affectee a la poule.';
            }
        }
    }

    $requested = (int) ($_GET['tournament_id'] ?? $_POST['selected_tournament_id'] ?? $selectedTournamentId);
    $resolved = resolveTournamentId($pdo, $requested > 0 ? $requested : null);
    $selectedTournamentId = $resolved ?? 0;

    $tournaments = fetchTournaments($pdo);

    if ($selectedTournamentId > 0) {
        $selectedTournament = fetchTournamentById($pdo, $selectedTournamentId);
        $teams = fetchTeams($pdo, $selectedTournamentId);
        $unassignedTeams = fetchUnassignedTeams($pdo, $selectedTournamentId);
        $pools = fetchPools($pdo, $selectedTournamentId);
        $poolGroups = fetchTeamsGroupedByPool($pdo, $selectedTournamentId);
    }
} catch (Throwable $exception) {
    error_log('[Bible_Master] admin/dashboard.php failed: ' . $exception->getMessage());
    $dbError = publicDatabaseErrorMessage($exception, 'Erreur base de donnees: impossible de charger le dashboard index.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Index Admin | Bible Master</title>
    <style>
        :root {
            --bg: #0e1117;
            --panel: #1a2333;
            --line: rgba(255,255,255,.14);
            --text: #f8fafc;
            --muted: #b8c2d8;
            --accent: #f59e0b;
            --ok: #22c55e;
            --bad: #ef4444;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top right, rgba(245,158,11,.16), transparent 30%), var(--bg);
            padding: 18px;
        }

        .shell {
            width: min(1220px, 100%);
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .card {
            background: var(--panel);
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

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            gap: 12px;
        }

        .grid3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 10px;
        }

        .field {
            display: grid;
            gap: 7px;
            margin-bottom: 10px;
        }

        input, select, button {
            border-radius: 10px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.06);
            color: var(--text);
            padding: 10px;
        }

        button, .btn {
            background: var(--accent);
            color: #111827;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
        }

        .btn.secondary {
            background: rgba(255,255,255,.12);
            color: var(--text);
            border: 1px solid var(--line);
        }

        .muted {
            color: var(--muted);
            font-size: .92rem;
        }

        .alert {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: .95rem;
        }

        .alert.success { border: 1px solid rgba(34,197,94,.45); background: rgba(34,197,94,.12); }
        .alert.error { border: 1px solid rgba(239,68,68,.45); background: rgba(239,68,68,.12); }

        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 6px 10px;
            font-size: .82rem;
            color: var(--muted);
        }

        .teams-list, .pools-list { display: grid; gap: 8px; }

        .team-row {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px 10px;
        }

        .team-row img {
            width: 36px;
            height: 36px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid rgba(245,158,11,.4);
        }

        .pool-block {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
        }

        @media (max-width: 980px) {
            .grid { grid-template-columns: 1fr; }
            .grid3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main class="shell">
    <section class="card topbar">
        <div>
            <h1>Dashboard Index Admin</h1>
            <p class="muted">Creer des tournois, equipes, logos et poules. Puis gerer chaque tournoi dans son dashboard dedie.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($selectedTournamentId > 0): ?>
                <a class="btn secondary" href="tournament_dashboard.php?tournament_id=<?php echo (int) $selectedTournamentId; ?>">Ouvrir dashboard tournoi</a>
            <?php endif; ?>
            <a class="btn secondary" href="logout.php">Deconnexion</a>
        </div>
    </section>

    <?php if ($dbError !== ''): ?>
        <section class="card alert error"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></section>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <section class="card alert <?php echo $messageType === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></section>
    <?php endif; ?>

    <section class="card">
        <h2>Tournois</h2>
        <div class="chips">
            <?php if (!$tournaments): ?>
                <span class="chip">Aucun tournoi</span>
            <?php endif; ?>
            <?php foreach ($tournaments as $t): ?>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a class="chip" href="dashboard.php?tournament_id=<?php echo (int) $t['id']; ?>">
                        <?php echo htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo (int) $t['teams_count']; ?> equipes)
                    </a>
                    <form method="post" onsubmit="return confirm('Supprimer ce tournoi et toutes ses donnees ?');" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete_tournament">
                        <input type="hidden" name="tournament_id" value="<?php echo (int) $t['id']; ?>">
                        <button type="submit" class="btn secondary" style="padding:6px 10px;">Supprimer</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_tournament">
            <div class="grid3">
                <div class="field">
                    <label for="tournament_name">Nom du tournoi</label>
                    <input id="tournament_name" name="tournament_name" required placeholder="Ex: Bible Master 2026">
                </div>
                <div class="field" style="align-self:end;">
                    <button type="submit">Creer tournoi</button>
                </div>
            </div>
        </form>
    </section>

    <section class="grid">
        <article class="card">
            <h2>Equipes du tournoi selectionne</h2>
            <p class="muted">Tournoi actif: <?php echo htmlspecialchars((string) ($selectedTournament['name'] ?? 'Aucun'), ENT_QUOTES, 'UTF-8'); ?></p>

            <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_team">
                <input type="hidden" name="selected_tournament_id" value="<?php echo (int) $selectedTournamentId; ?>">

                <div class="field">
                    <label for="team_name">Nom de l equipe</label>
                    <input id="team_name" name="team_name" required>
                </div>

                <div class="field">
                    <label for="team_logo">Logo (png/jpg/webp)</label>
                    <input id="team_logo" name="team_logo" type="file" accept="image/png,image/jpeg,image/webp">
                </div>

                <button type="submit">Ajouter equipe</button>
            </form>

            <div class="teams-list" style="margin-top:12px;">
                <?php if (!$teams): ?>
                    <div class="muted">Aucune equipe pour ce tournoi.</div>
                <?php endif; ?>
                <?php foreach ($teams as $team): ?>
                    <div class="team-row">
                        <img src="<?php echo htmlspecialchars((string) $team['logo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo equipe">
                        <span><?php echo htmlspecialchars((string) $team['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <form method="post" onsubmit="return confirm('Supprimer cette equipe ?');" style="margin-left:auto;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="selected_tournament_id" value="<?php echo (int) $selectedTournamentId; ?>">
                            <input type="hidden" name="team_id" value="<?php echo (int) $team['id']; ?>">
                            <button type="submit" class="btn secondary" style="padding:6px 10px;">Supprimer</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card">
            <h2>Poules</h2>
            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_pool">
                <input type="hidden" name="selected_tournament_id" value="<?php echo (int) $selectedTournamentId; ?>">

                <div class="field">
                    <label for="pool_name">Nom de la poule (A, B...)</label>
                    <input id="pool_name" name="pool_name" required>
                </div>

                <button type="submit">Creer poule</button>
            </form>

            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="attach_team_pool">
                <input type="hidden" name="selected_tournament_id" value="<?php echo (int) $selectedTournamentId; ?>">

                <div class="field">
                    <label for="pool_id">Poule</label>
                    <select id="pool_id" name="pool_id" required>
                        <option value="">Selectionner</option>
                        <?php foreach ($pools as $pool): ?>
                            <option value="<?php echo (int) $pool['id']; ?>"><?php echo htmlspecialchars((string) $pool['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="team_id">Equipe</label>
                    <select id="team_id" name="team_id" required <?php echo !$unassignedTeams ? 'disabled' : ''; ?>>
                        <option value="">Selectionner</option>
                        <?php foreach ($unassignedTeams as $team): ?>
                            <option value="<?php echo (int) $team['id']; ?>"><?php echo htmlspecialchars((string) $team['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" <?php echo !$unassignedTeams ? 'disabled' : ''; ?>>Affecter equipe</button>
                <?php if (!$unassignedTeams): ?>
                    <div class="muted">Toutes les equipes sont deja affectees a une poule.</div>
                <?php endif; ?>
            </form>

            <div class="pools-list" style="margin-top:12px;">
                <?php if (!$poolGroups): ?>
                    <div class="muted">Aucune poule ou aucune equipe affectee.</div>
                <?php endif; ?>
                <?php foreach ($poolGroups as $poolName => $poolTeams): ?>
                    <div class="pool-block">
                        <strong>Poule <?php echo htmlspecialchars((string) $poolName, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div class="muted" style="margin-top:6px;">
                            <?php if (!$poolTeams): ?>
                                Aucune equipe.
                            <?php else: ?>
                                <?php echo htmlspecialchars(implode(', ', array_map(static fn(array $row): string => (string) $row['name'], $poolTeams)), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$pdo = null;
$dbError = '';

try {
    $pdo = db();
    ensureDefaultAdmin($pdo);
} catch (Throwable $exception) {
    $dbError = 'Connexion impossible a la base de donnees. Verifiez vos parametres MySQL (XAMPP ou hebergeur).';
}

if (isAdminAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Veuillez renseigner tous les champs.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
            $error = 'Identifiants invalides.';
        } else {
            loginAdmin($admin);
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Connexion Admin | Bible Master</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700&family=Plus+Jakarta+Sans:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #1a1f24;
            --muted: #5f6873;
            --sand: #fbf5e8;
            --paper: #fffdf8;
            --teal: #0f766e;
            --teal-strong: #115e59;
            --danger: #b42318;
            --line: #eadfcb;
            --shadow: 0 16px 48px rgba(31, 41, 55, 0.18);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: "Outfit", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% 20%, rgba(15, 118, 110, 0.18), transparent 38%),
                radial-gradient(circle at 88% 14%, rgba(234, 88, 12, 0.2), transparent 35%),
                linear-gradient(180deg, #fffdfa 0%, var(--sand) 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .login-shell {
            width: min(980px, 100%);
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            border: 1px solid var(--line);
            border-radius: 26px;
            overflow: hidden;
            background: var(--paper);
            box-shadow: var(--shadow);
            animation: rise 0.55s ease;
        }

        .brand-side {
            padding: 40px 34px;
            background: linear-gradient(160deg, rgba(15, 118, 110, 0.96) 0%, rgba(17, 94, 89, 0.96) 100%);
            color: #f8fafc;
            display: grid;
            align-content: space-between;
            gap: 20px;
        }

        .logo { font-family: "Plus Jakarta Sans", sans-serif; font-size: 1.15rem; font-weight: 700; }
        .brand-side h1 { font-size: clamp(1.5rem, 2.4vw, 2.15rem); line-height: 1.2; margin-bottom: 12px; }
        .brand-side p { color: rgba(248, 250, 252, 0.88); line-height: 1.6; }

        .pill { display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; border: 1px solid rgba(248, 250, 252, 0.26); border-radius: 999px; padding: 8px 12px; }
        .dot { width: 9px; height: 9px; border-radius: 999px; background: #22c55e; }

        .form-side { padding: 40px 32px; display: grid; gap: 20px; align-content: center; }
        .form-side h2 { font-family: "Plus Jakarta Sans", sans-serif; font-size: 1.5rem; }
        .form-side .sub { color: var(--muted); line-height: 1.5; }

        .alert { border: 1px solid #f7cac8; background: #fff2f2; color: var(--danger); border-radius: 12px; padding: 10px 12px; font-size: 0.93rem; }

        form { display: grid; gap: 14px; }
        .field { display: grid; gap: 7px; }
        label { font-size: 0.92rem; font-weight: 600; }

        input {
            width: 100%; border: 1px solid #d8c7aa; border-radius: 12px; padding: 12px 13px; font-size: 0.98rem;
            background: #fffcf5; color: var(--ink); outline: none;
        }

        input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14); }

        .btn {
            margin-top: 6px; border: none; border-radius: 12px; padding: 12px 14px; font-size: 0.96rem; font-weight: 700;
            cursor: pointer; color: #fff; background: linear-gradient(135deg, var(--teal), var(--teal-strong));
        }

        .hint { color: var(--muted); font-size: 0.86rem; }

        @keyframes rise {
            from { opacity: 0; transform: translateY(10px) scale(0.99); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (max-width: 900px) {
            .login-shell { grid-template-columns: 1fr; }
            .brand-side, .form-side { padding: 28px 22px; }
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <section class="brand-side">
            <div>
                <p class="logo">Bible Master Admin</p>
                <h1>Acces securise a l'administration</h1>
                <p>Connectez-vous pour gerer les equipes, creer les matchs et mettre a jour les scores.</p>
            </div>
            <div class="pill"><span class="dot"></span>Zone reservee aux administrateurs</div>
        </section>

        <section class="form-side">
            <div>
                <h2>Connexion</h2>
                <p class="sub">Saisissez vos identifiants administrateur pour continuer.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($dbError !== ''): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="field">
                    <label for="username">Nom admin</label>
                    <input id="username" name="username" type="text" autocomplete="username" required>
                </div>

                <div class="field">
                    <label for="password">Mot de passe</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn">Se connecter</button>
                <p class="hint">Compte par defaut: admin / admin123 (a modifier apres installation).</p>
            </form>
        </section>
    </main>
</body>
</html>

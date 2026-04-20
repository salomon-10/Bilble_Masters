<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

function ensureDefaultAdmin(PDO $pdo): void
{
    $bootstrapEnabled = getenv('BIBLE_BOOTSTRAP_ADMIN') === '1';
    if (!$bootstrapEnabled) {
        return;
    }

    if (isProductionEnvironment()) {
        throw new RuntimeException('Bootstrap admin desactive en production. Creez un compte admin manuellement.');
    }

    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM admins');
    $count = (int) ($stmt->fetch()['total'] ?? 0);

    if ($count > 0) {
        return;
    }

    $username = trim((string) (getenv('BIBLE_ADMIN_USER') ?: 'admin'));
    $plainPassword = (string) (getenv('BIBLE_ADMIN_PASS') ?: '');

    if (!isStrongPassword($plainPassword)) {
        throw new RuntimeException('Mot de passe bootstrap trop faible. Utilisez au minimum 12 caracteres avec majuscule, minuscule, chiffre et symbole.');
    }

    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
    ]);
}

function isProductionEnvironment(): bool
{
    return strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'production';
}

function isStrongPassword(string $password): bool
{
    if (strlen($password) < 12) {
        return false;
    }

    return (bool) preg_match('/[A-Z]/', $password)
        && (bool) preg_match('/[a-z]/', $password)
        && (bool) preg_match('/[0-9]/', $password)
        && (bool) preg_match('/[^A-Za-z0-9]/', $password);
}

function isLoginThrottled(string $username): bool
{
    $bucket = loginThrottleBucket($username);
    $maxAttempts = 5;
    $lockSeconds = 900;

    if (($bucket['lock_until'] ?? 0) > time()) {
        return true;
    }

    if (($bucket['lock_until'] ?? 0) <= time() && ($bucket['lock_until'] ?? 0) > 0) {
        clearLoginThrottle($username);
    }

    return false;
}

function registerLoginFailure(string $username): void
{
    $key = loginThrottleKey($username);
    $now = time();
    $windowSeconds = 900;
    $maxAttempts = 5;
    $bucket = $_SESSION['login_throttle'][$key] ?? [
        'attempts' => 0,
        'first_attempt' => $now,
        'lock_until' => 0,
    ];

    if ($now - (int) $bucket['first_attempt'] > $windowSeconds) {
        $bucket = [
            'attempts' => 0,
            'first_attempt' => $now,
            'lock_until' => 0,
        ];
    }

    $bucket['attempts'] = (int) $bucket['attempts'] + 1;
    if ((int) $bucket['attempts'] >= $maxAttempts) {
        $bucket['lock_until'] = $now + $windowSeconds;
    }

    $_SESSION['login_throttle'][$key] = $bucket;
}

function clearLoginThrottle(string $username): void
{
    $key = loginThrottleKey($username);
    if (isset($_SESSION['login_throttle'][$key])) {
        unset($_SESSION['login_throttle'][$key]);
    }
}

function loginThrottleBucket(string $username): array
{
    $key = loginThrottleKey($username);
    $bucket = $_SESSION['login_throttle'][$key] ?? [];

    return [
        'attempts' => (int) ($bucket['attempts'] ?? 0),
        'first_attempt' => (int) ($bucket['first_attempt'] ?? 0),
        'lock_until' => (int) ($bucket['lock_until'] ?? 0),
    ];
}

function loginThrottleRemainingSeconds(string $username): int
{
    $bucket = loginThrottleBucket($username);
    $lockUntil = (int) ($bucket['lock_until'] ?? 0);
    if ($lockUntil <= time()) {
        return 0;
    }

    return $lockUntil - time();
}

function loginThrottleKey(string $username): string
{
    $normalized = strtolower(trim($username));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    return hash('sha256', $normalized . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

function isAdminAuthenticated(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function requireAdminAuth(): void
{
    if (!isAdminAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

function loginAdmin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
}

function logoutAdmin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

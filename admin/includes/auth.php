<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

function ensureDefaultAdmin(PDO $pdo): void
{
    ensureAdminRoleColumn($pdo);
    ensureDefaultStaffRoles($pdo);

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

    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash, role) VALUES (:username, :password_hash, :role)');
    $insert->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':role' => 'admin',
    ]);
}

function ensureDefaultStaffRoles(PDO $pdo): void
{
    $pdo->prepare("UPDATE admins SET role = 'admin' WHERE username = 'admin' AND (role IS NULL OR role = '' OR role <> 'admin')")->execute();
    $pdo->prepare("UPDATE admins SET role = 'arbitre' WHERE username IN ('arbitre1', 'arbitre2') AND (role IS NULL OR role = '' OR role <> 'arbitre')")->execute();
}

function ensureAdminRoleColumn(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => 'admins',
        ':column_name' => 'role',
    ]);

    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password_hash");
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

function currentAdminRole(): string
{
    $role = strtolower(trim((string) ($_SESSION['admin_role'] ?? 'admin')));

    return $role !== '' ? $role : 'admin';
}

function normalizeAllowedAdminRoles(string|array|null $allowedRoles): ?array
{
    if ($allowedRoles === null) {
        return null;
    }

    $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    $roles = array_values(array_unique(array_filter(array_map(
        static fn(string $role): string => strtolower(trim($role)),
        $roles
    ), static fn(string $role): bool => $role !== '')));

    return $roles === [] ? null : $roles;
}

function isAdminAuthenticated(string|array|null $allowedRoles = null): bool
{
    if (!isset($_SESSION['admin_id']) || !is_numeric($_SESSION['admin_id'])) {
        return false;
    }

    $roles = normalizeAllowedAdminRoles($allowedRoles);
    if ($roles === null) {
        return true;
    }

    return in_array(currentAdminRole(), $roles, true);
}

function requireAdminAuth(string|array|null $allowedRoles = null): void
{
    if (!isAdminAuthenticated($allowedRoles)) {
        if (isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id'])) {
            header('Location: ' . redirectAfterLoginForRole(currentAdminRole()));
            exit;
        }

        header('Location: /admin/login.php');
        exit;
    }
}

function loginAdmin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    $_SESSION['admin_role'] = strtolower(trim((string) ($admin['role'] ?? 'admin'))) ?: 'admin';
}

function redirectAfterLoginForRole(string $role): string
{
    return strtolower(trim($role)) === 'arbitre'
        ? '/admin/visibilite.php'
        : '/admin/dashboard.php';
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

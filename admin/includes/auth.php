<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

function ensureDefaultAdmin(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM admins');
    $count = (int) ($stmt->fetch()['total'] ?? 0);

    if ($count > 0) {
        return;
    }

    $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);

    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute([
        ':username' => 'admin',
        ':password_hash' => $passwordHash,
    ]);
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

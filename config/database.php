<?php

declare(strict_types=1);

function dbConfig(): array
{
    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $isLocalHost = $hostName === ''
        || str_contains($hostName, 'localhost')
        || str_contains($hostName, '127.0.0.1');

    $appEnvRaw = strtolower((string) (getenv('APP_ENV') ?: ''));
    $appEnv = $appEnvRaw !== '' ? $appEnvRaw : ($isLocalHost ? 'local' : 'production');

    if ($appEnv === 'production') {
        return [
            'host' => getenv('DB_HOST') ?: 'sql101.infinityfree.com',
            'port' => getenv('DB_PORT') ?: '3306',
            'name' => getenv('DB_NAME') ?: 'if0_41655329_bible_master',
            'user' => getenv('DB_USER') ?: 'if0_41655329',
            'pass' => getenv('DB_PASS') ?: 'tXTlsiphKinU',
        ];
    }

    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'bible_master',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = dbConfig();
    $host = $config['host'];
    $port = $config['port'];
    $name = $config['name'];
    $user = $config['user'];
    $pass = $config['pass'];

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    } catch (PDOException $exception) {
        $isSocketIssue = str_contains($exception->getMessage(), '[2002]') && strtolower((string) $host) === 'localhost';

        if ($isSocketIssue) {
            $fallbackHost = '127.0.0.1';
            $fallbackDsn = "mysql:host={$fallbackHost};port={$port};dbname={$name};charset=utf8mb4";

            try {
                $pdo = new PDO($fallbackDsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10,
                ]);

                return $pdo;
            } catch (PDOException $fallbackException) {
                error_log('[Bible_Master] Database connection fallback failed: ' . $fallbackException->getMessage());
                throw new RuntimeException('Impossible de se connecter a la base de donnees.', 0, $fallbackException);
            }
        }

        error_log('[Bible_Master] Database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Impossible de se connecter a la base de donnees.', 0, $exception);
    }

    return $pdo;
}

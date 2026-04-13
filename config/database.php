<?php

declare(strict_types=1);

function dbConfig(): array
{
    $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'local'));

    if ($appEnv === 'production') {
        return [
            'host' => getenv('DB_HOST') ?: '',
            'port' => getenv('DB_PORT') ?: '3306',
            'name' => getenv('DB_NAME') ?: '',
            'user' => getenv('DB_USER') ?: '',
            'pass' => getenv('DB_PASS') ?: '',
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
        error_log('[Bible_Master] Database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Impossible de se connecter a la base de donnees.', 0, $exception);
    }

    return $pdo;
}

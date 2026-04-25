<?php

declare(strict_types=1);

function readEnvAny(array $keys): string
{
    foreach ($keys as $key) {
        $value = readEnvValue($key);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function readEnvValue(string $key): string
{
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    $serverValue = $_SERVER[$key] ?? null;
    if (is_string($serverValue) && trim($serverValue) !== '') {
        return trim($serverValue);
    }

    $envValue = $_ENV[$key] ?? null;
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    return '';
}

function resolveAppEnv(): string
{
    $appEnvRaw = strtolower(trim(readEnvValue('APP_ENV')));
    if ($appEnvRaw === 'production' || $appEnvRaw === 'prod') {
        return 'production';
    }

    if ($appEnvRaw === 'local' || $appEnvRaw === 'development' || $appEnvRaw === 'dev') {
        return 'local';
    }

    // Automatic fallback: any non-local web host is treated as production.
    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $hostWithoutPort = preg_replace('/:\d+$/', '', $hostName) ?: $hostName;
    $isPrivateIp = (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $hostWithoutPort);
    $isLocalDomain = str_ends_with($hostWithoutPort, '.local');
    $isLocalHost = $hostName === ''
        || str_contains($hostName, 'localhost')
        || str_contains($hostName, '127.0.0.1')
        || str_contains($hostName, '::1')
        || $isPrivateIp
        || $isLocalDomain;

    return $isLocalHost ? 'local' : 'production';
}

function dbConfig(): array
{
    $appEnv = resolveAppEnv();

    $host = readEnvAny(['DB_HOST', 'DATABASE_HOST', 'MYSQLHOST', 'MYSQL_HOST']);
    $port = readEnvAny(['DB_PORT', 'DATABASE_PORT', 'MYSQLPORT', 'MYSQL_PORT']);
    $name = readEnvAny(['DB_NAME', 'DATABASE_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE']);
    $user = readEnvAny(['DB_USER', 'DATABASE_USER', 'MYSQLUSER', 'MYSQL_USER']);
    $pass = readEnvAny(['DB_PASS', 'DATABASE_PASSWORD', 'MYSQLPASSWORD', 'MYSQL_PASSWORD']);

    if ($appEnv === 'production') {
        return [
            // Backward-compatible defaults for existing InfinityFree deployment.
            'host' => $host !== '' ? $host : 'sql101.infinityfree.com',
            'port' => $port !== '' ? $port : '3306',
            'name' => $name !== '' ? $name : 'if0_41655329_bible_master',
            'user' => $user !== '' ? $user : 'if0_41655329',
            'pass' => $pass !== '' ? $pass : 'tXTlsiphKinU',
        ];
    }

    return [
        'host' => $host !== '' ? $host : 'localhost',
        'port' => $port !== '' ? $port : '3306',
        'name' => $name !== '' ? $name : 'bible_master',
        'user' => $user !== '' ? $user : 'root',
        'pass' => $pass,
    ];
}

function dbConfigForProduction(): array
{
    $host = readEnvAny(['DB_HOST', 'DATABASE_HOST', 'MYSQLHOST', 'MYSQL_HOST']);
    $port = readEnvAny(['DB_PORT', 'DATABASE_PORT', 'MYSQLPORT', 'MYSQL_PORT']);
    $name = readEnvAny(['DB_NAME', 'DATABASE_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE']);
    $user = readEnvAny(['DB_USER', 'DATABASE_USER', 'MYSQLUSER', 'MYSQL_USER']);
    $pass = readEnvAny(['DB_PASS', 'DATABASE_PASSWORD', 'MYSQLPASSWORD', 'MYSQL_PASSWORD']);

    return [
        'host' => $host !== '' ? $host : 'sql101.infinityfree.com',
        'port' => $port !== '' ? $port : '3306',
        'name' => $name !== '' ? $name : 'if0_41655329_bible_master',
        'user' => $user !== '' ? $user : 'if0_41655329',
        'pass' => $pass !== '' ? $pass : 'tXTlsiphKinU',
    ];
}

function validateDbConfig(array $config, string $appEnv): void
{
    $required = ['host', 'port', 'name', 'user'];
    foreach ($required as $field) {
        $value = trim((string) ($config[$field] ?? ''));
        if ($value === '') {
            throw new RuntimeException('Configuration DB invalide: champ manquant ' . $field . ' (env=' . $appEnv . ').');
        }
    }
}

function isPublicWebHostRequest(): bool
{
    $hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $hostWithoutPort = preg_replace('/:\d+$/', '', $hostName) ?: $hostName;

    if ($hostWithoutPort === '') {
        return false;
    }

    $isPrivateIp = (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $hostWithoutPort);

    return !str_contains($hostWithoutPort, 'localhost')
        && !str_contains($hostWithoutPort, '127.0.0.1')
        && !str_contains($hostWithoutPort, '::1')
        && !$isPrivateIp
        && !str_ends_with($hostWithoutPort, '.local');
}

function buildPdoDsn(array $config): string
{
    return 'mysql:host=' . $config['host']
        . ';port=' . $config['port']
        . ';dbname=' . $config['name']
        . ';charset=utf8mb4';
}

function connectPdoWithConfig(array $config): PDO
{
    return new PDO(buildPdoDsn($config), $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
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

    $appEnv = resolveAppEnv();
    validateDbConfig($config, $appEnv);

    try {
        $pdo = connectPdoWithConfig($config);
    } catch (PDOException $exception) {
        $isSocketIssue = str_contains($exception->getMessage(), '[2002]') && strtolower((string) $host) === 'localhost';

        if ($isSocketIssue) {
            $fallbackHost = '127.0.0.1';
            $fallbackConfig = $config;
            $fallbackConfig['host'] = $fallbackHost;

            try {
                $pdo = connectPdoWithConfig($fallbackConfig);

                return $pdo;
            } catch (PDOException $fallbackException) {
                error_log('[Bible_Master] Database connection fallback failed: ' . $fallbackException->getMessage());
            }
        }

        // Safety net for shared hosting/custom domains when APP_ENV isn't set properly.
        if (isPublicWebHostRequest()) {
            try {
                $pdo = connectPdoWithConfig(dbConfigForProduction());

                return $pdo;
            } catch (PDOException $productionException) {
                error_log('[Bible_Master] Production-profile fallback failed: ' . $productionException->getMessage());
            }
        }

        error_log('[Bible_Master] Database connection failed (env=' . $appEnv . ', host=' . $host . ', db=' . $name . ', user=' . $user . '): ' . $exception->getMessage());
        throw new RuntimeException('Impossible de se connecter a la base de donnees.', 0, $exception);
    }

    return $pdo;
}

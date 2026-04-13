<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfOrFail(?string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        http_response_code(400);
        exit('Requete invalide (CSRF).');
    }
}

<?php

declare(strict_types=1);

function startAppSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function appBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = str_replace('\\', '/', dirname($scriptName));

    $basePath = preg_replace('#/(?:tools|public)(?:/.*)?$#', '', $scriptDir);

    if (!is_string($basePath) || $basePath === '') {
        return '';
    }

    return rtrim($basePath, '/');
}

function requireLogin(): void
{
    startAppSession();

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . appBasePath() . '/login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();

    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak. Fitur ini hanya dapat digunakan oleh Admin.');
    }
}

function currentUser(): ?array
{
    startAppSession();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'role' => (string) ($_SESSION['user_role'] ?? ''),
    ];
}

function loginUser(array $user): void
{
    startAppSession();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['user_role'] = (string) $user['role'];
}

function logoutUser(): void
{
    startAppSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

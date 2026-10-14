<?php

declare(strict_types=1);

/**
 * Mulai sesi aplikasi dengan pengaturan cookie yang lebih aman.
 *
 * Secure aktif pada HTTPS langsung atau ketika request HTTPS diteruskan
 * melalui reverse proxy yang dipercaya (misalnya Cloudflare Tunnel).
 */
function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Cegah PHP menerima ID sesi yang belum pernah dibuat.
    ini_set('session.use_strict_mode', '1');

    // Cookie sesi tidak dapat dibaca melalui JavaScript.
    ini_set('session.cookie_httponly', '1');

    // Cookie tetap dikirim pada navigasi normal, tetapi dibatasi untuk
    // mengurangi pengiriman lintas situs.
    ini_set('session.cookie_samesite', 'Lax');

    // Deteksi HTTPS langsung atau protokol HTTPS yang diteruskan proxy.
    // HTTP_X_FORWARDED_PROTO hanya boleh dipercaya jika origin memang
    // berada di belakang proxy tepercaya seperti Cloudflare Tunnel.
    $httpsDirect = isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    $forwardedProto = strtolower(trim(
        explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]
    ));

    $cloudflareVisitor = json_decode(
        (string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''),
        true
    );

    $httpsViaProxy = $forwardedProto === 'https'
        || (is_array($cloudflareVisitor)
            && strtolower((string) ($cloudflareVisitor['scheme'] ?? '')) === 'https');

    $cookieParams = session_get_cookie_params();

    session_set_cookie_params([
        'lifetime' => (int) $cookieParams['lifetime'],
        'path' => $cookieParams['path'] !== '' ? $cookieParams['path'] : '/',
        'domain' => $cookieParams['domain'],
        'secure' => $httpsDirect || $httpsViaProxy,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
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
            [
                'expires' => time() - 42000,
                'path' => $params['path'] !== '' ? $params['path'] : '/',
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    session_destroy();
}

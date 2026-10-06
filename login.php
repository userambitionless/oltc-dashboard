<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

startAppSession();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if ($token === '' || !hash_equals((string) $_SESSION['login_csrf'], $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            $stmt = $pdo->prepare('
                SELECT id, username, password_hash, role
                FROM users
                WHERE username = :username
                LIMIT 1
            ');
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string) $user['password_hash'])) {
                loginUser($user);
                header('Location: index.php');
                exit;
            }

            $error = 'Username atau password tidak benar.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" href="favicon2.png">
    <link rel="shortcut icon" type="image/png" href="favicon2.png">
    <title>Login · OLTC Dashboard</title>
    <link rel="stylesheet" href="public/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/public/assets/css/auth.css') ?>">
</head>
<body>
<main class="auth-page">
    <section class="auth-shell">
        <div class="auth-visual">
            <div class="visual-top">
                <div class="visual-logos">
                    <img class="visual-pln" src="PLN%20Logo%20-%20Colored%20-%205716x2048%20-%20zonalogo.com.png" alt="Logo PLN">
                    <span class="visual-divider"></span>
                    <img class="visual-oltc" src="favicon2.png" alt="Logo OLTC">
                </div>
                <span class="visual-status"><i></i> SYSTEM ONLINE</span>
            </div>

            <div class="visual-content">
                <p class="visual-kicker">MONITORING SYSTEM</p>
                <h1>OLTC Counter<br><em>Dashboard</em></h1>
                <p class="visual-description">
                    Monitoring dan riwayat pembacaan counter OLTC berbasis data
                    dalam satu dashboard terpusat.
                </p>
            </div>

            <div class="visual-grid" aria-hidden="true"></div>
            <div class="visual-orb visual-orb-one" aria-hidden="true"></div>
            <div class="visual-orb visual-orb-two" aria-hidden="true"></div>

            <div class="visual-footer">
                <span>POWER SYSTEM MONITORING</span>
                <span>WIB · <?= date('Y') ?></span>
            </div>
        </div>

        <div class="auth-panel">
            <div class="auth-panel-inner">
                <div class="mobile-brand">
                    <img src="PLN%20Logo%20-%20Colored%20-%205716x2048%20-%20zonalogo.com.png" alt="Logo PLN">
                    <img src="favicon2.png" alt="Logo OLTC">
                </div>

                <div class="auth-heading">
                    <p class="eyebrow">LOGIN PAGE OLTC MONITORING</p>
                    <h2>Selamat datang.</h2>
                    <p>Masukkan ID serta Password Anda untuk melanjutkan ke dashboard.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">

                    <div class="auth-field">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <span class="field-icon" aria-hidden="true">ID</span>
                            <input id="username" name="username" type="text" autocomplete="username" autofocus required placeholder="Masukkan username">
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <span class="field-icon" aria-hidden="true">••</span>
                            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan password">
                        </div>
                    </div>

                    <button class="auth-button" type="submit">
                        <span>Masuk ke Dashboard</span>
                        <span class="auth-button-arrow">→</span>
                    </button>
                </form>

                <div class="auth-footer">Akses resmi · OLTC Counter Dashboard · <?= date('Y') ?></div>
            </div>
        </div>
    </section>
</main>
</body>
</html>

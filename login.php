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
    <title>Login · OLTC Dashboard</title>
    <link rel="stylesheet" href="public/assets/css/auth.css">
</head>
<body>
<main class="auth-page">
    <section class="auth-card">
        <div class="auth-header">
            <p class="eyebrow">MONITORING SYSTEM</p>
            <h1>OLTC Dashboard</h1>
            <p>Masuk untuk mengakses monitoring dan riwayat pembacaan counter.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">

            <div class="auth-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" autofocus required>
            </div>

            <div class="auth-field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <button class="auth-button" type="submit">Masuk</button>
        </form>

        <div class="auth-footer">OLTC Dashboard · <?= date('Y') ?></div>
    </section>
</main>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

try {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (PDOException $e) {
    http_response_code(503);
    exit('Tabel users belum tersedia. Jalankan database/003_add_users.sql terlebih dahulu.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        $error = 'Username harus 3–100 karakter dan hanya boleh memakai huruf, angka, titik, garis bawah, atau tanda minus.';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($password !== $confirmation) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO users (username, password_hash, role)
                VALUES (:username, :password_hash, :role)
            ');
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);

            $message = 'Admin berhasil dibuat. Demi keamanan, hapus atau nonaktifkan file tools/create-admin.php setelah selesai.';
            $count++;
        } catch (PDOException $e) {
            $error = 'Admin gagal dibuat. Username mungkin sudah digunakan.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Initial Admin Setup · OLTC Dashboard</title>
<link rel="stylesheet" href="../public/assets/css/auth.css">
</head>
<body>
<main class="auth-page">
<section class="auth-card">
<div class="auth-header">
<p class="eyebrow">INITIAL SETUP</p>
<h1>Buat Admin</h1>
<p>Gunakan halaman ini sekali untuk membuat akun Admin pertama.</p>
</div>

<?php if ($message !== ''): ?>
<div class="auth-alert auth-alert-success"><?= htmlspecialchars($message) ?></div>
<?php elseif ($error !== ''): ?>
<div class="auth-alert" role="alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($count === 0): ?>
<form method="post" class="auth-form">
<div class="auth-field">
<label for="username">Username Admin</label>
<input id="username" name="username" type="text" autocomplete="username" required>
</div>
<div class="auth-field">
<label for="password">Password</label>
<input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
</div>
<div class="auth-field">
<label for="password_confirmation">Konfirmasi Password</label>
<input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
</div>
<button class="auth-button" type="submit">Buat Admin</button>
</form>
<?php else: ?>
<div class="auth-actions">
<a class="auth-link" href="../login.php">Buka halaman login</a>
</div>
<?php endif; ?>

<div class="auth-footer">Setelah akun dibuat, hapus file setup ini dari server.</div>
</section>
</main>
</body>
</html>

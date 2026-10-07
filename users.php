<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

requireAdmin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$currentUser = currentUser();
$successMessage = '';
$errorMessage = '';

function formatEnglishDateTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '-';
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d H:i:s', $dateTime);

    if (!$dateObject) {
        return $dateTime;
    }

    return $dateObject->format('j F Y H:i:s');
}

function verifyUserCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');

    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        exit('Permintaan tidak valid atau sudah kedaluwarsa. Silakan kembali dan coba lagi.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyUserCsrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
        $role = (string) ($_POST['role'] ?? 'viewer');

        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $errorMessage = 'Username hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus (3-100 karakter).';
        } elseif (!in_array($role, ['admin', 'viewer'], true)) {
            $errorMessage = 'Role tidak valid.';
        } elseif (strlen($password) < 8) {
            $errorMessage = 'Password minimal 8 karakter.';
        } elseif ($password !== $passwordConfirmation) {
            $errorMessage = 'Konfirmasi password tidak sama.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO users (username, password_hash, role)
                    VALUES (:username, :password_hash, :role)
                ');
                $stmt->execute([
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);

                $successMessage = 'User berhasil ditambahkan.';
            } catch (PDOException $e) {
                if ((int) $e->errorInfo[1] === 1062) {
                    $errorMessage = 'Username tersebut sudah digunakan.';
                } else {
                    error_log('OLTC dashboard user create error: ' . $e->getMessage());
                    $errorMessage = 'User gagal ditambahkan.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $currentUserId = (int) ($currentUser['id'] ?? 0);

        if ($deleteId === false || $deleteId < 1) {
            $errorMessage = 'ID user tidak valid.';
        } elseif ($deleteId === $currentUserId) {
            $errorMessage = 'Akun yang sedang digunakan tidak boleh dihapus.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $deleteId]);
                $targetUser = $stmt->fetch();

                if (!$targetUser) {
                    $errorMessage = 'User tidak ditemukan.';
                } elseif ($targetUser['role'] === 'admin') {
                    $adminCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                    $adminCount = (int) $adminCountStmt->fetchColumn();

                    if ($adminCount <= 1) {
                        $errorMessage = 'Admin terakhir tidak boleh dihapus.';
                    }
                }

                if ($errorMessage === '') {
                    $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $deleteStmt->execute(['id' => $deleteId]);
                    $successMessage = 'User berhasil dihapus.';
                }
            } catch (PDOException $e) {
                error_log('OLTC dashboard user delete error: ' . $e->getMessage());
                $errorMessage = 'User gagal dihapus.';
            }
        }
    }
}

try {
    $usersStmt = $pdo->query('
        SELECT id, username, role, created_at, updated_at
        FROM users
        ORDER BY role ASC, username ASC
    ');
    $users = $usersStmt->fetchAll();
} catch (PDOException $e) {
    error_log('OLTC dashboard user list error: ' . $e->getMessage());
    $users = [];
    $errorMessage = 'Daftar user gagal dimuat.';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" href="favicon2.png">
    <link rel="shortcut icon" type="image/png" href="favicon2.png">
    <title>Manajemen User - OLTC Dashboard</title>
    <link rel="stylesheet" href="public/assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/public/assets/css/dashboard.css') ?>">
</head>
<body>

<button type="button" class="home-metal-button" id="aboutInfoButton" aria-label="About and Information" aria-controls="aboutInfoModal" aria-expanded="false">
<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.7"></circle><path d="M12 10.7v5.4"></path><path d="M12 7.7h.01"></path></svg>
</button>
<div class="about-info-modal" id="aboutInfoModal" aria-hidden="true">
<div class="about-info-backdrop" data-about-close></div>
<section class="about-info-dialog" role="dialog" aria-modal="true" aria-labelledby="aboutInfoTitle">
<button type="button" class="about-info-close" data-about-close aria-label="Tutup">×</button>
<div class="about-info-kicker">About and Information</div>
<h2 id="aboutInfoTitle">OLTC Counter Monitoring System</h2>
<p class="about-info-lead">Sistem monitoring untuk mencatat, memantau, dan menampilkan riwayat pembacaan counter OLTC secara terstruktur.</p>
<div class="about-info-grid">
<div class="about-info-item"><span>Project</span><strong>OLTC Counter Dashboard</strong></div>
<div class="about-info-item"><span>Fungsi utama</span><strong>Monitoring &amp; riwayat pembacaan</strong></div>
<div class="about-info-item"><span>Sumber data</span><strong>Data pembacaan counter OLTC</strong></div>
<div class="about-info-item"><span>Platform</span><strong>Web-based Monitoring System</strong></div>
</div>
<div class="about-info-section"><h3>Tentang sistem</h3><p>Dashboard ini dirancang sebagai bagian dari sistem monitoring counter OLTC. Pada tahap pengembangan berikutnya, nilai digital hasil pembacaan perangkat akan diterima oleh server dan ditampilkan pada dashboard sebagai data utama.</p></div>
<div class="about-info-section"><h3>Catatan pengembangan</h3><p>Perangkat pembaca berbasis kamera dan Computer Vision/ML masih merupakan bagian dari rencana pengembangan proyek. Foto pembuktian pembacaan digunakan sebagai evidence, sedangkan nilai digital menjadi data utama sistem.</p></div>
</section></div><div class="page">
    <header class="header">
        <div>
            <p class="eyebrow">Access Management</p>
            <h1>Manajemen User</h1>
            <p class="subtitle">Kelola akun yang dapat mengakses OLTC Dashboard.</p>
        </div>
        <div class="header-status">
            <div class="header-user">
                <span><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
                <strong>ADMIN</strong>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit">Keluar</button>
                </form>
            </div>
            <a class="secondary-button" href="index.php">Kembali ke dashboard</a>
        </div>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <section class="card user-create-card">
        <div class="data-header">
            <div>
                <p class="eyebrow">USER BARU</p>
                <h2 class="data-title">Tambah User</h2>
                <p class="data-description">Buat akun Admin atau Viewer untuk akses dashboard.</p>
            </div>
        </div>

        <form class="user-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create">

            <div class="edit-field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" minlength="3" maxlength="100" required autocomplete="username">
            </div>

            <div class="edit-field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="viewer">Viewer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="edit-field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
            </div>

            <div class="edit-field">
                <label for="password_confirmation">Konfirmasi Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" required autocomplete="new-password">
            </div>

            <div class="edit-actions">
                <button type="submit" class="button button-primary">Tambah User</button>
            </div>
        </form>
    </section>

    <section class="card data-card">
        <div class="data-header">
            <div>
                <p class="eyebrow">USER TERDAFTAR</p>
                <h2 class="data-title">Daftar User</h2>
                <p class="data-description"><?= count($users) ?> akun terdaftar di sistem.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                        <td>
                            <span class="role-badge <?= $user['role'] === 'admin' ? 'role-admin' : 'role-viewer' ?>">
                                <?= strtoupper(htmlspecialchars($user['role'])) ?>
                            </span>
                        </td>
                        <td class="time"><?= htmlspecialchars(formatEnglishDateTime($user['created_at'] ?? null)) ?></td>
                        <td class="table-action-cell">
                            <?php if ((int) $user['id'] === (int) ($currentUser['id'] ?? 0)): ?>
                                <span class="user-current-label">Akun saat ini</span>
                            <?php else: ?>
                                <form method="post" action="users.php" onsubmit="return confirm('Hapus user <?= htmlspecialchars($user['username'], ENT_QUOTES) ?>? Akun ini tidak dapat digunakan lagi.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                    <button type="submit" class="action-button action-delete">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$users): ?>
                    <tr>
                        <td colspan="4" class="empty">Belum ada user.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="footer">
        OLTC Dashboard &middot; Access Management
    </footer>
</div>
<script>(()=>{const b=document.getElementById('aboutInfoButton'),m=document.getElementById('aboutInfoModal');if(!b||!m)return;const close=()=>{m.classList.remove('is-open');m.setAttribute('aria-hidden','true');b.setAttribute('aria-expanded','false');document.body.classList.remove('about-info-open')};b.onclick=()=>{m.classList.add('is-open');m.setAttribute('aria-hidden','false');b.setAttribute('aria-expanded','true');document.body.classList.add('about-info-open');m.querySelector('.about-info-close')?.focus()};m.querySelectorAll('[data-about-close]').forEach(x=>x.onclick=close);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&m.classList.contains('is-open'))close()})})();</script></body>
</html>

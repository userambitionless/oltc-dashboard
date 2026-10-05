<?php

require_once __DIR__ . '/config/database.php';

try {
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_data,
            COALESCE((
                SELECT nilai_data
                FROM counter_readings
                ORDER BY tanggal DESC, jam DESC, id DESC
                LIMIT 1
            ), 0) AS latest_value,
            (
                SELECT CONCAT(tanggal, ' ', jam)
                FROM counter_readings
                ORDER BY tanggal DESC, jam DESC, id DESC
                LIMIT 1
            ) AS latest_datetime
        FROM counter_readings
    ");

    $stats = $statsStmt->fetch();

    $dataStmt = $pdo->query("
        SELECT
            id,
            hari,
            tanggal,
            jam,
            nilai_data,
            foto_path
        FROM counter_readings
        ORDER BY tanggal DESC, jam DESC, id DESC
        LIMIT 100
    ");

    $data = $dataStmt->fetchAll();

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

$totalData = (int) ($stats['total_data'] ?? 0);
$latestValue = $stats['latest_value'] ?? 0;
$latestDateTime = $stats['latest_datetime'] ?? null;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>OLTC Dashboard</title>

    <link rel="stylesheet" href="public/assets/css/dashboard.css">
</head>

<body>

<div class="page">

    <header class="header">
        <div>
            <p class="eyebrow">Monitoring System</p>
            <h1>OLTC Counter Dashboard</h1>
            <p class="subtitle">
                Monitoring dan riwayat pembacaan counter OLTC berbasis data.
            </p>
        </div>

        <div class="status">
            <span class="status-dot"></span>
            Database Connected
        </div>
    </header>

    <section class="stats">

        <article class="card stat-card">
            <div class="stat-label">Total Pembacaan</div>
            <div class="stat-value">
                <?= number_format($totalData, 0, ',', '.') ?>
            </div>
            <div class="stat-meta">
                Seluruh data yang tersimpan
            </div>
        </article>

        <article class="card stat-card">
            <div class="stat-label">Nilai Terbaru</div>
            <div class="stat-value">
                <?= htmlspecialchars((string) $latestValue) ?>
            </div>
            <div class="stat-meta">
                Hasil pembacaan terakhir
            </div>
        </article>

        <article class="card stat-card">
            <div class="stat-label">Pembacaan Terakhir</div>
            <div class="stat-value">
                <?= $latestDateTime ? htmlspecialchars($latestDateTime) : '-' ?>
            </div>
            <div class="stat-meta">
                Tanggal dan waktu data terbaru
            </div>
        </article>

    </section>

    <section class="card data-card">

        <div class="data-header">
            <div>
                <h2 class="data-title">Riwayat Pembacaan</h2>
                <p class="data-description">
                    Menampilkan maksimal 100 pembacaan terbaru dari database.
                </p>
            </div>
        </div>

        <div class="table-wrap">

            <?php if (empty($data)): ?>

                <div class="empty">
                    Belum ada data pembacaan.
                </div>

            <?php else: ?>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Hari</th>
                            <th>Tanggal</th>
                            <th>Jam</th>
                            <th>Nilai Counter</th>
                            <th>Evidence</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data as $row): ?>

                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>

                            <td><?= htmlspecialchars($row['hari']) ?></td>

                            <td><?= htmlspecialchars($row['tanggal']) ?></td>

                            <td><?= htmlspecialchars($row['jam']) ?></td>

                            <td class="value">
                                <?= htmlspecialchars($row['nilai_data']) ?>
                            </td>

                            <td>
                                <span class="evidence">
                                    <?= htmlspecialchars($row['foto_path'] ?? '-') ?>
                                </span>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>

        </div>

    </section>

    <footer class="footer">
        OLTC Dashboard &middot; <?= date('Y') ?>
    </footer>

</div>

</body>
</html>

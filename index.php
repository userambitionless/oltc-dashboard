<?php

require_once __DIR__ . '/config/database.php';

$filterStart = $_GET['start_date'] ?? '';
$filterEnd = $_GET['end_date'] ?? '';
$filterMinValue = $_GET['min_value'] ?? '';
$filterMaxValue = $_GET['max_value'] ?? '';

$where = [];
$params = [];

if ($filterStart !== '') {
    $where[] = 'tanggal >= :start_date';
    $params['start_date'] = $filterStart;
}

if ($filterEnd !== '') {
    $where[] = 'tanggal <= :end_date';
    $params['end_date'] = $filterEnd;
}

if ($filterMinValue !== '' && is_numeric($filterMinValue)) {
    $where[] = 'nilai_data >= :min_value';
    $params['min_value'] = $filterMinValue;
}

if ($filterMaxValue !== '' && is_numeric($filterMaxValue)) {
    $where[] = 'nilai_data <= :max_value';
    $params['max_value'] = $filterMaxValue;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $statsStmt = $pdo->prepare("
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
        $whereSql
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch();

    $dataStmt = $pdo->prepare("
        SELECT
            id,
            hari,
            tanggal,
            jam,
            nilai_data,
            foto_path
        FROM counter_readings
        $whereSql
        ORDER BY tanggal DESC, jam DESC, id DESC
        LIMIT 100
    ");
    $dataStmt->execute($params);
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
                Sesuai filter yang dipilih
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
                Data terbaru sesuai filter
            </div>
        </article>

    </section>

    <section class="card filter-card">

        <div class="filter-header">
            <div>
                <h2 class="data-title">Filter Data</h2>
                <p class="data-description">
                    Saring riwayat berdasarkan tanggal dan rentang nilai counter.
                </p>
            </div>
        </div>

        <form class="filter-form" method="get" action="">

            <div class="filter-field">
                <label for="start_date">Tanggal Mulai</label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= htmlspecialchars($filterStart) ?>"
                >
            </div>

            <div class="filter-field">
                <label for="end_date">Tanggal Akhir</label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= htmlspecialchars($filterEnd) ?>"
                >
            </div>

            <div class="filter-field">
                <label for="min_value">Nilai Minimum</label>
                <input
                    type="number"
                    id="min_value"
                    name="min_value"
                    step="0.001"
                    value="<?= htmlspecialchars($filterMinValue) ?>"
                    placeholder="Contoh: 100"
                >
            </div>

            <div class="filter-field">
                <label for="max_value">Nilai Maksimum</label>
                <input
                    type="number"
                    id="max_value"
                    name="max_value"
                    step="0.001"
                    value="<?= htmlspecialchars($filterMaxValue) ?>"
                    placeholder="Contoh: 200"
                >
            </div>

            <div class="filter-actions">
                <button type="submit" class="button button-primary">
                    Terapkan Filter
                </button>

                <a href="index.php" class="button button-secondary">
                    Reset
                </a>
            </div>

        </form>

    </section>

    <section class="card data-card">

        <div class="data-header">
            <div>
                <h2 class="data-title">Riwayat Pembacaan</h2>
                <p class="data-description">
                    Menampilkan maksimal 100 pembacaan terbaru sesuai filter.
                </p>
            </div>
        </div>

        <div class="table-wrap">

            <?php if (empty($data)): ?>

                <div class="empty">
                    Tidak ada data yang sesuai dengan filter.
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

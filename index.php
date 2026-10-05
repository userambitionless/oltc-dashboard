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
    $countStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_data,
            MIN(nilai_data) AS min_value,
            MAX(nilai_data) AS max_value,
            AVG(nilai_data) AS avg_value
        FROM counter_readings
        $whereSql
    ");
    $countStmt->execute($params);
    $stats = $countStmt->fetch();

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

    $latestRow = $data[0] ?? null;
    $previousRow = $data[1] ?? null;
    $latestValue = $latestRow['nilai_data'] ?? 0;
    $previousValue = $previousRow['nilai_data'] ?? null;
    $valueChange = $previousValue !== null
        ? (float) $latestValue - (float) $previousValue
        : null;

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

$totalData = (int) ($stats['total_data'] ?? 0);
$minValue = $stats['min_value'] ?? null;
$maxValue = $stats['max_value'] ?? null;
$avgValue = $stats['avg_value'] ?? null;
$latestDateTime = $latestRow
    ? ($latestRow['tanggal'] . ' ' . $latestRow['jam'])
    : null;

function evidenceUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }

    $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
    $fullPath = __DIR__ . '/public/' . $cleanPath;

    if (!is_file($fullPath)) {
        return null;
    }

    return 'public/' . $cleanPath;
}

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
            <div class="stat-label">Perubahan Terakhir</div>
            <?php if ($valueChange === null): ?>
                <div class="stat-value">-</div>
                <div class="stat-meta">Belum cukup data untuk dibandingkan</div>
            <?php else: ?>
                <?php $changeClass = $valueChange > 0 ? 'change-up' : ($valueChange < 0 ? 'change-down' : 'change-neutral'); ?>
                <div class="stat-value <?= $changeClass ?>">
                    <?= $valueChange > 0 ? '+' : '' ?><?= number_format($valueChange, 3, ',', '.') ?>
                </div>
                <div class="stat-meta">
                    <?= $valueChange > 0 ? 'Naik dari pembacaan sebelumnya' : ($valueChange < 0 ? 'Turun dari pembacaan sebelumnya' : 'Tidak berubah') ?>
                </div>
            <?php endif; ?>
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

    <section class="stats stats-summary">

        <article class="card stat-card">
            <div class="stat-label">Nilai Minimum</div>
            <div class="stat-value">
                <?= $minValue !== null ? htmlspecialchars(number_format((float) $minValue, 3, ',', '.')) : '-' ?>
            </div>
            <div class="stat-meta">
                Nilai terendah sesuai filter
            </div>
        </article>

        <article class="card stat-card">
            <div class="stat-label">Nilai Maksimum</div>
            <div class="stat-value">
                <?= $maxValue !== null ? htmlspecialchars(number_format((float) $maxValue, 3, ',', '.')) : '-' ?>
            </div>
            <div class="stat-meta">
                Nilai tertinggi sesuai filter
            </div>
        </article>

        <article class="card stat-card">
            <div class="stat-label">Nilai Rata-rata</div>
            <div class="stat-value">
                <?= $avgValue !== null ? htmlspecialchars(number_format((float) $avgValue, 3, ',', '.')) : '-' ?>
            </div>
            <div class="stat-meta">
                Rata-rata seluruh data sesuai filter
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

    <section class="card chart-card">
        <div class="data-header">
            <div>
                <h2 class="data-title">Trend Pembacaan Counter</h2>
                <p class="data-description">
                    Perubahan nilai counter berdasarkan data yang sedang ditampilkan.
                </p>
            </div>
        </div>

        <?php if (empty($data)): ?>
            <div class="empty">Belum ada data untuk ditampilkan pada grafik.</div>
        <?php else: ?>
            <div class="chart-wrap">
                <svg id="counterChart" class="counter-chart" role="img" aria-label="Grafik trend nilai counter"></svg>
            </div>
        <?php endif; ?>
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
                                <?php $photoUrl = evidenceUrl($row['foto_path']); ?>

                                <?php if ($photoUrl): ?>
                                    <button
                                        type="button"
                                        class="evidence-button"
                                        data-image="<?= htmlspecialchars($photoUrl) ?>"
                                        data-caption="ID <?= htmlspecialchars($row['id']) ?> · <?= htmlspecialchars($row['tanggal']) ?> <?= htmlspecialchars($row['jam']) ?>"
                                    >
                                        <img
                                            class="evidence-thumb"
                                            src="<?= htmlspecialchars($photoUrl) ?>"
                                            alt="Evidence pembacaan ID <?= htmlspecialchars($row['id']) ?>"
                                            loading="lazy"
                                        >
                                    </button>
                                <?php elseif (!empty($row['foto_path'])): ?>
                                    <span class="evidence evidence-missing">
                                        File tidak ditemukan
                                    </span>
                                <?php else: ?>
                                    <span class="evidence">
                                        Tidak ada
                                    </span>
                                <?php endif; ?>
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

<div class="image-modal" id="imageModal" aria-hidden="true">
    <div class="image-modal-backdrop" data-close-modal></div>

    <div class="image-modal-content" role="dialog" aria-modal="true" aria-labelledby="imageModalCaption">
        <button type="button" class="image-modal-close" data-close-modal aria-label="Tutup">
            &times;
        </button>

        <img id="imageModalPreview" src="" alt="Evidence pembacaan">

        <div class="image-modal-caption" id="imageModalCaption"></div>
    </div>
</div>

<script>
    const chartData = <?= json_encode(array_map(static function ($row) {
        return [
            'label' => $row['tanggal'] . ' ' . $row['jam'],
            'value' => (float) $row['nilai_data'],
        ];
    }, array_reverse($data)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function renderCounterChart() {
        const svg = document.getElementById('counterChart');

        if (!svg || chartData.length === 0) {
            return;
        }

        const width = Math.max(svg.parentElement.clientWidth, 320);
        const height = 330;
        const padding = { top: 28, right: 24, bottom: 58, left: 58 };

        const values = chartData.map((item) => item.value);
        const rawMin = Math.min(...values);
        const rawMax = Math.max(...values);
        const range = rawMax - rawMin || Math.max(Math.abs(rawMax) * 0.05, 1);
        const minValue = rawMin - range * 0.12;
        const maxValue = rawMax + range * 0.12;

        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;

        const x = (index) => {
            if (chartData.length === 1) {
                return padding.left + plotWidth / 2;
            }
            return padding.left + (index / (chartData.length - 1)) * plotWidth;
        };

        const y = (value) => {
            return padding.top + ((maxValue - value) / (maxValue - minValue)) * plotHeight;
        };

        const formatValue = (value) => Number(value).toLocaleString('id-ID', {
            minimumFractionDigits: 3,
            maximumFractionDigits: 3
        });

        const formatLabel = (label) => {
            const parts = label.split(' ');
            return parts.length >= 2 ? parts[1].slice(0, 5) : label;
        };

        const gridCount = 5;
        let markup = '';

        for (let i = 0; i <= gridCount; i++) {
            const value = minValue + ((maxValue - minValue) * (gridCount - i) / gridCount);
            const yPos = y(value);

            markup += `<line x1="${padding.left}" y1="${yPos}" x2="${width - padding.right}" y2="${yPos}" class="chart-grid-line"></line>`;
            markup += `<text x="${padding.left - 10}" y="${yPos + 4}" text-anchor="end" class="chart-axis-label">${formatValue(value)}</text>`;
        }

        const points = chartData.map((item, index) => `${x(index)},${y(item.value)}`).join(' ');

        markup += `<polyline points="${points}" class="chart-line"></polyline>`;

        chartData.forEach((item, index) => {
            const cx = x(index);
            const cy = y(item.value);

            markup += `<circle cx="${cx}" cy="${cy}" r="4.5" class="chart-point">
                <title>${item.label} — ${formatValue(item.value)}</title>
            </circle>`;

            const showLabel = chartData.length <= 12 || index === 0 || index === chartData.length - 1 || index % Math.ceil(chartData.length / 8) === 0;

            if (showLabel) {
                markup += `<text x="${cx}" y="${height - 25}" text-anchor="middle" class="chart-axis-label">${formatLabel(item.label)}</text>`;
            }
        });

        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.innerHTML = markup;
    }

    renderCounterChart();
    window.addEventListener('resize', renderCounterChart);

    const imageModal = document.getElementById('imageModal');
    const imageModalPreview = document.getElementById('imageModalPreview');
    const imageModalCaption = document.getElementById('imageModalCaption');

    document.querySelectorAll('.evidence-button').forEach((button) => {
        button.addEventListener('click', () => {
            imageModalPreview.src = button.dataset.image;
            imageModalCaption.textContent = button.dataset.caption || 'Evidence pembacaan';
            imageModal.classList.add('is-open');
            imageModal.setAttribute('aria-hidden', 'false');
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach((element) => {
        element.addEventListener('click', closeImageModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeImageModal();
        }
    });

    function closeImageModal() {
        imageModal.classList.remove('is-open');
        imageModal.setAttribute('aria-hidden', 'true');
        imageModalPreview.src = '';
    }
</script>

</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

requireLogin();

$currentUser = currentUser();
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$group = (string) ($_GET['group'] ?? 'harian');

if (!in_array($group, ['harian', 'mingguan', 'bulanan'], true)) {
    $group = 'harian';
}

$where = [];
$params = [];

if ($startDate !== '') {
    $where[] = 'tanggal >= :start_date';
    $params['start_date'] = $startDate;
}

if ($endDate !== '') {
    $where[] = 'tanggal <= :end_date';
    $params['end_date'] = $endDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_data,
            AVG(nilai_data) AS avg_value,
            MIN(nilai_data) AS min_value,
            MAX(nilai_data) AS max_value
        FROM counter_readings
        $whereSql
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch() ?: [];

    $firstStmt = $pdo->prepare("
        SELECT tanggal, jam, nilai_data
        FROM counter_readings
        $whereSql
        ORDER BY tanggal ASC, jam ASC, id ASC
        LIMIT 1
    ");
    $firstStmt->execute($params);
    $first = $firstStmt->fetch() ?: null;

    $lastStmt = $pdo->prepare("
        SELECT tanggal, jam, nilai_data
        FROM counter_readings
        $whereSql
        ORDER BY tanggal DESC, jam DESC, id DESC
        LIMIT 1
    ");
    $lastStmt->execute($params);
    $last = $lastStmt->fetch() ?: null;

    $groupExpression = match ($group) {
        'mingguan' => "DATE_FORMAT(DATE_SUB(tanggal, INTERVAL WEEKDAY(tanggal) DAY), '%Y-%m-%d')",
        'bulanan' => "DATE_FORMAT(tanggal, '%Y-%m')",
        default => 'DATE(tanggal)',
    };

    $labelExpression = match ($group) {
        'mingguan' => "DATE_FORMAT(DATE_SUB(tanggal, INTERVAL WEEKDAY(tanggal) DAY), '%d %b %Y')",
        'bulanan' => "DATE_FORMAT(tanggal, '%b %Y')",
        default => "DATE_FORMAT(tanggal, '%d %b %Y')",
    };

    $trendStmt = $pdo->prepare("
        SELECT
            $groupExpression AS periode,
            $labelExpression AS label,
            COUNT(*) AS jumlah,
            AVG(nilai_data) AS rata_rata,
            MIN(nilai_data) AS minimum,
            MAX(nilai_data) AS maksimum
        FROM counter_readings
        $whereSql
        GROUP BY periode, label
        ORDER BY periode ASC
    ");
    $trendStmt->execute($params);
    $trendRows = $trendStmt->fetchAll();

    $change = null;
    $changePercent = null;
    $direction = 'Stabil';

    if ($first && $last) {
        $change = (float) $last['nilai_data'] - (float) $first['nilai_data'];

        if ((float) $first['nilai_data'] != 0.0) {
            $changePercent = ($change / (float) $first['nilai_data']) * 100;
        }

        if ($change > 0) {
            $direction = 'Naik';
        } elseif ($change < 0) {
            $direction = 'Turun';
        }
    }
} catch (PDOException $e) {
    error_log('OLTC trend analysis error: ' . $e->getMessage());
    die('Gagal mengambil data analisis trend.');
}

$totalData = (int) ($stats['total_data'] ?? 0);
$avgValue = $stats['avg_value'] ?? null;
$minValue = $stats['min_value'] ?? null;
$maxValue = $stats['max_value'] ?? null;
$rangeValue = ($minValue !== null && $maxValue !== null)
    ? (float) $maxValue - (float) $minValue
    : null;

$periodLabel = match ($group) {
    'mingguan' => 'Mingguan',
    'bulanan' => 'Bulanan',
    default => 'Harian',
};

function trendNumber($value): string
{
    return $value === null ? '-' : number_format((float) $value, 3, ',', '.');
}

function trendDateLabel(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date ? $date->format('d M Y') : $value;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisis Trend · OLTC Dashboard</title>
    <link rel="stylesheet" href="public/assets/css/dashboard.css">
    <style>
        .trend-page-header {
            margin-bottom: 22px;
        }

        .trend-page-header .data-description {
            max-width: 760px;
        }

        .trend-filter {
            margin-bottom: 18px;
        }

        .trend-filter-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .trend-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted-strong);
            font-size: 11px;
            font-weight: 700;
        }

        .trend-field input,
        .trend-field select {
            width: 100%;
            height: 40px;
            padding: 8px 11px;
            border: 1px solid var(--border-strong);
            border-radius: 7px;
            outline: 0;
            background: #fff;
            color: var(--text);
            font-family: "IBM Plex Mono", monospace;
            font-size: 12px;
        }

        .trend-field input:focus,
        .trend-field select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(20,92,140,.10);
        }

        .trend-filter-actions {
            display: flex;
            gap: 8px;
        }

        .trend-filter-actions .button {
            white-space: nowrap;
        }

        .trend-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            overflow: hidden;
            margin-bottom: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--border);
        }

        .trend-metric {
            min-height: 112px;
            padding: 18px 20px;
            background: rgba(255,255,255,.94);
        }

        .trend-metric-label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .trend-metric-value {
            margin-top: 10px;
            color: var(--text);
            font-family: "IBM Plex Mono", monospace;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -.03em;
        }

        .trend-metric-meta {
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
        }

        .trend-direction {
            color: var(--primary);
        }

        .trend-direction.up {
            color: var(--success);
        }

        .trend-direction.down {
            color: var(--danger);
        }

        .trend-chart-card {
            margin-bottom: 18px;
        }

        .trend-chart-wrap {
            overflow-x: auto;
            padding: 8px 4px 0;
        }

        .trend-chart {
            display: block;
            width: 100%;
            min-width: 760px;
            height: 360px;
        }

        .trend-chart .grid {
            stroke: #e2e7ea;
            stroke-width: 1;
        }

        .trend-chart .axis {
            stroke: #bfc8ce;
            stroke-width: 1;
        }

        .trend-chart .line {
            fill: none;
            stroke: #145c8c;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .trend-chart .area {
            fill: rgba(20,92,140,.08);
        }

        .trend-chart .point {
            fill: #fff;
            stroke: #145c8c;
            stroke-width: 2;
        }

        .trend-chart text {
            fill: #68737d;
            font-family: "IBM Plex Mono", monospace;
            font-size: 10px;
        }

        .trend-analysis-grid {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        .trend-table-wrap {
            overflow-x: auto;
        }

        .trend-table {
            width: 100%;
            border-collapse: collapse;
        }

        .trend-table th,
        .trend-table td {
            padding: 11px 13px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            white-space: nowrap;
        }

        .trend-table th {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .trend-table td {
            color: var(--muted-strong);
            font-family: "IBM Plex Mono", monospace;
            font-size: 11px;
        }

        .trend-table td.num {
            text-align: right;
        }

        .trend-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .trend-insight {
            display: grid;
            gap: 0;
        }

        .trend-insight-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 13px 0;
            border-bottom: 1px solid var(--border);
        }

        .trend-insight-row:first-child {
            padding-top: 0;
        }

        .trend-insight-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .trend-insight-row span {
            color: var(--muted);
            font-size: 11px;
        }

        .trend-insight-row strong {
            color: var(--text);
            font-family: "IBM Plex Mono", monospace;
            font-size: 12px;
            text-align: right;
        }

        .trend-note {
            margin-top: 13px;
            padding: 11px 12px;
            border-left: 2px solid var(--primary);
            background: #f5f8fa;
            color: var(--muted-strong);
            font-size: 11px;
            line-height: 1.6;
        }

        @media (max-width: 1000px) {
            .trend-filter-form {
                grid-template-columns: 1fr 1fr;
            }

            .trend-filter-actions {
                grid-column: 1 / -1;
            }

            .trend-summary {
                grid-template-columns: repeat(2, 1fr);
            }

            .trend-analysis-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .trend-filter-form,
            .trend-summary {
                grid-template-columns: 1fr;
            }

            .trend-filter-actions {
                grid-column: auto;
            }

            .trend-filter-actions .button {
                flex: 1;
            }

            .trend-metric {
                min-height: 96px;
            }
        }
    </style>
</head>
<body>
<div class="page">

    <header class="header">
        <div>
            <p class="eyebrow">Analysis Module</p>
            <h1>Analisis Trend</h1>
            <p class="subtitle">
                Analisis perubahan pembacaan counter berdasarkan periode yang dipilih.
            </p>
        </div>

        <div class="header-status">
            <div class="header-user">
                <span><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
                <strong><?= $isAdmin ? 'ADMIN' : 'VIEWER' ?></strong>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')) ?>">
                    <button type="submit">Keluar</button>
                </form>
            </div>
            <a class="secondary-button" href="index.php">Kembali ke Dashboard</a>
        </div>
    </header>

    <section class="card trend-filter">
        <div class="data-header">
            <div>
                <h2 class="data-title">Parameter Analisis</h2>
                <p class="data-description">Tentukan rentang waktu dan tingkat pengelompokan data yang ingin dibandingkan.</p>
            </div>
        </div>

        <form class="trend-filter-form" method="get">
            <div class="trend-field">
                <label for="start_date">Tanggal Mulai</label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
            </div>

            <div class="trend-field">
                <label for="end_date">Tanggal Akhir</label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
            </div>

            <div class="trend-field">
                <label for="group">Periode</label>
                <select id="group" name="group">
                    <option value="harian" <?= $group === 'harian' ? 'selected' : '' ?>>Harian</option>
                    <option value="mingguan" <?= $group === 'mingguan' ? 'selected' : '' ?>>Mingguan</option>
                    <option value="bulanan" <?= $group === 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                </select>
            </div>

            <div class="trend-filter-actions">
                <button type="submit" class="button button-primary">Analisis</button>
                <a href="trend.php" class="button button-secondary">Reset</a>
            </div>
        </form>
    </section>

    <section class="trend-summary">
        <article class="trend-metric">
            <div class="trend-metric-label">Total Pembacaan</div>
            <div class="trend-metric-value"><?= number_format($totalData, 0, ',', '.') ?></div>
            <div class="trend-metric-meta">Data dalam periode analisis</div>
        </article>

        <article class="trend-metric">
            <div class="trend-metric-label">Perubahan</div>
            <div class="trend-metric-value trend-direction <?= $direction === 'Naik' ? 'up' : ($direction === 'Turun' ? 'down' : '') ?>">
                <?= $change === null ? '-' : ($change > 0 ? '+' : '') . trendNumber($change) ?>
            </div>
            <div class="trend-metric-meta">
                <?= $changePercent === null ? 'Persentase tidak tersedia' : ($changePercent > 0 ? '+' : '') . number_format($changePercent, 2, ',', '.') . '% dari awal periode' ?>
            </div>
        </article>

        <article class="trend-metric">
            <div class="trend-metric-label">Rata-rata</div>
            <div class="trend-metric-value"><?= trendNumber($avgValue) ?></div>
            <div class="trend-metric-meta">Nilai rata-rata seluruh pembacaan</div>
        </article>

        <article class="trend-metric">
            <div class="trend-metric-label">Rentang Nilai</div>
            <div class="trend-metric-value"><?= trendNumber($rangeValue) ?></div>
            <div class="trend-metric-meta"><?= trendNumber($minValue) ?> — <?= trendNumber($maxValue) ?></div>
        </article>
    </section>

    <section class="card trend-chart-card">
        <div class="data-header">
            <div>
                <h2 class="data-title">Grafik Trend <?= htmlspecialchars($periodLabel) ?></h2>
                <p class="data-description">Titik grafik menunjukkan rata-rata pembacaan pada setiap periode. Jumlah data dan rentang nilai ditampilkan di bawah.</p>
            </div>
            <?php if ($first && $last): ?>
                <div class="chart-header-value">
                    <span>ARAH TREND</span>
                    <strong><?= htmlspecialchars(strtoupper($direction)) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$trendRows): ?>
            <div class="empty">Belum ada data pada periode yang dipilih.</div>
        <?php else: ?>
            <div class="trend-chart-wrap">
                <svg id="trendChart" class="trend-chart" viewBox="0 0 1000 360" preserveAspectRatio="none" aria-label="Grafik trend rata-rata pembacaan">
                    <?php
                    $chartLeft = 58;
                    $chartRight = 978;
                    $chartTop = 26;
                    $chartBottom = 310;
                    $chartWidth = $chartRight - $chartLeft;
                    $chartHeight = $chartBottom - $chartTop;

                    $chartValues = array_map(
                        static fn(array $row): float => (float) $row['rata_rata'],
                        $trendRows
                    );
                    $chartMin = min($chartValues);
                    $chartMax = max($chartValues);
                    $chartPad = max(($chartMax - $chartMin) * 0.12, 0.5);
                    $yMin = $chartMin - $chartPad;
                    $yMax = $chartMax + $chartPad;
                    $ySpan = max($yMax - $yMin, 1);

                    $points = [];
                    foreach ($trendRows as $i => $row) {
                        $x = count($trendRows) === 1
                            ? ($chartLeft + $chartWidth / 2)
                            : $chartLeft + ($i / (count($trendRows) - 1)) * $chartWidth;
                        $y = $chartBottom - (((float) $row['rata_rata'] - $yMin) / $ySpan) * $chartHeight;
                        $points[] = [$x, $y, $row];
                    }

                    $linePath = '';
                    $areaPath = '';
                    foreach ($points as $i => [$x, $y]) {
                        $linePath .= ($i === 0 ? 'M' : 'L') . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ';
                    }

                    if ($points) {
                        $areaPath = $linePath
                            . 'L ' . number_format($points[count($points) - 1][0], 2, '.', '') . ' ' . $chartBottom
                            . ' L ' . number_format($points[0][0], 2, '.', '') . ' ' . $chartBottom
                            . ' Z';
                    }

                    for ($g = 0; $g <= 4; $g++) {
                        $gy = $chartTop + ($g / 4) * $chartHeight;
                        $gv = $yMax - ($g / 4) * $ySpan;
                    ?>
                        <line class="grid" x1="<?= $chartLeft ?>" y1="<?= $gy ?>" x2="<?= $chartRight ?>" y2="<?= $gy ?>"></line>
                        <text x="8" y="<?= $gy + 4 ?>"><?= htmlspecialchars(number_format($gv, 2, ',', '.')) ?></text>
                    <?php } ?>

                    <line class="axis" x1="<?= $chartLeft ?>" y1="<?= $chartBottom ?>" x2="<?= $chartRight ?>" y2="<?= $chartBottom ?>"></line>
                    <path class="area" d="<?= htmlspecialchars(trim($areaPath)) ?>"></path>
                    <path class="line" d="<?= htmlspecialchars(trim($linePath)) ?>"></path>

                    <?php foreach ($points as $i => [$x, $y, $row]): ?>
                        <circle class="point" cx="<?= $x ?>" cy="<?= $y ?>" r="<?= count($points) > 30 ? 2.5 : 4 ?>"></circle>
                        <?php if (count($points) <= 20 || $i === 0 || $i === count($points) - 1): ?>
                            <text x="<?= $x ?>" y="340" text-anchor="middle"><?= htmlspecialchars((string) $row['label']) ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </svg>
            </div>
        <?php endif; ?>
    </section>

    <section class="trend-analysis-grid">
        <div class="card">
            <div class="data-header">
                <div>
                    <h2 class="data-title">Ringkasan Periode</h2>
                    <p class="data-description">Detail hasil agregasi <?= strtolower($periodLabel) ?>.</p>
                </div>
            </div>

            <?php if (!$trendRows): ?>
                <div class="empty">Tidak ada data untuk diringkas.</div>
            <?php else: ?>
                <div class="trend-table-wrap">
                    <table class="trend-table">
                        <thead>
                            <tr>
                                <th>Periode</th>
                                <th>Data</th>
                                <th>Rata-rata</th>
                                <th>Min</th>
                                <th>Maks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trendRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['label']) ?></td>
                                    <td class="num"><?= number_format((int) $row['jumlah'], 0, ',', '.') ?></td>
                                    <td class="num"><?= trendNumber($row['rata_rata']) ?></td>
                                    <td class="num"><?= trendNumber($row['minimum']) ?></td>
                                    <td class="num"><?= trendNumber($row['maksimum']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="data-header">
                <div>
                    <h2 class="data-title">Interpretasi Data</h2>
                    <p class="data-description">Ringkasan numerik, bukan penilaian kondisi teknis.</p>
                </div>
            </div>

            <div class="trend-insight">
                <div class="trend-insight-row">
                    <span>Awal periode</span>
                    <strong><?= $first ? htmlspecialchars($first['tanggal'] . ' ' . $first['jam']) : '-' ?></strong>
                </div>
                <div class="trend-insight-row">
                    <span>Nilai awal</span>
                    <strong><?= $first ? trendNumber($first['nilai_data']) : '-' ?></strong>
                </div>
                <div class="trend-insight-row">
                    <span>Akhir periode</span>
                    <strong><?= $last ? htmlspecialchars($last['tanggal'] . ' ' . $last['jam']) : '-' ?></strong>
                </div>
                <div class="trend-insight-row">
                    <span>Nilai akhir</span>
                    <strong><?= $last ? trendNumber($last['nilai_data']) : '-' ?></strong>
                </div>
                <div class="trend-insight-row">
                    <span>Arah perubahan</span>
                    <strong class="trend-direction <?= $direction === 'Naik' ? 'up' : ($direction === 'Turun' ? 'down' : '') ?>"><?= htmlspecialchars($direction) ?></strong>
                </div>
            </div>

            <div class="trend-note">
                Analisis ini hanya membaca pola historis dari data yang tersimpan. Belum ada threshold teknis, prediksi, atau kesimpulan aman/tidak aman.
            </div>
        </div>
    </section>

</div>
</body>
</html>

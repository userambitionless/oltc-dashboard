<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

requireLogin();

$currentUser = currentUser();
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function verifyCsrfToken(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');

    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        exit('Permintaan tidak valid atau sudah kedaluwarsa. Silakan kembali dan coba lagi.');
    }
}

function redirectWithMessage(string $query): void
{
    header('Location: index.php?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['delete', 'edit'], true)) {
        requireAdmin();
    }

    if ($action === 'delete') {
        $deleteId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

        if ($deleteId === false || $deleteId < 1) {
            redirectWithMessage('delete_error=ID%20data%20tidak%20valid');
        }

        try {
            $pdo->beginTransaction();

            $deleteStmt = $pdo->prepare("
                SELECT foto_path
                FROM counter_readings
                WHERE id = :id
                LIMIT 1
            ");
            $deleteStmt->execute(['id' => $deleteId]);
            $record = $deleteStmt->fetch();

            if (!$record) {
                $pdo->rollBack();
                redirectWithMessage('delete_error=Data%20tidak%20ditemukan');
            }

            $deleteStmt = $pdo->prepare("
                DELETE FROM counter_readings
                WHERE id = :id
            ");
            $deleteStmt->execute(['id' => $deleteId]);

            $pdo->commit();

            if (!empty($record['foto_path'])) {
                $photoPath = ltrim(str_replace('\\', '/', (string) $record['foto_path']), '/');
                $photoFile = __DIR__ . '/public/' . $photoPath;

                if (is_file($photoFile)) {
                    unlink($photoFile);
                }
            }

            redirectWithMessage('deleted=1');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('OLTC dashboard delete error: ' . $e->getMessage());
            redirectWithMessage('delete_error=Data%20gagal%20dihapus');
        }
    }

    if ($action === 'edit') {
        $editId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
        $jam = trim((string) ($_POST['jam'] ?? ''));
        $nilaiDataInput = trim((string) ($_POST['nilai_data'] ?? ''));

        if ($editId === false || $editId < 1) {
            redirectWithMessage('edit_error=ID%20data%20tidak%20valid');
        }

        $date = DateTime::createFromFormat('!Y-m-d', $tanggal);
        $dateErrors = DateTime::getLastErrors();
        $validDate = $date
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $date->format('Y-m-d') === $tanggal;

        $time = DateTime::createFromFormat('!H:i:s', $jam);
        $timeErrors = DateTime::getLastErrors();
        $validTime = $time
            && ($timeErrors === false || ($timeErrors['warning_count'] === 0 && $timeErrors['error_count'] === 0))
            && $time->format('H:i:s') === $jam;

        if (!$validDate || !$validTime || !is_numeric($nilaiDataInput) || !is_finite((float) $nilaiDataInput)) {
            redirectWithMessage('edit_error=Data%20tanggal%2C%20jam%2C%20atau%20nilai%20tidak%20valid');
        }

        $nilaiData = (float) $nilaiDataInput;

        if (abs($nilaiData) > 999999999.999) {
            redirectWithMessage('edit_error=Nilai%20counter%20berada%20di%20luar%20batas%20yang%20didukung');
        }

        try {
            $editStmt = $pdo->prepare("
                UPDATE counter_readings
                SET tanggal = :tanggal,
                    jam = :jam,
                    nilai_data = :nilai_data,
                    source = 'manual'
                WHERE id = :id
            ");

            $editStmt->execute([
                'tanggal' => $tanggal,
                'jam' => $jam,
                'nilai_data' => $nilaiData,
                'id' => $editId,
            ]);

            redirectWithMessage('detail=' . $editId . '&updated=1');
        } catch (PDOException $e) {
            error_log('OLTC dashboard edit error: ' . $e->getMessage());
            redirectWithMessage('edit_error=Data%20gagal%20diperbarui');
        }
    }
}

$detailId = isset($_GET['detail']) && ctype_digit((string) $_GET['detail']) ? (int) $_GET['detail'] : 0;
$detailRecord = null;
$editId = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRecord = null;

if ($editId > 0) {
    requireAdmin();

    $editStmt = $pdo->prepare("
        SELECT id, tanggal, jam, nilai_data
        FROM counter_readings
        WHERE id = :id
        LIMIT 1
    ");
    $editStmt->execute(['id' => $editId]);
    $editRecord = $editStmt->fetch();

    if (!$editRecord) {
        redirectWithMessage('edit_error=Data%20tidak%20ditemukan');
    }
}

if ($detailId > 0) {
    $detailStmt = $pdo->prepare("
        SELECT id, tanggal, hari, jam, nilai_data, foto_path, source, created_at, updated_at
        FROM counter_readings
        WHERE id = :id
        LIMIT 1
    ");
    $detailStmt->execute(['id' => $detailId]);
    $detailRecord = $detailStmt->fetch();

    if (!$detailRecord) {
        header('Location: index.php');
        exit;
    }
}

$filterStart = $_GET['start_date'] ?? '';
$filterEnd = $_GET['end_date'] ?? '';
$filterMinValue = $_GET['min_value'] ?? '';
$filterMaxValue = $_GET['max_value'] ?? '';
$trendPeriod = (string) ($_GET['trend_period'] ?? 'harian');
if (!in_array($trendPeriod, ['harian', 'mingguan', 'bulanan'], true)) {
    $trendPeriod = 'harian';
}
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);
$perPage = 20;

$where = [];
$params = [];

$hasActiveFilters = $filterStart !== '' || $filterEnd !== '' || $filterMinValue !== '' || $filterMaxValue !== '';

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

    $totalData = (int) ($stats['total_data'] ?? 0);
    $totalPages = max(1, (int) ceil($totalData / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $chartStmt = $pdo->prepare("
        SELECT id, tanggal, jam, nilai_data
        FROM counter_readings
        $whereSql
        ORDER BY tanggal DESC, jam DESC, id DESC
        LIMIT 5000
    ");
    $chartStmt->execute($params);
    $chartDataRows = array_reverse($chartStmt->fetchAll());

    $trendBuckets = [];
    foreach ($chartDataRows as $row) {
        $date = new DateTimeImmutable((string) $row['tanggal']);
        $bucketStart = match ($trendPeriod) {
            'mingguan' => $date->modify('monday this week'),
            'bulanan' => $date->modify('first day of this month'),
            default => $date,
        };
        $bucketKey = $bucketStart->format('Y-m-d');

        if (!isset($trendBuckets[$bucketKey])) {
            $trendBuckets[$bucketKey] = [
                'label' => $trendPeriod === 'bulanan'
                    ? $bucketStart->format('Y-m')
                    : ($trendPeriod === 'mingguan'
                        ? 'Minggu ' . $bucketStart->format('d/m/Y')
                        : $bucketStart->format('d/m/Y')),
                'count' => 0,
                'sum' => 0.0,
                'min' => (float) $row['nilai_data'],
                'max' => (float) $row['nilai_data'],
                'first' => (float) $row['nilai_data'],
                'last' => (float) $row['nilai_data'],
            ];
        }

        $value = (float) $row['nilai_data'];
        $trendBuckets[$bucketKey]['count']++;
        $trendBuckets[$bucketKey]['sum'] += $value;
        $trendBuckets[$bucketKey]['min'] = min($trendBuckets[$bucketKey]['min'], $value);
        $trendBuckets[$bucketKey]['max'] = max($trendBuckets[$bucketKey]['max'], $value);
        $trendBuckets[$bucketKey]['last'] = $value;
    }

    $trendData = [];
    foreach ($trendBuckets as $bucket) {
        $trendData[] = [
            'label' => $bucket['label'],
            'count' => $bucket['count'],
            'average' => $bucket['sum'] / $bucket['count'],
            'min' => $bucket['min'],
            'max' => $bucket['max'],
            'first' => $bucket['first'],
            'last' => $bucket['last'],
        ];
    }

    $trendCount = count($trendData);
    $trendTotalCount = array_sum(array_column($trendData, 'count'));
    $trendTotalSum = 0.0;
    foreach ($trendData as $period) {
        $trendTotalSum += $period['average'] * $period['count'];
    }
    $trendAverage = $trendTotalCount > 0 ? $trendTotalSum / $trendTotalCount : null;
    $trendMin = $trendCount > 0 ? min(array_column($trendData, 'min')) : null;
    $trendMax = $trendCount > 0 ? max(array_column($trendData, 'max')) : null;
    $trendFirst = $trendCount > 0 ? $trendData[0]['first'] : null;
    $trendLast = $trendCount > 0 ? $trendData[$trendCount - 1]['last'] : null;
    $trendChange = ($trendFirst !== null && $trendLast !== null) ? $trendLast - $trendFirst : null;
    $trendPercent = ($trendChange !== null && $trendFirst != 0) ? ($trendChange / abs($trendFirst)) * 100 : null;
    $trendDirection = $trendChange === null
        ? 'Belum cukup data'
        : ($trendChange > 0 ? 'Cenderung naik' : ($trendChange < 0 ? 'Cenderung turun' : 'Relatif stabil'));

    $dataStmt = $pdo->prepare("
        SELECT
            id,
            hari,
            tanggal,
            jam,
            nilai_data,
            foto_path,
            source
        FROM counter_readings
        $whereSql
        ORDER BY tanggal DESC, jam DESC, id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($params);
    $data = $dataStmt->fetchAll();

    $latestRow = $chartDataRows[count($chartDataRows) - 1] ?? null;
    $previousRow = $chartDataRows[count($chartDataRows) - 2] ?? null;
    $latestValue = $latestRow['nilai_data'] ?? 0;
    $previousValue = $previousRow['nilai_data'] ?? null;
    $valueChange = $previousValue !== null
        ? (float) $latestValue - (float) $previousValue
        : null;

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

$totalData = (int) ($stats['total_data'] ?? 0);
$tableStart = $totalData > 0 ? $offset + 1 : 0;
$tableEnd = min($offset + $perPage, $totalData);
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

        <div class="header-status">
            <div class="header-user">
                <span><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
                <strong><?= $isAdmin ? 'ADMIN' : 'VIEWER' ?></strong>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit">Keluar</button>
                </form>
            </div>
            <div class="status">
                <span class="status-dot"></span>
                Database Connected
            </div>
            <?php if ($isAdmin): ?>
            <div class="header-nav-actions">
                <a class="secondary-button" href="users.php">Manajemen User</a>
            </div>
        <?php endif; ?>
            <?php if ($latestDateTime): ?>
                <div class="header-meta">
                    Latest&nbsp;&nbsp;<?= htmlspecialchars($latestDateTime) ?>
                </div>
            <?php endif; ?>
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

        <?php if ($hasActiveFilters): ?>
            <div class="active-filters">
                <span class="active-filters-label">Filter aktif</span>
                <?php if ($filterStart !== ''): ?>
                    <span class="filter-chip">Mulai: <?= htmlspecialchars($filterStart) ?></span>
                <?php endif; ?>
                <?php if ($filterEnd !== ''): ?>
                    <span class="filter-chip">Sampai: <?= htmlspecialchars($filterEnd) ?></span>
                <?php endif; ?>
                <?php if ($filterMinValue !== ''): ?>
                    <span class="filter-chip">Min: <?= htmlspecialchars($filterMinValue) ?></span>
                <?php endif; ?>
                <?php if ($filterMaxValue !== ''): ?>
                    <span class="filter-chip">Maks: <?= htmlspecialchars($filterMaxValue) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </section>

    <?php if ($detailRecord): ?>
<section class="card detail-card">
    <div class="data-header">
        <div>
            <p class="eyebrow">DETAIL PEMBACAAN</p>
            <h2 class="data-title">Data #<?= (int) $detailRecord['id'] ?></h2>
            <p class="data-description">Pemeriksaan satu pembacaan counter dan evidence yang tersimpan.</p>
        </div>
        <div class="detail-actions">
            <?php if ($isAdmin): ?>
                <a class="secondary-button" href="?edit=<?= (int) $detailRecord['id'] ?>">Edit data</a>
            <?php endif; ?>
            <a class="secondary-button" href="index.php">Kembali ke riwayat</a>
        </div>
    </div>
    <div class="detail-grid">
        <div><span>Hari</span><strong><?= htmlspecialchars((string) $detailRecord['hari']) ?></strong></div>
        <div><span>Tanggal</span><strong><?= htmlspecialchars((string) $detailRecord['tanggal']) ?></strong></div>
        <div><span>Jam</span><strong><?= htmlspecialchars((string) $detailRecord['jam']) ?></strong></div>
        <div><span>Nilai Counter</span><strong><?= htmlspecialchars(number_format((float) $detailRecord['nilai_data'], 3, ',', '.')) ?></strong></div>
        <div><span>Sumber Data</span><strong><?= ($detailRecord['source'] ?? 'api') === 'manual' ? 'Manual' : 'API' ?></strong></div>
        <div><span>Dibuat</span><strong><?= htmlspecialchars((string) $detailRecord['created_at']) ?></strong></div>
        <div><span>Diperbarui</span><strong><?= htmlspecialchars((string) $detailRecord['updated_at']) ?></strong></div>
    </div>
    <?php if (!empty($detailRecord['foto_path'])): ?>
        <div class="detail-evidence">
            <div class="detail-evidence-header">
                <span>Evidence</span>
                <a href="<?= htmlspecialchars('public/' . ltrim(str_replace('\\', '/', (string) $detailRecord['foto_path']), '/')) ?>" target="_blank" rel="noopener">Buka ukuran penuh</a>
            </div>
            <img src="<?= htmlspecialchars('public/' . ltrim(str_replace('\\', '/', (string) $detailRecord['foto_path']), '/')) ?>" alt="Evidence pembacaan counter ID <?= (int) $detailRecord['id'] ?>">
        </div>
    <?php else: ?>
        <div class="empty-evidence">Tidak ada evidence foto untuk pembacaan ini.</div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($editRecord): ?>
<section class="card edit-card">
    <div class="data-header">
        <div>
            <p class="eyebrow">EDIT DATA</p>
            <h2 class="data-title">Perbarui Pembacaan #<?= (int) $editRecord['id'] ?></h2>
            <p class="data-description">Perubahan manual akan dicatat sebagai sumber data <strong>Manual</strong>. Evidence foto yang sudah tersimpan tidak diubah.</p>
        </div>
        <a class="secondary-button" href="?detail=<?= (int) $editRecord['id'] ?>">Batal</a>
    </div>

    <form class="edit-form" method="post" action="index.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">

        <div class="edit-field">
            <label for="edit_tanggal">Tanggal</label>
            <input type="date" id="edit_tanggal" name="tanggal" value="<?= htmlspecialchars((string) $editRecord['tanggal']) ?>" required>
        </div>

        <div class="edit-field">
            <label for="edit_jam">Jam</label>
            <input type="time" id="edit_jam" name="jam" step="1" value="<?= htmlspecialchars((string) $editRecord['jam']) ?>" required>
        </div>

        <div class="edit-field">
            <label for="edit_nilai_data">Nilai Counter</label>
            <input type="number" id="edit_nilai_data" name="nilai_data" step="0.001" value="<?= htmlspecialchars((string) $editRecord['nilai_data']) ?>" required>
        </div>

        <div class="edit-actions">
            <button type="submit" class="button button-primary">Simpan Perubahan</button>
            <a href="?detail=<?= (int) $editRecord['id'] ?>" class="button button-secondary">Batal</a>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card chart-card">
        <div class="data-header trend-header">
            <div>
                <h2 class="data-title">Trend Pembacaan Counter</h2>
                <p class="data-description">Analisis perubahan nilai berdasarkan agregasi periode dan filter data yang sedang aktif.</p>
            </div>
            <div class="trend-controls">
                <span>PERIODE</span>
                <div class="trend-periods">
                    <?php foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $periodKey => $periodLabel): ?>
                        <?php $periodQuery = $_GET; $periodQuery['trend_period'] = $periodKey; $periodQuery['page'] = 1; ?>
                        <a class="<?= $trendPeriod === $periodKey ? 'is-active' : '' ?>" href="index.php?<?= htmlspecialchars(http_build_query($periodQuery)) ?>"><?= htmlspecialchars($periodLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (empty($trendData)): ?>
            <div class="empty">Belum ada data untuk dianalisis pada grafik.</div>
        <?php else: ?>
            <div class="trend-analysis">
                <div class="trend-metrics">
                    <div class="trend-metric"><span>Periode</span><strong><?= number_format($trendCount, 0, ',', '.') ?></strong><small><?= htmlspecialchars(ucfirst($trendPeriod)) ?></small></div>
                    <div class="trend-metric"><span>Perubahan</span><strong class="<?= $trendChange > 0 ? 'change-up' : ($trendChange < 0 ? 'change-down' : 'change-neutral') ?>"><?= $trendChange > 0 ? '+' : '' ?><?= number_format((float) $trendChange, 3, ',', '.') ?></strong><small><?= $trendPercent !== null ? ($trendPercent > 0 ? '+' : '') . number_format($trendPercent, 2, ',', '.') . '%' : 'Persentase tidak tersedia' ?></small></div>
                    <div class="trend-metric"><span>Arah Trend</span><strong class="<?= $trendChange > 0 ? 'change-up' : ($trendChange < 0 ? 'change-down' : 'change-neutral') ?>"><?= htmlspecialchars($trendDirection) ?></strong><small><?= $trendFirst !== null && $trendLast !== null ? number_format($trendFirst, 3, ',', '.') . ' → ' . number_format($trendLast, 3, ',', '.') : 'Belum cukup data' ?></small></div>
                </div>
                <div class="chart-wrap">
                    <svg id="counterChart" class="counter-chart" role="img" aria-labelledby="counterChartTitle counterChartDescription"></svg>
                </div>
                <div class="trend-period-table-wrap">
                    <table class="trend-period-table">
                        <thead><tr><th>Periode</th><th>Data</th><th>Rata-rata</th><th>Min</th><th>Maks</th><th>Awal → Akhir</th></tr></thead>
                        <tbody>
                        <?php foreach ($trendData as $period): ?>
                            <tr>
                                <td><?= htmlspecialchars($period['label']) ?></td>
                                <td><?= number_format($period['count'], 0, ',', '.') ?></td>
                                <td><?= number_format($period['average'], 3, ',', '.') ?></td>
                                <td><?= number_format($period['min'], 3, ',', '.') ?></td>
                                <td><?= number_format($period['max'], 3, ',', '.') ?></td>
                                <td><?= number_format($period['first'], 3, ',', '.') ?> → <?= number_format($period['last'], 3, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">
            Data pembacaan berhasil diperbarui. Sumber data sekarang tercatat sebagai Manual.
        </div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">
            Data pembacaan berhasil dihapus.
        </div>
    <?php elseif (isset($_GET['delete_error'])): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars((string) $_GET['delete_error']) ?>
        </div>
    <?php elseif (isset($_GET['edit_error'])): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars((string) $_GET['edit_error']) ?>
        </div>
    <?php endif; ?>

    <section class="card data-card">

        <div class="data-header">
            <div>
                <h2 class="data-title">Riwayat Pembacaan</h2>
                <p class="data-description">
                    Menampilkan <?= $tableStart ?>–<?= $tableEnd ?> dari <?= $totalData ?> data sesuai filter.
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
                            <th>Sumber</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data as $row): ?>

                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td><?= htmlspecialchars($row['hari']) ?></td>
                            <td><?= htmlspecialchars($row['tanggal']) ?></td>
                            <td class="time"><?= htmlspecialchars($row['jam']) ?></td>
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
                            <td class="source-cell">
                                <span class="source-badge source-<?= ($row['source'] ?? 'api') === 'manual' ? 'manual' : 'api' ?>">
                                    <?= ($row['source'] ?? 'api') === 'manual' ? 'Manual' : 'API' ?>
                                </span>
                            </td>
                            <td class="table-action-cell">
                                <div class="table-actions">
                                    <a class="action-button action-detail" href="?detail=<?= (int) $row['id'] ?>">Detail</a>
                                    <?php if ($isAdmin): ?>
                                    <form method="post" action="index.php" onsubmit="return confirm('Hapus data pembacaan ID <?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>? Data dan evidence fotonya akan dihapus permanen.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $row['id']) ?>">
                                        <button type="submit" class="action-button action-delete">Hapus</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>

        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php
                $baseQuery = $_GET;
                $pageUrl = static function (int $targetPage) use ($baseQuery): string {
                    $query = $baseQuery;
                    $query['page'] = $targetPage;
                    return 'index.php?' . http_build_query($query);
                };
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <?php if ($page > 1): ?>
                    <a class="pagination-link" href="<?= htmlspecialchars($pageUrl($page - 1)) ?>">Sebelumnya</a>
                <?php endif; ?>

                <?php if ($startPage > 1): ?>
                    <a class="pagination-link" href="<?= htmlspecialchars($pageUrl(1)) ?>">1</a>
                    <?php if ($startPage > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a class="pagination-link <?= $i === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($pageUrl($i)) ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                    <a class="pagination-link" href="<?= htmlspecialchars($pageUrl($totalPages)) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="pagination-link" href="<?= htmlspecialchars($pageUrl($page + 1)) ?>">Berikutnya</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

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
            'label' => $row['label'],
            'value' => (float) $row['average'],
            'count' => (int) $row['count'],
            'min' => (float) $row['min'],
            'max' => (float) $row['max'],
        ];
    }, $trendData), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function renderCounterChart() {
        const svg = document.getElementById('counterChart');

        if (!svg || chartData.length === 0) {
            return;
        }

        const width = Math.max(svg.parentElement.clientWidth, 320);
        const height = 330;
        const padding = { top: 28, right: 24, bottom: 58, left: 68 };

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
            if (parts.length < 2) return label;
            const date = parts[0].split('-');
            const time = parts[1].slice(0, 5);
            return date.length === 3 ? date[2] + '/' + date[1] + ' ' + time : time;
        };

        const gridCount = 5;
        let markup = '<title id="counterChartTitle">Trend pembacaan counter OLTC</title><desc id="counterChartDescription">Grafik menunjukkan perubahan nilai counter berdasarkan pembacaan terbaru sesuai filter yang dipilih.</desc>';

        if (chartData.length > 1) {
            const firstValue = chartData[0].value;
            const lastValue = chartData[chartData.length - 1].value;
            const trend = lastValue > firstValue ? 'meningkat' : (lastValue < firstValue ? 'menurun' : 'tidak berubah');
            markup += '<text x="' + padding.left + '" y="15" class="chart-trend-note">Trend periode: ' + trend + '</text>';
        }

        for (let i = 0; i <= gridCount; i++) {
            const value = minValue + ((maxValue - minValue) * (gridCount - i) / gridCount);
            const yPos = y(value);

            markup += `<line x1="${padding.left}" y1="${yPos}" x2="${width - padding.right}" y2="${yPos}" class="chart-grid-line"></line>`;
            markup += `<text x="${padding.left - 10}" y="${yPos + 4}" text-anchor="end" class="chart-axis-label">${formatValue(value)}</text>`;
        }

        const points = chartData.map((item, index) => `${x(index)},${y(item.value)}`).join(' ');

        const areaPoints = padding.left + ',' + (height - padding.bottom) + ' ' + points + ' ' + x(chartData.length - 1) + ',' + (height - padding.bottom);
        markup += '<polygon points="' + areaPoints + '" class="chart-area"></polygon>';
        markup += '<polyline points="' + points + '" class="chart-line"></polyline>';

        chartData.forEach((item, index) => {
            const cx = x(index);
            const cy = y(item.value);

            const pointClass = index === chartData.length - 1 ? 'chart-point chart-point-latest' : 'chart-point';
            markup += '<circle cx="' + cx + '" cy="' + cy + '" r="' + (index === chartData.length - 1 ? 5.5 : 4) + '" class="' + pointClass + '" tabindex="0" aria-label="' + item.label + ', nilai ' + formatValue(item.value) + '"><title>' + item.label + ' — ' + formatValue(item.value) + '</title></circle>';

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

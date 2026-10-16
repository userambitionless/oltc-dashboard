<?php

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

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

        // Input type="time" may submit HH:MM when seconds are not explicitly entered.
        // Normalize both HH:MM and HH:MM:SS into the database's HH:MM:SS format.
        $time = DateTime::createFromFormat('!H:i:s', $jam);
        if (!$time) {
            $time = DateTime::createFromFormat('!H:i', $jam);
            if ($time) {
                $jam .= ':00';
                $time = DateTime::createFromFormat('!H:i:s', $jam);
            }
        }
        $timeErrors = DateTime::getLastErrors();
        $validTime = $time
            && ($timeErrors === false || ($timeErrors['warning_count'] === 0 && $timeErrors['error_count'] === 0))
            && $time->format('H:i:s') === $jam;

        // DECIMAL columns can return values such as "157386.000".
        // Accept an optional decimal fraction while rejecting separators and malformed values.
        $validCounterValue = preg_match('/^\\d+(?:\\.\\d{1,3})?$/', $nilaiDataInput) === 1;

        if (!$validDate || !$validTime || !$validCounterValue) {
            redirectWithMessage('edit_error=Data%20tanggal%2C%20jam%2C%20atau%20nilai%20tidak%20valid');
        }

        $nilaiData = (float) $nilaiDataInput;

        if (!is_finite($nilaiData) || $nilaiData < 0 || $nilaiData > 999999999.999) {
            redirectWithMessage('edit_error=Nilai%20counter%20berada%20di%20luar%20batas%20yang%20didukung');
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (strlen($reason) > 500) {
            redirectWithMessage('edit_error=Alasan%20koreksi%20maksimal%20500%20karakter');
        }

        try {
            $pdo->beginTransaction();

            // Lock the current row so the audit entry and update use the same original values.
            $currentStmt = $pdo->prepare("
                SELECT id, tanggal, jam, nilai_data, source
                FROM counter_readings
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $currentStmt->execute(['id' => $editId]);
            $currentRecord = $currentStmt->fetch();

            if (!$currentRecord) {
                $pdo->rollBack();
                redirectWithMessage('edit_error=Data%20tidak%20ditemukan');
            }

            $oldValue = (float) $currentRecord['nilai_data'];
            $hasChanges = (string) $currentRecord['tanggal'] !== $tanggal
                || (string) $currentRecord['jam'] !== $jam
                || $oldValue !== (float) $nilaiData;

            if ($hasChanges) {
                // Fail closed if the audit migration has not been applied: never update without a log.
                $auditStmt = $pdo->prepare("
                    INSERT INTO counter_reading_corrections (
                        counter_reading_id,
                        old_tanggal,
                        old_jam,
                        old_nilai_data,
                        new_tanggal,
                        new_jam,
                        new_nilai_data,
                        original_source,
                        changed_by_user_id,
                        changed_by_username,
                        reason
                    ) VALUES (
                        :counter_reading_id,
                        :old_tanggal,
                        :old_jam,
                        :old_nilai_data,
                        :new_tanggal,
                        :new_jam,
                        :new_nilai_data,
                        :original_source,
                        :changed_by_user_id,
                        :changed_by_username,
                        :reason
                    )
                ");

                $userId = filter_var($currentUser['id'] ?? null, FILTER_VALIDATE_INT);
                $auditStmt->execute([
                    'counter_reading_id' => $editId,
                    'old_tanggal' => $currentRecord['tanggal'],
                    'old_jam' => $currentRecord['jam'],
                    'old_nilai_data' => $currentRecord['nilai_data'],
                    'new_tanggal' => $tanggal,
                    'new_jam' => $jam,
                    'new_nilai_data' => $nilaiData,
                    'original_source' => (string) ($currentRecord['source'] ?? 'api'),
                    'changed_by_user_id' => ($userId !== false && $userId !== null && $userId > 0) ? $userId : null,
                    'changed_by_username' => substr((string) ($currentUser['username'] ?? 'unknown'), 0, 100),
                    'reason' => $reason !== '' ? $reason : null,
                ]);

                // Keep source unchanged: correcting a Main System reading does not make it a Manual-origin reading.
                $editStmt = $pdo->prepare("
                    UPDATE counter_readings
                    SET tanggal = :tanggal,
                        jam = :jam,
                        nilai_data = :nilai_data
                    WHERE id = :id
                ");
                $editStmt->execute([
                    'tanggal' => $tanggal,
                    'jam' => $jam,
                    'nilai_data' => $nilaiData,
                    'id' => $editId,
                ]);
            }

            $pdo->commit();
            redirectWithMessage('detail=' . $editId . '&updated=1');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('OLTC dashboard edit error: ' . $e->getMessage());
            if (str_contains(strtolower($e->getMessage()), 'counter_reading_corrections')) {
                redirectWithMessage('edit_error=Tabel%20riwayat%20koreksi%20belum%20tersedia.%20Jalankan%20migrasi%20SQL%20terlebih%20dahulu');
            }
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

$correctionHistory = [];
$correctionHistoryAvailable = true;
if ($detailRecord) {
    try {
        $historyStmt = $pdo->prepare("
            SELECT old_tanggal, old_jam, old_nilai_data,
                   new_tanggal, new_jam, new_nilai_data,
                   original_source, changed_by_username, reason, changed_at
            FROM counter_reading_corrections
            WHERE counter_reading_id = :id
            ORDER BY changed_at DESC, id DESC
        ");
        $historyStmt->execute(['id' => $detailId]);
        $correctionHistory = $historyStmt->fetchAll();
    } catch (PDOException $e) {
        $correctionHistoryAvailable = false;
        error_log('OLTC dashboard correction history error: ' . $e->getMessage());
    }
}

$filterStart = $_GET['start_date'] ?? '';
$filterEnd = $_GET['end_date'] ?? '';
$filterMinValue = $_GET['min_value'] ?? '';
$filterMaxValue = $_GET['max_value'] ?? '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);
$perPage = 10;

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

if ($filterMinValue !== '' && preg_match('/^\d+$/', $filterMinValue)) {
    $where[] = 'nilai_data >= :min_value';
    $params['min_value'] = $filterMinValue;
}

if ($filterMaxValue !== '' && preg_match('/^\d+$/', $filterMaxValue)) {
    $where[] = 'nilai_data <= :max_value';
    $params['max_value'] = $filterMaxValue;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Export seluruh hasil filter, bukan hanya 10 baris pada halaman aktif.
// Fitur ini hanya membaca data dan tidak membutuhkan perubahan skema database.
if (in_array(($_GET['export'] ?? ''), ['csv', 'xlsx'], true)) {
    try {
        $exportStmt = $pdo->prepare("
            SELECT id, hari, tanggal, jam, nilai_data, foto_path, source
            FROM counter_readings
            $whereSql
            ORDER BY tanggal DESC, jam DESC, id DESC
        ");
        $exportStmt->execute($params);
        $exportRows = $exportStmt->fetchAll();

        // Nomor tampilan tetap dihitung dari semua pembacaan pada tanggal yang sama,
        // sama seperti tabel riwayat dan tidak berubah akibat filter nilai.
        $displayIdByRecord = [];
        if ($exportRows) {
            $exportDates = array_values(array_unique(array_map(
                static fn (array $row): string => (string) $row['tanggal'],
                $exportRows
            )));
            $datePlaceholders = [];
            $dateParams = [];
            foreach ($exportDates as $index => $dateValue) {
                $placeholder = ':export_date_' . $index;
                $datePlaceholders[] = $placeholder;
                $dateParams[$placeholder] = $dateValue;
            }

            $allDateRowsStmt = $pdo->prepare(
                'SELECT id, tanggal
                 FROM counter_readings
                 WHERE tanggal IN (' . implode(', ', $datePlaceholders) . ')
                 ORDER BY tanggal ASC, jam ASC, id ASC'
            );
            $allDateRowsStmt->execute($dateParams);
            $displayCountersByDate = [];
            foreach ($allDateRowsStmt->fetchAll() as $dateRow) {
                $dateKey = (string) $dateRow['tanggal'];
                $displayCountersByDate[$dateKey] = ($displayCountersByDate[$dateKey] ?? 0) + 1;
                $displayIdByRecord[(int) $dateRow['id']] = $displayCountersByDate[$dateKey];
            }
        }

        // Label koreksi bersifat opsional agar ekspor tetap berjalan jika tabel audit
        // belum ada pada instalasi lama. Tidak ada tabel atau kolom yang ditambahkan.
        $correctedRecordIds = [];
        if ($exportRows) {
            $recordIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['id'],
                $exportRows
            )));
            $recordPlaceholders = [];
            $recordParams = [];
            foreach ($recordIds as $index => $recordId) {
                $placeholder = ':export_record_' . $index;
                $recordPlaceholders[] = $placeholder;
                $recordParams[$placeholder] = $recordId;
            }

            try {
                $correctedStmt = $pdo->prepare(
                    'SELECT DISTINCT counter_reading_id
                     FROM counter_reading_corrections
                     WHERE counter_reading_id IN (' . implode(', ', $recordPlaceholders) . ')'
                );
                $correctedStmt->execute($recordParams);
                foreach ($correctedStmt->fetchAll(PDO::FETCH_COLUMN) as $correctedId) {
                    $correctedRecordIds[(int) $correctedId] = true;
                }
            } catch (PDOException $e) {
                error_log('OLTC dashboard export correction labels error: ' . $e->getMessage());
            }
        }

        if (($_GET['export'] ?? '') === 'xlsx') {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)
                || !class_exists(\PhpOffice\PhpSpreadsheet\Writer\Xlsx::class)) {
                http_response_code(503);
                exit('Ekspor XLSX belum siap. Jalankan composer install di folder proyek terlebih dahulu.');
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Riwayat OLTC');

            $headers = [
                'ID Pembacaan',
                'Hari',
                'Tanggal',
                'Jam',
                'Nilai Counter',
                'Evidence Path',
                'Sumber Data',
            ];
            $sheet->fromArray($headers, null, 'A1');

            $excelRow = 2;
            foreach ($exportRows as $row) {
                $isManualSource = ($row['source'] ?? 'api') === 'manual';
                $sourceLabel = $isManualSource ? 'Manual' : 'Main System';
                if (isset($correctedRecordIds[(int) $row['id']])) {
                    $sourceLabel .= ' (E)';
                }

                $counterValue = is_numeric($row['nilai_data']) ? (float) $row['nilai_data'] : (string) $row['nilai_data'];
                $sheet->fromArray([
                    (int) ($displayIdByRecord[(int) $row['id']] ?? 0),
                    (string) ($row['hari'] ?? ''),
                    (string) $row['tanggal'],
                    (string) $row['jam'],
                    $counterValue,
                    (string) ($row['foto_path'] ?? ''),
                    $sourceLabel,
                ], null, 'A' . $excelRow);
                $excelRow++;
            }

            $lastRow = max(1, $excelRow - 1);
            $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()
                ->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:G1')->getFill()->setFillType(
                \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
            )->getStartColor()->setARGB('FF17324D');
            $sheet->getStyle('A1:G1')->getAlignment()->setVertical(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            );
            $sheet->getRowDimension(1)->setRowHeight(24);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:G' . $lastRow);

            foreach (range('A', 'G') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->getColumnDimension('F')->setWidth(32);
            if ($lastRow >= 2) {
                $sheet->getStyle('A2:A' . $lastRow)->getNumberFormat()->setFormatCode('0');
                $sheet->getStyle('E2:E' . $lastRow)->getNumberFormat()->setFormatCode('0.###');
            }

            $filename = 'oltc-riwayat-pembacaan-' . date('Ymd-His') . '.xlsx';
            $temporaryFile = tempnam(sys_get_temp_dir(), 'oltc_xlsx_');

            if ($temporaryFile === false) {
                $spreadsheet->disconnectWorksheets();
                throw new RuntimeException('Tidak dapat menyiapkan file sementara untuk ekspor Excel.');
            }

            try {
                // Selesaikan arsip XLSX sebelum mengirim respons agar file tidak parsial.
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save($temporaryFile);
                $spreadsheet->disconnectWorksheets();

                if (!is_file($temporaryFile) || filesize($temporaryFile) < 4) {
                    throw new RuntimeException('File XLSX hasil ekspor kosong atau tidak lengkap.');
                }

                // Buang output buffer agar whitespace/warning dari include tidak merusak arsip ZIP.
                while (ob_get_level() > 0) {
                    if (!ob_end_clean()) {
                        break;
                    }
                }

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . (string) filesize($temporaryFile));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Pragma: no-cache');
                header('X-Content-Type-Options: nosniff');

                readfile($temporaryFile);
                unlink($temporaryFile);
                exit;
            } catch (Throwable $e) {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
                error_log('OLTC dashboard XLSX export error: ' . $e->getMessage());
                http_response_code(500);
                exit('File Excel gagal dibuat. Periksa log PHP untuk detail kesalahan.');
            }
        }

        $filename = 'oltc-riwayat-pembacaan-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException('Tidak dapat membuka output ekspor.');
        }

        // BOM membantu Excel mengenali UTF-8; escape kosong menghasilkan CSV standar.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'ID Pembacaan',
            'Hari',
            'Tanggal',
            'Jam',
            'Nilai Counter',
            'Evidence Path',
            'Sumber Data',
        ], ';', '"', '');

        foreach ($exportRows as $row) {
            $isManualSource = ($row['source'] ?? 'api') === 'manual';
            $sourceLabel = $isManualSource ? 'Manual' : 'Main System';
            if (isset($correctedRecordIds[(int) $row['id']])) {
                $sourceLabel .= ' (E)';
            }

            fputcsv($output, [
                (string) ($displayIdByRecord[(int) $row['id']] ?? ''),
                (string) ($row['hari'] ?? ''),
                (string) $row['tanggal'],
                (string) $row['jam'],
                formatCounterValue($row['nilai_data']),
                (string) ($row['foto_path'] ?? ''),
                $sourceLabel,
            ], ';', '"', '');
        }

        fclose($output);
        exit;
    } catch (PDOException $e) {
        error_log('OLTC dashboard CSV export error: ' . $e->getMessage());
        http_response_code(500);
        exit('Ekspor riwayat gagal. Silakan coba lagi atau periksa log server.');
    }
}

try {
    $countStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_data
        FROM counter_readings
        $whereSql
    ");
    $countStmt->execute($params);
    $stats = $countStmt->fetch();

    $totalData = (int) ($stats['total_data'] ?? 0);
    $totalPages = max(1, (int) ceil($totalData / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // Grafik, arah trend, dan pembacaan terakhir menggunakan urutan waktu yang sama.
    // ID menjadi penentu urutan jika beberapa data memiliki tanggal dan jam yang sama.
    $chartStmt = $pdo->prepare("
        SELECT id, tanggal, jam, nilai_data
        FROM counter_readings
        $whereSql
        ORDER BY tanggal ASC, jam ASC, id ASC
    ");
    $chartStmt->execute($params);
    $chartDataRows = $chartStmt->fetchAll();

    $trendData = [];
    foreach ($chartDataRows as $index => $row) {
        $trendData[] = [
            'id' => (int) $row['id'],
            'label' => 'Pembacaan #' . ($index + 1),
            'tanggal' => (string) $row['tanggal'],
            'jam' => (string) $row['jam'],
            'value' => (float) $row['nilai_data'],
        ];
    }

    $trendCount = count($trendData);

    // Nomor tampilan dihitung setelah data halaman diambil, memakai seluruh pembacaan
    // pada tanggal terkait agar nomor tidak berubah saat filter nilai atau pagination berubah.

    // Arah trend menggunakan maksimal 3 pembacaan aktual paling terakhir.
    $recentTrendRows = array_slice($chartDataRows, -3);
    $recentTrendValues = array_map(
        static fn (array $row): float => (float) $row['nilai_data'],
        $recentTrendRows
    );
    $recentTrendCount = count($recentTrendValues);

    if ($recentTrendCount < 2) {
        $trendDirection = 'Belum cukup data';
    } elseif ($recentTrendCount === 2) {
        $recentChange = $recentTrendValues[1] - $recentTrendValues[0];
        $trendDirection = $recentChange > 0
            ? 'Cenderung naik'
            : ($recentChange < 0 ? 'Cenderung turun' : 'Relatif stabil');
    } else {
        $firstRecent = $recentTrendValues[0];
        $middleRecent = $recentTrendValues[1];
        $lastRecent = $recentTrendValues[2];

        if ($lastRecent > $middleRecent && $middleRecent > $firstRecent) {
            $trendDirection = 'Cenderung naik';
        } elseif ($lastRecent < $middleRecent && $middleRecent < $firstRecent) {
            $trendDirection = 'Cenderung turun';
        } else {
            $recentNetChange = $lastRecent - $firstRecent;
            $trendDirection = $recentNetChange > 0
                ? 'Cenderung naik'
                : ($recentNetChange < 0 ? 'Cenderung turun' : 'Berfluktuasi');
        }
    }

    $recentTrendSummary = $recentTrendCount > 0
        ? implode(' → ', array_map(
            static fn (float $value): string => formatCounterValue($value),
            $recentTrendValues
        ))
        : 'Belum cukup data';

    $trendDirectionClass = str_contains($trendDirection, 'naik')
        ? 'change-up'
        : (str_contains($trendDirection, 'turun') ? 'change-down' : 'change-neutral');

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

    $displayIdByRecord = [];
    if (!empty($data)) {
        $pageDates = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['tanggal'],
            $data
        )));
        $datePlaceholders = [];
        $dateParams = [];
        foreach ($pageDates as $index => $dateValue) {
            $placeholder = ':display_date_' . $index;
            $datePlaceholders[] = $placeholder;
            $dateParams[$placeholder] = $dateValue;
        }

        $allDateRowsStmt = $pdo->prepare(
            'SELECT id, tanggal
             FROM counter_readings
             WHERE tanggal IN (' . implode(', ', $datePlaceholders) . ')
             ORDER BY tanggal ASC, jam ASC, id ASC'
        );
        $allDateRowsStmt->execute($dateParams);

        $displayCountersByDate = [];
        foreach ($allDateRowsStmt->fetchAll() as $dateRow) {
            $dateKey = (string) $dateRow['tanggal'];
            $displayCountersByDate[$dateKey] = ($displayCountersByDate[$dateKey] ?? 0) + 1;
            $displayIdByRecord[(int) $dateRow['id']] = $displayCountersByDate[$dateKey];
        }
    }

    // Tandai data yang pernah dikoreksi tanpa mengubah source asli (misalnya api/Main System).
    // Query terpisah agar dashboard tetap bisa dibuka jika tabel audit belum dibuat.
    $correctedRecordIds = [];
    if (!empty($data)) {
        try {
            $recordIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['id'],
                $data
            )));
            $placeholders = [];
            $correctionParams = [];
            foreach ($recordIds as $index => $recordId) {
                $placeholder = ':record_id_' . $index;
                $placeholders[] = $placeholder;
                $correctionParams[$placeholder] = $recordId;
            }

            $correctedStmt = $pdo->prepare(
                'SELECT DISTINCT counter_reading_id
                 FROM counter_reading_corrections
                 WHERE counter_reading_id IN (' . implode(', ', $placeholders) . ')'
            );
            $correctedStmt->execute($correctionParams);
            foreach ($correctedStmt->fetchAll(PDO::FETCH_COLUMN) as $correctedId) {
                $correctedRecordIds[(int) $correctedId] = true;
            }
        } catch (PDOException $e) {
            // Tanpa tabel audit, data tetap tampil dengan label sumber aslinya.
            error_log('OLTC dashboard corrected source labels error: ' . $e->getMessage());
        }
    }

    // Karena chartDataRows sudah diurutkan dari waktu terlama ke terbaru,
    // baris terakhir adalah pembacaan dengan tanggal/jam paling baru dalam filter.
    $latestRow = $chartDataRows[count($chartDataRows) - 1] ?? null;
    $latestValue = $latestRow['nilai_data'] ?? 0;

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

$totalData = (int) ($stats['total_data'] ?? 0);
$tableStart = $totalData > 0 ? $offset + 1 : 0;
$tableEnd = min($offset + $perPage, $totalData);
$latestDateTime = $latestRow
    ? ($latestRow['tanggal'] . ' ' . $latestRow['jam'])
    : null;

function formatCounterValue(float|int|string $value): string
{
    return number_format((float) $value, 0, '', '');
}

function formatEnglishDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);

    if (!$dateObject) {
        return $date;
    }

    return $dateObject->format('j F Y');
}

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

    <link rel="icon" type="image/png" href="favicon2.png">
    <link rel="shortcut icon" type="image/png" href="favicon2.png">

    <title>OLTC Monitoring Dashboard</title>

    <link rel="stylesheet" href="public/assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/public/assets/css/dashboard.css') ?>">
</head>

<body>


<button type="button" class="home-metal-button" id="aboutInfoButton" aria-label="About and Information" aria-controls="aboutInfoModal" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="8.7"></circle>
        <path d="M12 10.7v5.4"></path>
        <path d="M12 7.7h.01"></path>
    </svg>
</button>

<div class="about-info-modal" id="aboutInfoModal" aria-hidden="true">
    <div class="about-info-backdrop" data-about-close></div>
    <section class="about-info-dialog" role="dialog" aria-modal="true" aria-labelledby="aboutInfoTitle">
        <button type="button" class="about-info-close" data-about-close aria-label="Tutup">×</button>
        <div class="about-info-kicker">About and Information</div>
        <h2 id="aboutInfoTitle">OLTC Counter Monitoring System</h2>
        <p class="about-info-lead">
            Sistem monitoring untuk mencatat, memantau, dan menampilkan riwayat pembacaan counter OLTC secara terstruktur.
        </p>

        <div class="about-info-grid">
            <div class="about-info-item">
                <span>Project</span>
                <strong>OLTC Counter Dashboard</strong>
            </div>
            <div class="about-info-item">
                <span>Fungsi utama</span>
                <strong>Monitoring &amp; riwayat pembacaan</strong>
            </div>
            <div class="about-info-item">
                <span>Sumber data</span>
                <strong>Data pembacaan counter OLTC</strong>
            </div>
            <div class="about-info-item">
                <span>Platform</span>
                <strong>Web-based Monitoring System</strong>
            </div>
        </div>

        <div class="about-info-section">
            <h3>Tentang sistem</h3>
            <p>
                Dashboard ini dirancang sebagai bagian dari sistem monitoring counter OLTC.
                Pada tahap pengembangan berikutnya, nilai digital hasil pembacaan perangkat
                akan diterima oleh server dan ditampilkan pada dashboard sebagai data utama.
            </p>
        </div>

        <div class="about-info-section">
            <h3>Catatan pengembangan</h3>
            <p>
                Perangkat pembaca berbasis kamera dan Computer Vision/ML masih merupakan
                bagian dari rencana pengembangan proyek. Foto pembuktian pembacaan digunakan
                sebagai evidence, sedangkan nilai digital menjadi data utama sistem.
            </p>
        </div>
    </section>
</div>

<div class="page">

    <header class="header">
        <div class="header-brand">
            <div class="brand-row">
                <div class="brand-logos">
                    <img
                        class="pln-logo"
                        src="PLN%20Logo%20-%20Colored%20-%205716x2048%20-%20zonalogo.com.png"
                        alt="Logo PLN"
                    >
                    <img
                        class="oltc-logo"
                        src="favicon2.png"
                        alt="Logo OLTC"
                    >
                </div>
                <div class="brand-copy">
                    <p class="eyebrow">Monitoring System</p>
                    <h1>OLTC Counter Dashboard</h1>
                    <p class="subtitle">
                        Monitoring dan riwayat pembacaan counter OLTC berbasis data.
                    </p>
                </div>
            </div>
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
            <div class="header-meta header-realtime" aria-label="Waktu realtime">
                <div class="realtime-topline">
                    <span class="realtime-dot"></span>
                    <span class="realtime-label">Waktu Realtime</span>
                    <span class="realtime-zone">WIB</span>
                </div>
                <div class="realtime-time" aria-live="polite">
                    <span id="realtimeHourMinute">--:--</span><span id="realtimeSeconds" class="realtime-seconds">:--</span>
                </div>
                <div id="realtimeDate" class="realtime-date">Memuat tanggal...</div>
            </div>
            <?php if ($latestDateTime): ?>
                <div class="header-meta">
                    Latest&nbsp;&nbsp;<?= htmlspecialchars(formatEnglishDate($latestRow['tanggal']) . ' ' . $latestRow['jam']) ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <section class="stats">

        <a class="card stat-card stat-card-link" href="#counter-trend" aria-label="Lihat trend pembacaan counter">
            <div class="stat-label">Total Pembacaan</div>
            <div class="stat-value">
                <?= number_format($totalData, 0, ',', '.') ?>
            </div>
            <div class="stat-meta">
                Sesuai filter yang dipilih
            </div>
        </a>

        <a class="card stat-card stat-card-link" href="#reading-history" aria-label="Lihat riwayat dan tandai pembacaan terbaru">
            <div class="stat-label">Nilai Terbaru</div>
            <div class="stat-value">
                <?= htmlspecialchars(formatCounterValue($latestValue)) ?>
            </div>
            <div class="stat-meta">
                Hasil pembacaan terakhir
            </div>
        </a>

        <article class="card stat-card">
            <div class="stat-label">Pembacaan Terakhir</div>
            <div class="stat-value stat-datetime">
                <?php if ($latestDateTime): ?>
                    <span><?= htmlspecialchars(formatEnglishDate($latestRow['tanggal'])) ?></span>
                    <span class="stat-time"><?= htmlspecialchars($latestRow['jam']) ?></span>
                <?php else: ?>
                    <span>-</span>
                <?php endif; ?>
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
                    step="1"
                    value="<?= htmlspecialchars($filterMinValue) ?>"
                    placeholder="Contoh: 157387"
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
        <div><span>Tanggal</span><strong><?= htmlspecialchars(formatEnglishDate((string) $detailRecord['tanggal'])) ?></strong></div>
        <div><span>Jam</span><strong><?= htmlspecialchars((string) $detailRecord['jam']) ?></strong></div>
        <div><span>Nilai Counter</span><strong><?= htmlspecialchars(formatCounterValue($detailRecord['nilai_data'])) ?></strong></div>
        <div><span>Sumber Data</span><strong><?= ($detailRecord['source'] ?? 'api') === 'manual' ? 'Manual' : 'Main System' ?></strong></div>
        <div><span>Dibuat</span><strong><?= htmlspecialchars(formatEnglishDateTime($detailRecord['created_at'] ?? null)) ?></strong></div>
        <div><span>Diperbarui</span><strong><?= htmlspecialchars(formatEnglishDateTime($detailRecord['updated_at'] ?? null)) ?></strong></div>
    </div>
    <div class="correction-history">
        <div class="data-header">
            <div>
                <h3 class="data-title">Riwayat Koreksi</h3>
                <p class="data-description">Catatan nilai sebelum dan sesudah koreksi, tanpa mengubah sumber data asli.</p>
            </div>
        </div>
        <?php if (!$correctionHistoryAvailable): ?>
            <div class="empty">Tabel riwayat koreksi belum tersedia. Jalankan file migrasi SQL di folder migrations sebelum menggunakan fitur edit.</div>
        <?php elseif (empty($correctionHistory)): ?>
            <div class="empty">Belum ada koreksi untuk pembacaan ini.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Waktu Koreksi</th><th>Sebelum</th><th>Sesudah</th><th>Sumber Asli</th><th>Admin</th><th>Alasan</th></tr></thead>
                    <tbody>
                    <?php foreach ($correctionHistory as $correction): ?>
                        <tr>
                            <td><?= htmlspecialchars(formatEnglishDateTime((string) $correction['changed_at'])) ?></td>
                            <td><?= htmlspecialchars(formatEnglishDate((string) $correction['old_tanggal'])) ?><br><?= htmlspecialchars((string) $correction['old_jam']) ?><br><strong><?= htmlspecialchars(formatCounterValue($correction['old_nilai_data'])) ?></strong></td>
                            <td><?= htmlspecialchars(formatEnglishDate((string) $correction['new_tanggal'])) ?><br><?= htmlspecialchars((string) $correction['new_jam']) ?><br><strong><?= htmlspecialchars(formatCounterValue($correction['new_nilai_data'])) ?></strong></td>
                            <td><?= ($correction['original_source'] ?? 'api') === 'manual' ? 'Manual' : 'Main System' ?></td>
                            <td><?= htmlspecialchars((string) $correction['changed_by_username']) ?></td>
                            <td><?= htmlspecialchars((string) ($correction['reason'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
            <p class="data-description">Koreksi akan dicatat di riwayat perubahan. Sumber asli data dan evidence foto tetap dipertahankan.</p>
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

        <div class="edit-field edit-reason-field">
            <label for="edit_reason">Alasan Koreksi (opsional)</label>
            <input type="text" id="edit_reason" name="reason" maxlength="500" placeholder="Contoh: hasil verifikasi pembacaan">
        </div>

        <div class="edit-actions">
            <button type="submit" class="button button-primary">Simpan Perubahan</button>
            <a href="?detail=<?= (int) $editRecord['id'] ?>" class="button button-secondary">Batal</a>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card chart-card" id="counter-trend">
        <div class="data-header trend-header">
            <div>
                <h2 class="data-title">Trend Pembacaan Counter</h2>
                <p class="data-description">Setiap nilai counter yang masuk ditampilkan sebagai satu titik pembacaan.</p>
            </div>
        </div>

        <?php if (empty($trendData)): ?>
            <div class="empty">Belum ada data untuk dianalisis pada grafik.</div>
        <?php else: ?>
            <div class="trend-analysis">
                <div class="trend-metrics">
                    <div class="trend-metric"><span>Total Pembacaan</span><strong><?= formatCounterValue($trendCount) ?></strong><small>Data Perubahan</small></div>
                    <div class="trend-metric">
                        <span>Arah Trend</span>
                        <strong class="<?= $trendDirectionClass ?>"><?= htmlspecialchars($trendDirection) ?></strong>
                        <small>3 pembacaan terakhir: <?= htmlspecialchars($recentTrendSummary) ?></small>
                    </div>
                </div>
                <div class="chart-wrap">
                    <svg id="counterChart" class="counter-chart" role="img" aria-labelledby="counterChartTitle counterChartDescription"></svg>
                </div>
                <div class="trend-period-table-wrap">
                    <table class="trend-period-table">
                        <thead><tr><th>Pembacaan</th><th>Nilai Counter</th><th>Tanggal</th><th>Jam</th></tr></thead>
                        <tbody>
                        <?php foreach ($trendData as $reading): ?>
                            <tr>
                                <td><?= htmlspecialchars($reading['label']) ?></td>
                                <td><span class="trend-count"><?= htmlspecialchars(formatCounterValue($reading['value'])) ?></span></td>
                                <td><?= htmlspecialchars(formatEnglishDate($reading['tanggal'])) ?></td>
                                <td><?= htmlspecialchars($reading['jam']) ?></td>
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
            Data pembacaan berhasil diperbarui. Sumber data asli tetap dipertahankan dan koreksi tercatat pada riwayat.
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

    <section class="card data-card" id="reading-history">

        <div class="data-header">
            <div>
                <h2 class="data-title">Riwayat Pembacaan</h2>
                <p class="data-description">
                    Menampilkan <?= $tableStart ?>–<?= $tableEnd ?> dari <?= $totalData ?> data sesuai filter.
                </p>
            </div>
            <div class="export-actions">
                <a class="secondary-button" href="index.php?<?= htmlspecialchars(http_build_query(array_merge(array_intersect_key($_GET, array_flip(['start_date', 'end_date', 'min_value', 'max_value'])), ['export' => 'csv']))) ?>" aria-label="Ekspor seluruh riwayat sesuai filter ke CSV">
                    Ekspor CSV
                </a>
                <a class="secondary-button" href="index.php?<?= htmlspecialchars(http_build_query(array_merge(array_intersect_key($_GET, array_flip(['start_date', 'end_date', 'min_value', 'max_value'])), ['export' => 'xlsx']))) ?>" aria-label="Ekspor seluruh riwayat sesuai filter ke Excel XLSX">
                    Ekspor Excel
                </a>
            </div>
        </div>

        <div class="table-wrap" role="region" aria-label="Tabel riwayat pembacaan" tabindex="0">

            <?php if (empty($data)): ?>

                <div class="empty">
                    Tidak ada data yang sesuai dengan filter.
                </div>

            <?php else: ?>

                <table>
                    <caption class="sr-only">Riwayat pembacaan counter OLTC, diurutkan dari waktu terbaru ke terlama.</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Hari</th>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Jam</th>
                            <th scope="col">Nilai Counter</th>
                            <th scope="col">Evidence</th>
                            <th scope="col">Sumber</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data as $row): ?>
                        <?php $displayId = $displayIdByRecord[(int) $row['id']] ?? 0; ?>

                        <tr<?= isset($latestRow['id']) && (int) $row['id'] === (int) $latestRow['id'] ? ' id="latest-reading-row" class="latest-reading-row' . (isset($_GET['detail']) ? ' latest-reading-fade' : '') . '"' : '' ?>>
                            <td><?= $displayId ?></td>
                            <td><?= htmlspecialchars($row['hari']) ?></td>
                            <td><?= htmlspecialchars(formatEnglishDate($row['tanggal'])) ?></td>
                            <td class="time"><?= htmlspecialchars($row['jam']) ?></td>
                            <td class="value">
                                <?= htmlspecialchars(formatCounterValue($row['nilai_data'])) ?>
                            </td>
                            <td>
                                <?php $photoUrl = evidenceUrl($row['foto_path']); ?>

                                <?php if ($photoUrl): ?>
                                    <button
                                        type="button"
                                        class="evidence-button"
                                        data-image="<?= htmlspecialchars($photoUrl) ?>"
                                        data-caption="ID <?= htmlspecialchars($row['id']) ?> · <?= htmlspecialchars(formatEnglishDate($row['tanggal'])) ?> <?= htmlspecialchars($row['jam']) ?>"
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
                                <?php
                                    $isManualSource = ($row['source'] ?? 'api') === 'manual';
                                    $sourceLabel = $isManualSource ? 'Manual' : 'Main System';
                                    $isCorrected = isset($correctedRecordIds[(int) $row['id']]);
                                    if ($isCorrected) {
                                        $sourceLabel .= ' (E)';
                                    }
                                ?>
                                <span class="source-badge source-<?= $isManualSource ? 'manual' : 'api' ?><?= $isCorrected ? ' source-edited' : '' ?>"
                                      title="<?= $isCorrected ? 'Data pernah dikoreksi; sumber asli tetap dipertahankan.' : 'Sumber data asli.' ?>">
                                    <?= htmlspecialchars($sourceLabel) ?>
                                </span>
                            </td>
                            <td class="table-action-cell">
                                <div class="table-actions">
                                    <a class="action-button action-detail" href="?detail=<?= (int) $row['id'] ?>" aria-label="Lihat detail pembacaan nomor <?= $displayId ?>">Detail</a>
                                    <?php if ($isAdmin): ?>
                                    <form method="post" action="index.php" onsubmit="return confirm('Hapus data pembacaan ID <?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>? Data dan evidence fotonya akan dihapus permanen.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $row['id']) ?>">
                                        <button type="submit" class="action-button action-delete" aria-label="Hapus pembacaan nomor <?= $displayId ?>">Hapus</button>
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
    const chartData = <?= json_encode($trendData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

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

        const formatValue = (value) => String(Math.round(Number(value)));

        const formatLabel = (label) => {
            if (!label) return '';
            const match = String(label).match(/#(\d+)$/);
            return match ? '#' + match[1] : label;
        };

        const gridCount = 5;
        let markup = '<title id="counterChartTitle">Trend pembacaan counter OLTC</title><desc id="counterChartDescription">Grafik menunjukkan perubahan nilai counter berdasarkan pembacaan terbaru sesuai filter yang dipilih.</desc>';

        if (chartData.length > 1) {
            const firstValue = chartData[0].value;
            const lastValue = chartData[chartData.length - 1].value;
            const trend = lastValue > firstValue ? 'meningkat' : (lastValue < firstValue ? 'menurun' : 'tidak berubah');
            markup += '<text x="' + padding.left + '" y="15" class="chart-trend-note">Trend pembacaan: ' + trend + '</text>';
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
            const pointDate = item.tanggal ? ' · ' + item.tanggal + ' ' + item.jam : '';
            markup += '<circle cx="' + cx + '" cy="' + cy + '" r="' + (index === chartData.length - 1 ? 5.5 : 4) + '" class="' + pointClass + '" tabindex="0" aria-label="' + item.label + ', nilai ' + formatValue(item.value) + pointDate + '"><title>' + item.label + ' — ' + formatValue(item.value) + (pointDate ? ' — ' + item.tanggal + ' ' + item.jam : '') + '</title></circle>';

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

    // Keep the latest-reading row highlighted briefly, then fade it back to normal.
    document.querySelectorAll('.stat-card-link[href="#reading-history"]').forEach((card) => {
        card.addEventListener('click', () => {
            const row = document.getElementById('latest-reading-row');
            if (!row) return;

            row.classList.remove('latest-reading-fade');
            window.setTimeout(() => {
                row.classList.add('latest-reading-fade');
            }, 1000);
        });
    });


    function updateRealtimeClock() {
        const hourMinute = document.getElementById('realtimeHourMinute');
        const seconds = document.getElementById('realtimeSeconds');
        const date = document.getElementById('realtimeDate');

        if (!hourMinute || !seconds || !date) {
            return;
        }

        const now = new Date();

        const timeParts = new Intl.DateTimeFormat('id-ID', {
            timeZone: 'Asia/Jakarta',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).formatToParts(now);

        const getPart = (type) => timeParts.find((part) => part.type === type)?.value ?? '--';
        const currentHourMinute = getPart('hour') + ':' + getPart('minute');
        const currentSeconds = ':' + getPart('second');

        const dateLabel = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Jakarta',
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        }).format(now);

        hourMinute.textContent = currentHourMinute;
        seconds.textContent = currentSeconds;
        date.textContent = dateLabel;

        seconds.classList.remove('is-ticking');
        void seconds.offsetWidth;
        seconds.classList.add('is-ticking');
    }

    updateRealtimeClock();
    window.setInterval(updateRealtimeClock, 1000);

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

<script>
(() => {
    const button = document.getElementById('aboutInfoButton');
    const modal = document.getElementById('aboutInfoModal');
    if (!button || !modal) return;

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        button.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('about-info-open');
    };

    const openModal = () => {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        button.setAttribute('aria-expanded', 'true');
        document.body.classList.add('about-info-open');
        modal.querySelector('.about-info-close')?.focus();
    };

    button.addEventListener('click', openModal);
    modal.querySelectorAll('[data-about-close]').forEach((element) => {
        element.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>
</body>
</html>

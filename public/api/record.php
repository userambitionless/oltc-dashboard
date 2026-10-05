<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method tidak diizinkan. Gunakan POST.',
    ]);
    exit;
}

$tanggal = trim($_POST['tanggal'] ?? '');
$jam = trim($_POST['jam'] ?? '');
$nilaiData = trim($_POST['nilai_data'] ?? '');

if ($tanggal === '' || $jam === '' || $nilaiData === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'tanggal, jam, dan nilai_data wajib diisi.',
    ]);
    exit;
}

$date = DateTime::createFromFormat('Y-m-d', $tanggal);
$time = DateTime::createFromFormat('H:i:s', $jam);

if (!$date || $date->format('Y-m-d') !== $tanggal) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Format tanggal harus YYYY-MM-DD.',
    ]);
    exit;
}

if (!$time || $time->format('H:i:s') !== $jam) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Format jam harus HH:MM:SS.',
    ]);
    exit;
}

if (!is_numeric($nilaiData)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'nilai_data harus berupa angka.',
    ]);
    exit;
}

$nilaiData = (float) $nilaiData;

$photoPath = null;

if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Upload foto gagal.',
        ]);
        exit;
    }

    if ($_FILES['foto']['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Ukuran foto maksimal 10 MB.',
        ]);
        exit;
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($_FILES['foto']['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Format foto harus JPG, PNG, atau WEBP.',
        ]);
        exit;
    }

    $evidenceDirectory = __DIR__ . '/../evidence';

    if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0775, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Folder evidence tidak dapat dibuat.',
        ]);
        exit;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
    $destination = $evidenceDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Foto gagal disimpan.',
        ]);
        exit;
    }

    $photoPath = 'evidence/' . $filename;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO counter_readings
            (tanggal, jam, nilai_data, foto_path)
        VALUES
            (:tanggal, :jam, :nilai_data, :foto_path)
    ");

    $stmt->execute([
        'tanggal' => $tanggal,
        'jam' => $jam,
        'nilai_data' => $nilaiData,
        'foto_path' => $photoPath,
    ]);

    $id = (int) $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Data pembacaan berhasil disimpan.',
        'data' => [
            'id' => $id,
            'tanggal' => $tanggal,
            'hari' => null,
            'jam' => $jam,
            'nilai_data' => $nilaiData,
            'foto_path' => $photoPath,
        ],
    ], JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    if ($photoPath) {
        $savedPhoto = __DIR__ . '/../' . $photoPath;
        if (is_file($savedPhoto)) {
            unlink($savedPhoto);
        }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Data gagal disimpan ke database.',
    ]);
}

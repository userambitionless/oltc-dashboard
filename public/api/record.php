<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

$apiConfigPath = __DIR__ . '/../../config/api.php';

if (!is_file($apiConfigPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'API belum dikonfigurasi. Buat config/api.php terlebih dahulu.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$apiConfig = require $apiConfigPath;
$expectedApiKey = trim((string) ($apiConfig['api_key'] ?? ''));

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($expectedApiKey === '') {
    jsonResponse(503, [
        'success' => false,
        'message' => 'API belum dikonfigurasi dengan API Key.',
    ]);
}

$providedApiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));

if ($providedApiKey === '' || !hash_equals($expectedApiKey, $providedApiKey)) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'API Key tidak valid atau tidak diberikan.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'message' => 'Method tidak diizinkan. Gunakan POST.',
    ]);
}

$tanggal = trim((string) ($_POST['tanggal'] ?? ''));
$jam = trim((string) ($_POST['jam'] ?? ''));
$nilaiDataInput = trim((string) ($_POST['nilai_data'] ?? ''));

if ($tanggal === '' || $jam === '' || $nilaiDataInput === '') {
    jsonResponse(422, [
        'success' => false,
        'message' => 'tanggal, jam, dan nilai_data wajib diisi.',
    ]);
}

$date = DateTime::createFromFormat('!Y-m-d', $tanggal);
$dateErrors = DateTime::getLastErrors();

if (
    !$date ||
    ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
    $date->format('Y-m-d') !== $tanggal
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Format tanggal harus YYYY-MM-DD.',
    ]);
}

$time = DateTime::createFromFormat('!H:i:s', $jam);
$timeErrors = DateTime::getLastErrors();

if (
    !$time ||
    ($timeErrors !== false && ($timeErrors['warning_count'] > 0 || $timeErrors['error_count'] > 0)) ||
    $time->format('H:i:s') !== $jam
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Format jam harus HH:MM:SS.',
    ]);
}

if (!is_numeric($nilaiDataInput) || !is_finite((float) $nilaiDataInput)) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'nilai_data harus berupa angka yang valid.',
    ]);
}

$nilaiData = (float) $nilaiDataInput;

if (abs($nilaiData) > 999999999.999) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'nilai_data berada di luar batas yang didukung.',
    ]);
}

$photoPath = null;

if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $photo = $_FILES['foto'];

    if ($photo['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Upload foto gagal.',
        ]);
    }

    if (!isset($photo['tmp_name'], $photo['size']) || !is_uploaded_file($photo['tmp_name'])) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'File foto tidak valid.',
        ]);
    }

    if ((int) $photo['size'] <= 0 || (int) $photo['size'] > 10 * 1024 * 1024) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Ukuran foto harus lebih dari 0 dan maksimal 10 MB.',
        ]);
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($photo['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Format foto harus JPG, PNG, atau WEBP.',
        ]);
    }

    $evidenceDirectory = __DIR__ . '/../evidence';

    if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0775, true)) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Folder evidence tidak dapat dibuat.',
        ]);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
    $destination = $evidenceDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($photo['tmp_name'], $destination)) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Foto gagal disimpan.',
        ]);
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

    jsonResponse(201, [
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
    ]);
} catch (PDOException $e) {
    if ($photoPath !== null) {
        $savedPhoto = __DIR__ . '/../' . $photoPath;

        if (is_file($savedPhoto)) {
            unlink($savedPhoto);
        }
    }

    error_log('OLTC record API database error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Data gagal disimpan ke database.',
    ]);
}

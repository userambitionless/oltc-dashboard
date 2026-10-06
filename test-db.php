<?php

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM counter_readings");
    $result = $stmt->fetch();

    echo "Koneksi database berhasil.<br>";
    echo "Tabel counter_readings berhasil dibaca.<br>";
    echo "Total data: " . $result['total'];

} catch (PDOException $e) {
    echo "Query gagal: " . $e->getMessage();
}
<?php

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $pdo->query("
        SELECT
            id,
            hari,
            tanggal,
            jam,
            nilai_data,
            foto_path
        FROM counter_readings
        ORDER BY tanggal DESC, jam DESC
    ");

    $data = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>OLTC Dashboard</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            background: #f5f6f8;
        }

        h1 {
            margin-bottom: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #222;
            color: white;
        }

        tr:nth-child(even) {
            background: #f8f8f8;
        }
    </style>
</head>

<body>

    <h1>OLTC Counter Dashboard</h1>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Hari</th>
                <th>Tanggal</th>
                <th>Jam</th>
                <th>Nilai Data</th>
                <th>Evidence</th>
            </tr>
        </thead>

        <tbody>

            <?php if (empty($data)): ?>

                <tr>
                    <td colspan="6">Belum ada data.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($data as $row): ?>

                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>

                        <td><?= htmlspecialchars($row['hari']) ?></td>

                        <td><?= htmlspecialchars($row['tanggal']) ?></td>

                        <td><?= htmlspecialchars($row['jam']) ?></td>

                        <td><?= htmlspecialchars($row['nilai_data']) ?></td>

                        <td><?= htmlspecialchars($row['foto_path'] ?? '-') ?></td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

        </tbody>
    </table>

</body>
</html>
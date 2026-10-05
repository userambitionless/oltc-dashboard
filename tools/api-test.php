<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/api.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('config/api.php belum dibuat.');
}

$config = require $configPath;
$apiKey = trim((string) ($config['api_key'] ?? ''));
if ($apiKey === '') {
    http_response_code(503);
    exit('API Key belum dikonfigurasi.');
}

$result = null;
$status = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/oltc-dashboard/public/api/record.php';

    $ch = curl_init($url);
    $postFields = [
        'tanggal' => trim((string) ($_POST['tanggal'] ?? '')),
        'jam' => trim((string) ($_POST['jam'] ?? '')),
        'nilai_data' => trim((string) ($_POST['nilai_data'] ?? '')),
    ];

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $postFields['foto'] = new CURLFile(
            $_FILES['foto']['tmp_name'],
            $_FILES['foto']['type'] ?: 'application/octet-stream',
            $_FILES['foto']['name']
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey],
        CURLOPT_TIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $result = $error !== '' ? ['curl_error' => $error] : $body;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OLTC API Test Console</title>
<style>
body{font-family:Arial,sans-serif;background:#eef1f3;margin:0;color:#20282e}
main{max-width:760px;margin:48px auto;padding:0 20px}
.card{background:#fff;border:1px solid #d8dee3;border-radius:12px;padding:28px;box-shadow:0 8px 24px rgba(20,27,32,.06)}
h1{margin:0 0 8px;font-size:25px}.muted{color:#68747c;margin-top:0}
label{display:block;font-weight:700;font-size:13px;margin:18px 0 7px}
input{box-sizing:border-box;width:100%;padding:11px 12px;border:1px solid #cbd3d8;border-radius:7px;font:inherit}
button{margin-top:22px;border:0;border-radius:7px;padding:11px 16px;background:#145c8c;color:#fff;font-weight:700;cursor:pointer}
button:hover{filter:brightness(.95)}
pre{background:#182126;color:#e8eef1;padding:16px;border-radius:8px;overflow:auto;line-height:1.5}
.status{font-weight:700;margin-top:24px}
.note{font-size:13px;color:#68747c;margin-top:18px}
</style>
</head>
<body>
<main>
<section class="card">
<h1>OLTC API Test Console</h1>
<p class="muted">Alat pengujian lokal untuk endpoint penerimaan data counter.</p>
<form method="post" enctype="multipart/form-data">
<label for="tanggal">Tanggal</label>
<input id="tanggal" name="tanggal" type="date" value="<?= htmlspecialchars($_POST['tanggal'] ?? date('Y-m-d')) ?>" required>

<label for="jam">Jam</label>
<input id="jam" name="jam" type="time" step="1" value="<?= htmlspecialchars($_POST['jam'] ?? date('H:i:s')) ?>" required>

<label for="nilai_data">Nilai Counter</label>
<input id="nilai_data" name="nilai_data" type="number" step="0.001" value="<?= htmlspecialchars($_POST['nilai_data'] ?? '') ?>" placeholder="Contoh: 125.500" required>

<label for="foto">Evidence (opsional)</label>
<input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">

<button type="submit">Kirim ke API</button>
</form>

<?php if ($result !== null): ?>
<div class="status">HTTP Status: <?= (int) $status ?></div>
<pre><?= htmlspecialchars(is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php endif; ?>

<p class="note">Halaman ini khusus development lokal. Jangan expose ke internet/public deployment.</p>
</section>
</main>
</body>
</html>

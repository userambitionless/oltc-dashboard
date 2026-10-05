<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
requireAdmin();

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
:root{--bg:#eef1f3;--surface:#fff;--surface-soft:#f7f8f9;--text:#172027;--muted:#68737d;--muted-strong:#46525c;--border:#dfe4e8;--border-strong:#cbd3d9;--primary:#145c8c;--primary-dark:#0d466c;--success:#19724a;--danger:#b42318;--shadow:0 8px 30px rgba(21,32,41,.055);--radius:12px}
*{box-sizing:border-box}
html{background:var(--bg)}
body{min-height:100vh;margin:0;font-family:"DM Sans",ui-sans-serif,system-ui,sans-serif;color:var(--text);background:linear-gradient(rgba(255,255,255,.45) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.45) 1px,transparent 1px),var(--bg);background-size:28px 28px}
body:before{content:"";position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse at center,transparent 52%,rgba(15,35,50,.035) 72%,rgba(15,35,50,.075) 100%)}
main{position:relative;z-index:1;width:min(820px,calc(100% - 32px));margin:0 auto;padding:44px 0 32px}
.card{overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow)}
.card-header{padding:22px 24px 18px;border-bottom:1px solid var(--border)}
.eyebrow{margin:0 0 7px;color:var(--primary);font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
h1{margin:0;font-size:clamp(24px,4vw,30px);line-height:1.05;letter-spacing:-.035em}
.muted{margin:8px 0 0;color:var(--muted);font-size:13px;line-height:1.55}
form{padding:20px 24px 24px}
.field{margin-bottom:15px}
label{display:block;margin-bottom:7px;color:var(--muted-strong);font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
input{display:block;width:100%;height:40px;padding:8px 11px;border:1px solid var(--border-strong);border-radius:7px;outline:0;background:#fff;color:var(--text);font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:13px;font-variant-numeric:tabular-nums;transition:border-color .15s ease,box-shadow .15s ease}
input:hover{border-color:#aeb9c1}
input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(20,92,140,.10)}
input[type=file]{height:auto;min-height:40px;padding:8px;font-family:"DM Sans",ui-sans-serif,system-ui,sans-serif;font-size:12px;cursor:pointer}
input[type=file]::file-selector-button{margin-right:9px;padding:7px 10px;border:1px solid var(--border-strong);border-radius:6px;background:#f5f7f8;color:var(--muted-strong);font:700 11px "DM Sans",ui-sans-serif,system-ui,sans-serif;cursor:pointer}
input[type=file]::file-selector-button:hover{border-color:#aeb9c1;background:#eef2f4}
.form-actions{display:flex;align-items:center;gap:8px;margin-top:19px}
button{min-height:40px;padding:8px 14px;border:1px solid var(--primary);border-radius:7px;background:var(--primary);color:#fff;font:700 12px "DM Sans",ui-sans-serif,system-ui,sans-serif;cursor:pointer;transition:background .15s ease,border-color .15s ease,transform .08s ease}
button:hover{border-color:var(--primary-dark);background:var(--primary-dark)}
button:active{transform:translateY(1px)}
button:focus-visible{outline:0;box-shadow:0 0 0 3px rgba(20,92,140,.12)}
.result{margin:0 24px 24px;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--surface-soft)}
.result-status{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:10px}
.status-label{color:var(--muted);font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.status-code{color:var(--text);font-family:"IBM Plex Mono",monospace;font-size:14px;font-weight:600}
.status-code.is-success{color:var(--success)}
.status-code.is-error{color:var(--danger)}
pre{max-height:360px;margin:0;padding:14px;overflow:auto;border:1px solid #dce2e6;border-radius:7px;background:#182126;color:#e8eef1;font:12px/1.55 "IBM Plex Mono",ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere}
.note{margin:0 24px 24px;padding:11px 13px;border-left:3px solid #cbd3d9;background:#f7f8f9;color:var(--muted);font-size:11px;line-height:1.55}
@media(max-width:600px){main{width:min(100% - 24px,820px);padding-top:20px}.card-header,form{padding-left:16px;padding-right:16px}.result,.note{margin-left:16px;margin-right:16px}.form-actions{align-items:stretch}.form-actions button{width:100%}}
</style>
</head>
<body>
<main>
<section class="card">
<div class="card-header">
<p class="eyebrow">DEVELOPMENT TOOL</p>
<h1>OLTC API Test Console</h1>
<p class="muted">Alat pengujian lokal untuk endpoint penerimaan data counter.</p>
</div>
<form method="post" enctype="multipart/form-data">
<div class="field">
<label for="tanggal">Tanggal</label>
<input id="tanggal" name="tanggal" type="date" value="<?= htmlspecialchars($_POST['tanggal'] ?? date('Y-m-d')) ?>" required>
</div>

<div class="field">
<label for="jam">Jam</label>
<input id="jam" name="jam" type="time" step="1" value="<?= htmlspecialchars($_POST['jam'] ?? date('H:i:s')) ?>" required>
</div>

<div class="field">
<label for="nilai_data">Nilai Counter</label>
<input id="nilai_data" name="nilai_data" type="number" step="0.001" value="<?= htmlspecialchars($_POST['nilai_data'] ?? '') ?>" placeholder="Contoh: 125.500" required>
</div>

<div class="field">
<label for="foto">Evidence (opsional)</label>
<input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
</div>

<div class="form-actions">
<button type="submit">Kirim ke API</button>
</div>
</form>

<?php if ($result !== null): ?>
<div class="result">
<div class="result-status">
<span class="status-label">HTTP Status</span>
<span class="status-code <?= ($status !== null && $status >= 200 && $status < 300) ? 'is-success' : 'is-error' ?>"><?= (int) $status ?></span>
</div>
<pre><?= htmlspecialchars(is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<p class="note">Halaman ini khusus development lokal dan hanya dapat diakses Admin. Jangan expose ke internet/public deployment.</p>
</section>
</main>
</body>
</html>

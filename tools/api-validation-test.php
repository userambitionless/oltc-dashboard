<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/api.php';
if (!is_file($configPath)) {
    exit("ERROR: config/api.php belum ada.\n");
}

$config = require $configPath;
$apiKey = trim((string) ($config['api_key'] ?? ''));
if ($apiKey === '') {
    exit("ERROR: API Key kosong.\n");
}

$url = 'http://localhost/oltc-dashboard/public/api/record.php';

$tests = [
    [
        'name' => 'API Key salah',
        'expected' => 401,
        'headers' => ['X-API-Key: wrong-key-for-test'],
        'post' => [
            'tanggal' => date('Y-m-d'),
            'jam' => '14:00:00',
            'nilai_data' => '150.000',
        ],
    ],
    [
        'name' => 'Field wajib kosong',
        'expected' => 422,
        'headers' => ['X-API-Key: ' . $apiKey],
        'post' => [
            'tanggal' => '',
            'jam' => '14:00:00',
            'nilai_data' => '150.000',
        ],
    ],
    [
        'name' => 'Tanggal tidak valid',
        'expected' => 422,
        'headers' => ['X-API-Key: ' . $apiKey],
        'post' => [
            'tanggal' => '2026-99-99',
            'jam' => '14:00:00',
            'nilai_data' => '150.000',
        ],
    ],
    [
        'name' => 'Jam tidak valid',
        'expected' => 422,
        'headers' => ['X-API-Key: ' . $apiKey],
        'post' => [
            'tanggal' => date('Y-m-d'),
            'jam' => '99:99:99',
            'nilai_data' => '150.000',
        ],
    ],
    [
        'name' => 'Nilai bukan angka',
        'expected' => 422,
        'headers' => ['X-API-Key: ' . $apiKey],
        'post' => [
            'tanggal' => date('Y-m-d'),
            'jam' => '14:00:00',
            'nilai_data' => 'bukan-angka',
        ],
    ],
    [
        'name' => 'Request GET ditolak',
        'expected' => 405,
        'headers' => ['X-API-Key: ' . $apiKey],
        'method' => 'GET',
    ],
];

$passed = 0;
$total = count($tests);

echo "OLTC API VALIDATION TEST\n";
echo str_repeat('=', 52) . "\n";

foreach ($tests as $test) {
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $test['headers'],
        CURLOPT_CUSTOMREQUEST => $test['method'] ?? 'POST',
    ];

    if (($test['method'] ?? 'POST') === 'POST') {
        $options[CURLOPT_POSTFIELDS] = $test['post'];
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = $curlError === '' && $status === $test['expected'];

    echo ($ok ? '[PASS]' : '[FAIL]') . ' ' . $test['name'];
    echo ' — HTTP ' . $status . ' (expected ' . $test['expected'] . ')' . "\n";

    if (!$ok && $curlError !== '') {
        echo '       cURL: ' . $curlError . "\n";
    }

    if (!$ok && is_string($body) && $body !== '') {
        echo '       Response: ' . preg_replace('/\s+/', ' ', $body) . "\n";
    }

    if ($ok) {
        $passed++;
    }
}

echo str_repeat('-', 52) . "\n";
echo "Hasil: {$passed}/{$total} test lulus.\n";

exit($passed === $total ? 0 : 1);

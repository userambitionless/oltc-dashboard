$apiUrl = "http://localhost/oltc-dashboard/public/api/record.php"
$apiKey = "16bc6673857a9cf29f7b90390a723c531e7e2ad052387c96ed4e0c75ac2cade7"

# Buat 10 nilai random 6 digit
$dummyData = @()

for ($i = 0; $i -lt 10; $i++) {
    $dummyData += Get-Random -Minimum 100000 -Maximum 1000000
}

Write-Host ""
Write-Host "10 nilai dummy yang akan dikirim:" -ForegroundColor Cyan

for ($i = 0; $i -lt $dummyData.Count; $i++) {
    Write-Host "$($i + 1). $($dummyData[$i])"
}

for ($i = 0; $i -lt $dummyData.Count; $i++) {

    $now = Get-Date

    $payload = @{
        tanggal    = $now.ToString("yyyy-MM-dd")
        jam        = $now.ToString("HH:mm:ss")
        nilai_data = [string]$dummyData[$i]
    }

    Write-Host ""
    Write-Host "========================================" -ForegroundColor DarkGray
    Write-Host "Data ke-$($i + 1) dari 10" -ForegroundColor Cyan
    Write-Host "Tanggal : $($payload.tanggal)"
    Write-Host "Jam     : $($payload.jam)"
    Write-Host "Nilai   : $($payload.nilai_data)"

    try {

        $response = Invoke-RestMethod `
            -Uri $apiUrl `
            -Method POST `
            -Headers @{
                "X-API-Key" = $apiKey
            } `
            -ContentType "application/x-www-form-urlencoded" `
            -Body $payload

        Write-Host "STATUS  : BERHASIL" -ForegroundColor Green
        Write-Host "ID      : $($response.data.id)" -ForegroundColor Green

    }
    catch {

        Write-Host "STATUS  : GAGAL" -ForegroundColor Red
        Write-Host "ERROR   : $($_.Exception.Message)" -ForegroundColor Red

        if ($_.ErrorDetails.Message) {
            Write-Host "DETAIL  : $($_.ErrorDetails.Message)" -ForegroundColor Red
        }
    }

    if ($i -lt 9) {

        Write-Host ""
        Write-Host "Menunggu 30 detik..." -ForegroundColor Yellow

        for ($seconds = 30; $seconds -gt 0; $seconds--) {
            Write-Host "`rData berikutnya dalam $seconds detik... " -NoNewline
            Start-Sleep -Seconds 1
        }

        Write-Host ""
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor DarkGray
Write-Host "SELESAI" -ForegroundColor Green
Write-Host "10 data dummy telah dikirim." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor DarkGray
# OLTC Dashboard API

Dokumentasi endpoint penerimaan data pembacaan counter OLTC.

## Endpoint

**POST**

`/public/api/record.php`

Contoh lokal:

`http://localhost/oltc-dashboard/public/api/record.php`

> Endpoint ini merupakan receiver prototype untuk integrasi perangkat di masa depan. Metode komunikasi perangkat Raspberry Pi belum ditetapkan.

## Authentication

Request wajib membawa header:

`X-API-Key: <API_KEY>`

API Key disimpan lokal di:

`config/api.php`

File tersebut sengaja tidak masuk Git karena berisi secret.

## Request

Saat ini endpoint menerima **multipart/form-data**.

Field wajib:

| Field | Tipe | Format | Keterangan |
|---|---|---|---|
| `tanggal` | string | `YYYY-MM-DD` | Tanggal pembacaan |
| `jam` | string | `HH:MM:SS` | Waktu pembacaan |
| `nilai_data` | numeric | desimal | Nilai digital counter hasil pembacaan |

Field opsional:

| Field | Tipe | Batas | Keterangan |
|---|---|---|---|
| `foto` | file | max 10 MB | JPG, PNG, atau WEBP sebagai evidence |

## Contoh request dengan cURL

Tanpa foto:

```powershell
curl.exe -X POST "http://localhost/oltc-dashboard/public/api/record.php" `
-H "X-API-Key: YOUR_API_KEY" `
-F "tanggal=2026-10-05" `
-F "jam=12:30:00" `
-F "nilai_data=140.500"
```

Dengan foto:

```powershell
curl.exe -X POST "http://localhost/oltc-dashboard/public/api/record.php" `
-H "X-API-Key: YOUR_API_KEY" `
-F "tanggal=2026-10-05" `
-F "jam=12:30:00" `
-F "nilai_data=140.500" `
-F "foto=@test.jpg"
```

## Response sukses

HTTP status: **201 Created**

Contoh:

```json
{
  "success": true,
  "message": "Data pembacaan berhasil disimpan.",
  "data": {
    "id": 6,
    "tanggal": "2026-10-05",
    "hari": null,
    "jam": "12:30:00",
    "nilai_data": 140.5,
    "foto_path": null
  }
}
```

Catatan: `hari` saat ini dikembalikan `null` karena nilai hari dibuat otomatis oleh database. Ini dapat disempurnakan pada tahap berikutnya.

## HTTP status

| Status | Arti |
|---|---|
| 201 | Data berhasil disimpan |
| 401 | API Key tidak valid/tidak diberikan |
| 405 | Method selain POST |
| 422 | Data request tidak valid |
| 500 | Gagal menyimpan data/foto |
| 503 | API belum dikonfigurasi |

## Validasi

API memvalidasi:

- tanggal harus valid dan menggunakan format `YYYY-MM-DD`;
- jam harus valid dan menggunakan format `HH:MM:SS`;
- nilai counter harus berupa angka;
- nilai counter harus berada dalam rentang yang didukung database;
- file evidence harus benar-benar berupa JPG, PNG, atau WEBP;
- ukuran evidence maksimal 10 MB;
- nama file evidence dibuat acak oleh server.

## Alur data

```text
Camera
   ↓
Computer Vision / Machine Learning
   ↓
Digital Counter Value
   +
Evidence Photo
   ↓
[Future device communication]
   ↓
OLTC Dashboard API
   ↓
MySQL
   ↓
Dashboard / History / Chart
```

Komunikasi antara Raspberry Pi dan server belum dikunci. Dokumentasi ini hanya mendefinisikan receiver API yang tersedia saat ini.

## Keamanan

API Key tidak boleh:

- ditulis langsung di repository;
- dimasukkan ke URL/query string;
- dibagikan di screenshot atau dokumentasi publik.

Untuk deployment yang dapat diakses dari jaringan luar, tambahkan HTTPS dan kontrol akses/rate limiting sebelum API digunakan oleh perangkat nyata.

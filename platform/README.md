# Microservice Recovery System

Sistem untuk mendeteksi downtime microservice, menemukan data yang terdampak (missing / duplicate / inconsistent), mengkuantifikasi dampaknya, dan memperbaikinya lewat proses replay yang idempotent.

> Status: **work in progress**. Saat ini berjalan di lingkungan simulasi (dua microservice tiruan). Belum tersambung ke database/microservice asli.

## Struktur Repo

```
microservice-recovery/
├── platform/        # Laravel: health monitoring, discovery, recovery amount, replay
├── sim/             # Lingkungan simulasi (bukan bagian sistem final)
│   ├── order-service/     # Dummy service :4001
│   ├── payment-service/   # Dummy service :4002
│   ├── traffic.js         # Generator trafik order
│   └── seed-test.js       # Pembuat data uji (inconsistent)
└── README.md
```

## Prasyarat

- PHP 8.2+ dengan extension `pdo_sqlite` dan `sqlite3` aktif
- Composer
- Node.js (LTS) dan npm

## Setup

```powershell
# 1. Simulasi
cd sim/order-service;   npm install
cd ../payment-service;  npm install
cd ..;                  npm install

# 2. Platform
cd ../platform
composer install
copy .env.example .env
php artisan key:generate
New-Item database\database.sqlite -ItemType File -Force
php artisan migrate
```

Seed dua service yang dipantau lewat `php artisan tinker`:

```php
App\Models\Service::create(['name'=>'Order Service','slug'=>'order-service','health_url'=>'http://localhost:4001/health','check_interval_sec'=>15]);
App\Models\Service::create(['name'=>'Payment Service','slug'=>'payment-service','health_url'=>'http://localhost:4002/health','check_interval_sec'=>15]);
```

## Menjalankan Simulasi

Buka terminal terpisah untuk masing-masing:

```powershell
cd sim/payment-service; node index.js
cd sim/order-service;   node index.js
cd sim;                 node traffic.js
cd platform;            php artisan schedule:work
```

Untuk mensimulasikan downtime: matikan `payment-service` (Ctrl+C), tunggu beberapa saat, lalu nyalakan lagi.

## Modul & Command

### 1. Health Monitoring
`php artisan app:ping-services` — memanggil endpoint `/health` tiap service aktif, mencatat hasil ke `health_checks`, dan membuka/menutup `incidents` saat status berubah `up` ↔ `down`. Dijadwalkan tiap 15 detik lewat scheduler.

### 2. Data Loss Discovery
`php artisan app:discover-impact {incident_id} [--entity=default_incident_entity]`

Membandingkan data source vs target dalam rentang waktu incident (hanya untuk incident berstatus `resolved`). Kategori hasil:

| Kategori | Arti |
|---|---|
| `missing` | Ada di source, tidak ada di target |
| `duplicate` | Ada lebih dari satu baris di target untuk key yang sama |
| `inconsistent` | Ada di kedua sisi, tetapi nilai `amount` berbeda |

Nama tabel/kolom/koneksi dibaca dari `config/discovery.php`, bukan hardcode, sehingga dapat disesuaikan ke struktur data lain. Hasil disimpan ke `impacted_records` dengan `updateOrCreate` sehingga command aman dijalankan berulang.

### 3. Recovery Amount
`GET /incidents/{id}/recovery-amount` — mengembalikan jumlah record terdampak, breakdown per kategori, estimasi nilai, dan breakdown status. Semua angka dapat diverifikasi ulang dari `source_snapshot` di `impacted_records` atau dari query langsung ke database sumber.

### 4. Recovery Process (Replay)
`php artisan app:replay {impacted_record_id} [--dry-run]`

Alur: cek idempotency key → cek kondisi target → (dry-run berhenti di sini) → kirim ulang ke target → verifikasi data benar-benar ada → tandai `verified`. Idempotent: dijalankan berulang tidak menduplikasi data maupun `recovery_actions`.

## Skema Tabel (Platform)

`services`, `health_checks`, `incidents`, `impacted_records`, `recovery_actions`.

## Hasil Uji

| Skenario | Hasil |
|---|---|
| Payment-service mati (2 kejadian) | 61 dan 172 record `missing` terdeteksi; angka cocok dengan hitung manual di `order.db` |
| Data uji amount berbeda | 1 record `inconsistent` terdeteksi |
| Replay dijalankan 2x pada record yang sama | Eksekusi kedua ditolak (noop); `recovery_actions` tetap 1 baris |
| Order-service mati | Incident terdeteksi, tetapi tidak ada record terdampak yang dapat ditemukan (lihat Keterbatasan) |

## Keterbatasan yang Diketahui

- **Request yang gagal total sebelum tersimpan** (mis. order-service sendiri yang mati) tidak meninggalkan jejak di database mana pun, sehingga tidak terdeteksi oleh perbandingan dua database. Dibutuhkan sumber data tambahan di luar service (mis. log API gateway).
- **Kategori `duplicate`** belum dapat didemokan di simulasi karena tabel `payments` memiliki constraint `UNIQUE` pada `order_ref`. Logic-nya sudah ada di command.
- Belum ada UI (dashboard monitoring dan admin UI perbaikan data), autentikasi, audit trail, dan alert otomatis.

## Open Questions

1. Database asli mewakili microservice yang mana, dan bagaimana skemanya?
2. Kolom apa yang menjadi kunci pembanding antar tabel?
3. Apakah discovery boleh dijalankan dengan akses read-only terlebih dahulu?
4. Apakah kasus request gagal total termasuk dalam definisi "data terdampak"?
5. Tingkat otomasi recovery: otomatis penuh atau butuh approval manual?
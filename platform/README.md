# Audit Jurnal: Microservice Recovery System

API untuk mengaudit kelengkapan data jurnal (`journal_all`) di PostgreSQL: idx yang hilang atau berlebih,
jumlah kemunculan idx yang tidak rata, pola scenario yang tidak dikenal, debit tidak sama dengan credit,
dan scenario tanpa template. Logika audit ada di database (view, function, procedure); Laravel hanya memanggilnya.

## Prasyarat database

Objek berikut harus sudah ada di database (dibuat dari script SQL audit):

| Objek | Fungsi |
|---|---|
| tabel `journal_all`, `scenario_template`, `normal_scenario_patterns` | Data dan acuan |
| materialized view `referensi_pola_full`, `scenario_stats_cache` | Cache pola dan statistik |
| view `audit_anomali` | Daftar anomali |
| function `cek_referensi(text)`, `cek_idx(text)` | Cek satu referensi langsung dari `journal_all` |
| procedure `refresh_audit()` | Refresh cache (sekitar 48 detik di data asli) |

## Setup

Butuh PHP 8.4 atau lebih baru (dependency di `composer.lock` mensyaratkan >= 8.4.1), Composer, dan ekstensi `pdo_pgsql`.

```powershell
cd platform
composer install
copy .env.example .env
php artisan key:generate
# isi DB_PASSWORD dan (opsional) AUDIT_API_TOKEN di .env
php artisan migrate        # membuat tabel audit_repair_log
php artisan serve
```

Hanya PostgreSQL yang didukung.

## Autentikasi

Kirim header `Authorization: Bearer <AUDIT_API_TOKEN>`. Kalau `AUDIT_API_TOKEN` kosong, API hanya terbuka saat
`APP_ENV=local`; di lingkungan lain semua request ditolak (401).

## Endpoint

Semua di bawah `/api/audit`. Kirim `Accept: application/json`.

### Cek seluruh data

| Endpoint | Isi |
|---|---|
| `GET report` | Ringkasan + jumlah debit/credit tidak seimbang + scenario tanpa template (memindai seluruh `journal_all`, lebih lambat) |
| `GET summary` | Total referensi, normal, tidak normal, anomali per jenis, top pola |
| `GET anomalies` | Semua anomali. Filter: `jenis`, `scenario`, `q`, `page`, `per_page` |
| `GET anomalies/idx?tipe=hilang\|kurang` | Detail tingkat idx untuk semua anomali |
| `GET unknown-patterns` | Pola yang tidak ada di pola normal, dengan jumlah referensi dan contoh |
| `GET unbalanced?tolerance=0.05` | Debit tidak sama dengan credit per referensi + scenario |
| `GET scenarios-without-template` | Scenario di data yang tidak ada di `scenario_template` |

### Satu referensi

| Endpoint | Isi |
|---|---|
| `GET references/{referensi}` | Hasil `cek_referensi` dan `cek_idx` |
| `POST references/{referensi}/repair` | Tambah baris idx yang hilang. Body: `konfirmasi: true`, `baris: [{scenario, idx, debit, credit}]` |

Perbaikan: nama akun diambil dari template, nominal wajib dari pengguna, idx hanya boleh ditambah sebanyak
kekurangannya menurut `cek_idx`, semuanya satu transaksi dan dicatat di `audit_repair_log`
(nama pencatat dari header `X-Audit-User`). Cache tidak otomatis diperbarui; jalankan refresh.

### Pola normal dan cache

| Endpoint | Isi |
|---|---|
| `GET patterns` | Pola normal beserta jumlah referensi |
| `POST patterns` | Tambah pola. Body: `pattern` (mis. `2,2,3`), `is_spx` |
| `DELETE patterns/{pattern}` | Keluarkan pola (koma di URL ditulis `%2C`) |
| `POST refresh` | Jalankan `refresh_audit()`. Setara `php artisan audit:refresh` |

Setelah mengubah pola normal atau memperbaiki data, jalankan refresh supaya `summary`, `anomalies`, dan
`anomalies/idx` ikut berubah. `references/{referensi}` selalu langsung dari `journal_all`, tidak perlu refresh.

## Keterbatasan yang diketahui

- Jumlah kemunculan scenario dihitung dari total baris dibagi jumlah idx template (dibulatkan). Kalau banyak
  baris hilang sekaligus, pembulatan bisa keliru, terutama untuk scenario berisi 2 idx.
- Referensi dengan pola tidak dikenal tidak dicek per idx; yang muncul hanya polanya.
- Kalau satu kejadian scenario hilang utuh dan pola barunya masih termasuk pola normal, tidak terdeteksi.
- Belum ada UI.

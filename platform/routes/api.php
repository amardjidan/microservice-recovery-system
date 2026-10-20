<?php

// Tambahkan ke routes/api.php. Kalau project belum punya api.php:
//   php artisan install:api   (Laravel 11+)
// Semua endpoint wajib login; sesuaikan guard dengan yang dipakai project.

use App\Http\Controllers\Api\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('audit')->group(function () {
    Route::get('summary', [AuditController::class, 'summary']);
    Route::get('anomalies', [AuditController::class, 'anomalies']);
    Route::get('references/{referensi}', [AuditController::class, 'reference']);
    Route::post('references/{referensi}/repair', [AuditController::class, 'repair']);

    Route::get('patterns', [AuditController::class, 'patterns']);
    Route::post('patterns', [AuditController::class, 'addPattern']);
    // pola berisi koma, kirim URL-encoded: /patterns/6%2C6%2C9
    Route::delete('patterns/{pattern}', [AuditController::class, 'removePattern']);

    Route::post('refresh', [AuditController::class, 'refresh']);
});

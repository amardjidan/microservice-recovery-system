<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Middleware\EnsureAuditToken;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureAuditToken::class)->prefix('audit')->group(function () {
    // ---- Cek seluruh data ----
    Route::get('report', [AuditController::class, 'report']);
    Route::get('summary', [AuditController::class, 'summary']);
    Route::get('anomalies', [AuditController::class, 'anomalies']);
    Route::get('anomalies/idx', [AuditController::class, 'anomalyIdx']);
    Route::get('unknown-patterns', [AuditController::class, 'unknownPatterns']);
    Route::get('unbalanced', [AuditController::class, 'unbalanced']);
    Route::get('scenarios-without-template', [AuditController::class, 'scenariosWithoutTemplate']);

    // ---- Cek dan perbaiki satu referensi ----
    Route::get('references/{referensi}', [AuditController::class, 'reference']);
    Route::post('references/{referensi}/repair', [AuditController::class, 'repair']);

    // ---- Pola normal ----
    Route::get('patterns', [AuditController::class, 'patterns']);
    Route::post('patterns', [AuditController::class, 'addPattern']);
    Route::delete('patterns/{pattern}', [AuditController::class, 'removePattern']);

    // ---- Cache ----
    Route::post('refresh', [AuditController::class, 'refresh']);
});

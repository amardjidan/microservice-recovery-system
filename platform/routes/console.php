<?php

use App\Jobs\RefreshAuditJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    // Sama seperti jadwalkanAudit(): lewati kalau audit lain sedang berjalan
    if (! Cache::add('audit.berjalan', true, 900)) {
        return;
    }

    Cache::forever('audit.status', ['status' => 'berjalan', 'mulai' => now()->toIso8601String()]);
    RefreshAuditJob::dispatch();
})->everyFifteenMinutes()->name('audit-terjadwal');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
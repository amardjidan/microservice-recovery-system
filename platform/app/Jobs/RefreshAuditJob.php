<?php

namespace App\Jobs;

use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshAuditJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(AuditService $audit): void
    {
        $mulai = microtime(true);
        $awal = Cache::get('audit.status')['mulai'] ?? now()->toIso8601String();

        try {
            $audit->refresh();
        } catch (Throwable $e) {
            Cache::forever('audit.status', [
                'status' => 'gagal', 'mulai' => $awal, 'selesai' => now()->toIso8601String(),
                'pesan' => mb_substr($e->getMessage(), 0, 300),
            ]);
            throw $e;
        } finally {
            Cache::forget('audit.berjalan');
        }

        Cache::forget('audit.summary');
        Cache::forever('audit.status', [
            'status' => 'selesai', 'mulai' => $awal, 'selesai' => now()->toIso8601String(),
            'detik' => round(microtime(true) - $mulai, 1),
        ]);
    }
}
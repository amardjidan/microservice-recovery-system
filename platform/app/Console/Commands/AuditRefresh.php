<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;

class AuditRefresh extends Command
{
    protected $signature = 'audit:refresh';
    protected $description = 'Refresh cache audit jurnal (materialized view), sekitar 48 detik di data asli';

    public function handle(AuditService $audit): int
    {
        $mulai = microtime(true);
        $audit->refresh();
        $this->info('Selesai dalam ' . round(microtime(true) - $mulai, 1) . ' detik.');

        return self::SUCCESS;
    }
}

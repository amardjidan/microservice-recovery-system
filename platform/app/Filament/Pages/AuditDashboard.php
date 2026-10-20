<?php

namespace App\Filament\Pages;

use App\Filament\Support\AuditSummary;
use App\Services\AuditApi;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AuditDashboard extends Dashboard
{
    protected static ?string $title = 'Ringkasan audit';

    protected static ?string $navigationLabel = 'Ringkasan';

    public function getColumns(): int|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Jalankan audit ulang')
                ->requiresConfirmation()
                ->modalDescription('Menghitung ulang seluruh data, sekitar 30 detik. Jangan tutup halaman ini.')
                ->action(function (AuditApi $api) {
                    set_time_limit(300);
                    $mulai = microtime(true);

                    // Penanda: selama audit berjalan, widget memakai ringkasan terakhir
                    // (tidak memanggil API yang sedang sibuk).
                    Cache::put('audit.berjalan', true, 120);

                    try {
                        $api->refresh();
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Audit ulang gagal')->body($e->getMessage())->danger()->send();

                        return;
                    } finally {
                        Cache::forget('audit.berjalan');
                    }

                    AuditSummary::forget();

                    Notification::make()
                        ->title('Audit ulang selesai dalam '.round(microtime(true) - $mulai, 1).' detik')
                        ->success()
                        ->send();

                    // Tanpa reload halaman: widget ringkasan menyegarkan dirinya lewat event ini.
                    $this->dispatch('audit-selesai');
                }),
        ];
    }
}

<?php

namespace App\Filament\Pages;

// use App\Filament\Support\AuditSummary;
use App\Services\AuditApi;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
// use Illuminate\Support\Facades\Cache;
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
                ->modalDescription('Audit dijalankan di latar belakang (sekitar 20 detik). Anda boleh meninggalkan halaman ini.')
                ->action(function (AuditApi $api) {
                    try {
                        $api->refresh();
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Audit ulang gagal dimulai')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Audit dijalankan di latar belakang')
                        ->body('Status tampil di halaman Ringkasan.')
                        ->success()
                        ->send();

                    $this->dispatch('audit-selesai'); // segarkan status di widget
                }),
        ];
    }
}

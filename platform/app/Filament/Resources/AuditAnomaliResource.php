<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditAnomaliResource\Pages\DetailReferensi;
use App\Filament\Resources\AuditAnomaliResource\Pages\ListAuditAnomalis;
use App\Models\AuditAnomali;
use Filament\Resources\Resource;

class AuditAnomaliResource extends Resource
{
    protected static ?string $model = AuditAnomali::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Daftar anomali';

    protected static ?string $modelLabel = 'anomali';

    protected static ?string $pluralModelLabel = 'anomali';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Warna badge per jenis (kode = kata pertama). */
    public static function warna(string $kode): string
    {
        return match ($kode) {
            'IDX_HILANG', 'HILANG' => 'danger',
            'JUMLAH_TIDAK_RATA', 'KURANG' => 'warning',
            'IDX_LEBIH', 'TIDAK_ADA_DI_TEMPLATE' => 'info',
            'OK' => 'success',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditAnomalis::route('/'),
            'detail' => DetailReferensi::route('/{referensi}'),
        ];
    }
}

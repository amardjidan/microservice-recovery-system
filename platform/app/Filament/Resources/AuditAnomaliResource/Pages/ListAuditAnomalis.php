<?php

namespace App\Filament\Resources\AuditAnomaliResource\Pages;

use App\Filament\Resources\AuditAnomaliResource;
use App\Services\AuditApi;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Daftar anomali. Halaman biasa (bukan ListRecords) karena datanya dari API,
 * bukan model Eloquent.
 */
class ListAuditAnomalis extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = AuditAnomaliResource::class;

    protected static ?string $title = 'Daftar anomali';

    protected string $view = 'filament.pages.daftar-anomali';

    public function table(Table $table): Table
    {
        return $table
            // Data dari API (GET /api/audit/anomalies), bukan query database langsung.
            ->records(function (?string $search, array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                $hasil = app(AuditApi::class)->anomalies([
                    'jenis' => $filters['jenis']['value'] ?? null,
                    'scenario' => $filters['scenario']['scenario'] ?? null,
                    'q' => $search,
                ], $page, $recordsPerPage);

                $baris = [];
                foreach ($hasil['data'] as $r) {
                    $r['id'] = $r['referensi'].':'.$r['scenario'];
                    $baris[$r['id']] = $r;
                }

                return new LengthAwarePaginator($baris, (int) $hasil['total'], $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('referensi')
                    ->label('Referensi')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('scenario')
                    ->label('Scenario'),
                TextColumn::make('pola')
                    ->label('Pola')
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('idx_ditemukan')
                    ->label('Idx ditemukan / seharusnya')
                    ->formatStateUsing(fn ($state, array $record) => "{$record['idx_ditemukan']} / {$record['idx_seharusnya']}"),
                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => explode(' ', $state)[0])
                    ->color(fn (string $state) => AuditAnomaliResource::warna(explode(' ', $state)[0])),
            ])
            ->filters([
                SelectFilter::make('jenis')
                    ->label('Jenis anomali')
                    ->options([
                        'IDX_HILANG' => 'IDX_HILANG',
                        'JUMLAH_TIDAK_RATA' => 'JUMLAH_TIDAK_RATA',
                        'IDX_LEBIH' => 'IDX_LEBIH',
                    ]),
                Filter::make('scenario')
                    ->schema([TextInput::make('scenario')->label('Scenario')->numeric()]),
            ])
            ->recordActions([
                Action::make('lihat')
                    ->label('Detail')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (array $record) => AuditAnomaliResource::getUrl('detail', ['referensi' => $record['referensi']])),
            ])
            ->recordUrl(fn (array $record) => AuditAnomaliResource::getUrl('detail', ['referensi' => $record['referensi']]))
            ->paginated([25, 50, 100]);
    }
}

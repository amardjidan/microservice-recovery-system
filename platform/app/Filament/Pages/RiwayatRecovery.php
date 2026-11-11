<?php

namespace App\Filament\Pages;

use App\Services\AuditApi;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Filament\Actions\Action;

class RiwayatRecovery extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Riwayat recovery';

    protected static ?string $title = 'Riwayat recovery';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.riwayat-recovery';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                $hasil = app(AuditApi::class)->riwayat([
                    'referensi' => $search,
                    'aksi' => $filters['aksi']['value'] ?? null,
                ], $page, $recordsPerPage);

                $baris = [];
                foreach ($hasil['data'] as $r) {
                    $baris[$r['id']] = $r;
                }

                return new LengthAwarePaginator($baris, (int) $hasil['total'], $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu'),
                TextColumn::make('referensi')
                    ->label('Referensi')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('scenario')
                    ->label('Scenario'),
                TextColumn::make('idx')
                    ->label('Index'),
                TextColumn::make('coa_name')
                    ->label('Nama akun'),
                TextColumn::make('debit')
                    ->label('Debit')
                    ->numeric(thousandsSeparator: '.', decimalSeparator: ',', decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('credit')
                    ->label('Kredit')
                    ->numeric(thousandsSeparator: '.', decimalSeparator: ',', decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('aksi')
                    ->label('Aksi')
                    ->badge()
                    ->color(fn (string $state) => $state === 'HAPUS' ? 'danger' : 'success'),
                TextColumn::make('dibuat_oleh')
                    ->label('Oleh'),
            ])
            ->filters([
                SelectFilter::make('aksi')
                    ->label('Aksi')
                    ->options([
                        'TAMBAH' => 'Tambah',
                        'HAPUS' => 'Hapus',
                    ]),
            ])
            ->recordActions([
            Action::make('detail')
            ->label('Detail')
            ->modalWidth('3xl')
            ->modalHeading(fn (array $record) => 'Referensi '.$record['referensi'])
            ->modalContent(fn (array $record) => view('filament.pages.riwayat-detail', ['r' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup'),
        ])
            ->recordAction('detail')
            ->paginated([25, 50, 100]);
    }
}
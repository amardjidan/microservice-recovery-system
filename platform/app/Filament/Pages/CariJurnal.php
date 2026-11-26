<?php

namespace App\Filament\Pages;

use App\Services\AuditApi;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class CariJurnal extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Cari jurnal';

    protected static ?string $title = 'Cari data jurnal';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.cari-jurnal';

    public function table(Table $table): Table
{
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $tahun = collect(range(now()->year + 1, 2020))
        ->mapWithKeys(fn ($y) => [$y => (string) $y])
        ->all();

    return $table
        ->records(function (array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
            $f = $filters['cari'] ?? [];
            $param = [
                'referensi' => trim((string) ($f['awb'] ?? '')),
                'dari' => $f['dari'] ?? null,
                'sampai' => $f['sampai'] ?? null,
                'bulan' => $f['bulan'] ?? null,
                'tahun' => $f['tahun'] ?? null,
            ];

            // tanpa filter: jangan memuat apa pun
            if (! filled(array_filter($param, fn ($v) => filled($v)))) {
                return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
            }

            try {
                $hasil = app(AuditApi::class)->jurnal($param, $page, $recordsPerPage);
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Gagal memuat data jurnal')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
            }

            $baris = [];
            foreach ($hasil['data'] as $i => $r) {
                $baris[($page - 1) * $recordsPerPage + $i] = $r;
            }

            return new LengthAwarePaginator($baris, $hasil['total'], $recordsPerPage, $page);
        })
        ->filters([
            Filter::make('cari')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('awb')->label('AWB')->placeholder('mis. SPXID064...'),
                    DatePicker::make('dari')->label('Dari tanggal'),
                    DatePicker::make('sampai')->label('Sampai tanggal'),
                  ])
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                    'lg' => 3,
                    'xl' => 5,
                ]),
        ], layout: FiltersLayout::AboveContent)
        ->filtersFormColumns(1)
        ->deferFilters()
        ->columns([
            TextColumn::make('referensi')->label('AWB')->fontFamily('mono'),
            TextColumn::make('scenario')->label('Scenario'),
            TextColumn::make('idx')->label('Index'),
            TextColumn::make('coa_name')->label('Nama akun (COA)'),
            TextColumn::make('debit')
                ->label('Debit')
                ->numeric(decimalPlaces: 2, thousandsSeparator: '.', decimalSeparator: ',')
                ->alignEnd(),
            TextColumn::make('credit')
                ->label('Kredit')
                ->numeric(decimalPlaces: 2, thousandsSeparator: '.', decimalSeparator: ',')
                ->alignEnd(),
            TextColumn::make('journal_date')->label('Tanggal')->date('d/m/Y'),
            TextColumn::make('source_month')->label('Bulan sumber'),
        ])
        ->emptyStateHeading('Isi filter untuk menampilkan data jurnal')
        ->emptyStateDescription('Cari berdasarkan AWB, rentang tanggal, bulan, atau tahun, lalu terapkan filter.')
        ->emptyStateIcon('heroicon-o-magnifying-glass')
        ->paginated([25, 50, 100]);
}
}
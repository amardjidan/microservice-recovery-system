<?php

namespace App\Filament\Pages;

use App\Filament\Support\AuditSummary;
use App\Services\AuditApi;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class PolaNormal extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Pola normal';

    protected static ?string $title = 'Pola normal';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.pola-normal';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tambah')
                ->label('Tambah pola')
                ->schema([
                    TextInput::make('pattern')
                        ->label('Pola baru')
                        ->placeholder('mis. 6,6,9')
                        ->helperText('Angka dipisah koma, tanpa spasi.')
                        ->required()
                        ->maxLength(200)
                        ->regex('/^\d+(,\d+)*$/'),
                    Toggle::make('is_spx')->label('Pola SPX'),
                ])
                ->action(function (array $data, AuditApi $api) {
                    try {
                        $baru = $api->addPattern($data['pattern'], (bool) ($data['is_spx'] ?? false));
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Gagal menambah pola')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    AuditSummary::forget();

                    Notification::make()
                        ->title($baru ? "Pola {$data['pattern']} ditambahkan" : "Pola {$data['pattern']} sudah ada")
                        ->body($baru ? 'Jalankan audit ulang dari Ringkasan agar hitungan ikut berubah.' : null)
                        ->{$baru ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator {
                $semua = collect(app(AuditApi::class)->patterns());

                if (filled($search)) {
                    $semua = $semua->filter(fn (array $r) => str_contains($r['pattern'], $search));
                }

                $kolom = in_array($sortColumn, ['pattern', 'jumlah'], true) ? $sortColumn : 'jumlah';
                $arah = ($sortDirection ?? 'desc') === 'asc' ? 'asc' : 'desc';
                $semua = $semua->sortBy([[$kolom, $arah], ['pattern', 'asc']])->values();

                $halaman = $semua->forPage($page, $recordsPerPage)
                    ->mapWithKeys(fn (array $r) => [$r['pattern'] => $r])
                    ->all();

                return new LengthAwarePaginator($halaman, $semua->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('pattern')
                    ->label('Pola')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_spx')->label('SPX')->boolean(),
                TextColumn::make('jumlah')
                    ->label('Referensi')
                    ->numeric(thousandsSeparator: '.', decimalSeparator: ',')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('jumlah', 'desc')
            ->recordActions([
            Action::make('hapus')
                ->label('Hapus')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (array $record) => "Hapus pola {$record['pattern']}?")
                ->modalDescription('Pola ini akan dihapus dari daftar pola normal. Referensi dengan pola ini akan dianggap tidak normal setelah audit ulang.')
                ->modalSubmitActionLabel('Hapus')
                ->action(function (array $record, AuditApi $api) {
                    try {
                        $api->removePattern($record['pattern']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Gagal menghapus pola')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    AuditSummary::forget();

                    // Buang cache baris tabel supaya daftar langsung memuat data terbaru
                    $this->js('$wire.$refresh()');

                    Notification::make()
                        ->title("Pola {$record['pattern']} dihapus")
                        ->body('Jalankan audit ulang dari Ringkasan agar hitungan ikut berubah.')
                        ->success()
                        ->send();
            }),
        ])
            ->paginated([25, 50, 100]);
    }
}

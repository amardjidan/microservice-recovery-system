<?php

namespace App\Filament\Pages;

use App\Filament\Support\AuditSummary;
use App\Services\AuditApi;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;
use InvalidArgumentException;
use Filament\Forms\Components\Hidden;



class PolaKhusus extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'Pola khusus';

    protected static ?string $title = 'Pola khusus';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.pola-khusus';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tambah')
                ->label('Tambah pola khusus')
                ->schema([
                    TextInput::make('pola')
                        ->label('Pola yang dicek')
                        ->placeholder('mis. 6,6,7,16')
                        ->helperText('Angka dipisah koma, tanpa spasi.')
                        ->required()
                        ->maxLength(200)
                        ->regex('/^\d+(,\d+)*$/'),
                    TextInput::make('pola_normal')
                        ->label('Pola normal acuan')
                        ->placeholder('mis. 6,6,6,7,16')
                        ->helperText('Harus sudah terdaftar di Pola normal.')
                        ->required()
                        ->maxLength(200)
                        ->regex('/^\d+(,\d+)*$/'),
                    TextInput::make('scenario')
                        ->label('Scenario yang dihitung blok-nya')
                        ->numeric()->minValue(0)->required(),
                    Hidden::make('idx')->default(0),
                    TextInput::make('jumlah_seharusnya')
                        ->label('Jumlah blok seharusnya')
                        ->numeric()->minValue(1)->required(),
                ])
                ->action(function (array $data, AuditApi $api) {
                    try {
                        $baru = $api->addPolaKhusus(
                            $data['pola'], (int) $data['scenario'], $data['pola_normal'],
                            (int) $data['idx'], (int) $data['jumlah_seharusnya']
                        );
                    } catch (InvalidArgumentException|RuntimeException $e) {
                        Notification::make()->title('Gagal menambah pola khusus')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    AuditSummary::forget();

                    Notification::make()
                        ->title($baru ? "Pola khusus {$data['pola']} ditambahkan" : "Pola {$data['pola']} sudah ada")
                        ->body($baru ? 'AWB dengan pola ini langsung muncul di Daftar anomali.' : null)
                        ->{$baru ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator {
                $semua = collect(app(AuditApi::class)->polaKhusus());

                if (filled($search)) {
                    $semua = $semua->filter(fn (array $r) => str_contains($r['pola'], $search));
                }

                $kolom = in_array($sortColumn, ['pola', 'jumlah'], true) ? $sortColumn : 'jumlah';
                $arah = ($sortDirection ?? 'desc') === 'asc' ? 'asc' : 'desc';
                $semua = $semua->sortBy([[$kolom, $arah], ['pola', 'asc']])->values();

                $halaman = $semua->forPage($page, $recordsPerPage)
                    ->mapWithKeys(fn (array $r) => [$r['pola'] => $r])
                    ->all();

                return new LengthAwarePaginator($halaman, $semua->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('pola')->label('Pola')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('pola_normal')->label('Pola normal acuan')->fontFamily('mono'),
                TextColumn::make('scenario')->label('Scenario'),
                TextColumn::make('jumlah_seharusnya')->label('Blok seharusnya'),
                TextColumn::make('jumlah')
                    ->label('Total AWB')
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
                    ->modalHeading(fn (array $record) => "Hapus pola khusus {$record['pola']}?")
                    ->modalDescription('AWB dengan pola ini tidak lagi ditandai sebagai Audit Pola Khusus.')
                    ->modalSubmitActionLabel('Hapus')
                    ->action(function (array $record, AuditApi $api) {
                        try {
                            $api->removePolaKhusus($record['pola']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal menghapus')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        AuditSummary::forget();
                        $this->js('$wire.$refresh()');

                        Notification::make()->title("Pola khusus {$record['pola']} dihapus")->success()->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
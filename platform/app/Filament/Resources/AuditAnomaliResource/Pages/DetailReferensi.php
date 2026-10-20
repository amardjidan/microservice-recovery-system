<?php

namespace App\Filament\Resources\AuditAnomaliResource\Pages;

use App\Filament\Resources\AuditAnomaliResource;
use App\Services\AuditApi;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use InvalidArgumentException;
use RuntimeException;

class DetailReferensi extends Page
{
    protected static string $resource = AuditAnomaliResource::class;

    protected string $view = 'filament.pages.detail-referensi';

    public string $referensi = '';

    /** Baris yang kurang: [{scenario, idx, coa, debit, credit}] */
    public array $baris = [];

    public bool $konfirmasi = false;

    public function mount(string $referensi): void
    {
        $this->referensi = $referensi;
        $this->muatBaris();
    }

    public function getTitle(): string
    {
        return 'Detail referensi '.$this->referensi;
    }

    public function getBreadcrumbs(): array
    {
        return [
            AuditAnomaliResource::getUrl() => 'Daftar anomali',
            $this->referensi,
        ];
    }

    /** Data cek langsung dari journal_all (cek_referensi + cek_idx). */
    public function data(): array
    {
        $d = app(AuditApi::class)->reference($this->referensi);

        return [
            'scenario' => array_map(fn ($r) => (array) $r, $d['scenario']),
            'idx' => array_map(fn ($r) => (array) $r, $d['idx']),
        ];
    }

    private function muatBaris(): void
    {
        $this->baris = [];
        foreach ($this->data()['idx'] as $i) {
            $n = (int) $i['max_muncul'] - (int) $i['muncul'];
            if ($i['coa_name'] !== null && $n > 0) {
                for ($k = 0; $k < $n; $k++) {
                    $this->baris[] = [
                        'scenario' => (int) $i['scenario'],
                        'idx' => (int) $i['idx'],
                        'coa' => $i['coa_name'],
                        'debit' => '',
                        'credit' => '',
                    ];
                }
            }
        }
        $this->konfirmasi = false;
    }

    public function repair(): void
    {
        $this->validate([
            'konfirmasi' => ['accepted'],
            'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.debit' => ['required', 'numeric', 'min:0'],
            'baris.*.credit' => ['required', 'numeric', 'min:0'],
        ], [
            'konfirmasi.accepted' => 'Centang dulu pernyataan bahwa nominal sudah diperiksa.',
            'baris.*.debit.required' => 'Debit wajib diisi (isi 0 kalau tidak ada).',
            'baris.*.credit.required' => 'Kredit wajib diisi (isi 0 kalau tidak ada).',
            'baris.*.debit.numeric' => 'Debit harus angka.',
            'baris.*.credit.numeric' => 'Kredit harus angka.',
        ]);

        $rows = array_map(fn ($b) => [
            'scenario' => (int) $b['scenario'],
            'idx' => (int) $b['idx'],
            'debit' => $b['debit'],
            'credit' => $b['credit'],
        ], $this->baris);

        try {
            $hasil = app(AuditApi::class)->repair(
                $this->referensi, $rows, auth()->user()?->email ?? 'filament'
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->title('Gagal menambahkan baris')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(count($hasil['ditambahkan']).' baris ditambahkan ke journal_all')
            ->body('Dicatat di audit_repair_log. Jalankan audit ulang dari Ringkasan agar daftar anomali ikut berubah.')
            ->success()
            ->send();

        $this->muatBaris();
    }
}

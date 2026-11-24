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

    /** Idx berlebih: [{scenario, idx, muncul, pilih}] */
    public array $lebih = [];

    public bool $konfirmasiHapus = false;

    public array $blok = [];

    public bool $konfirmasiBlok = false;

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
        try {
        $idxData = $this->data()['idx'];
    } catch (RuntimeException $e) {
        Notification::make()
            ->title('Perubahan tersimpan')
            ->body('Tabel belum bisa dimuat ulang. Muat ulang halaman sebentar lagi.')
            ->warning()
            ->send();

        return;
    }

    $this->baris = [];
    foreach ($idxData as $i) {
        $n = (int) $i['max_muncul'] - (int) $i['muncul'];
        if ($i['coa_name'] !== null && $n > 0) {
            for ($k = 0; $k < $n; $k++) {
                    $this->baris[] = [
                    'scenario' => (int) $i['scenario'],
                    'idx' => (int) $i['idx'],
                    'coa' => $i['coa_name'],
                    'debit' => '',
                    'credit' => '',
                    'pilih' => false,
                ];
            }
        }
    }

    $this->lebih = [];
    foreach ($idxData as $i) {
        if ($i['coa_name'] === null && (int) $i['muncul'] > 0) {
            $this->lebih[] = [
                'scenario' => (int) $i['scenario'],
                'idx' => (int) $i['idx'],
                'muncul' => (int) $i['muncul'],
                'pilih' => false,
            ];
        }
    }

    $this->konfirmasi = false;
    $this->konfirmasiHapus = false;
    $this->konfirmasiBlok = false;
    $this->muatBlok();
    }

    private function muatBlok(): void
{
    $this->blok = [];

    try {
        foreach ($this->data()['scenario'] as $sc) {
            if (! str_starts_with(strtoupper((string) $sc['jenis']), 'POLA_SKENARIO_TIDAK_SESUAI')) {
                continue;
            }

            $f = app(AuditApi::class)->formBlok($this->referensi, (int) $sc['scenario']);

            $this->blok[] = [
                'scenario' => (int) $f['scenario'],
                'sekarang' => (int) $f['blok_sekarang'],
                'target' => (int) $f['target_blok'],
                'baris' => array_map(fn ($t) => [
                    'idx' => (int) $t['idx'],
                    'coa' => $t['coa_name'],
                    'debit' => '',
                    'credit' => '',
                ], $f['template']),
            ];
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        // form tidak tampil kalau API gagal; halaman lain tetap jalan
    }
}

public function tambahBlok(int $b): void
{
    $this->validate([
        'konfirmasiBlok' => ['accepted'],
        "blok.$b.baris.*.debit" => ['required', 'numeric', 'min:0'],
        "blok.$b.baris.*.credit" => ['required', 'numeric', 'min:0'],
    ], [
        'konfirmasiBlok.accepted' => 'Centang dulu pernyataan bahwa nominal sudah diperiksa.',
        'blok.*.baris.*.debit.required' => 'Debit wajib diisi (isi 0 kalau tidak ada).',
        'blok.*.baris.*.credit.required' => 'Kredit wajib diisi (isi 0 kalau tidak ada).',
        'blok.*.baris.*.debit.numeric' => 'Debit harus angka.',
        'blok.*.baris.*.credit.numeric' => 'Kredit harus angka.',
    ]);

    $blok = $this->blok[$b];
    $nilai = [];
    foreach ($blok['baris'] as $r) {
        $nilai[$r['idx']] = ['debit' => $r['debit'], 'credit' => $r['credit']];
    }

    try {
        app(AuditApi::class)->tambahBlok(
            $this->referensi, $blok['scenario'], $blok['target'], $nilai,
            auth()->user()?->email ?? 'filament'
        );
    } catch (InvalidArgumentException|RuntimeException $e) {
        Notification::make()->title('Gagal menambah blok')->body($e->getMessage())->danger()->send();
        $this->muatBaris();

        return;
    }

    Notification::make()
        ->title('1 blok scenario '.$blok['scenario'].' ditambahkan ke journal_all')
        ->body('Audit ulang berjalan otomatis di latar belakang.')
        ->success()
        ->send();

    $this->redirect(AuditAnomaliResource::getUrl());
}

        public function repair(): void
    {
        $dipilih = array_filter($this->baris, fn ($b) => ! empty($b['pilih']));

        if (! $dipilih) {
            Notification::make()->title('Pilih minimal satu baris')->warning()->send();

            return;
        }

        $aturan = ['konfirmasi' => ['accepted']];
        foreach ($dipilih as $k => $b) {
            $aturan["baris.$k.debit"] = ['required', 'numeric', 'min:0'];
            $aturan["baris.$k.credit"] = ['required', 'numeric', 'min:0'];
        }

        $this->validate($aturan, [
            'konfirmasi.accepted' => 'Centang dulu pernyataan bahwa nominal sudah diperiksa.',
            'baris.*.debit.required' => 'Debit wajib diisi (isi 0 kalau tidak ada).',
            'baris.*.credit.required' => 'Kredit wajib diisi (isi 0 kalau tidak ada).',
            'baris.*.debit.numeric' => 'Debit harus angka.',
            'baris.*.credit.numeric' => 'Kredit harus angka.',
        ]);

        $rows = array_values(array_map(fn ($b) => [
            'scenario' => (int) $b['scenario'],
            'idx' => (int) $b['idx'],
            'debit' => $b['debit'],
            'credit' => $b['credit'],
        ], $dipilih));

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
            ->body('Audit ulang berjalan otomatis di latar belakang.')
            ->success()
            ->send();

        $this->muatBaris();
    }

        public function hapusLebih(): void
    {
        $this->validate(
            ['konfirmasiHapus' => ['accepted']],
            ['konfirmasiHapus.accepted' => 'Centang dulu pernyataan konfirmasi penghapusan.']
        );

        $dipilih = array_values(array_filter($this->lebih, fn ($b) => ! empty($b['pilih'])));
        if (! $dipilih) {
            Notification::make()->title('Pilih minimal satu index')->warning()->send();

            return;
        }

        try {
            $hasil = app(AuditApi::class)->removeExtraIdx(
                $this->referensi,
                array_map(fn ($b) => ['scenario' => $b['scenario'], 'idx' => $b['idx']], $dipilih),
                auth()->user()?->email ?? 'filament'
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->title('Gagal menghapus index')->body($e->getMessage())->danger()->send();

            // Segarkan daftar supaya idx yang sudah terhapus tidak tertinggal
            $this->muatBaris();

            return;
        }

        $dihapus = $hasil['dihapus'] ?? 0;
        $jumlah = is_array($dihapus) ? count($dihapus) : (int) $dihapus;

        Notification::make()
            ->title($jumlah.' baris index lebih dihapus dari journal_all')
            ->body('Dicatat di audit_repair_log. Audit ulang berjalan otomatis di latar belakang.')
            ->success()
            ->send();

        $this->muatBaris();
    }
}

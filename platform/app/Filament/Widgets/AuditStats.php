<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AuditAnomaliResource;
use App\Filament\Support\AuditSummary as S;
use App\Services\AuditApi;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;


/**
 * Seluruh isi dashboard dalam SATU widget (kartu angka, proporsi, dua tabel pola),
 * jadi satu polling, satu query, dan semuanya selalu menampilkan data dari saat yang sama.
 */
class AuditStats extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.audit-ringkasan';

    #[On('audit-selesai')]
    public function segarkan(): void
    {
    }

    protected function getViewData(): array
    {
        $s = S::get();
        $total = max($s['total_referensi'], 1);
        $normalPct = round($s['referensi_normal'] / $total * 100, 1);

        $baris = fn (array $rows) => array_map(
            fn ($r) => ['pola' => $r->pola, 'jumlah' => S::angka($r->jumlah)], $rows
        );

        return [
            'kartu' => [
                ['label' => 'Total referensi', 'nilai' => S::angka($s['total_referensi']),
                    'ket' => 'Seluruh journal_all', 'warna' => null],
                ['label' => 'Referensi normal', 'nilai' => S::angka($s['referensi_normal']),
                    'ket' => S::persen($s['referensi_normal'] / $total * 100).'% dari total', 'warna' => 'success'],
                ['label' => 'Referensi tidak normal', 'nilai' => S::angka($s['referensi_tidak_normal']),
                    'ket' => S::persen($s['referensi_tidak_normal'] / $total * 100).'% dari total', 'warna' => 'warning'],
            ],
            'normal' => $normalPct,
            'tidak' => round(100 - $normalPct, 1),
            'normalLabel' => S::persen($normalPct),
            'tidakLabel' => S::persen(100 - $normalPct),
            'polaBerbeda' => S::angka($s['pola_tidak_normal_berbeda']),
            'perJenis' => array_map(fn ($j) => [
                'kode' => $j->kode,
                'warna' => AuditAnomaliResource::warna($j->kode),
                'referensi' => S::angka($j->jumlah_referensi),
                'scenario' => S::angka($j->jumlah_scenario),
                'url' => AuditAnomaliResource::getUrl('index', ['filters' => ['jenis' => ['value' => $j->kode]]]),
            ], $s['anomali_per_jenis']),
            'tabel' => [
                ['judul' => 'Pola tidak normal teratas', 'rows' => $baris($s['pola_tidak_normal_top'])],
                ['judul' => 'Pola normal teratas', 'rows' => $baris($s['pola_normal_top'])],
            ],
            'diperbarui' => now()->format('H:i:s'),
            'statusAudit' => $this->statusAudit(),
        ];
    }

        private function statusAudit(): ?array
    {
        try {
            return app(AuditApi::class)->refreshStatus();
        } catch (\Throwable) {
            return null;
        }
    }
}

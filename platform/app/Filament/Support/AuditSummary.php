<?php

namespace App\Filament\Support;

use App\Services\AuditApi;
use Illuminate\Support\Facades\Cache;

/** Ringkasan dihitung sekali per request, dipakai bersama oleh semua widget dashboard. */
class AuditSummary
{
    private static ?array $data = null;

    public static function get(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        // Cache singkat: semua widget memakai satu hasil, jadi polling tidak menjalankan query berat berkali-kali.
        // Disimpan sebagai JSON (bukan objek) karena Laravel menolak memulihkan stdClass dari cache.
        $json = Cache::get('audit.berjalan') ? Cache::get('audit.summary.terakhir') : null;

        $json ??= Cache::remember('audit.summary', 60, function () {
            $hasil = json_encode(app(AuditApi::class)->summary());
            Cache::forever('audit.summary.terakhir', $hasil);

            return $hasil;
        });

        $d = json_decode($json, true);
        foreach (['anomali_per_jenis', 'pola_tidak_normal_top', 'pola_normal_top'] as $k) {
            $d[$k] = array_map(fn ($r) => (object) $r, $d[$k] ?? []);
        }

        return self::$data = $d;
    }

    public static function forget(): void
    {
        self::$data = null;
        Cache::forget('audit.summary');
    }

    public static function angka(int|float $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    public static function persen(float $n): string
    {
        return number_format($n, 1, ',', '.');
    }
}

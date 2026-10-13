<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lapisan tipis di atas objek database audit yang sudah ada.
 * Logika audit TIDAK ditulis ulang di sini; semua dipanggil dari DB:
 *   view  audit_anomali, function cek_referensi(), cek_idx(), procedure refresh_audit().
 *
 * ASUMSI NAMA KOLOM (cek dengan \d di psql, ubah di sini kalau beda):
 *   audit_anomali       : referensi, scenario, max_cnt, min_cnt, idx_ditemukan, idx_seharusnya, pola, jenis
 *   referensi_pola_full : referensi, pola
 */
class AuditService
{
    public const JENIS = ['IDX_HILANG', 'IDX_LEBIH', 'JUMLAH_TIDAK_RATA', 'TANPA_TEMPLATE'];

    public function summary(): array
    {
        $row = DB::selectOne("
            SELECT COUNT(*)                                        AS total,
                   COUNT(*) FILTER (WHERE n.pattern IS NOT NULL)   AS normal
            FROM referensi_pola_full f
            LEFT JOIN normal_scenario_patterns n ON n.pattern = f.pola
        ");

        $perJenis = DB::select("SELECT jenis, COUNT(DISTINCT referensi) AS jumlah
                                FROM audit_anomali GROUP BY jenis ORDER BY jumlah DESC");

        $topTidakNormal = DB::select("
            SELECT f.pola, COUNT(*) AS jumlah
            FROM referensi_pola_full f
            LEFT JOIN normal_scenario_patterns n ON n.pattern = f.pola
            WHERE n.pattern IS NULL
            GROUP BY f.pola ORDER BY jumlah DESC LIMIT 10");

        $topNormal = DB::select("
            SELECT f.pola, COUNT(*) AS jumlah
            FROM referensi_pola_full f
            JOIN normal_scenario_patterns n ON n.pattern = f.pola
            GROUP BY f.pola ORDER BY jumlah DESC LIMIT 10");

        return [
            'total_referensi'        => (int) $row->total,
            'referensi_normal'       => (int) $row->normal,
            'referensi_tidak_normal' => (int) $row->total - (int) $row->normal,
            'anomali_per_jenis'      => $perJenis,
            'pola_tidak_normal_top'  => $topTidakNormal,
            'pola_normal_top'        => $topNormal,
        ];
    }

    public function anomalies(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $bind = [];
        if (!empty($f['jenis']))    { $where[] = 'jenis = ?';             $bind[] = $f['jenis']; }
        if (!empty($f['scenario'])) { $where[] = 'scenario = ?';          $bind[] = (int) $f['scenario']; }
        if (!empty($f['q']))        { $where[] = 'referensi ILIKE ?';     $bind[] = '%' . $f['q'] . '%'; }
        $w = implode(' AND ', $where);

        $total = DB::selectOne("SELECT COUNT(*) AS n FROM audit_anomali WHERE $w", $bind)->n;
        $rows = DB::select(
            "SELECT * FROM audit_anomali WHERE $w ORDER BY referensi, scenario LIMIT ? OFFSET ?",
            array_merge($bind, [$perPage, ($page - 1) * $perPage])
        );

        return ['total' => (int) $total, 'page' => $page, 'per_page' => $perPage, 'data' => $rows];
    }

    /** Detail satu referensi: ringkasan per scenario + tabel idx. */
    public function reference(string $ref): array
    {
        return [
            'referensi' => $ref,
            'scenario'  => DB::select('SELECT * FROM cek_referensi(?)', [$ref]),
            'idx'       => DB::select('SELECT * FROM cek_idx(?)', [$ref]),
        ];
    }

    public function patterns(): array
    {
        return DB::select("
            SELECT n.pattern, n.is_spx, COUNT(f.referensi) AS jumlah
            FROM normal_scenario_patterns n
            LEFT JOIN referensi_pola_full f ON f.pola = n.pattern
            GROUP BY n.pattern, n.is_spx
            ORDER BY jumlah DESC, n.pattern");
    }

    public function addPattern(string $pattern, bool $isSpx): bool
    {
        $this->assertPattern($pattern);
        return DB::affectingStatement(
            'INSERT INTO normal_scenario_patterns (pattern, is_spx) VALUES (?, ?) ON CONFLICT (pattern) DO NOTHING',
            [$pattern, $isSpx ? 'true' : 'false']
        ) > 0;
    }

    public function removePattern(string $pattern): bool
    {
        return DB::affectingStatement('DELETE FROM normal_scenario_patterns WHERE pattern = ?', [$pattern]) > 0;
    }

    /** Pola ditulis angka dipisah koma tanpa spasi, mis. 6,6,9. */
    private function assertPattern(string $p): void
    {
        if (!preg_match('/^\d+(,\d+)*$/', $p)) {
            throw new InvalidArgumentException('Format pola harus angka dipisah koma tanpa spasi, mis. 6,6,9');
        }
    }

    /** Refresh materialized view (+/- 48 detik di data asli). */
    public function refresh(): void
    {
        DB::statement('CALL refresh_audit()');
    }

    /**
     * Perbaiki idx yang hilang. Nama akun dari template; nominal WAJIB dari user.
     * $rows: [{scenario, idx, debit, credit}], $user: siapa yang memperbaiki.
     * Satu transaksi; semua dicatat di audit_repair_log.
     * Validasi: idx harus ada di template DAN memang kurang muncul menurut cek_idx().
     */
    public function repair(string $ref, array $rows, string $user): array
    {
        return DB::transaction(function () use ($ref, $rows, $user) {
            $status = collect(DB::select('SELECT * FROM cek_idx(?)', [$ref]))
                ->keyBy(fn ($r) => $r->scenario . ':' . $r->idx);

            // berapa baris yang diminta per (scenario, idx)
            $diminta = [];
            foreach ($rows as $r) {
                $k = (int) $r['scenario'] . ':' . (int) $r['idx'];
                $diminta[$k] = ($diminta[$k] ?? 0) + 1;
            }
            foreach ($diminta as $k => $n) {
                $s = $status->get($k);
                if (!$s) {
                    throw new InvalidArgumentException("Idx $k tidak ada di template / referensi ini.");
                }
                $kurang = (int) $s->max_muncul - (int) $s->muncul;
                if ($n > $kurang) {
                    throw new InvalidArgumentException("Idx $k hanya kurang $kurang baris, diminta $n.");
                }
            }

            $inserted = [];
            foreach ($rows as $r) {
                $s = (int) $r['scenario'];
                $i = (int) $r['idx'];

                // tanggal & source_month disalin dari baris lain di scenario yang sama
                $base = DB::selectOne(
                    'SELECT journal_date, source_month FROM journal_all
                     WHERE referensi = ? AND scenario = ? LIMIT 1', [$ref, $s]);
                $tpl = DB::selectOne(
                    'SELECT coa_name FROM scenario_template WHERE scenario = ? AND idx = ?', [$s, $i]);
                if (!$base || !$tpl) {
                    throw new InvalidArgumentException("Data dasar scenario $s idx $i tidak ditemukan.");
                }

                DB::insert(
                    'INSERT INTO journal_all
                       (scenario, idx, referensi, journal_date, coa_name, debit, credit, source_month)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$s, $i, $ref, $base->journal_date, $tpl->coa_name,
                     $r['debit'], $r['credit'], $base->source_month]
                );

                DB::insert(
                    'INSERT INTO audit_repair_log
                       (referensi, scenario, idx, coa_name, debit, credit, dibuat_oleh, created_at)
                     VALUES (?,?,?,?,?,?,?, now())',
                    [$ref, $s, $i, $tpl->coa_name, $r['debit'], $r['credit'], $user]
                );
                $inserted[] = ['scenario' => $s, 'idx' => $i, 'coa_name' => $tpl->coa_name];
            }

            return [
                'ditambahkan' => $inserted,
                // cek ulang cepat untuk referensi ini saja (tanpa refresh 48 detik)
                'hasil_cek'   => $this->reference($ref),
            ];
        });
    }
}

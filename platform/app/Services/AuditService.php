<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lapisan tipis di atas objek database audit yang sudah ada.
 * Logika audit TIDAK ditulis ulang di sini; dibaca dari:
 *   view      audit_anomali
 *   matview   referensi_pola_full
 *   function  cek_referensi(), cek_idx()
 *   procedure refresh_audit()
 *   tabel     journal_all, scenario_template, normal_scenario_patterns
 */
class AuditService
{
    /** Kemungkinan nama kolom pola di referensi_pola_full (dicek otomatis). */
    private const POLA_COLUMNS = ['pola_lengkap', 'pola', 'pola_unik'];

    private ?string $polaColumn = null;

    // ------------------------------------------------------------------
    // Cek seluruh data
    // ------------------------------------------------------------------

    public function summary(): array
    {
        $pola = $this->polaColumn();

        $row = DB::selectOne("
            SELECT COUNT(*)                                      AS total,
                   COUNT(*) FILTER (WHERE n.pattern IS NOT NULL) AS normal,
                   COUNT(DISTINCT f.{$pola}) FILTER (WHERE n.pattern IS NULL) AS pola_tidak_normal_berbeda
            FROM referensi_pola_full f
            LEFT JOIN normal_scenario_patterns n ON n.pattern = f.{$pola}
        ");

        $perJenis = collect(DB::select('
            SELECT jenis,
                   COUNT(*)                   AS jumlah_scenario,
                   COUNT(DISTINCT referensi)  AS jumlah_referensi
            FROM audit_anomali
            GROUP BY jenis
            ORDER BY jumlah_referensi DESC'))
            ->map(fn ($r) => $this->withKode($r))
            ->all();

        $topTidakNormal = DB::select("
            SELECT f.{$pola} AS pola, COUNT(*) AS jumlah
            FROM referensi_pola_full f
            LEFT JOIN normal_scenario_patterns n ON n.pattern = f.{$pola}
            WHERE n.pattern IS NULL
            GROUP BY f.{$pola} ORDER BY jumlah DESC, pola LIMIT 10");

        $topNormal = DB::select("
            SELECT f.{$pola} AS pola, COUNT(*) AS jumlah
            FROM referensi_pola_full f
            JOIN normal_scenario_patterns n ON n.pattern = f.{$pola}
            GROUP BY f.{$pola} ORDER BY jumlah DESC, pola LIMIT 10");

        return [
            'total_referensi' => (int) $row->total,
            'referensi_normal' => (int) $row->normal,
            'referensi_tidak_normal' => (int) $row->total - (int) $row->normal,
            'pola_tidak_normal_berbeda' => (int) $row->pola_tidak_normal_berbeda,
            'anomali_per_jenis' => $perJenis,
            'pola_tidak_normal_top' => $topTidakNormal,
            'pola_normal_top' => $topNormal,
        ];
    }

    /**
     * Laporan menyeluruh dalam satu panggilan: ringkasan + cek yang membaca
     * langsung journal_all (balance dan scenario tanpa template).
     * Dua cek terakhir memindai seluruh journal_all, jadi lebih lambat.
     */
    public function report(float $tolerance): array
    {
        $unbalanced = DB::selectOne('
            SELECT COUNT(*) AS n FROM (
                SELECT 1 FROM journal_all
                GROUP BY referensi, scenario
                HAVING ABS(SUM(debit) - SUM(credit)) > ?
            ) t', [$tolerance])->n;

        $tanpaTemplate = $this->scenariosWithoutTemplate();

        return $this->summary() + [
            'tidak_seimbang' => (int) $unbalanced,
            'scenario_tanpa_template' => $tanpaTemplate,
        ];
    }

    /** Semua anomali (referensi berpola normal yang idx-nya tidak lengkap/rata). */
    public function anomalies(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $bind = [];
        if (! empty($f['jenis'])) {
            $where[] = 'jenis LIKE ?';
            $bind[] = $f['jenis'].'%';
        }
        if (! empty($f['scenario'])) {
            $where[] = 'scenario = ?';
            $bind[] = (int) $f['scenario'];
        }
        if (! empty($f['q'])) {
            $where[] = 'referensi ILIKE ?';
            $bind[] = '%'.$f['q'].'%';
        }

        $out = $this->paginate(
            'SELECT * FROM audit_anomali WHERE '.implode(' AND ', $where),
            $bind, 'referensi, scenario', $page, $perPage
        );
        $out['data'] = array_map(fn ($r) => $this->withKode($r), $out['data']);

        return $out;
    }

    /**
     * Detail tingkat idx untuk SEMUA anomali.
     * tipe=hilang : idx ada di template tapi tidak ada sama sekali di data
     * tipe=kurang : idx muncul lebih sedikit dari idx lain di scenario yang sama
     */
    public function anomalyIdx(string $tipe, int $page, int $perPage): array
    {
        if ($tipe === 'hilang') {
            $sql = '
                SELECT a.referensi, a.scenario, st.idx, st.coa_name
                FROM audit_anomali a
                JOIN scenario_template st ON st.scenario = a.scenario
                LEFT JOIN journal_all ja
                       ON ja.referensi = a.referensi
                      AND ja.scenario  = a.scenario
                      AND ja.idx       = st.idx
                WHERE ja.referensi IS NULL';

            return $this->paginate($sql, [], 'referensi, scenario, idx', $page, $perPage);
        }

        $sql = '
            SELECT a.referensi, a.scenario, ja.idx, st.coa_name,
                   COUNT(*)::int AS muncul, a.max_cnt::int AS seharusnya_muncul
            FROM audit_anomali a
            JOIN journal_all ja ON ja.referensi = a.referensi AND ja.scenario = a.scenario
            LEFT JOIN scenario_template st ON st.scenario = ja.scenario AND st.idx = ja.idx
            WHERE a.max_cnt != a.min_cnt
            GROUP BY a.referensi, a.scenario, ja.idx, st.coa_name, a.max_cnt
            HAVING COUNT(*) < a.max_cnt';

        return $this->paginate($sql, [], 'referensi, scenario, idx', $page, $perPage);
    }

    /** Pola scenario yang tidak ada di normal_scenario_patterns, seluruh data. */
    public function unknownPatterns(int $page, int $perPage): array
    {
        $pola = $this->polaColumn();

        $sql = "
            SELECT f.{$pola} AS pola,
                   COUNT(*)::int AS jumlah_referensi,
                   MIN(f.referensi) AS contoh_referensi
            FROM referensi_pola_full f
            LEFT JOIN normal_scenario_patterns n ON n.pattern = f.{$pola}
            WHERE n.pattern IS NULL
            GROUP BY f.{$pola}";

        return $this->paginate($sql, [], 'jumlah_referensi DESC, pola', $page, $perPage);
    }

    /** Debit tidak sama dengan credit per referensi + scenario, seluruh journal_all. */
    public function unbalanced(float $tolerance, int $page, int $perPage): array
    {
        $sql = '
            SELECT referensi, scenario,
                   SUM(debit)  AS total_debit,
                   SUM(credit) AS total_credit,
                   SUM(debit) - SUM(credit) AS selisih
            FROM journal_all
            GROUP BY referensi, scenario
            HAVING ABS(SUM(debit) - SUM(credit)) > ?';

        return $this->paginate($sql, [$tolerance], 'ABS(selisih) DESC, referensi, scenario', $page, $perPage);
    }

    /** Scenario yang muncul di data tapi tidak ada di scenario_template. */
    public function scenariosWithoutTemplate(): array
    {
        return DB::select('
            SELECT j.scenario,
                   COUNT(*)::int AS jumlah_baris,
                   COUNT(DISTINCT j.referensi)::int AS jumlah_referensi,
                   MIN(j.referensi) AS contoh_referensi
            FROM journal_all j
            WHERE NOT EXISTS (SELECT 1 FROM scenario_template t WHERE t.scenario = j.scenario)
            GROUP BY j.scenario
            ORDER BY j.scenario');
    }

    // ------------------------------------------------------------------
    // Satu referensi
    // ------------------------------------------------------------------

    /** Detail satu referensi: ringkasan per scenario + tabel idx. Langsung dari journal_all. */
    public function reference(string $ref): array
    {
        return [
            'referensi' => $ref,
            'scenario' => DB::select('SELECT * FROM cek_referensi(?)', [$ref]),
            'idx' => DB::select('SELECT * FROM cek_idx(?)', [$ref]),
        ];
    }

    /**
     * Perbaiki idx yang hilang. Nama akun dari template; nominal WAJIB dari user.
     * $rows: [{scenario, idx, debit, credit}]. Satu transaksi; semua dicatat di audit_repair_log.
     * Validasi: idx harus ada di template DAN memang kurang muncul menurut cek_idx().
     */
    public function repair(string $ref, array $rows, string $user): array
    {
        DB::transaction(function () use ($ref, $rows, $user, &$inserted) {
            // Cegah dua perbaikan bersamaan untuk referensi yang sama.
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$ref]);

            $status = collect(DB::select('SELECT * FROM cek_idx(?)', [$ref]))
                ->keyBy(fn ($r) => $r->scenario.':'.$r->idx);

            $diminta = [];
            foreach ($rows as $r) {
                $k = (int) $r['scenario'].':'.(int) $r['idx'];
                $diminta[$k] = ($diminta[$k] ?? 0) + 1;
            }
            foreach ($diminta as $k => $n) {
                $s = $status->get($k);
                if (! $s || $s->coa_name === null) {
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

                // tanggal dan source_month disalin dari baris lain di scenario yang sama
                $base = DB::selectOne(
                    'SELECT journal_date, source_month FROM journal_all
                     WHERE referensi = ? AND scenario = ? LIMIT 1', [$ref, $s]);
                $tpl = DB::selectOne(
                    'SELECT coa_name FROM scenario_template WHERE scenario = ? AND idx = ?', [$s, $i]);
                if (! $base || ! $tpl) {
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
        });

        return [
            'ditambahkan' => $inserted,
            // cek ulang cepat untuk referensi ini saja (tanpa refresh 48 detik)
            'hasil_cek' => $this->reference($ref),
        ];
    }

    // ------------------------------------------------------------------
    // Pola normal
    // ------------------------------------------------------------------

    public function patterns(): array
    {
        $pola = $this->polaColumn();

        return DB::select("
            SELECT n.pattern, n.is_spx, COUNT(f.referensi)::int AS jumlah
            FROM normal_scenario_patterns n
            LEFT JOIN referensi_pola_full f ON f.{$pola} = n.pattern
            GROUP BY n.pattern, n.is_spx
            ORDER BY jumlah DESC, n.pattern");
    }

    public function addPattern(string $pattern, bool $isSpx): bool
    {
        $this->assertPattern($pattern);

        return DB::affectingStatement(
            'INSERT INTO normal_scenario_patterns (pattern, is_spx) VALUES (?, ?::boolean)
             ON CONFLICT (pattern) DO NOTHING',
            [$pattern, $isSpx ? 'true' : 'false']
        ) > 0;
    }

    public function removePattern(string $pattern): bool
    {
        return DB::affectingStatement('DELETE FROM normal_scenario_patterns WHERE pattern = ?', [$pattern]) > 0;
    }

    /** Refresh materialized view (+/- 48 detik di data asli). */
    public function refresh(): void
    {
        DB::statement('CALL refresh_audit()');
    }

    // ------------------------------------------------------------------
    // Bantuan
    // ------------------------------------------------------------------

    /** Pola ditulis angka dipisah koma tanpa spasi, mis. 6,6,9. */
    private function assertPattern(string $p): void
    {
        if (! preg_match('/^\d+(,\d+)*$/', $p)) {
            throw new InvalidArgumentException('Format pola harus angka dipisah koma tanpa spasi, mis. 6,6,9');
        }
    }

    /** jenis di view bisa berakhiran keterangan, mis. "IDX_LEBIH (tidak ada di template)". */
    private function withKode(object $row): object
    {
        $row->kode = explode(' ', (string) $row->jenis)[0];

        return $row;
    }

    /** Hitung total lalu ambil satu halaman. $order tidak boleh berasal dari input user. */
    private function paginate(string $sql, array $bind, string $order, int $page, int $perPage): array
    {
        $total = DB::selectOne("SELECT COUNT(*) AS n FROM ($sql) t", $bind)->n;
        $rows = DB::select(
            "SELECT * FROM ($sql) t ORDER BY $order LIMIT ? OFFSET ?",
            array_merge($bind, [$perPage, ($page - 1) * $perPage])
        );

        return ['total' => (int) $total, 'page' => $page, 'per_page' => $perPage, 'data' => $rows];
    }

    /** Nama kolom pola di referensi_pola_full berbeda antar versi; cari dari katalog. */
    private function polaColumn(): string
    {
        if ($this->polaColumn) {
            return $this->polaColumn;
        }

        $cols = array_map(fn ($r) => $r->attname, DB::select("
            SELECT attname FROM pg_attribute
            WHERE attrelid = to_regclass('referensi_pola_full')
              AND attnum > 0 AND NOT attisdropped"));

        foreach (self::POLA_COLUMNS as $c) {
            if (in_array($c, $cols, true)) {
                return $this->polaColumn = $c;
            }
        }

        throw new RuntimeException(
            'Kolom pola tidak ditemukan di referensi_pola_full. Kolom yang ada: '.implode(', ', $cols)
        );
    }
}

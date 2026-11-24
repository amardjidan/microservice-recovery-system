<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lapisan tipis di atas objek database audit yang sudah ada.
 * Logika audit TIDAK ditulis ulang di sini; dibaca dari:
 *   view      audit_anomali, audit_pola_khusus, audit_saldo
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
                   COUNT(*)                  AS jumlah_scenario,
                   COUNT(DISTINCT referensi) AS jumlah_referensi
            FROM (
                SELECT jenis, referensi FROM audit_anomali
                UNION ALL
                SELECT jenis, referensi FROM audit_pola_khusus
                UNION ALL
                SELECT jenis, referensi FROM audit_saldo
            ) a
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

    /**
     * Semua anomali. Jenis POLA_SKENARIO_TIDAK_SESUAI dibaca dari audit_pola_khusus,
     * TIDAK_SEIMBANG dari audit_saldo, jenis lainnya dari audit_anomali.
     */
    public function anomalies(array $f, int $page, int $perPage): array
    {
        $jenis = $f['jenis'] ?? null;
        $kolom = 'referensi, scenario, jenis, idx_ditemukan, idx_seharusnya';
        $kolomSaldo = 'referensi, scenario, jenis, NULL::int AS idx_ditemukan, NULL::int AS idx_seharusnya';

        if ($jenis === 'POLA_SKENARIO_TIDAK_SESUAI') {
            $sumber = 'audit_pola_khusus';
        } elseif ($jenis === 'TIDAK_SEIMBANG') {
            $sumber = 'audit_saldo';
        } elseif (empty($jenis)) {
            // tanpa filter: gabungkan ketiga view
            $sumber = "(SELECT {$kolom} FROM audit_anomali
                        UNION ALL
                        SELECT {$kolom} FROM audit_pola_khusus
                        UNION ALL
                        SELECT {$kolomSaldo} FROM audit_saldo) s";
        } else {
            $sumber = 'audit_anomali';
        }

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
            "SELECT * FROM {$sumber} WHERE ".implode(' AND ', $where),
            $bind, 'referensi, scenario', $page, $perPage
        );
        $out['data'] = array_map(fn ($r) => $this->withKode($r), $out['data']);

        $pola = $this->polaFor(array_values(array_unique(array_map(fn ($r) => $r->referensi, $out['data']))));
        foreach ($out['data'] as $r) {
            $r->pola = $pola[$r->referensi] ?? null;
        }

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
        $scenario = DB::select('SELECT * FROM cek_referensi(?)', [$ref]);

        // anomali pola khusus: timpa jenis scenario yang bersangkutan
        $khusus = collect(DB::select(
            'SELECT scenario, jenis FROM audit_pola_khusus WHERE referensi = ?', [$ref]
        ))->keyBy('scenario');

        foreach ($scenario as $sc) {
            if ($k = $khusus->get((int) $sc->scenario)) {
                $sc->jenis = $k->jenis;
            }
        }

        return [
            'referensi' => $ref,
            'scenario' => $scenario,
            'idx' => DB::select('SELECT * FROM cek_idx(?)', [$ref]),
        ];
    }

    /** Riwayat recovery satu halaman, terbaru di atas. */
    public function riwayat(array $f, int $page, int $perPage): array
    {
        $q = DB::table('audit_repair_log');

        if (filled($f['referensi'] ?? null)) {
            $q->where('referensi', 'ilike', '%'.$f['referensi'].'%');
        }
        if (filled($f['aksi'] ?? null)) {
            $q->where('aksi', $f['aksi']);
        }
        if (filled($f['dari'] ?? null)) {
            $q->whereDate('created_at', '>=', $f['dari']);
        }
        if (filled($f['sampai'] ?? null)) {
            $q->whereDate('created_at', '<=', $f['sampai']);
        }
        if (filled($f['tahun'] ?? null)) {
            $q->whereYear('created_at', (int) $f['tahun']);
        }

        $total = (clone $q)->count();
        $data = $q->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'data' => $data->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** Keadaan referensi sebelum perubahan: jenis per scenario dan muncul per idx. */
    private function snapshotRef(string $ref): array
    {
        $d = $this->reference($ref);

        $jenis = [];
        foreach ($d['scenario'] as $sc) {
            $jenis[(int) $sc->scenario] = $sc->jenis;
        }

        $idx = [];
        foreach ($d['idx'] as $i) {
            $idx[(int) $i->scenario.':'.(int) $i->idx] = [
                'muncul' => (int) $i->muncul,
                // index yang tidak ada di template tidak punya "seharusnya"
                'seharusnya' => $i->coa_name === null ? 0 : (int) $i->max_muncul,
            ];
        }

        return ['jenis' => $jenis, 'idx' => $idx];
    }

    /**
     * Perbaiki idx yang hilang. Nama akun dari template; nominal WAJIB dari user.
     * $rows: [{scenario, idx, debit, credit}]. Satu transaksi; semua dicatat di audit_repair_log.
     * Validasi: total debit = credit per scenario, idx harus ada di template DAN memang kurang
     * muncul menurut cek_idx().
     */
    public function repair(string $ref, array $rows, string $user): array
    {
        $this->validasiSaldo($rows);
        $sebelum = $this->snapshotRef($ref);
        $inserted = [];

        DB::transaction(function () use ($ref, $rows, $user, $sebelum, &$inserted) {
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
                    throw new InvalidArgumentException("Index $k tidak ada di template / referensi ini.");
                }
                $kurang = (int) $s->max_muncul - (int) $s->muncul;
                if ($n > $kurang) {
                    throw new InvalidArgumentException("Index $k hanya kurang $kurang baris, diminta $n.");
                }
            }

            $sesudahMap = [];
            foreach ($rows as $r) {
                $s = (int) $r['scenario'];
                $i = (int) $r['idx'];
                $k = "$s:$i";

                // tanggal dan source_month disalin dari baris lain di scenario yang sama
                $base = DB::selectOne(
                    'SELECT journal_date, source_month FROM journal_all
                     WHERE referensi = ? AND scenario = ? LIMIT 1', [$ref, $s]);
                $tpl = DB::selectOne(
                    'SELECT coa_name FROM scenario_template WHERE scenario = ? AND idx = ?', [$s, $i]);
                if (! $base || ! $tpl) {
                    throw new InvalidArgumentException("Data dasar scenario $s index $i tidak ditemukan.");
                }

                DB::insert(
                    'INSERT INTO journal_all
                       (scenario, idx, referensi, journal_date, coa_name, debit, credit, source_month)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$s, $i, $ref, $base->journal_date, $tpl->coa_name,
                        $r['debit'], $r['credit'], $base->source_month]
                );

                $mulai = $sesudahMap[$k] ?? ($sebelum['idx'][$k]['muncul'] ?? 0);
                $sesudahMap[$k] = $mulai + 1;

                DB::insert(
                    'INSERT INTO audit_repair_log
                       (referensi, scenario, idx, coa_name, debit, credit, dibuat_oleh, created_at, aksi,
                        jenis, muncul_sebelum, muncul_sesudah, seharusnya)
                     VALUES (?,?,?,?,?,?,?, now(), ?, ?, ?, ?, ?)',
                    [$ref, $s, $i, $tpl->coa_name, $r['debit'], $r['credit'], $user, 'TAMBAH',
                        $sebelum['jenis'][$s] ?? null, $mulai, $mulai + 1,
                        $sebelum['idx'][$k]['seharusnya'] ?? null]
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

    /**
     * Hapus idx yang tidak ada di template (IDX_LEBIH). $items: [{scenario, idx}].
     * Semua baris yang dihapus dicatat di audit_repair_log (aksi HAPUS) beserta nominalnya.
     */
    public function hapusIdxLebih(string $ref, array $items, string $user): array
    {
        $sebelum = $this->snapshotRef($ref);
        $dihapus = [];

        DB::transaction(function () use ($ref, $items, $user, $sebelum, &$dihapus) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$ref]);

            $status = collect(DB::select('SELECT * FROM cek_idx(?)', [$ref]))
                ->keyBy(fn ($r) => $r->scenario.':'.$r->idx);

            $sesudahMap = [];
            foreach ($items as $it) {
                $s = (int) $it['scenario'];
                $i = (int) $it['idx'];
                $k = "$s:$i";
                $baris = $status->get($k);

                // hanya idx yang ada di data tapi TIDAK ada di template
                if (! $baris || $baris->coa_name !== null || (int) $baris->muncul < 1) {
                    throw new InvalidArgumentException("Index $k bukan idx berlebih, tidak dihapus.");
                }

                $rows = DB::select(
                    'DELETE FROM journal_all
                     WHERE referensi = ? AND scenario = ? AND idx = ?
                     RETURNING scenario, idx, coa_name, debit, credit',
                    [$ref, $s, $i]
                );

                foreach ($rows as $r) {
                    $mulai = $sesudahMap[$k] ?? ($sebelum['idx'][$k]['muncul'] ?? 0);
                    $sesudahMap[$k] = $mulai - 1;

                    DB::insert(
                        'INSERT INTO audit_repair_log
                           (referensi, scenario, idx, coa_name, debit, credit, dibuat_oleh, created_at, aksi,
                            jenis, muncul_sebelum, muncul_sesudah, seharusnya)
                         VALUES (?,?,?,?,?,?,?, now(), ?, ?, ?, ?, ?)',
                        [$ref, $r->scenario, $r->idx, $r->coa_name, $r->debit, $r->credit, $user, 'HAPUS',
                            $sebelum['jenis'][$r->scenario] ?? null, $mulai, $mulai - 1,
                            $sebelum['idx'][$k]['seharusnya'] ?? null]
                    );

                    $dihapus[] = ['scenario' => $r->scenario, 'idx' => $r->idx, 'coa_name' => $r->coa_name];
                }
            }
        });

        return ['dihapus' => $dihapus, 'hasil_cek' => $this->reference($ref)];
    }

    /** Data untuk form tambah blok: baris template + blok sekarang / seharusnya. */
    public function formBlok(string $ref, int $scenario): array
    {
        $khusus = DB::selectOne(
            'SELECT idx_ditemukan, idx_seharusnya FROM audit_pola_khusus
             WHERE referensi = ? AND scenario = ?', [$ref, $scenario]);

        if (! $khusus) {
            throw new InvalidArgumentException('AWB ini tidak terdeteksi sebagai POLA_SKENARIO_TIDAK_SESUAI.');
        }

        return [
            'referensi' => $ref,
            'scenario' => $scenario,
            'blok_sekarang' => (int) $khusus->idx_ditemukan,
            'target_blok' => (int) $khusus->idx_seharusnya,
            'template' => DB::select(
                'SELECT idx, coa_name FROM scenario_template WHERE scenario = ? ORDER BY idx', [$scenario]),
        ];
    }

    /**
     * Tambah satu blok utuh untuk scenario. $nilai: [idx => ['debit' => .., 'credit' => ..]]
     * untuk SEMUA idx di template scenario itu. $targetBlok = jumlah blok yang seharusnya.
     * Validasi: total debit = credit untuk blok ini.
     */
    public function tambahBlok(string $ref, int $scenario, int $targetBlok, array $nilai, string $user): void
    {
        DB::transaction(function () use ($ref, $scenario, $targetBlok, $nilai, $user) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$ref]);

            $tpl = DB::select('SELECT idx, coa_name FROM scenario_template WHERE scenario = ? ORDER BY idx', [$scenario]);
            if (! $tpl) {
                throw new InvalidArgumentException("Scenario $scenario tidak ada di template.");
            }

            $idxTpl = array_map(fn ($t) => (int) $t->idx, $tpl);
            $idxInput = array_map('intval', array_keys($nilai));
            sort($idxTpl);
            sort($idxInput);
            if ($idxTpl !== $idxInput) {
                throw new InvalidArgumentException('Isi debit dan credit untuk semua index template scenario ini.');
            }

            $this->validasiSaldo(array_map(
                fn ($n) => ['scenario' => $scenario, 'debit' => $n['debit'], 'credit' => $n['credit']],
                $nilai
            ));

            $base = DB::selectOne(
                'SELECT journal_date, source_month FROM journal_all WHERE referensi = ? AND scenario = ? LIMIT 1',
                [$ref, $scenario]
            );
            if (! $base) {
                throw new InvalidArgumentException("Scenario $scenario tidak ada di AWB ini.");
            }

            $blok = (int) DB::selectOne(
                'SELECT COUNT(*) AS n FROM journal_all WHERE referensi = ? AND scenario = ? AND idx = ?',
                [$ref, $scenario, $idxTpl[0]]
            )->n;
            if ($blok >= $targetBlok) {
                throw new InvalidArgumentException("Scenario $scenario sudah $blok blok, target $targetBlok.");
            }

            $jenis = "POLA_SKENARIO_TIDAK_SESUAI scenario $scenario";

            foreach ($tpl as $t) {
                $n = $nilai[(int) $t->idx];

                DB::insert(
                    'INSERT INTO journal_all
                       (scenario, idx, referensi, journal_date, coa_name, debit, credit, source_month)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$scenario, $t->idx, $ref, $base->journal_date, $t->coa_name,
                        $n['debit'], $n['credit'], $base->source_month]
                );

                DB::insert(
                    'INSERT INTO audit_repair_log
                       (referensi, scenario, idx, coa_name, debit, credit, dibuat_oleh, created_at, aksi,
                        jenis, muncul_sebelum, muncul_sesudah, seharusnya)
                     VALUES (?,?,?,?,?,?,?, now(), ?, ?, ?, ?, ?)',
                    [$ref, $scenario, $t->idx, $t->coa_name, $n['debit'], $n['credit'], $user, 'TAMBAH',
                        $jenis, $blok, $blok + 1, $targetBlok]
                );
            }
        });
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

    /** Nama kolom pola di referensi_pola_full (dipakai halaman Filament). */
    public function polaColumnName(): string
    {
        return $this->polaColumn();
    }

    /** Pola per referensi untuk satu halaman daftar: [referensi => pola]. */
    public function polaFor(array $refs): array
    {
        if (! $refs) {
            return [];
        }
        $pola = $this->polaColumn();
        $in = implode(',', array_fill(0, count($refs), '?'));
        $out = [];
        foreach (DB::select("SELECT referensi, {$pola} AS pola FROM referensi_pola_full WHERE referensi IN ($in)", array_values($refs)) as $r) {
            $out[$r->referensi] = $r->pola;
        }

        return $out;
    }

    /** Refresh materialized view (+/- 48 detik di data asli). */
    public function refresh(): void
    {
        DB::statement('CALL refresh_audit()');
    }

    // ------------------------------------------------------------------
    // Bantuan
    // ------------------------------------------------------------------

    /**
     * Pastikan total debit = total credit untuk setiap scenario dalam input recovery.
     * $rows: [{scenario, debit, credit}, ...]
     */
    private function validasiSaldo(array $rows): void
    {
        $total = [];
        foreach ($rows as $r) {
            $s = (int) $r['scenario'];
            $total[$s]['debit']  = ($total[$s]['debit'] ?? 0)  + (float) $r['debit'];
            $total[$s]['credit'] = ($total[$s]['credit'] ?? 0) + (float) $r['credit'];
        }

        foreach ($total as $s => $t) {
            $selisih = $t['debit'] - $t['credit'];
            if (abs($selisih) > 0.005) {
                throw new InvalidArgumentException(sprintf(
                    'Scenario %d belum seimbang: debit %s, credit %s, selisih %s.',
                    $s,
                    number_format($t['debit'], 2, ',', '.'),
                    number_format($t['credit'], 2, ',', '.'),
                    number_format($selisih, 2, ',', '.')
                ));
            }
        }
    }

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
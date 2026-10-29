<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Jobs\RefreshAuditJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AuditController extends Controller
{
    public function __construct(private AuditService $audit) {}

    // ---- Cek seluruh data ----

    public function report(Request $r): JsonResponse
    {
        set_time_limit(300);
        $d = $r->validate(['tolerance' => ['nullable', 'numeric', 'min:0']]);

        return response()->json($this->audit->report(
            (float) ($d['tolerance'] ?? config('audit.balance_tolerance'))
        ));
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->audit->summary());
    }

    public function anomalies(Request $r): JsonResponse
    {
        $f = $r->validate([
            'jenis' => ['nullable', 'regex:/^[A-Z_]+$/'],
            'scenario' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:50'],
            ...$this->pageRules(),
        ]);

        return response()->json($this->audit->anomalies($f, $this->page($f), $this->perPage($f)));
    }

    public function anomalyIdx(Request $r): JsonResponse
    {
        $f = $r->validate([
            'tipe' => ['nullable', 'in:hilang,kurang'],
            ...$this->pageRules(),
        ]);

        return response()->json(
            $this->audit->anomalyIdx($f['tipe'] ?? 'hilang', $this->page($f), $this->perPage($f))
        );
    }

    public function unknownPatterns(Request $r): JsonResponse
    {
        $f = $r->validate($this->pageRules());

        return response()->json($this->audit->unknownPatterns($this->page($f), $this->perPage($f)));
    }

    public function unbalanced(Request $r): JsonResponse
    {
        set_time_limit(300);
        $f = $r->validate(['tolerance' => ['nullable', 'numeric', 'min:0'], ...$this->pageRules()]);

        return response()->json($this->audit->unbalanced(
            (float) ($f['tolerance'] ?? config('audit.balance_tolerance')),
            $this->page($f), $this->perPage($f)
        ));
    }

    public function scenariosWithoutTemplate(): JsonResponse
    {
        return response()->json($this->audit->scenariosWithoutTemplate());
    }

    // ---- Satu referensi ----

    public function reference(string $referensi): JsonResponse
    {
        return response()->json($this->audit->reference($referensi));
    }

    public function repair(Request $r, string $referensi): JsonResponse
    {
        $data = $r->validate([
            'konfirmasi' => ['required', 'accepted'],
            'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.scenario' => ['required', 'integer'],
            'baris.*.idx' => ['required', 'integer', 'min:0'],
            'baris.*.debit' => ['required', 'numeric', 'min:0'],
            'baris.*.credit' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $hasil = $this->audit->repair(
                $referensi, $data['baris'], substr((string) $r->header('X-Audit-User', 'api'), 0, 100)
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($hasil, 201);
    }

    public function removeExtraIdx(Request $r, string $referensi): JsonResponse
{
    $data = $r->validate([
        'konfirmasi' => ['required', 'accepted'],
        'idx' => ['required', 'array', 'min:1', 'max:50'],
        'idx.*.scenario' => ['required', 'integer'],
        'idx.*.idx' => ['required', 'integer', 'min:0'],
    ]);

    try {
        $hasil = $this->audit->hapusIdxLebih(
            $referensi, $data['idx'], substr((string) $r->header('X-Audit-User', 'api'), 0, 100)
        );
    } catch (InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    return response()->json($hasil);
    }

    // ---- Pola normal ----

    public function patterns(): JsonResponse
    {
        return response()->json($this->audit->patterns());
    }

    public function addPattern(Request $r): JsonResponse
    {
        $d = $r->validate([
            'pattern' => ['required', 'string', 'max:200', 'regex:/^\d+(,\d+)*$/'],
            'is_spx' => ['boolean'],
        ]);
        $baru = $this->audit->addPattern($d['pattern'], (bool) ($d['is_spx'] ?? false));

        return response()->json(
            ['pattern' => $d['pattern'], 'baru' => $baru, 'perlu_refresh' => $baru],
            $baru ? 201 : 200
        );
    }

    public function removePattern(string $pattern): JsonResponse
    {
        $ada = $this->audit->removePattern($pattern);

        return response()->json(['dihapus' => $ada, 'perlu_refresh' => $ada], $ada ? 200 : 404);
    }

    // ---- Cache ----

        public function refresh(): JsonResponse
    {
        // Hanya satu audit pada satu waktu
        if (! Cache::add('audit.berjalan', true, 900)) {
            return response()->json(['status' => 'berjalan', 'pesan' => 'Audit sedang berjalan.'], 202);
        }

        Cache::forever('audit.status', ['status' => 'berjalan', 'mulai' => now()->toIso8601String()]);
        RefreshAuditJob::dispatch();

        return response()->json(['status' => 'berjalan'], 202);
    }

    public function refreshStatus(): JsonResponse
    {
        return response()->json(Cache::get('audit.status', ['status' => 'belum_pernah']));
    }

    // ---- Bantuan ----

    private function pageRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    private function page(array $f): int
    {
        return (int) ($f['page'] ?? 1);
    }

    private function perPage(array $f): int
    {
        return (int) ($f['per_page'] ?? 50);
    }
}

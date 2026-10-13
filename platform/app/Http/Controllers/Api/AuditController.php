<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AuditController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function summary(): JsonResponse
    {
        return response()->json($this->audit->summary());
    }

    public function anomalies(Request $r): JsonResponse
    {
        $f = $r->validate([
            'jenis'    => ['nullable', Rule::in(AuditService::JENIS)],
            'scenario' => ['nullable', 'integer'],
            'q'        => ['nullable', 'string', 'max:50'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(
            $this->audit->anomalies($f, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 50))
        );
    }

    public function reference(string $referensi): JsonResponse
    {
        return response()->json($this->audit->reference($referensi));
    }

    public function repair(Request $r, string $referensi): JsonResponse
    {
        $data = $r->validate([
            'konfirmasi'        => ['required', 'accepted'],
            'baris'             => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.scenario'  => ['required', 'integer'],
            'baris.*.idx'       => ['required', 'integer', 'min:0'],
            'baris.*.debit'     => ['required', 'numeric', 'min:0'],
            'baris.*.credit'    => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $hasil = $this->audit->repair(
                $referensi, $data['baris'], $r->user()->email ?? 'sistem'
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($hasil, 201);
    }

    public function patterns(): JsonResponse
    {
        return response()->json($this->audit->patterns());
    }

    public function addPattern(Request $r): JsonResponse
    {
        $d = $r->validate([
            'pattern' => ['required', 'string', 'max:200', 'regex:/^\d+(,\d+)*$/'],
            'is_spx'  => ['boolean'],
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

    public function refresh(): JsonResponse
    {
        set_time_limit(300);
        $mulai = microtime(true);
        $this->audit->refresh();

        return response()->json(['selesai' => true, 'detik' => round(microtime(true) - $mulai, 1)]);
    }
}

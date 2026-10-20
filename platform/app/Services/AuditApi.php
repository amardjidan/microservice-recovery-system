<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Klien HTTP untuk API audit (/api/audit/...).
 * Tampilan web memakai kelas ini, bukan AuditService, jadi web dan API terpisah
 * dan hanya berhubungan lewat HTTP (alamat di AUDIT_API_URL, token di AUDIT_API_TOKEN).
 */
class AuditApi
{
    public function summary(): array
    {
        return $this->pastikan($this->kirim('GET', 'summary'))->json();
    }

    /** Daftar anomali satu halaman: ['total', 'page', 'per_page', 'data' => [...]]. */
    public function anomalies(array $filter, int $page, int $perPage): array
    {
        $query = array_filter($filter, fn ($v) => filled($v)) + ['page' => $page, 'per_page' => $perPage];

        return $this->pastikan($this->kirim('GET', 'anomalies', $query))->json();
    }

    public function reference(string $ref): array
    {
        return $this->pastikan($this->kirim('GET', 'references/'.rawurlencode($ref)))->json();
    }

    /** @throws InvalidArgumentException kalau API menolak data (422) */
    public function repair(string $ref, array $rows, string $user): array
    {
        set_time_limit(150);
        $res = $this->kirim(
            'POST', 'references/'.rawurlencode($ref).'/repair',
            ['konfirmasi' => true, 'baris' => $rows], 120, ['X-Audit-User' => $user]
        );

        return $this->pastikan($res)->json();
    }

    public function patterns(): array
    {
        return $this->pastikan($this->kirim('GET', 'patterns'))->json();
    }

    /** true kalau pola baru ditambahkan, false kalau sudah ada. */
    public function addPattern(string $pattern, bool $isSpx): bool
    {
        $res = $this->kirim('POST', 'patterns', ['pattern' => $pattern, 'is_spx' => $isSpx]);

        return (bool) $this->pastikan($res)->json('baru');
    }

    /** true kalau pola ada dan dihapus. */
    public function removePattern(string $pattern): bool
    {
        $res = $this->kirim('DELETE', 'patterns/'.rawurlencode($pattern));

        if ($res->status() === 404) {
            return false;
        }

        return (bool) $this->pastikan($res)->json('dihapus');
    }

    /** CALL refresh_audit() di sisi API (sekitar 20 detik). */
    public function refresh(): void
    {
        $this->pastikan($this->kirim('POST', 'refresh', [], 300));
    }

    // ------------------------------------------------------------------

    private function kirim(string $method, string $path, array $data = [], int $timeout = 20, array $headers = []): Response
    {
        $req = Http::baseUrl(rtrim((string) config('audit.api_url'), '/'))
            ->acceptJson()
            ->timeout($timeout)
            ->withHeaders($headers);

        if (filled(config('audit.token'))) {
            $req = $req->withToken((string) config('audit.token'));
        }

        try {
            return $req->send($method, ltrim($path, '/'), $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'API audit tidak bisa dihubungi di '.config('audit.api_url').'. Pastikan server API sedang berjalan.',
                0, $e
            );
        }
    }

    private function pastikan(Response $res): Response
    {
        if ($res->status() === 422) {
            throw new InvalidArgumentException((string) ($res->json('message') ?? 'Data ditolak oleh API.'));
        }

        if ($res->failed()) {
            throw new RuntimeException(
                'API audit menjawab '.$res->status().': '.($res->json('message') ?? mb_substr($res->body(), 0, 200))
            );
        }

        return $res;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuditToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('audit.token');

        if (blank($token)) {
            // Tanpa token hanya boleh di lingkungan local.
            if (app()->environment('local')) {
                return $next($request);
            }

            return response()->json(['message' => 'AUDIT_API_TOKEN belum diisi.'], 401);
        }

        if (! hash_equals((string) $token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Token tidak valid.'], 401);
        }

        return $next($request);
    }
}

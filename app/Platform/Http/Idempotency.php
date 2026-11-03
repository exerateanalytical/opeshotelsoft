<?php

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for unsafe requests. The first response for a
 * key is stored; repeats with the same key and body get the same
 * response, so retries never double-book or double-charge.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || $request->isMethodSafe()) {
            return $next($request);
        }

        $scope = ($request->user()?->id ?? 'anon').'|'.$request->method().'|'.$request->path();
        $hash = hash('sha256', $request->getContent());

        $existing = DB::table('idempotency_keys')->where('key', $key)->where('scope', $scope)->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'type' => 'https://opeshotelsoft.test/problems/idempotency-mismatch',
                    'title' => 'Idempotency key reused with a different request body',
                    'status' => 422,
                ], 422);
            }

            return response($existing->response_body, $existing->status_code)
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            DB::table('idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'scope' => $scope,
                'request_hash' => $hash,
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}

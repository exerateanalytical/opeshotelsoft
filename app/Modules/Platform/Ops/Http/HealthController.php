<?php

namespace App\Modules\Platform\Ops\Http;

use App\Modules\Platform\Ops\Application\HealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health — unauthenticated readiness probe. Without a matching X-Health-Token header
 * it returns only check names and states (no numbers, hostnames, versions or errors).
 */
class HealthController
{
    public function __invoke(Request $request, HealthChecker $checker): JsonResponse
    {
        $result = $checker->run();
        $token = (string) config('ops.health.token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->header('X-Health-Token'));

        if (! $detailed) {
            $result['checks'] = array_map(fn ($c) => ['status' => $c['status']], $result['checks']);
        }
        $result['time'] = now()->toIso8601String();

        return response()->json($result, $result['status'] === 'fail' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}

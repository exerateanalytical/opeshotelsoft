<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local performance instrumentation (docs/PERFORMANCE.md). Only active when
 * PERF_DEBUG=true AND the app runs in the local environment: adds
 * X-Debug-Queries, X-Debug-Query-Ms and X-Debug-Time-Ms headers so
 * scripts/perf/bench.php can report query counts per request.
 */
class PerfDebug
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.perf_debug') || ! app()->environment('local')) {
            return $next($request);
        }

        $count = 0;
        $ms = 0.0;
        $sql = [];
        $trace = config('app.perf_debug') === 'sql';
        DB::listen(function (QueryExecuted $q) use (&$count, &$ms, &$sql, $trace) {
            $count++;
            $ms += $q->time;
            if ($trace) {
                $sql[] = sprintf('%7.1fms %s', $q->time, substr(preg_replace('/\s+/', ' ', $q->sql), 0, 240));
            }
        });

        $response = $next($request);
        if ($trace) {
            file_put_contents(storage_path('logs/perf-sql.log'), $request->method().' '.$request->fullUrl()."\n".implode("\n", $sql)."\n\n", FILE_APPEND);
        }
        $response->headers->set('X-Debug-Queries', (string) $count);
        $response->headers->set('X-Debug-Query-Ms', number_format($ms, 1, '.', ''));
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $response->headers->set('X-Debug-Time-Ms', number_format((microtime(true) - $start) * 1000, 1, '.', ''));

        return $response;
    }
}

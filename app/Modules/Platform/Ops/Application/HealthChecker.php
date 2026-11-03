<?php

namespace App\Modules\Platform\Ops\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness checks. Each returns ['status' => ok|warn|fail, ...numbers].
 * "fail" means take the node out of rotation (HTTP 503); "warn" does not.
 */
class HealthChecker
{
    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function run(): array
    {
        $checks = ['database' => $this->database(), 'queue' => $this->queue(), 'disk' => $this->disk()];
        if ($this->redisWanted()) {
            $checks['redis'] = $this->redis();
        }

        $states = array_column($checks, 'status');
        $status = in_array('fail', $states, true) ? 'fail' : (in_array('warn', $states, true) ? 'warn' : 'ok');

        return ['status' => $status, 'checks' => $checks];
    }

    private function database(): array
    {
        try {
            $t = microtime(true);
            DB::select('select 1');

            return ['status' => 'ok', 'latency_ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
    }

    private function queue(): array
    {
        try {
            $conn = config('queue.default');
            $driver = config("queue.connections.$conn.driver");
            $pending = match ($driver) {
                'database' => DB::table(config("queue.connections.$conn.table", 'jobs'))->count(),
                'redis' => (int) app('queue')->connection($conn)->size(),
                default => null,
            };
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            $warn = ($pending !== null && $pending > (int) config('ops.health.queue_backlog_max', 500))
                || $failed > (int) config('ops.health.failed_jobs_max', 50);

            return ['status' => $warn ? 'warn' : 'ok', 'pending' => $pending, 'failed' => $failed];
        } catch (Throwable) {
            return ['status' => 'warn'];
        }
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return ['status' => 'warn'];
        }
        $mb = (int) floor($free / 1048576);

        return ['status' => $mb < (int) config('ops.health.disk_min_free_mb', 1024) ? 'warn' : 'ok', 'free_mb' => $mb];
    }

    private function redisWanted(): bool
    {
        $flag = config('ops.health.redis');
        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return in_array('redis', [
            config('cache.default'),
            config('session.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
        ], true);
    }

    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
    }
}

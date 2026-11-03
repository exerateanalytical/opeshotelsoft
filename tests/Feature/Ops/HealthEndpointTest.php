<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_is_public_minimal_json(): void
    {
        auth()->forgetGuards();
        $res = $this->getJson('/health')->assertOk()
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonStructure(['status', 'time', 'checks' => ['database', 'queue', 'disk']]);

        $this->assertSame('no-store', explode(',', $res->headers->get('Cache-Control'))[0]);
        foreach ($res->json('checks') as $check) {
            $this->assertSame(['status'], array_keys($check), 'unauthenticated output carries no numbers');
        }
        $body = $res->getContent();
        foreach ([config('database.connections.pgsql.password'), config('app.key'), config('database.connections.pgsql.host')] as $secret) {
            if ($secret) {
                $this->assertStringNotContainsString((string) $secret, $body);
            }
        }
    }

    public function test_token_reveals_numbers(): void
    {
        config(['ops.health.token' => 'probe-secret']);
        $this->getJson('/health', ['X-Health-Token' => 'probe-secret'])->assertOk()
            ->assertJsonStructure(['checks' => ['database' => ['latency_ms'], 'queue' => ['pending', 'failed'], 'disk' => ['free_mb']]]);
        $this->getJson('/health', ['X-Health-Token' => 'wrong'])->assertOk()->assertJsonMissingPath('checks.database.latency_ms');
    }

    public function test_queue_backlog_warns_without_failing(): void
    {
        config(['queue.default' => 'database', 'ops.health.queue_backlog_max' => 0, 'ops.health.token' => 't']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        $this->getJson('/health', ['X-Health-Token' => 't'])->assertOk()
            ->assertJsonPath('status', 'warn')->assertJsonPath('checks.queue.status', 'warn')
            ->assertJsonPath('checks.queue.pending', 1);
    }

    public function test_redis_down_fails_with_503(): void
    {
        config(['ops.health.redis' => 'true', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        app()->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.redis.status', 'fail');
    }

    public function test_disk_threshold_warns(): void
    {
        config(['ops.health.disk_min_free_mb' => PHP_INT_MAX]);
        $this->getJson('/health')->assertOk()->assertJsonPath('checks.disk.status', 'warn');
    }

    public function test_public_booking_write_is_rate_limited(): void
    {
        auth()->forgetGuards();
        $slug = $this->group->slug;
        $codes = [];
        for ($i = 0; $i < 22; $i++) {
            $codes[] = $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['reference' => 'X', 'email' => 'a@b.c'])->status();
        }
        $this->assertNotContains(429, array_slice($codes, 0, 20));
        $this->assertSame(429, end($codes));
    }

    public function test_json_log_channel_writes_json_lines(): void
    {
        $path = storage_path('logs/ops-test-'.uniqid().'.json.log');
        config(['logging.channels.json.driver' => 'single', 'logging.channels.json.path' => $path]);
        Log::channel('json')->info('ops.test', ['hotel' => 'AKW']);

        $line = json_decode(trim(file_get_contents($path)), true);
        @unlink($path);
        $this->assertSame('ops.test', $line['message']);
        $this->assertSame('AKW', $line['context']['hotel']);
        $this->assertSame('INFO', $line['level_name']);
    }
}

<?php

namespace Tests\Feature\Edge;

use App\Modules\Edge\Application\CloudSync;
use App\Modules\Edge\Application\EdgeInstaller;
use App\Modules\Edge\Application\EdgeSyncer;
use App\Modules\Edge\Application\EdgeTransport;
use App\Modules\Edge\Application\Signature;
use App\Modules\Edge\Domain\EdgeNode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Two databases in one process: the default connection is the cloud
 * (opeshotelsoft_test_p6, RefreshDatabase) and the "edge" connection is a
 * separate PostgreSQL database (<cloud>_edge) restored from a snapshot of
 * the cloud, then prepared with edge:init. onEdge() runs code as the edge
 * node by switching the default connection and turning EDGE_MODE on.
 */
abstract class EdgeTestCase extends TestCase
{
    private static bool $edgeMigrated = false;

    protected EdgeNode $node;

    protected string $secret;

    /** @var callable|null fault injection: fn(string $endpoint, int $call): ?string ('before'|'after' to fail) */
    public $fault = null;

    public int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $cloud = config('database.connections.pgsql');
        config(['database.connections.edge' => ['database' => $cloud['database'].'_edge'] + $cloud]);
        if (! self::$edgeMigrated) {
            Artisan::call('migrate:fresh', ['--database' => 'edge', '--force' => true]);
            self::$edgeMigrated = true;
        }
    }

    /** Register the edge node on the cloud and restore the cloud snapshot into the edge DB. */
    protected function bootEdge(): void
    {
        [$this->node, $this->secret] = app(CloudSync::class)->register($this->hotel, 'Edge '.$this->hotel->code);
        $this->snapshotToEdge();
        $this->onEdge(fn () => app(EdgeInstaller::class)->install());
    }

    protected function snapshotToEdge(): void
    {
        $edge = DB::connection('edge');
        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'"))->pluck('tablename');
        $edge->statement('SET session_replication_role = replica');
        $edge->statement('TRUNCATE '.$tables->map(fn ($t) => '"'.$t.'"')->implode(', ').' RESTART IDENTITY CASCADE');
        foreach ($tables as $t) {
            if (in_array($t, ['edge_changes', 'edge_nodes', 'edge_batches', 'edge_conflicts', 'edge_state'], true)) {
                continue;
            }
            foreach (DB::table($t)->get()->chunk(200) as $chunk) {
                $edge->table($t)->insert($chunk->map(fn ($r) => (array) $r)->all());
            }
        }
        $edge->statement('SET session_replication_role = DEFAULT');
    }

    protected function onEdge(callable $fn): mixed
    {
        $prev = DB::getDefaultConnection();
        $prevMode = config('edge');
        DB::setDefaultConnection('edge');
        config(['edge.mode' => true, 'edge.node_id' => $this->node->id, 'edge.hotel_id' => $this->hotel->id, 'edge.secret' => $this->secret]);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($prev);
            config(['edge' => $prevMode]);
        }
    }

    protected function onCloud(callable $fn): mixed
    {
        $prev = DB::getDefaultConnection();
        $prevMode = config('edge');
        DB::setDefaultConnection('pgsql');
        config(['edge.mode' => false]);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($prev);
            config(['edge' => $prevMode]);
        }
    }

    /** In-process transport: a signed HTTP call into the cloud app on the cloud DB. */
    protected function transport(): EdgeTransport
    {
        $test = $this;

        return new class($test) implements EdgeTransport
        {
            public function __construct(private EdgeTestCase $test) {}

            public function send(string $endpoint, array $body): array
            {
                return $this->test->cloudCall($endpoint, $body);
            }
        };
    }

    public function cloudCall(string $endpoint, array $body, ?array $headers = null): array
    {
        $this->calls++;
        $fault = $this->fault ? ($this->fault)($endpoint, $this->calls) : null;
        if ($fault === 'before') {
            throw new RuntimeException('Network down (before request)');
        }
        $response = $this->signedPost($endpoint, $body, $headers);
        if ($fault === 'after') {
            throw new RuntimeException('Network down (response lost)');
        }

        return $response->assertOk()->json();
    }

    public function signedPost(string $endpoint, array $body, ?array $headers = null)
    {
        $path = 'api/v1/edge/sync/'.$endpoint;
        $json = json_encode($body);
        $headers ??= Signature::headers($this->node->id, $this->secret, 'POST', $path, $json);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->onCloud(fn () => $this->call('POST', '/'.$path, [], [], [], $server, $json));
    }

    protected function sync(): array
    {
        $r = $this->onEdge(fn () => app(EdgeSyncer::class)->run($this->transport()));
        $this->actAs($this->user, $this->hotel);

        return $r;
    }

    protected function edgeTable(string $table)
    {
        return DB::connection('edge')->table($table);
    }
}

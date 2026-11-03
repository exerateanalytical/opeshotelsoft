<?php

namespace App\Modules\Edge\Application;

use App\Modules\Edge\Domain\EdgeNode;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cloud side of the sync protocol: node registration, request signature
 * verification, push (edge -> cloud) and pull (cloud -> edge).
 */
class CloudSync
{
    public function __construct(private TenantContext $ctx, private RowStore $rows, private ChangeFeed $feed, private ChangeCapture $capture) {}

    /** @return array{0: EdgeNode, 1: string} the node and its secret (shown once) */
    public function register(Hotel $hotel, string $name): array
    {
        $secret = Str::random(64);
        $node = $this->ctx->withoutScope(function () use ($hotel, $name, $secret) {
            $node = new EdgeNode(['name' => $name, 'secret' => $secret]);
            $node->group_id = $hotel->group_id;
            $node->hotel_id = $hotel->id;
            $node->save();

            return $node;
        });
        $this->capture->forget();

        return [$node, $secret];
    }

    public function authenticate(Request $request): EdgeNode
    {
        $node = $this->ctx->withoutScope(fn () => EdgeNode::find((int) $request->header('X-Edge-Node')));
        $ts = (string) $request->header('X-Edge-Timestamp');
        $sig = (string) $request->header('X-Edge-Signature');
        if (! $node || $node->status !== 'active' || ! ctype_digit($ts) || abs(time() - (int) $ts) > config('edge.clock_skew', 300)) {
            throw new HttpException(401, 'Unknown, revoked or expired edge token.');
        }
        $expected = Signature::sign($node->secret, $request->method(), $request->path(), $ts, $request->getContent());
        if (! hash_equals($expected, $sig)) {
            throw new HttpException(401, 'Invalid edge signature.');
        }
        $this->ctx->set($node->group_id, $node->hotel_id, [$node->hotel_id]);

        return $node;
    }

    /** Apply a batch of edge changes exactly once. */
    public function push(EdgeNode $node, array $batch): array
    {
        return DB::transaction(function () use ($node, $batch) {
            $node = $this->ctx->withoutScope(fn () => EdgeNode::lockForUpdate()->findOrFail($node->id));
            $stored = DB::table('edge_batches')->where('edge_node_id', $node->id)->where('batch_key', $batch['batch_key'])->value('response');
            if ($stored) {
                return json_decode($stored, true) + ['replayed' => true];
            }

            $stats = ['applied' => 0, 'skipped' => 0, 'conflicts' => 0];
            foreach ($batch['changes'] as $c) {
                if ($c['seq'] <= $node->push_cursor) {
                    $stats['skipped']++; // already applied by an earlier batch

                    continue;
                }
                $outcome = $this->applyFromEdge($node, $c);
                $stats[$outcome === 'applied' ? 'applied' : 'conflicts']++;
            }

            $ack = max((int) $node->push_cursor, (int) $batch['to_cursor']);
            DB::table('edge_nodes')->where('id', $node->id)->update(['push_cursor' => $ack, 'last_push_at' => now(), 'updated_at' => now()]);
            $response = $stats + ['ack_cursor' => $ack];
            DB::table('edge_batches')->insert([
                'edge_node_id' => $node->id, 'batch_key' => $batch['batch_key'], 'direction' => 'push',
                'response' => json_encode($response), 'created_at' => now(),
            ]);

            return $response;
        });
    }

    public function pull(EdgeNode $node, int $cursor, int $limit): array
    {
        $log = DB::table('edge_changes')
            ->where('id', '>', $cursor)
            ->where(fn ($q) => $q->whereNull('origin_node_id')->orWhere('origin_node_id', '!=', $node->id))
            ->where(fn ($q) => $q->where('hotel_id', $node->hotel_id)
                ->orWhere(fn ($q) => $q->whereNull('hotel_id')->where('group_id', $node->group_id)))
            ->orderBy('id')->limit($limit)->get();

        DB::table('edge_nodes')->where('id', $node->id)->update(['pull_cursor' => $cursor, 'last_pull_at' => now(), 'updated_at' => now()]);

        return [
            'changes' => $this->feed->build($log),
            'next_cursor' => $log->isEmpty() ? $cursor : (int) $log->last()->id,
            'has_more' => $log->count() === $limit,
        ];
    }

    /** @return string applied|conflict */
    private function applyFromEdge(EdgeNode $node, array $c): string
    {
        $table = (string) $c['table'];
        $id = (int) $c['row_id'];
        $data = $c['data'] ?? null;

        if ($reason = $this->tenantViolation($node, $c)) {
            return $this->conflict($node, $c, 'tenant_rejected', $reason);
        }

        $rule = TablePolicy::rule($table);
        if ($rule === 'master') {
            // Cloud is master: re-emit the cloud row so the edge reverts on its next pull.
            if ($this->rows->read($table, $id)) {
                $this->log($node, $c, null);
            }

            return $this->conflict($node, $c, 'master_cloud_wins', 'Master data is edited in the cloud only.');
        }

        if ($rule === 'financial' && $c['op'] === 'delete') {
            return $this->conflict($node, $c, 'financial_append_only', 'Financial records are never deleted.');
        }

        if ($rule === 'operational') {
            $newer = DB::table('edge_changes')->where('table_name', $table)->where('row_id', $id)
                ->where(fn ($q) => $q->whereNull('origin_node_id')->orWhere('origin_node_id', '!=', $node->id))
                ->where('changed_at', '>', $c['changed_at'])->exists();
            if ($newer) {
                return $this->conflict($node, $c, 'lww_kept_newer', 'The cloud has a later change for this row.');
            }
        }

        try {
            $clashes = DB::transaction(function () use ($rule, $table, $id, $c, $data) {
                if ($c['op'] === 'delete') {
                    $this->rows->delete($table, $id);

                    return [];
                }

                return $rule === 'financial' ? $this->rows->append($table, $data) : ($this->rows->upsert($table, $data) ?? []);
            });
        } catch (QueryException $e) {
            return $this->conflict($node, $c, 'apply_failed', Str::limit($e->getMessage(), 500));
        }

        $this->log($node, $c, $node->id);
        if ($clashes) {
            $this->conflict($node, $c, 'financial_append_only', 'Kept cloud values for: '.implode(', ', $clashes));
        }

        return 'applied';
    }

    private function tenantViolation(EdgeNode $node, array $c): ?string
    {
        $table = (string) $c['table'];
        $data = $c['data'] ?? [];
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table) || in_array($table, TablePolicy::EXCLUDED, true) || ! $this->rows->hasTable($table)) {
            return 'Table is not replicated.';
        }
        if ($table === 'hotels') {
            return (int) $c['row_id'] === $node->hotel_id ? null : 'Another hotel.';
        }
        // Use the stored row too, so an edge cannot take over a row owned by another tenant.
        foreach (array_filter([$data, $this->rows->read($table, (int) $c['row_id'])]) as $row) {
            if (array_key_exists('hotel_id', $row) && $row['hotel_id'] !== null && (int) $row['hotel_id'] !== $node->hotel_id) {
                return 'Row belongs to another hotel.';
            }
            if (array_key_exists('group_id', $row) && $row['group_id'] !== null && (int) $row['group_id'] !== $node->group_id) {
                return 'Row belongs to another group.';
            }
            if (! array_key_exists('hotel_id', $row) && ! array_key_exists('group_id', $row)) {
                [$parent, $fk] = TablePolicy::PARENTS[$table] ?? [null, null];
                $p = $parent && isset($row[$fk]) ? $this->rows->read($parent, (int) $row[$fk]) : null;
                if (! $p || (int) ($p['hotel_id'] ?? 0) !== $node->hotel_id && (int) ($p['group_id'] ?? 0) !== $node->group_id) {
                    return 'Parent row is not owned by this hotel.';
                }
            }
        }

        return null;
    }

    private function log(EdgeNode $node, array $c, ?int $origin): void
    {
        DB::table('edge_changes')->insert([
            'table_name' => $c['table'], 'row_id' => $c['row_id'], 'op' => $c['op'],
            'group_id' => $node->group_id, 'hotel_id' => $c['table'] === 'hotels' || isset($c['hotel_id']) ? $node->hotel_id : null,
            'origin_node_id' => $origin, 'changed_at' => $origin ? $c['changed_at'] : now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function conflict(EdgeNode $node, array $c, string $rule, string $detail): string
    {
        DB::table('edge_conflicts')->insert([
            'group_id' => $node->group_id, 'hotel_id' => $node->hotel_id, 'edge_node_id' => $node->id, 'side' => 'cloud',
            'table_name' => Str::limit((string) $c['table'], 80, ''), 'row_id' => (int) $c['row_id'], 'rule' => $rule,
            'detail' => $detail, 'incoming' => json_encode($c['data'] ?? null), 'created_at' => now(),
        ]);

        return 'conflict';
    }
}

<?php

namespace App\Modules\Edge\Application;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Edge side of the sync protocol (`edge:sync`). Push first (local changes
 * since the acknowledged cursor), then pull (cloud changes since the pull
 * cursor). Each pulled page and its cursor are committed together, so an
 * interruption resumes exactly where it stopped.
 */
class EdgeSyncer
{
    public function __construct(private RowStore $rows, private ChangeFeed $feed) {}

    public function run(?EdgeTransport $transport = null): array
    {
        $transport ??= app(HttpTransport::class);
        try {
            $result = ['push' => $this->push($transport), 'pull' => $this->pull($transport)];
            self::put('last_sync_at', now()->toIso8601String());
            self::put('last_error', null);
            self::put('last_result', json_encode($result));

            return $result;
        } catch (Throwable $e) {
            self::put('last_error', Str::limit($e->getMessage(), 500));
            self::put('last_attempt_at', now()->toIso8601String());
            throw $e;
        }
    }

    public function push(EdgeTransport $transport): array
    {
        $total = ['batches' => 0, 'applied' => 0, 'skipped' => 0, 'conflicts' => 0];
        $size = (int) config('edge.batch_size', 200);
        while (true) {
            $cursor = (int) self::get('push_cursor', 0);
            $log = DB::table('edge_changes')->where('id', '>', $cursor)->whereNull('origin_node_id')->orderBy('id')->limit($size)->get();
            if ($log->isEmpty()) {
                return $total;
            }
            $to = (int) $log->last()->id;
            $response = $transport->send('push', [
                'batch_key' => hash('sha256', config('edge.node_id').'|'.$cursor.'|'.$to),
                'from_cursor' => $cursor, 'to_cursor' => $to, 'changes' => $this->feed->build($log),
            ]);
            self::put('push_cursor', (string) max($cursor, (int) $response['ack_cursor']));
            $total['batches']++;
            foreach (['applied', 'skipped', 'conflicts'] as $k) {
                $total[$k] += $response[$k] ?? 0;
            }
            if ((int) $response['ack_cursor'] < $to) {
                return $total; // cloud did not take the whole batch; retry next run
            }
        }
    }

    public function pull(EdgeTransport $transport): array
    {
        $total = ['pages' => 0, 'applied' => 0, 'kept_local' => 0];
        do {
            $cursor = (int) self::get('pull_cursor', 0);
            $page = $transport->send('pull', ['cursor' => $cursor, 'limit' => (int) config('edge.batch_size', 200)]);
            DB::transaction(function () use ($page, &$total) {
                foreach ($page['changes'] as $c) {
                    $this->applyFromCloud($c) ? $total['applied']++ : $total['kept_local']++;
                }
                self::put('pull_cursor', (string) $page['next_cursor']);
            });
            $total['pages']++;
        } while ($page['has_more'] && $page['next_cursor'] > $cursor);

        return $total;
    }

    private function applyFromCloud(array $c): bool
    {
        $table = $c['table'];
        $id = (int) $c['row_id'];
        $hotel = config('edge.hotel_id');
        if ($hotel && $c['hotel_id'] && (int) $c['hotel_id'] !== (int) $hotel) {
            return false; // never accept another hotel's rows
        }
        $rule = TablePolicy::rule($table);

        if ($rule === 'operational') {
            $localNewer = DB::table('edge_changes')->where('table_name', $table)->where('row_id', $id)
                ->where('id', '>', (int) self::get('push_cursor', 0))->whereNull('origin_node_id')
                ->where('changed_at', '>', $c['changed_at'])->exists();
            if ($localNewer) {
                $this->conflict($c, 'lww_kept_newer', 'A later local change has not been pushed yet.');

                return false;
            }
        }

        try {
            DB::transaction(function () use ($rule, $table, $id, $c) {
                if ($c['op'] === 'delete') {
                    if ($rule !== 'financial') {
                        $this->rows->delete($table, $id);
                    }
                } elseif ($rule === 'financial') {
                    $this->rows->append($table, $c['data']);
                } else {
                    $this->rows->upsert($table, $c['data']); // master: cloud wins
                }
            });
        } catch (QueryException $e) {
            $this->conflict($c, 'apply_failed', Str::limit($e->getMessage(), 500));

            return false;
        }

        return true;
    }

    private function conflict(array $c, string $rule, string $detail): void
    {
        $group = $c['group_id'] ?? DB::table('hotels')->where('id', config('edge.hotel_id'))->value('group_id');
        $hotel = $c['hotel_id'] ?? config('edge.hotel_id');
        if (! $group || ! $hotel) {
            return;
        }
        DB::table('edge_conflicts')->insert([
            'group_id' => $group, 'hotel_id' => $hotel, 'edge_node_id' => config('edge.node_id'), 'side' => 'edge',
            'table_name' => $c['table'], 'row_id' => (int) $c['row_id'], 'rule' => $rule, 'detail' => $detail,
            'incoming' => json_encode($c['data'] ?? null), 'created_at' => now(),
        ]);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return DB::table('edge_state')->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        DB::table('edge_state')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}

<?php

namespace App\Modules\Edge\Application;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes one edge_changes row per Eloquent save/delete of a tenant-scoped
 * model (any model carrying group_id/hotel_id, a hotel row, or a known
 * child table). The log stores references only: the current row is read
 * when the change is shipped, so a batch always carries the final state.
 *
 * Runs in the caller's transaction, so a rolled-back write leaves no change.
 * Writes made with the query builder (bulk updates) are not captured.
 */
class ChangeCapture
{
    /** @var array<string, bool> connection|group => has an active edge node (cloud side) */
    private array $groupsWithEdge = [];

    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->record($model, 'upsert');
        }
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'delete');
    }

    public function forget(): void
    {
        $this->groupsWithEdge = [];
    }

    private function record(Model $model, string $op): void
    {
        if (! config('edge.capture', true) || ! $this->replicable($model)) {
            return;
        }
        $table = $model->getTable();
        $id = $model->getKey();
        if (in_array($table, TablePolicy::EXCLUDED, true) || ! is_numeric($id)) {
            return;
        }
        $conn = DB::connection($model->getConnectionName());
        [$groupId, $hotelId] = $this->tenantOf($model, $table, $conn);
        if (! $groupId) {
            return;
        }
        if (! config('edge.mode') && ! $this->groupHasEdge($conn, $groupId)) {
            return;
        }

        $conn->table('edge_changes')->insert([
            'table_name' => $table, 'row_id' => (int) $id, 'op' => $op,
            'group_id' => $groupId, 'hotel_id' => $hotelId, 'changed_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function replicable(Model $model): bool
    {
        $class = $model::class;

        return ($model instanceof User || str_starts_with($class, 'App\\Modules\\'))
            && ! str_starts_with($class, 'App\\Modules\\Edge\\');
    }

    /** @return array{0: ?int, 1: ?int} */
    private function tenantOf(Model $model, string $table, $conn): array
    {
        if ($table === 'hotels') {
            return [(int) $model->getAttribute('group_id'), (int) $model->getKey()];
        }
        if ($table === 'groups') {
            return [(int) $model->getKey(), null];
        }
        $attrs = $model->getAttributes();
        $group = $attrs['group_id'] ?? null;
        $hotel = $attrs['hotel_id'] ?? null;
        if (! $group && ! $hotel && isset(TablePolicy::PARENTS[$table])) {
            [$parent, $fk] = TablePolicy::PARENTS[$table];
            if ($pid = $attrs[$fk] ?? null) {
                $p = (array) ($conn->table($parent)->where('id', $pid)->first() ?? []);
                $group = $p['group_id'] ?? null;
                $hotel = $p['hotel_id'] ?? null;
            }
        }
        if ($hotel && ! $group) {
            $group = $conn->table('hotels')->where('id', $hotel)->value('group_id');
        }

        return [$group ? (int) $group : null, $hotel ? (int) $hotel : null];
    }

    private function groupHasEdge($conn, int $groupId): bool
    {
        return $this->groupsWithEdge[$conn->getName().'|'.$groupId]
            ??= $conn->table('edge_nodes')->where('group_id', $groupId)->where('status', 'active')->exists();
    }
}

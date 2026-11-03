<?php

namespace App\Modules\Edge\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw row access for replication. Writes bypass Eloquent on purpose:
 * no model events (so applied rows are not re-captured) and no domain
 * events (side effects already happened on the origin and arrive as rows).
 */
class RowStore
{
    private array $columns = [];

    public function read(string $table, int $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    public function upsert(string $table, array $data): void
    {
        $data = $this->filter($table, $data);
        if (DB::table($table)->where('id', $data['id'])->exists()) {
            DB::table($table)->where('id', $data['id'])->update(collect($data)->except('id')->all());
        } else {
            DB::table($table)->insert($data);
        }
    }

    /**
     * Financial rows: insert when absent; otherwise fill empty columns only.
     *
     * @return string[] columns where an incoming value differed from a stored non-null value (kept)
     */
    public function append(string $table, array $data): array
    {
        $data = $this->filter($table, $data);
        $current = $this->read($table, (int) $data['id']);
        if (! $current) {
            DB::table($table)->insert($data);

            return [];
        }
        $fill = [];
        $clashes = [];
        foreach ($data as $col => $value) {
            if ($col === 'id' || $col === 'updated_at' || $value === null) {
                continue;
            }
            if ($current[$col] === null) {
                $fill[$col] = $value;
            } elseif ((string) $current[$col] !== (string) $value) {
                $clashes[] = $col;
            }
        }
        if ($fill) {
            DB::table($table)->where('id', $data['id'])->update($fill);
        }

        return $clashes;
    }

    public function delete(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->delete();
    }

    public function hasTable(string $table): bool
    {
        return $this->columnsOf($table) !== [];
    }

    private function filter(string $table, array $data): array
    {
        return array_intersect_key($data, array_flip($this->columnsOf($table)));
    }

    private function columnsOf(string $table): array
    {
        return $this->columns[DB::getDefaultConnection().'|'.$table] ??= Schema::getColumnListing($table);
    }
}

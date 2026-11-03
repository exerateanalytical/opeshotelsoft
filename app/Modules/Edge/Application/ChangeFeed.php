<?php

namespace App\Modules\Edge\Application;

use Illuminate\Support\Collection;

/**
 * Turns raw change-log rows into a shippable batch: one entry per row
 * (latest state, read now), ordered by the row's first change so parents
 * are applied before their children (foreign keys).
 */
class ChangeFeed
{
    public function __construct(private RowStore $rows) {}

    /** @param Collection<int, object> $log */
    public function build(Collection $log): array
    {
        $items = [];
        foreach ($log as $c) {
            $key = $c->table_name.'|'.$c->row_id;
            $items[$key] ??= ['first' => (int) $c->id, 'table' => $c->table_name, 'row_id' => (int) $c->row_id,
                'group_id' => $c->group_id ? (int) $c->group_id : null, 'hotel_id' => $c->hotel_id ? (int) $c->hotel_id : null];
            $items[$key]['seq'] = (int) $c->id;
            $items[$key]['changed_at'] = $c->changed_at;
        }
        uasort($items, fn ($a, $b) => $a['first'] <=> $b['first']);

        return array_values(array_map(function ($i) {
            $data = $this->rows->read($i['table'], $i['row_id']);
            unset($i['first']);

            return $i + ['op' => $data ? 'upsert' : 'delete', 'data' => $data];
        }, $items));
    }
}

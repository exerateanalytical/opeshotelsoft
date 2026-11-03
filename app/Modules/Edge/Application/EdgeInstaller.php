<?php

namespace App\Modules\Edge\Application;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time preparation of an edge database (`edge:init`), run after the
 * snapshot restore:
 *  - every bigint primary-key sequence jumps to node_id * id_block, so rows
 *    created on the edge never collide with cloud ids;
 *  - legal number sequences (invoices, receipts, POS checks...) issued on the
 *    edge start above number_range_start, enforced by a trigger so new
 *    series and new years are covered too.
 */
class EdgeInstaller
{
    public function install(): array
    {
        $node = (int) config('edge.node_id');
        if (! $node) {
            throw new RuntimeException('EDGE_NODE_ID is not set.');
        }
        $offset = $node * (int) config('edge.id_block');
        $start = (int) config('edge.number_range_start');

        $moved = 0;
        $seqs = DB::select("select schemaname, sequencename, last_value from pg_sequences where schemaname = 'public' and data_type = 'bigint'::regtype");
        foreach ($seqs as $s) {
            if ($s->last_value === null || (int) $s->last_value < $offset) {
                DB::select('select setval(?::regclass, ?, false)', ['"'.$s->schemaname.'"."'.$s->sequencename.'"', $offset]);
                $moved++;
            }
        }

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION edge_number_range() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.last < {$start} THEN NEW.last := {$start}; END IF;
                RETURN NEW;
            END; \$\$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS edge_number_range ON number_sequences;
            CREATE TRIGGER edge_number_range BEFORE INSERT ON number_sequences FOR EACH ROW EXECUTE FUNCTION edge_number_range();
            UPDATE number_sequences SET last = {$start} WHERE last < {$start};
        SQL);

        EdgeSyncer::put('node_id', (string) $node);
        EdgeSyncer::put('id_offset', (string) $offset);
        EdgeSyncer::put('number_range_start', (string) $start);

        return ['sequences_moved' => $moved, 'id_offset' => $offset, 'number_range_start' => $start];
    }
}

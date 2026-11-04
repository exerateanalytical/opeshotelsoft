<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign-key columns read in joins and filters that had no index (PostgreSQL
 * does not index foreign keys). Found with pg_stat_user_tables (sequential
 * scans) on the demo database; see docs/PERFORMANCE.md. Additive and guarded:
 * skips missing tables/columns and existing indexes.
 */
return new class extends Migration
{
    private const INDEXES = [
        'folio_lines' => ['folio_id'],
        'pos_check_lines' => ['pos_check_id'],
        'pos_payments' => ['pos_check_id'],
        'guest_register_entries' => ['hotel_id', 'reservation_id', 'guest_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$column}_perf_index ON {$table} ({$column})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_perf_index");
            }
        }
    }
};

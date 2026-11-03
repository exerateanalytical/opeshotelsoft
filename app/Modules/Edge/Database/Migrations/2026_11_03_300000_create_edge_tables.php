<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change log: one row per write to a tenant table (both on the cloud and on the edge).
        Schema::create('edge_changes', function (Blueprint $t) {
            $t->id();
            $t->string('table_name', 80);
            $t->unsignedBigInteger('row_id');
            $t->string('op', 10); // upsert, delete
            $t->unsignedBigInteger('group_id')->nullable();
            $t->unsignedBigInteger('hotel_id')->nullable();
            $t->unsignedBigInteger('origin_node_id')->nullable(); // set when the change came from an edge node
            $t->timestampTz('changed_at', 6);
            $t->index(['hotel_id', 'id']);
            $t->index(['group_id', 'id']);
            $t->index(['table_name', 'row_id']);
        });

        Schema::create('edge_nodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->unique()->constrained();
            $t->string('name');
            $t->text('secret'); // encrypted; used to verify request signatures
            $t->unsignedBigInteger('push_cursor')->default(0); // last edge change seq applied on the cloud
            $t->unsignedBigInteger('pull_cursor')->default(0); // last cloud change id the edge acknowledged
            $t->string('status')->default('active'); // active, revoked
            $t->timestampTz('last_push_at')->nullable();
            $t->timestampTz('last_pull_at')->nullable();
            $t->timestamps();
        });

        // Batch idempotency: a replayed batch key returns the stored response.
        Schema::create('edge_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('edge_node_id')->constrained();
            $t->string('batch_key', 80);
            $t->string('direction', 10);
            $t->jsonb('response');
            $t->timestampTz('created_at');
            $t->unique(['edge_node_id', 'batch_key']);
        });

        Schema::create('edge_conflicts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedBigInteger('edge_node_id')->nullable();
            $t->string('side', 10); // cloud, edge (where the decision was taken)
            $t->string('table_name', 80);
            $t->unsignedBigInteger('row_id');
            $t->string('rule', 40); // master_cloud_wins, financial_append_only, lww_kept_newer, tenant_rejected, apply_failed
            $t->text('detail')->nullable();
            $t->jsonb('incoming')->nullable();
            $t->timestampTz('created_at');
        });

        // Edge-local key/value state (cursors, last sync, last error).
        Schema::create('edge_state', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->text('value')->nullable();
            $t->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['edge_state', 'edge_conflicts', 'edge_batches', 'edge_nodes', 'edge_changes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

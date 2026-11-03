<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Floor plan: tables per outlet, placed on a grid.
        Schema::create('pos_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('outlet_id')->constrained();
            $t->string('name', 10);
            $t->unsignedSmallInteger('seats')->default(4);
            $t->unsignedSmallInteger('pos_x')->default(0); // grid column
            $t->unsignedSmallInteger('pos_y')->default(0); // grid row
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['outlet_id', 'name']);
        });

        // Price levels (happy hour, late night...) with a weekly time window.
        Schema::create('pos_price_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('outlet_id')->nullable()->constrained(); // null = every outlet
            $t->string('name');
            $t->jsonb('days')->default('[]'); // ISO weekdays 1-7; empty = every day
            $t->time('starts_at');
            $t->time('ends_at'); // may be earlier than starts_at (window crosses midnight)
            $t->decimal('percent_off', 5, 2)->default(0); // applied to items without an explicit price
            $t->unsignedSmallInteger('priority')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_price_list_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_price_list_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_menu_item_id')->constrained();
            $t->unsignedBigInteger('price');
            $t->unique(['pos_price_list_id', 'pos_menu_item_id']);
        });

        Schema::table('pos_checks', function (Blueprint $t) {
            $t->foreignId('pos_table_id')->nullable()->constrained('pos_tables');
            $t->unsignedBigInteger('comp_total')->default(0);
        });

        Schema::table('pos_check_lines', function (Blueprint $t) {
            $t->foreignId('pos_price_list_id')->nullable()->constrained('pos_price_lists');
            $t->unsignedBigInteger('list_price')->nullable(); // menu price before the price level
            $t->string('comp_status')->nullable(); // pending, approved, rejected
            $t->string('comp_reason')->nullable();
            $t->foreignId('comp_requested_by')->nullable()->constrained('users');
            $t->foreignId('comp_approved_by')->nullable()->constrained('users');
            $t->timestamp('comped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_check_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pos_price_list_id');
            $t->dropConstrainedForeignId('comp_requested_by');
            $t->dropConstrainedForeignId('comp_approved_by');
            $t->dropColumn(['list_price', 'comp_status', 'comp_reason', 'comped_at']);
        });
        Schema::table('pos_checks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pos_table_id');
            $t->dropColumn('comp_total');
        });
        Schema::dropIfExists('pos_price_list_items');
        Schema::dropIfExists('pos_price_lists');
        Schema::dropIfExists('pos_tables');
    }
};

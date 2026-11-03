<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** I-A inventory control: recipes, units of measure, par levels, lots/expiry, usage variance reviews. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $t->string('unit', 10);
            $t->decimal('factor', 16, 6); // stock units in one of this unit
            $t->string('role', 12)->default('recipe'); // purchase, recipe, other
            $t->timestamps();
            $t->unique(['stock_item_id', 'unit']);
        });

        Schema::create('recipes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('kind', 12)->default('dish'); // dish, sub_recipe
            $t->unsignedBigInteger('pos_menu_item_id')->nullable();
            $t->decimal('yield_qty', 14, 4)->default(1);
            $t->string('yield_unit', 10)->default('portion');
            $t->decimal('sell_vat_rate', 6, 3)->default(19.25);
            $t->decimal('target_margin_pct', 6, 2)->default(65);
            $t->decimal('plate_cost', 18, 4)->default(0); // cost per yield unit
            $t->timestamp('costed_at')->nullable();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
            $t->index('pos_menu_item_id');
        });

        Schema::create('recipe_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->nullable()->constrained();
            $t->foreignId('sub_recipe_id')->nullable()->constrained('recipes');
            $t->decimal('quantity', 14, 4);
            $t->string('unit', 10)->nullable();
            $t->decimal('yield_pct', 6, 2)->default(100); // usable part after trimming/cooking
            $t->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('recipe_cost_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->decimal('plate_cost', 18, 4);
            $t->bigInteger('net_price')->default(0);
            $t->decimal('margin_pct', 8, 2)->nullable();
            $t->string('cause', 30)->default('recost'); // recost, ingredient_cost, edit
            $t->timestamps();
        });

        Schema::create('supplier_lead_times', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('lead_time_days')->default(2);
            $t->timestamps();
            $t->unique('supplier_id');
        });

        Schema::create('par_levels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('par_level', 14, 3);             // order up to
            $t->decimal('min_level', 14, 3)->default(0); // reorder point floor
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->unsignedSmallInteger('lead_time_days')->nullable(); // overrides the supplier's
            $t->timestamps();
            $t->unique(['store_id', 'stock_item_id']);
        });

        Schema::create('item_controls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $t->boolean('tracks_lots')->default(false);
            $t->unsignedSmallInteger('shelf_life_days')->nullable();
            $t->unsignedSmallInteger('expiry_alert_days')->default(7);
            $t->timestamps();
            $t->unique('stock_item_id');
        });

        Schema::create('stock_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->string('lot_number', 40);
            $t->date('received_date');
            $t->date('expiry_date')->nullable();
            $t->decimal('quantity_received', 16, 4);
            $t->decimal('quantity_remaining', 16, 4);
            $t->decimal('unit_cost', 16, 4)->default(0);
            $t->unsignedBigInteger('receipt_movement_id')->nullable();
            $t->unsignedBigInteger('parent_lot_id')->nullable(); // set when split off by a transfer
            $t->timestamp('alerted_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'stock_item_id', 'store_id', 'expiry_date']);
            $t->index('receipt_movement_id');
        });

        Schema::create('stock_lot_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_lot_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('stock_movement_id')->nullable();
            $t->string('kind', 20);
            $t->decimal('quantity', 16, 4); // signed effect on the lot
            $t->timestamps();
        });

        Schema::create('usage_variance_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('outlet_id')->constrained();
            $t->foreignId('store_id')->nullable()->constrained();
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 12)->default('draft'); // draft, final
            $t->bigInteger('variance_value')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('usage_variance_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usage_variance_review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('theoretical', 16, 4);
            $t->decimal('actual', 16, 4);
            $t->decimal('variance_qty', 16, 4);
            $t->decimal('unexplained_qty', 16, 4);
            $t->bigInteger('variance_value');
            $t->string('reason_code', 30)->nullable();
            $t->string('note')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['usage_variance_lines', 'usage_variance_reviews', 'stock_lot_movements', 'stock_lots', 'item_controls', 'par_levels',
            'supplier_lead_times', 'recipe_cost_history', 'recipe_lines', 'recipes', 'item_units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_menu_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('outlet_id')->nullable()->constrained(); // null = all outlets
            $t->string('code', 20);
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('category')->nullable();
            $t->unsignedBigInteger('price'); // gross, tax-inclusive per tax code
            $t->string('charge_code', 16)->default('FNB');
            $t->string('kitchen_station')->nullable(); // kitchen, bar, pastry...
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        // Simple bill of materials: one sale of the item consumes these stock items.
        Schema::create('pos_item_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_menu_item_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('stock_item_id');
            $t->decimal('quantity', 12, 4);
        });

        Schema::create('pos_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('outlet_id')->constrained();
            $t->string('number', 20);
            $t->uuid('client_uuid')->nullable()->unique(); // offline-created checks
            $t->string('table_number')->nullable();
            $t->unsignedSmallInteger('covers')->default(1);
            $t->string('status')->default('open'); // open, settled, void
            $t->date('business_date');
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->foreignId('guest_id')->nullable()->constrained();
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('discount')->default(0);
            $t->string('discount_reason')->nullable();
            $t->unsignedBigInteger('total')->default(0);
            $t->string('settlement')->nullable(); // payment, room_charge, house
            $t->foreignId('opened_by')->nullable()->constrained('users');
            $t->foreignId('settled_by')->nullable()->constrained('users');
            $t->timestamp('settled_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'outlet_id', 'status']);
        });

        Schema::create('pos_check_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_check_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_menu_item_id')->constrained();
            $t->string('name');
            $t->decimal('quantity', 10, 3);
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('amount');
            $t->jsonb('modifiers')->default('[]');
            $t->string('notes')->nullable();
            $t->string('charge_code', 16);
            $t->timestamp('fired_at')->nullable();
            $t->boolean('voided')->default(false);
            $t->string('void_reason')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_check_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('method_code', 16);
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('tendered')->nullable();
            $t->unsignedBigInteger('change_given')->default(0);
            $t->string('reference')->nullable();
            $t->foreignId('cashier_shift_id')->nullable()->constrained();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pos_payments', 'pos_check_lines', 'pos_checks', 'pos_item_components', 'pos_menu_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

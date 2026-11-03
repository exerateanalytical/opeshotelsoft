<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('tax_id')->nullable();
            $t->string('trade_register')->nullable();
            $t->string('contact_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('bank_account')->nullable();
            $t->string('momo_number')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['group_id', 'code']);
        });

        Schema::create('stock_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('category'); // food, beverage, supplies, amenities, linen, cleaning, maintenance
            $t->string('unit', 10);           // stock (issue) unit: kg, l, pc, btl
            $t->string('purchase_unit', 10)->nullable(); // e.g. case
            $t->decimal('purchase_factor', 12, 4)->default(1); // stock units per purchase unit
            $t->string('inventory_account', 20);
            $t->string('cost_account', 20);
            $t->decimal('vat_rate', 6, 3)->default(19.25);
            $t->decimal('reorder_level', 14, 3)->default(0);
            $t->decimal('reorder_quantity', 14, 3)->default(0);
            $t->decimal('avg_cost', 16, 4)->default(0); // weighted average per stock unit
            $t->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->string('kind')->default('main'); // main, kitchen, bar, housekeeping, maintenance, minibar
            $t->string('department')->nullable();
            $t->foreignId('outlet_id')->nullable()->constrained(); // POS deducts from this store
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 16, 4)->default(0);
            $t->unique(['store_id', 'stock_item_id']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('kind'); // receipt, issue, transfer, sale, wastage, adjustment, return
            $t->foreignId('stock_item_id')->constrained();
            $t->foreignId('from_store_id')->nullable()->constrained('stores');
            $t->foreignId('to_store_id')->nullable()->constrained('stores');
            $t->decimal('quantity', 16, 4);
            $t->decimal('unit_cost', 16, 4);
            $t->bigInteger('value');
            $t->date('date');
            $t->string('department')->nullable();
            $t->string('reference')->nullable();
            $t->string('reason')->nullable();
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
            $t->index(['hotel_id', 'stock_item_id', 'date']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->string('status')->default('draft'); // draft, submitted, approved, partially_received, received, cancelled
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->unsignedBigInteger('net_total')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 14, 3);       // in purchase units
            $t->unsignedBigInteger('unit_price'); // net, per purchase unit
            $t->decimal('vat_rate', 6, 3)->default(0);
            $t->decimal('received_quantity', 14, 3)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->date('date');
            $t->string('delivery_note')->nullable();
            $t->unsignedBigInteger('net_total')->default(0);
            $t->boolean('invoiced')->default(false);
            $t->foreignId('received_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_line_id')->nullable()->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 14, 3); // purchase units
            $t->unsignedBigInteger('unit_price');
            $t->decimal('vat_rate', 6, 3)->default(0);
        });

        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('supplier_id')->constrained();
            $t->string('reference');
            $t->date('date');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('net_total');
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total');
            $t->unsignedBigInteger('paid')->default(0);
            $t->string('status')->default('pending'); // pending, approved, partially_paid, paid, rejected
            $t->jsonb('goods_receipt_ids')->default('[]');
            $t->jsonb('expense_lines')->default('[]'); // non-stock: [{account, amount, description}]
            $t->bigInteger('match_variance')->default(0);
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'supplier_id', 'reference']);
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('supplier_invoice_id')->constrained();
            $t->date('date');
            $t->unsignedBigInteger('amount');
            $t->string('account', 20);
            $t->string('reference')->nullable();
            $t->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->date('date');
            $t->string('status')->default('open'); // open, posted
            $t->foreignId('counted_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('expected', 16, 4);
            $t->decimal('counted', 16, 4)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['stock_count_lines', 'stock_counts', 'supplier_payments', 'supplier_invoices', 'goods_receipt_lines', 'goods_receipts',
            'purchase_order_lines', 'purchase_orders', 'stock_movements', 'stock_balances', 'stores', 'stock_items', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->string('department');
            $t->foreignId('store_id')->constrained();
            $t->string('status')->default('draft'); // draft, submitted, approved, rejected, converted
            $t->date('needed_by')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 14, 3); // purchase units
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->foreignId('purchase_order_id')->nullable()->constrained();
        });

        Schema::create('supplier_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->unsignedBigInteger('unit_price'); // net per purchase unit
            $t->decimal('min_quantity', 14, 3)->default(0);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'stock_item_id', 'supplier_id']);
        });

        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->decimal('returned_quantity', 14, 3)->default(0);
        });

        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->foreignId('goods_receipt_id')->constrained();
            $t->foreignId('supplier_id')->constrained();
            $t->date('date');
            $t->string('reason')->nullable();
            $t->jsonb('lines')->default('[]');
            $t->unsignedBigInteger('net_total')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            // Credit note: 'open' (offsets payables, can be applied to invoices), 'applied', or 'receipt' (GRN not yet invoiced: GRN reduced instead).
            $t->string('credit_status')->default('open');
            $t->unsignedBigInteger('credit_applied')->default(0);
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('supplier_payment_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->date('date');
            $t->string('account', 20);
            $t->string('reference')->nullable();
            $t->unsignedInteger('payments_count')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->foreignId('payment_run_id')->nullable()->constrained('supplier_payment_runs');
            $t->foreignId('supplier_return_id')->nullable()->constrained('supplier_returns');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payment_run_id');
            $t->dropConstrainedForeignId('supplier_return_id');
        });
        Schema::dropIfExists('supplier_payment_runs');
        Schema::dropIfExists('supplier_returns');
        Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn('returned_quantity'));
        Schema::dropIfExists('supplier_prices');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }
};

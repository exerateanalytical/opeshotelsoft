<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** I-B inventory operations: RFQ, barcodes, blind stocktake, minibar, uniform/asset issues. */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('group_id')->constrained();
        $t->foreignId('hotel_id')->constrained();
    }

    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('number', 20);
            $t->string('title');
            $t->string('status', 12)->default('draft'); // draft, sent, awarded, converted, cancelled
            $t->foreignId('store_id')->constrained();
            $t->date('due_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });
        Schema::create('rfq_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 16, 4);
            $t->string('notes')->nullable();
        });
        Schema::create('rfq_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['rfq_id', 'supplier_id']);
        });
        Schema::create('rfq_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_line_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained();
            $t->unsignedBigInteger('unit_price');
            $t->unsignedSmallInteger('lead_time_days')->nullable();
            $t->date('valid_until')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['rfq_line_id', 'supplier_id']);
        });
        Schema::create('rfq_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_line_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained();
            $t->unsignedBigInteger('unit_price');
            $t->decimal('quantity', 16, 4);
            $t->unsignedBigInteger('purchase_order_id')->nullable();
            $t->timestamps();
        });

        Schema::create('item_barcodes', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $t->string('code', 64);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('stocktakes', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('number', 20);
            $t->foreignId('store_id')->constrained();
            $t->string('status', 12)->default('counting'); // counting, recount, review, posted, cancelled
            $t->boolean('blind')->default(true);
            $t->decimal('tolerance_pct', 6, 2)->default(2);
            $t->decimal('tolerance_qty', 14, 3)->default(0.5);
            $t->date('date');
            $t->foreignId('started_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->text('approval_note')->nullable();
            $t->bigInteger('variance_value')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });
        Schema::create('stocktake_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('expected', 16, 4); // hidden from counters
            $t->decimal('count_1', 16, 4)->nullable();
            $t->decimal('count_2', 16, 4)->nullable();
            $t->foreignId('counted_by')->nullable()->constrained('users');
            $t->foreignId('recounted_by')->nullable()->constrained('users');
            $t->boolean('recount_required')->default(false);
            $t->unique(['stocktake_id', 'stock_item_id']);
        });

        Schema::create('minibar_pars', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('room_type_id')->constrained();
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('par_qty', 12, 3);
            $t->unsignedBigInteger('price'); // sale price per unit, VAT included
            $t->timestamps();
            $t->unique(['room_type_id', 'stock_item_id']);
        });
        Schema::create('minibar_tasks', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('room_id')->constrained();
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->date('date');
            $t->string('status', 10)->default('pending'); // pending, done
            $t->jsonb('lines')->default('[]'); // [{stock_item_id, quantity}]
            $t->foreignId('done_by')->nullable()->constrained('users');
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });
        Schema::create('minibar_consumptions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('room_id')->constrained();
            $t->unsignedBigInteger('reservation_id');
            $t->foreignId('stock_item_id')->constrained();
            $t->decimal('quantity', 12, 3);
            $t->unsignedBigInteger('price');
            $t->unsignedBigInteger('folio_line_id')->nullable();
            $t->unsignedBigInteger('stock_movement_id')->nullable();
            $t->unsignedBigInteger('minibar_task_id')->nullable(); // set once a restock task covers it
            $t->foreignId('recorded_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('issuables', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 20);
            $t->string('name');
            $t->string('kind', 10)->default('uniform'); // uniform, asset
            $t->foreignId('stock_item_id')->nullable()->constrained(); // deducted from stock when set
            $t->unsignedBigInteger('unit_value')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });
        Schema::create('issue_records', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('issuable_id')->constrained();
            $t->unsignedBigInteger('employee_id'); // HR employees.id, read through HR queries
            $t->foreignId('store_id')->nullable()->constrained();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('returned_quantity', 12, 3)->default(0);
            $t->string('serial', 60)->nullable();
            $t->string('size', 20)->nullable();
            $t->date('issued_on');
            $t->date('due_back_on')->nullable();
            $t->date('returned_on')->nullable();
            $t->string('status', 10)->default('issued'); // issued, returned, lost
            $t->string('return_condition', 12)->nullable(); // good, worn, damaged, lost
            $t->foreignId('issued_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['issue_records', 'issuables', 'minibar_consumptions', 'minibar_tasks', 'minibar_pars', 'stocktake_lines', 'stocktakes',
            'item_barcodes', 'rfq_awards', 'rfq_quotes', 'rfq_suppliers', 'rfq_lines', 'rfqs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

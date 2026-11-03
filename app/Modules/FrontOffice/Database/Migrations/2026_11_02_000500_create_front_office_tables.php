<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax codes are owned by Accounting but needed from the first folio posting.
        Schema::create('tax_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('kind')->default('percent'); // percent | fixed_per_night | fixed_per_unit
            $t->decimal('rate', 7, 4)->default(0);   // percent, e.g. 19.25
            $t->unsignedBigInteger('fixed_amount')->default(0);
            $t->boolean('included_in_price')->default(true);
            $t->string('account', 20); // GL account credited
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        // Charge codes map folio postings to departments, taxes and revenue accounts.
        Schema::create('charge_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('department'); // rooms, fnb, beverage, laundry, spa, telephone, misc, tourist_tax
            $t->string('revenue_account', 20);
            $t->jsonb('tax_codes')->default('[]');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('kind'); // cash, card, mobile_money, bank_transfer, cheque, city_ledger, voucher, loyalty_points
            $t->string('account', 20);
            $t->string('provider')->nullable(); // mtn_momo, orange_money, stripe...
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('business_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->date('date');
            $t->string('status')->default('open');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->jsonb('summary')->nullable();
            $t->unique(['hotel_id', 'date']);
        });

        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('station')->nullable();
            $t->date('business_date');
            $t->unsignedBigInteger('opening_float')->default(0);
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->jsonb('expected')->nullable();
            $t->jsonb('counted')->nullable();
            $t->bigInteger('variance')->default(0);
            $t->string('status')->default('open');
            $t->timestamps();
        });

        Schema::create('folios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->string('kind')->default('guest'); // guest, master, company, house, walk_in, city_ledger
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->foreignId('guest_id')->nullable()->constrained();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->string('name');
            $t->string('currency', 3)->default('XAF');
            $t->bigInteger('balance')->default(0); // charges - payments, minor units
            $t->string('status')->default('open'); // open, closed
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });

        // Append-only: corrections are reversal lines, never edits or deletes.
        Schema::create('folio_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folio_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->date('business_date');
            $t->string('type'); // charge, payment, transfer_in, transfer_out, reversal, refund
            $t->string('code', 16)->nullable(); // charge or payment method code
            $t->string('department')->nullable();
            $t->string('description');
            $t->decimal('quantity', 10, 3)->default(1);
            $t->bigInteger('amount'); // signed: charges +, payments -
            $t->bigInteger('tax_amount')->default(0);
            $t->jsonb('tax_breakdown')->default('[]');
            $t->string('account', 20)->nullable();
            $t->string('reference')->nullable();
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->foreignId('reverses_line_id')->nullable()->constrained('folio_lines');
            $t->boolean('reversed')->default(false);
            $t->foreignId('cashier_shift_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('reason')->nullable();
            $t->timestamp('created_at');
            $t->index(['hotel_id', 'business_date']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 24);
            $t->string('kind')->default('invoice'); // invoice, credit_note
            $t->foreignId('folio_id')->nullable()->constrained();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('guest_id')->nullable()->constrained();
            $t->string('bill_to_name');
            $t->string('bill_to_tax_id')->nullable();
            $t->string('bill_to_address')->nullable();
            $t->date('issue_date');
            $t->date('due_date')->nullable();
            $t->string('currency', 3);
            $t->bigInteger('total');
            $t->bigInteger('tax_total');
            $t->bigInteger('paid')->default(0);
            $t->jsonb('lines');
            $t->jsonb('taxes');
            $t->string('status')->default('issued'); // issued, paid, partially_paid, cancelled
            $t->foreignId('credits_invoice_id')->nullable()->constrained('invoices');
            $t->foreignId('issued_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        // Police / immigration guest register (fiche de police).
        Schema::create('guest_register_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->foreignId('guest_id')->constrained();
            $t->string('room_number', 10);
            $t->string('full_name');
            $t->string('nationality', 2)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('place_of_birth')->nullable();
            $t->string('profession')->nullable();
            $t->string('id_type')->nullable();
            $t->string('id_number')->nullable();
            $t->date('id_expiry')->nullable();
            $t->string('coming_from')->nullable();
            $t->string('going_to')->nullable();
            $t->string('purpose')->nullable();
            $t->date('arrival');
            $t->date('departure');
            $t->timestamp('checked_out_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['guest_register_entries', 'invoices', 'folio_lines', 'folios', 'cashier_shifts', 'business_days', 'payment_methods', 'charge_codes', 'tax_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

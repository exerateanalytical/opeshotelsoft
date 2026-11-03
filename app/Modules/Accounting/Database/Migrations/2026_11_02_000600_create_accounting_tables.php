<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->unsignedTinyInteger('class'); // SYSCOHADA class 1-9
            $t->string('type'); // asset, liability, equity, income, expense, off_balance
            $t->string('parent_code', 20)->nullable();
            $t->boolean('postable')->default(true);
            $t->boolean('reconcilable')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 10);
            $t->string('name');
            $t->string('kind'); // sales, purchases, cash, bank, mobile_money, payroll, inventory, general
            $t->string('default_account', 20)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('status')->default('open'); // open, closed
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->unique(['hotel_id', 'year', 'month']);
        });

        // Immutable once posted; corrections are reversing entries.
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('journal_code', 10);
            $t->string('number', 24);
            $t->date('date');
            $t->string('description');
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->string('source_event')->nullable()->unique(); // idempotency: one entry per source event
            $t->string('status')->default('posted'); // draft, posted
            $t->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries');
            $t->boolean('reversed')->default(false);
            $t->bigInteger('total');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'date']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hotel_id')->constrained();
            $t->string('account_code', 20);
            $t->unsignedBigInteger('debit')->default(0);
            $t->unsignedBigInteger('credit')->default(0);
            $t->string('partner_type')->nullable(); // company, guest, supplier, employee
            $t->unsignedBigInteger('partner_id')->nullable();
            $t->string('department')->nullable(); // cost centre (USALI)
            $t->string('description')->nullable();
            $t->date('date');
            $t->string('reconciliation_ref')->nullable();
            $t->index(['hotel_id', 'account_code', 'date']);
            $t->index(['partner_type', 'partner_id']);
        });

        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('name');
            $t->string('kind')->default('bank'); // bank, mobile_money
            $t->string('bank_name')->nullable();
            $t->string('account_number')->nullable();
            $t->string('iban')->nullable();
            $t->string('currency', 3)->default('XAF');
            $t->string('gl_account', 20);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_account_id')->constrained();
            $t->date('date');
            $t->string('description');
            $t->bigInteger('amount'); // + money in, - money out
            $t->string('reference')->nullable();
            $t->foreignId('journal_line_id')->nullable()->constrained();
            $t->timestamp('reconciled_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('asset_account', 20);
            $t->string('depreciation_account', 20);
            $t->string('expense_account', 20);
            $t->date('acquired_on');
            $t->unsignedBigInteger('cost');
            $t->unsignedBigInteger('salvage_value')->default(0);
            $t->unsignedSmallInteger('useful_life_months');
            $t->unsignedBigInteger('accumulated')->default(0);
            $t->date('last_depreciated_on')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('from', 3);
            $t->string('to', 3);
            $t->date('date');
            $t->decimal('rate', 18, 8);
            $t->unique(['from', 'to', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['exchange_rates', 'fixed_assets', 'bank_statement_lines', 'bank_accounts', 'journal_lines', 'journal_entries', 'fiscal_periods', 'journals', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

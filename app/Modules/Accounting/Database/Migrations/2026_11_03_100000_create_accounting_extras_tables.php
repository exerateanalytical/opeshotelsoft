<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** W10: reconciliation, fixed-asset register, FX revaluation, year-end close. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('bank_account_id')->constrained();
            $t->string('filename')->nullable();
            $t->jsonb('mapping');
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->date('from')->nullable();
            $t->date('to')->nullable();
            $t->bigInteger('opening_balance')->nullable();
            $t->bigInteger('closing_balance')->nullable();
            $t->foreignId('imported_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::table('bank_statement_lines', function (Blueprint $t) {
            $t->bigInteger('balance')->nullable();
            $t->foreignId('bank_statement_import_id')->nullable()->constrained();
            $t->string('match_kind')->nullable(); // auto, manual, posted
        });

        Schema::table('journal_lines', function (Blueprint $t) {
            $t->string('currency', 3)->nullable();
            $t->decimal('fx_amount', 20, 2)->nullable(); // unsigned amount in `currency`; sign follows debit/credit
            $t->decimal('fx_rate', 18, 8)->nullable();
        });

        Schema::create('asset_classes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('asset_account', 20);        // class 2
            $t->string('depreciation_account', 20); // class 28
            $t->string('expense_account', 20)->default('681000'); // class 68
            $t->string('method')->default('straight_line'); // straight_line, declining_balance
            $t->unsignedSmallInteger('useful_life_months');
            $t->decimal('declining_coefficient', 5, 2)->default(2);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::table('fixed_assets', function (Blueprint $t) {
            $t->foreignId('asset_class_id')->nullable()->constrained();
            $t->string('method')->default('straight_line');
            $t->decimal('declining_coefficient', 5, 2)->default(2);
            $t->unsignedBigInteger('supplier_invoice_id')->nullable();
            $t->foreignId('acquisition_entry_id')->nullable()->constrained('journal_entries');
            $t->date('disposed_on')->nullable();
            $t->unsignedBigInteger('disposal_proceeds')->nullable();
            $t->bigInteger('disposal_result')->nullable();
            $t->foreignId('disposal_entry_id')->nullable()->constrained('journal_entries');
        });

        Schema::create('depreciation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->bigInteger('total')->default(0);
            $t->foreignId('journal_entry_id')->nullable()->constrained();
            $t->jsonb('details');
            $t->timestamps();
            $t->unique(['hotel_id', 'year', 'month']);
        });

        Schema::create('fx_revaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->date('date');
            $t->jsonb('rates');
            $t->bigInteger('gain')->default(0);
            $t->bigInteger('loss')->default(0);
            $t->foreignId('journal_entry_id')->nullable()->constrained();
            $t->jsonb('details');
            $t->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->string('status')->default('locked');
            $t->bigInteger('result')->default(0);
            $t->foreignId('closing_entry_id')->nullable()->constrained('journal_entries');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'year']);
        });

        Schema::create('opening_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->string('account_code', 20);
            $t->bigInteger('debit')->default(0);
            $t->bigInteger('credit')->default(0);
            $t->unique(['hotel_id', 'year', 'account_code']);
        });
    }

    public function down(): void
    {
        foreach (['opening_balances', 'fiscal_years', 'fx_revaluations', 'depreciation_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('fixed_assets', fn (Blueprint $t) => $t->dropConstrainedForeignId('asset_class_id'));
        Schema::table('fixed_assets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('acquisition_entry_id');
            $t->dropConstrainedForeignId('disposal_entry_id');
            $t->dropColumn(['method', 'declining_coefficient', 'supplier_invoice_id', 'disposed_on', 'disposal_proceeds', 'disposal_result']);
        });
        Schema::dropIfExists('asset_classes');
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_amount', 'fx_rate']));
        Schema::table('bank_statement_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bank_statement_import_id');
            $t->dropColumn(['balance', 'match_kind']);
        });
        Schema::dropIfExists('bank_statement_imports');
    }
};

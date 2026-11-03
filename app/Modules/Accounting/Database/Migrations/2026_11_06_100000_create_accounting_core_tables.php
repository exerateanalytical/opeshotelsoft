<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A-A core ledger: dimensions, budgets, deferrals, recurring journals, period-lock approvals,
// audit trail, attachments and inter-hotel transactions.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->string('outlet')->nullable();
            $t->string('project')->nullable();
            $t->string('cost_centre')->nullable();
            $t->index(['hotel_id', 'outlet']);
            $t->index(['hotel_id', 'project']);
            $t->index(['hotel_id', 'cost_centre']);
        });

        $tenant = function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
        };

        Schema::create('analytic_dimensions', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('kind', 20); // department, outlet, project, cost_centre
            $t->string('code', 40);
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'kind', 'code']);
        });

        Schema::create('dimension_defaults', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('department', 60); // posting-rule department the defaults apply to
            $t->string('outlet')->nullable();
            $t->string('project')->nullable();
            $t->string('cost_centre')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'department']);
        });

        Schema::create('budgets', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('name');
            $t->unsignedSmallInteger('year');
            $t->string('status')->default('draft'); // draft, approved
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'year', 'name']);
        });

        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hotel_id')->constrained();
            $t->string('account_code', 20);
            $t->string('department', 60)->nullable();
            $t->unsignedTinyInteger('month');
            $t->bigInteger('amount'); // natural sign: revenue and expense both positive
            $t->index(['budget_id', 'account_code']);
        });

        Schema::create('deferral_schedules', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('kind', 20); // prepaid_expense, deferred_income, accrued_expense, accrued_income
            $t->string('description');
            $t->string('debit_account', 20);
            $t->string('credit_account', 20);
            $t->bigInteger('total');
            $t->date('start_date');
            $t->unsignedSmallInteger('months')->default(1);
            $t->boolean('auto_reverse')->default(false);
            $t->string('department', 60)->nullable();
            $t->string('outlet')->nullable();
            $t->string('project')->nullable();
            $t->string('cost_centre')->nullable();
            $t->string('status')->default('active'); // active, cancelled
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('deferral_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('deferral_schedules')->cascadeOnDelete();
            $t->foreignId('hotel_id')->constrained();
            $t->string('entry_type', 12)->default('release'); // release, accrual, reversal
            $t->date('period_date');
            $t->bigInteger('amount');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->timestamp('posted_at')->nullable();
            $t->string('error')->nullable();
            $t->index(['hotel_id', 'period_date']);
        });

        Schema::create('recurring_journals', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('name');
            $t->string('journal', 10);
            $t->string('frequency', 12)->default('monthly'); // monthly, quarterly, yearly
            $t->date('next_date');
            $t->date('end_date')->nullable();
            $t->json('lines');
            $t->boolean('active')->default(true);
            $t->timestamp('last_run_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('period_lock_requests', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('action', 10); // close, reopen
            $t->string('status')->default('pending'); // pending, approved, rejected
            $t->text('reason')->nullable();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('accounting_audit_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action', 40);
            $t->string('subject_type', 40);
            $t->string('subject_id', 40);
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::create('journal_attachments', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('journal_entry_id')->constrained('journal_entries');
            $t->string('filename');
            $t->string('mime', 100);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->string('path');
            $t->foreignId('uploaded_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('intercompany_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('from_hotel_id')->constrained('hotels'); // creditor side (receivable 451100)
            $t->foreignId('to_hotel_id')->constrained('hotels');   // debtor side (payable 451200)
            $t->string('kind', 12); // service, funding, settlement
            $t->date('date');
            $t->bigInteger('amount');
            $t->string('description');
            $t->string('revenue_account', 20)->nullable();
            $t->string('expense_account', 20)->nullable();
            $t->string('treasury_account', 20)->nullable();
            $t->string('department', 60)->nullable();
            $t->string('status')->default('posted'); // posted, reversed
            $t->foreignId('from_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('to_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['group_id', 'date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE OR REPLACE FUNCTION accounting_audit_immutable() RETURNS trigger AS $fn$
                BEGIN RAISE EXCEPTION \'accounting_audit_log is append-only\'; END; $fn$ LANGUAGE plpgsql;
                CREATE TRIGGER accounting_audit_no_change BEFORE UPDATE OR DELETE ON accounting_audit_log
                FOR EACH ROW EXECUTE FUNCTION accounting_audit_immutable();');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_no_change ON accounting_audit_log; DROP FUNCTION IF EXISTS accounting_audit_immutable();');
        }
        foreach (['intercompany_transactions', 'journal_attachments', 'accounting_audit_log', 'period_lock_requests', 'recurring_journals', 'deferral_items', 'deferral_schedules', 'budget_lines', 'budgets', 'dimension_defaults', 'analytic_dimensions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->dropColumn(['outlet', 'project', 'cost_centre']);
        });
    }
};

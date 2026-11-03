<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A-B payables, tax and close: approval chains, invoice capture, dunning, VAT returns, withholding tax,
// stamp duty, payroll filings, statement packs, bank rules, month-end close. The application never deletes from these tables.
return new class extends Migration
{
    public function up(): void
    {
        $tenant = function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
        };

        // ---- Payables ----
        Schema::create('invoice_approval_chains', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('name');
            $t->unsignedBigInteger('min_amount')->default(0); // applies when invoice total >= min_amount (highest matching min wins)
            $t->unsignedBigInteger('max_amount')->nullable();
            $t->jsonb('steps'); // [{role, label}] in order
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('invoice_workflows', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('supplier_invoice_id')->constrained();
            $t->foreignId('chain_id')->nullable()->constrained('invoice_approval_chains');
            $t->string('status')->default('pending'); // pending, approved, rejected
            $t->foreignId('captured_by')->nullable()->constrained('users');
            $t->foreignId('submitted_by')->nullable()->constrained('users');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique('supplier_invoice_id');
        });
        Schema::create('invoice_approvals', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('workflow_id')->constrained('invoice_workflows');
            $t->foreignId('supplier_invoice_id')->constrained();
            $t->unsignedSmallInteger('step_no');
            $t->string('role');
            $t->string('label')->nullable();
            $t->string('status')->default('pending'); // pending, approved, rejected
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->foreignId('delegated_from')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['workflow_id', 'step_no']);
        });
        Schema::create('approval_delegations', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('from_user_id')->constrained('users');
            $t->foreignId('to_user_id')->constrained('users');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('reason')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('supplier_invoice_captures', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('status')->default('uploaded'); // uploaded, extracted, converted
            $t->string('filename');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->string('path');
            $t->string('engine')->nullable();
            $t->jsonb('extracted')->default('{}');  // what the extractor suggested
            $t->jsonb('fields')->default('{}');     // what the clerk confirmed
            $t->foreignId('supplier_invoice_id')->nullable()->constrained();
            $t->foreignId('captured_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'sha256']);
        });

        // ---- Dunning ----
        Schema::create('dunning_settings', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->boolean('interest_enabled')->default(false);
            $t->decimal('interest_rate', 6, 2)->default(0); // annual percent, simple interest on the overdue balance
            $t->jsonb('channels')->default('["email","whatsapp"]');
            $t->timestamps();
            $t->unique('hotel_id');
        });
        Schema::create('dunning_levels', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->unsignedTinyInteger('level');
            $t->unsignedSmallInteger('days_overdue');
            $t->unsignedBigInteger('fee')->default(0);
            $t->text('subject_fr')->nullable();
            $t->text('subject_en')->nullable();
            $t->text('body_fr')->nullable();
            $t->text('body_en')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'level']);
        });
        Schema::create('dunning_notices', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->unsignedTinyInteger('level');
            $t->unsignedInteger('days_overdue');
            $t->unsignedBigInteger('balance');
            $t->unsignedBigInteger('fee')->default(0);
            $t->unsignedBigInteger('interest')->default(0);
            $t->string('locale', 2)->default('fr');
            $t->string('subject');
            $t->text('body');
            $t->jsonb('channels')->default('[]');   // [{channel, recipient, outbox_id}]
            $t->string('status')->default('sent');   // sent, no_contact
            $t->foreignId('sent_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'invoice_id', 'level']);
        });
        Schema::create('dunning_holds', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->string('reason');
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('released_by')->nullable()->constrained('users');
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
        });

        // ---- Tax ----
        Schema::create('tax_profiles', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('country', 2);
            $t->jsonb('config'); // overrides on top of CountryProfiles defaults
            $t->timestamps();
            $t->unique('hotel_id');
        });
        Schema::create('vat_returns', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('country', 2);
            $t->date('period_from');
            $t->date('period_to');
            $t->unsignedSmallInteger('version')->default(1);
            $t->string('status')->default('draft'); // draft, filed, superseded
            $t->string('form_code')->nullable();
            $t->decimal('rate', 6, 2);
            $t->jsonb('figures');
            $t->jsonb('lines');
            $t->string('fingerprint', 64);
            $t->string('filing_reference')->nullable();
            $t->date('filed_on')->nullable();
            $t->foreignId('filed_by')->nullable()->constrained('users');
            $t->foreignId('settlement_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('amends_id')->nullable()->constrained('vat_returns');
            $t->foreignId('prepared_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'period_from', 'period_to']);
        });
        Schema::create('withholding_rates', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('code', 30);
            $t->string('name');
            $t->decimal('rate', 6, 2);
            $t->string('base')->default('net'); // net (HT) or gross (TTC)
            $t->unsignedBigInteger('threshold')->default(0); // no withholding on a payment base below this
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });
        Schema::create('supplier_tax_profiles', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('supplier_id')->constrained();
            $t->string('withholding_code', 30)->nullable();
            $t->boolean('exempt')->default(false);
            $t->string('niu')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'supplier_id']);
        });
        Schema::create('withholding_certificates', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('number', 24);
            $t->foreignId('supplier_id')->constrained();
            $t->unsignedBigInteger('supplier_payment_id')->nullable();
            $t->date('date');
            $t->string('code', 30);
            $t->decimal('rate', 6, 2);
            $t->unsignedBigInteger('base');
            $t->unsignedBigInteger('amount');
            $t->string('supplier_name');
            $t->string('supplier_niu')->nullable();
            $t->string('status')->default('issued'); // issued, remitted
            $t->date('remitted_on')->nullable();
            $t->string('remittance_reference')->nullable();
            $t->string('hash', 64);
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('withheld')->default(0);
        });
        Schema::create('stamp_duty_rules', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('code', 30);
            $t->string('name');
            $t->string('applies_to', 30); // cash_receipt, supplier_payment, contract, other
            $t->string('kind', 10)->default('percent'); // percent, fixed
            $t->decimal('rate', 8, 4)->default(0);       // percent of base
            $t->unsignedBigInteger('fixed_amount')->default(0);
            $t->unsignedBigInteger('min_base')->default(0);
            $t->unsignedBigInteger('min_duty')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });
        Schema::create('stamp_duty_entries', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('rule_id')->constrained('stamp_duty_rules');
            $t->string('source_type', 30)->nullable();
            $t->string('source_id', 40)->nullable();
            $t->date('date');
            $t->unsignedBigInteger('base');
            $t->unsignedBigInteger('amount');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('payroll_filings', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('kind', 20); // cnps, irpp, cfc_fne_cac
            $t->string('period', 7);
            $t->unsignedInteger('employees');
            $t->jsonb('totals');
            $t->string('file_hash', 64);
            $t->string('status')->default('generated'); // generated, filed
            $t->string('filing_reference')->nullable();
            $t->date('filed_on')->nullable();
            $t->foreignId('generated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'kind', 'period']);
        });

        // ---- Statements pack ----
        Schema::create('statement_packs', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status')->default('draft'); // draft, final
            $t->jsonb('payload');
            $t->string('hash', 64);
            $t->foreignId('generated_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        // ---- Bank rules ----
        Schema::create('bank_rules', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->string('name');
            $t->unsignedSmallInteger('priority')->default(100);
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->string('direction', 3)->default('any'); // in, out, any
            $t->string('match_mode', 10)->default('contains'); // contains, regex
            $t->string('pattern');
            $t->unsignedBigInteger('amount_min')->nullable();
            $t->unsignedBigInteger('amount_max')->nullable();
            $t->string('account_code', 20);
            $t->string('partner_type', 20)->nullable();
            $t->unsignedBigInteger('partner_id')->nullable();
            $t->string('label')->nullable();
            $t->boolean('auto_post')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('bank_rule_hits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('bank_statement_line_id')->constrained();
            $t->foreignId('bank_rule_id')->constrained();
            $t->string('status')->default('suggested'); // suggested, applied
            $t->timestamps();
            $t->unique('bank_statement_line_id');
        });

        // ---- Month-end close ----
        Schema::create('close_templates', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('title');
            $t->string('owner_role')->nullable();
            $t->unsignedSmallInteger('due_offset_days')->default(5); // days after month end
            $t->boolean('required')->default(true);
            $t->string('auto_check', 40)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('close_runs', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('status')->default('open'); // open, complete
            $t->foreignId('started_by')->nullable()->constrained('users');
            $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('lock_request_id')->nullable()->constrained('period_lock_requests');
            $t->timestamps();
            $t->unique(['hotel_id', 'year', 'month']);
        });
        Schema::create('close_tasks', function (Blueprint $t) use ($tenant) {
            $tenant($t);
            $t->foreignId('close_run_id')->constrained();
            $t->foreignId('template_id')->nullable()->constrained('close_templates');
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('title');
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->string('owner_role')->nullable();
            $t->date('due_date');
            $t->boolean('required')->default(true);
            $t->string('auto_check', 40)->nullable();
            $t->jsonb('check_result')->nullable();
            $t->string('status')->default('pending'); // pending, done, skipped
            $t->foreignId('signed_by')->nullable()->constrained('users');
            $t->timestamp('signed_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropColumn('withheld'));
        foreach (['close_tasks', 'close_runs', 'close_templates', 'bank_rule_hits', 'bank_rules', 'statement_packs', 'payroll_filings',
            'stamp_duty_entries', 'stamp_duty_rules', 'withholding_certificates', 'supplier_tax_profiles', 'withholding_rates', 'vat_returns',
            'tax_profiles', 'dunning_holds', 'dunning_notices', 'dunning_levels', 'dunning_settings', 'supplier_invoice_captures',
            'approval_delegations', 'invoice_approvals', 'invoice_workflows', 'invoice_approval_chains'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Q5: pay links (QR), inbound MoMo / statement matching, salary payout runs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 16)->unique();
            $t->string('target_type', 16); // folio | pos_check | invoice | reservation
            $t->unsignedBigInteger('target_id');
            $t->string('label')->nullable();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('amount_paid')->default(0);
            $t->string('currency', 3)->default('XAF');
            $t->boolean('allow_partial')->default(true);
            $t->string('payer_phone', 30)->nullable();
            $t->string('status', 16)->default('open'); // open | partially_paid | paid | cancelled
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['target_type', 'target_id']);
        });

        Schema::table('payment_intents', function (Blueprint $t) {
            $t->foreignId('payment_link_id')->nullable()->constrained();
        });

        // Money received outside a started intent: unsolicited MoMo callbacks and imported statement lines.
        Schema::create('payment_inbound', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('source', 16); // callback | statement
            $t->string('provider', 32)->nullable();
            $t->string('transaction_id', 100)->nullable();
            $t->unsignedBigInteger('bank_statement_line_id')->nullable();
            $t->unsignedBigInteger('amount');
            $t->string('payer_phone', 30)->nullable();
            $t->string('message')->nullable();
            $t->date('date');
            $t->string('status', 16)->default('review'); // review | posted | ignored
            $t->foreignId('payment_link_id')->nullable()->constrained();
            $t->unsignedTinyInteger('confidence')->default(0);
            $t->jsonb('candidates')->default('[]');
            $t->foreignId('payment_intent_id')->nullable()->constrained();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'source', 'transaction_id']);
            $t->unique(['bank_statement_line_id']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('payout_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('payroll_run_id')->unique()->constrained();
            $t->string('period', 7);
            $t->string('status', 16)->default('draft'); // draft | processing | completed
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('paid')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('payout_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_run_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('payslip_id')->constrained();
            $t->string('channel', 16); // mtn_momo | orange_money | bank
            $t->string('destination', 60)->nullable();
            $t->string('bank_name')->nullable();
            $t->string('employee_name');
            $t->unsignedBigInteger('amount');
            $t->string('status', 16)->default('pending'); // pending | exported | succeeded | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('reference', 64)->unique();
            $t->string('provider_reference')->nullable();
            $t->string('driver', 32)->nullable();
            $t->string('error')->nullable();
            $t->unsignedBigInteger('journal_entry_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['payout_run_id', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payout_runs');
        Schema::dropIfExists('payment_inbound');
        Schema::table('payment_intents', fn (Blueprint $t) => $t->dropConstrainedForeignId('payment_link_id'));
        Schema::dropIfExists('payment_links');
    }
};

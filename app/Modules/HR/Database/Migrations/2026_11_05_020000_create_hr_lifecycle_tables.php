<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Q2 employment lifecycle: e-contracts + e-signature, raises/promotions, pay elements, transfers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contract_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('kind', 16); // CDI, CDD, stage, journalier, avenant
            $t->string('locale', 2)->default('fr');
            $t->string('title');
            $t->text('body'); // text with {{placeholders}}
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['group_id', 'kind', 'locale']);
        });

        Schema::create('hr_econtracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('contract_id')->nullable()->constrained();
            $t->string('number', 30);
            $t->string('kind', 16);
            $t->string('locale', 2)->default('fr');
            $t->string('title');
            $t->text('body');
            $t->string('status', 20)->default('draft'); // draft, sent, employee_signed, completed, cancelled
            $t->string('token_hash', 64)->nullable()->unique();
            $t->timestamp('token_expires_at')->nullable();
            $t->string('otp_hash', 64)->nullable();
            $t->timestamp('otp_expires_at')->nullable();
            $t->unsignedTinyInteger('otp_attempts')->default(0);
            $t->string('otp_channel', 12)->nullable();
            $t->timestamp('otp_verified_at')->nullable();
            $t->text('employee_signature')->nullable(); // PNG data URL
            $t->timestamp('employee_signed_at')->nullable();
            $t->string('employee_ip', 45)->nullable();
            $t->string('employee_user_agent')->nullable();
            $t->text('employer_signature')->nullable();
            $t->foreignId('employer_signed_by')->nullable()->constrained('users');
            $t->timestamp('employer_signed_at')->nullable();
            $t->string('employer_ip', 45)->nullable();
            $t->string('pdf_path')->nullable();
            $t->string('pdf_sha256', 64)->nullable();
            $t->string('body_sha256', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('hr_econtract_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('econtract_id')->constrained('hr_econtracts');
            $t->string('event', 40);
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->jsonb('meta')->default('{}');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('hr_compensation_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('type', 12); // raise, promotion
            $t->unsignedBigInteger('from_salary');
            $t->unsignedBigInteger('to_salary');
            $t->foreignId('from_position_id')->nullable()->constrained('positions');
            $t->foreignId('to_position_id')->nullable()->constrained('positions');
            $t->date('effective_date');
            $t->text('reason')->nullable();
            $t->string('status', 12)->default('pending'); // pending, approved, rejected, applied
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->foreignId('econtract_id')->nullable()->constrained('hr_econtracts');
            $t->timestamps();
        });

        Schema::create('hr_salary_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->unsignedBigInteger('base_salary');
            $t->foreignId('position_id')->nullable()->constrained('positions');
            $t->date('effective_date');
            $t->string('source', 16); // hire, raise, promotion, transfer
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('hr_pay_elements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('kind', 12); // one_off, recurring
            $t->unsignedBigInteger('amount');
            $t->boolean('taxable')->default(true);
            $t->boolean('cnps')->default(true);
            $t->string('period', 7)->nullable(); // one_off: Y-m
            $t->string('start_period', 7)->nullable(); // recurring
            $t->string('end_period', 7)->nullable();
            $t->string('status', 12)->default('active'); // active, cancelled
            $t->unsignedBigInteger('service_charge_pool_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('hr_service_charge_pools', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('period', 7);
            $t->unsignedBigInteger('amount');
            $t->string('method', 8); // points, days
            $t->jsonb('shares')->default('[]'); // [{employee_id, weight, amount}]
            $t->string('status', 12)->default('draft'); // draft, distributed
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('hr_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('from_hotel_id')->constrained('hotels');
            $t->foreignId('to_hotel_id')->constrained('hotels');
            $t->foreignId('from_department_id')->nullable()->constrained('departments');
            $t->foreignId('to_department_id')->nullable()->constrained('departments');
            $t->foreignId('from_position_id')->nullable()->constrained('positions');
            $t->foreignId('to_position_id')->nullable()->constrained('positions');
            $t->unsignedBigInteger('new_base_salary')->nullable();
            $t->string('from_number', 20)->nullable();
            $t->string('to_number', 20)->nullable();
            $t->date('effective_date');
            $t->text('reason')->nullable();
            $t->string('status', 12)->default('pending'); // pending, approved, rejected, completed
            $t->date('seniority_date')->nullable();
            $t->decimal('leave_balance', 6, 1)->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hr_transfers', 'hr_service_charge_pools', 'hr_pay_elements', 'hr_salary_history', 'hr_compensation_changes', 'hr_econtract_audit', 'hr_econtracts', 'hr_contract_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

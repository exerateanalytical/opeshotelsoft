<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->string('cost_center')->nullable(); // rooms, fnb, admin...
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('department_id')->constrained();
            $t->string('title');
            $t->string('category')->nullable(); // convention collective category/échelon
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('number', 20);
            $t->string('first_name');
            $t->string('last_name');
            $t->string('gender', 1)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('nationality', 2)->default('CM');
            $t->string('id_number')->nullable();
            $t->string('cnps_number')->nullable();
            $t->string('tax_number')->nullable(); // NIU
            $t->string('marital_status')->nullable();
            $t->unsignedTinyInteger('children')->default(0);
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('payment_method')->default('bank'); // bank, mobile_money, cash
            $t->string('bank_name')->nullable();
            $t->string('bank_account')->nullable();
            $t->string('momo_number')->nullable();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('position_id')->nullable()->constrained();
            $t->date('hire_date');
            $t->date('termination_date')->nullable();
            $t->string('termination_reason')->nullable();
            $t->string('status')->default('active'); // active, on_leave, suspended, terminated
            $t->unsignedBigInteger('base_salary')->default(0); // monthly
            $t->jsonb('allowances')->default('[]'); // [{code, name, amount, taxable, cnps}]
            $t->decimal('leave_days_per_month', 4, 2)->default(1.5);
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('kind'); // CDI, CDD, stage, journalier
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('probation_end')->nullable();
            $t->unsignedBigInteger('base_salary');
            $t->unsignedTinyInteger('hours_per_week')->default(40);
            $t->string('status')->default('active'); // active, ended, renewed
            $t->timestamps();
        });

        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->date('date');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('role')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'date', 'starts_at']);
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->date('date');
            $t->timestamp('clock_in');
            $t->timestamp('clock_out')->nullable();
            $t->unsignedSmallInteger('worked_minutes')->default(0);
            $t->unsignedSmallInteger('overtime_minutes')->default(0);
            $t->string('source')->default('app'); // app, web, biometric, manual
            $t->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->boolean('paid')->default(true);
            $t->boolean('accrues')->default(false); // annual leave accrues monthly
            $t->timestamps();
            $t->unique(['group_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('leave_type_id')->constrained();
            $t->date('start_date');
            $t->date('end_date');
            $t->decimal('days', 5, 1);
            $t->string('status')->default('pending'); // pending, approved, rejected, cancelled
            $t->text('reason')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('public_holidays', function (Blueprint $t) {
            $t->id();
            $t->string('country', 2);
            $t->date('date');
            $t->string('name');
            $t->unique(['country', 'date']);
        });

        Schema::create('payroll_rule_packs', function (Blueprint $t) {
            $t->id();
            $t->string('country', 2);
            $t->string('version');
            $t->date('effective_from');
            $t->jsonb('params');
            $t->boolean('certified')->default(false);
            $t->string('certified_by')->nullable();
            $t->timestamp('certified_at')->nullable();
            $t->timestamps();
            $t->unique(['country', 'version']);
        });

        Schema::create('salary_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('kind')->default('advance'); // advance, loan
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('monthly_deduction');
            $t->unsignedBigInteger('balance');
            $t->date('granted_on');
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('period', 7); // YYYY-MM
            $t->foreignId('payroll_rule_pack_id')->constrained();
            $t->string('status')->default('draft'); // draft, approved, paid
            $t->unsignedBigInteger('gross')->default(0);
            $t->unsignedBigInteger('net')->default(0);
            $t->unsignedBigInteger('employee_social')->default(0);
            $t->unsignedBigInteger('employer_social')->default(0);
            $t->unsignedBigInteger('taxes_withheld')->default(0);
            $t->unsignedBigInteger('other_employer')->default(0);
            $t->unsignedBigInteger('advances_recovered')->default(0);
            $t->foreignId('prepared_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->unsignedBigInteger('gross');
            $t->unsignedBigInteger('taxable');
            $t->unsignedBigInteger('net');
            $t->unsignedBigInteger('employee_social');
            $t->unsignedBigInteger('employer_social');
            $t->unsignedBigInteger('taxes_withheld');
            $t->unsignedBigInteger('other_employer');
            $t->unsignedBigInteger('advances_recovered')->default(0);
            $t->jsonb('lines');
            $t->jsonb('inputs')->default('{}'); // bonuses, overtime hours, service charge...
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['payslips', 'payroll_runs', 'salary_advances', 'payroll_rule_packs', 'public_holidays', 'leave_requests', 'leave_types',
            'attendances', 'shifts', 'contracts', 'employees', 'positions', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

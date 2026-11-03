<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** W11: employee documents, org chart, onboarding, overtime approval, holiday flags, payslip language. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('reports_to')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('payslip_language', 2)->default('fr');
        });

        Schema::table('public_holidays', function (Blueprint $t) {
            $t->boolean('movable')->default(false); // religious holidays entered per year by an admin
        });

        Schema::create('employee_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20); // contract, id, diploma, other
            $t->string('title')->nullable();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->date('expires_on')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'expires_on']);
        });

        Schema::create('onboarding_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('position_id')->constrained()->cascadeOnDelete();
            $t->jsonb('tasks')->default('[]'); // [{title, owner_role, due_days}]
            $t->timestamps();
            $t->unique('position_id');
        });

        Schema::create('onboarding_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('owner_role')->nullable();
            $t->date('due_on')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('overtime_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('category', 12); // weekday, night, sunday, holiday
            $t->unsignedSmallInteger('minutes');
            $t->string('status')->default('pending'); // pending, approved, rejected
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['attendance_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_entries');
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_templates');
        Schema::dropIfExists('employee_documents');
        Schema::table('public_holidays', fn (Blueprint $t) => $t->dropColumn('movable'));
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reports_to');
            $t->dropColumn('payslip_language');
        });
    }
};

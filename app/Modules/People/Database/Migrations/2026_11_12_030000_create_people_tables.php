<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Q3 People: performance reviews, guest feedback on staff, tasks, discipline, exits. */
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
        Schema::create('people_review_cycles', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name', 120);
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 16)->default('draft'); // draft, open, calibration, closed
            $t->unsignedTinyInteger('scale')->default(5);
            $t->timestamps();
        });

        Schema::create('people_reviews', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('cycle_id')->constrained('people_review_cycles');
            $t->unsignedBigInteger('employee_id')->index(); // HR employee, referenced by id
            $t->unsignedBigInteger('manager_employee_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('status', 16)->default('pending'); // pending, self_done, manager_done, closed
            $t->decimal('self_rating', 4, 2)->nullable();
            $t->decimal('manager_rating', 4, 2)->nullable();
            $t->decimal('calibrated_rating', 4, 2)->nullable();
            $t->text('self_comment')->nullable();
            $t->text('manager_comment')->nullable();
            $t->string('calibration_note')->nullable();
            $t->timestamp('self_submitted_at')->nullable();
            $t->timestamp('manager_submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['cycle_id', 'employee_id']);
        });

        Schema::create('people_objectives', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('review_id')->constrained('people_reviews')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('weight'); // percent, objectives of a review sum to 100
            $t->decimal('self_score', 4, 2)->nullable();
            $t->decimal('manager_score', 4, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('people_feedback', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('source', 16)->default('manual'); // manual, review
            $t->unsignedBigInteger('guest_id')->nullable();
            $t->string('external_ref', 100)->nullable();
            $t->unsignedTinyInteger('rating')->nullable(); // 1..5
            $t->string('sentiment', 10); // positive, neutral, negative
            $t->text('comment')->nullable();
            $t->date('received_on');
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'source', 'external_ref', 'employee_id']);
        });

        Schema::create('people_tasks', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('assignee_employee_id')->nullable()->index();
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->timestamp('due_at')->nullable();
            $t->string('priority', 10)->default('normal'); // low, normal, high, urgent
            $t->string('status', 16)->default('open'); // open, in_progress, done, cancelled
            $t->jsonb('checklist')->default('[]'); // [{label, done}]
            $t->boolean('requires_photo')->default(false);
            $t->text('completion_note')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('people_task_photos', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('task_id')->constrained('people_tasks')->cascadeOnDelete();
            $t->string('path');
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('people_disciplinary_actions', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('kind', 20); // verbal_warning, written_warning, suspension
            $t->date('incident_date');
            $t->string('reason', 200);
            $t->text('facts')->nullable();
            $t->date('suspension_start')->nullable();
            $t->date('suspension_end')->nullable();
            $t->string('status', 16)->default('issued'); // issued, acknowledged, refused, withdrawn
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('acknowledgement_note')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->timestamps();
        });

        Schema::create('people_disciplinary_documents', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('action_id')->constrained('people_disciplinary_actions')->cascadeOnDelete();
            $t->string('title', 150)->nullable();
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('people_exits', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('kind', 20); // resignation, termination, end_of_contract
            $t->string('reason')->nullable();
            $t->boolean('gross_misconduct')->default(false);
            $t->date('notice_date');
            $t->date('last_day');
            $t->boolean('notice_worked')->default(true);
            $t->boolean('is_executive')->default(false); // cadre: longer notice
            $t->jsonb('settlement')->nullable();
            $t->unsignedBigInteger('settlement_total')->default(0);
            $t->string('status', 16)->default('draft'); // draft, approved
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('certificate_number', 40)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['people_exits', 'people_disciplinary_documents', 'people_disciplinary_actions', 'people_task_photos', 'people_tasks',
            'people_feedback', 'people_objectives', 'people_reviews', 'people_review_cycles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

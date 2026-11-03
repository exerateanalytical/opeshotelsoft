<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hotel(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('group_id')->constrained();
        $t->foreignId('hotel_id')->constrained();
    }

    public function up(): void
    {
        // ---- group level catalogues
        Schema::create('tal_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->string('category')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'code']);
        });
        Schema::create('tal_cert_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->unsignedSmallInteger('validity_months')->nullable();
            $t->boolean('mandatory')->default(false);
            $t->unsignedSmallInteger('alert_days')->default(60);
            $t->timestamps();
            $t->unique(['group_id', 'code']);
        });
        Schema::create('tal_courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('title');
            $t->foreignId('skill_id')->nullable()->constrained('tal_skills');
            $t->unsignedTinyInteger('skill_level')->nullable();
            $t->foreignId('cert_type_id')->nullable()->constrained('tal_cert_types');
            $t->decimal('duration_hours', 6, 1)->default(0);
            $t->string('provider')->nullable();
            $t->unsignedBigInteger('default_cost')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // ---- recruitment
        Schema::create('tal_job_posts', function (Blueprint $t) {
            $this->hotel($t);
            $t->string('title');
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('position_id')->nullable()->constrained();
            $t->text('description')->nullable();
            $t->text('requirements')->nullable();
            $t->string('location')->nullable();
            $t->string('contract_kind', 12)->default('CDI');
            $t->unsignedBigInteger('salary_min')->nullable();
            $t->unsignedBigInteger('salary_max')->nullable();
            $t->unsignedSmallInteger('openings')->default(1);
            $t->string('status', 12)->default('draft'); // draft, open, closed
            $t->date('closes_on')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tal_applicants', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('job_post_id')->nullable()->constrained('tal_job_posts');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('cover_note')->nullable();
            $t->string('cv_path')->nullable();
            $t->string('cv_name')->nullable();
            $t->string('source', 30)->default('careers');
            $t->string('stage', 12)->default('applied'); // applied, screening, interview, offer, hired, rejected, withdrawn
            $t->boolean('talent_pool')->default(false);
            $t->timestamp('consent_at')->nullable();
            $t->date('retain_until')->nullable();
            $t->timestamp('anonymised_at')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained();
            $t->timestamps();
            $t->index(['hotel_id', 'stage']);
        });
        Schema::create('tal_applicant_notes', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('applicant_id')->constrained('tal_applicants')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('kind', 12)->default('note'); // note, stage
            $t->string('stage', 12)->nullable();
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('tal_interviews', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('applicant_id')->constrained('tal_applicants')->cascadeOnDelete();
            $t->timestamp('scheduled_at');
            $t->string('location')->nullable();
            $t->string('kind', 12)->default('onsite');
            $t->jsonb('panel')->default('[]'); // user ids
            $t->jsonb('criteria')->default('[]'); // names of scoring criteria
            $t->string('status', 12)->default('scheduled'); // scheduled, done, cancelled
            $t->timestamps();
        });
        Schema::create('tal_interview_scores', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('interview_id')->constrained('tal_interviews')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->jsonb('scores')->default('[]'); // [{criterion, score 1-5}]
            $t->decimal('average', 4, 2)->default(0);
            $t->string('recommendation', 12); // hire, maybe, no
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->unique(['interview_id', 'user_id']);
        });
        Schema::create('tal_offers', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('applicant_id')->constrained('tal_applicants');
            $t->foreignId('job_post_id')->nullable()->constrained('tal_job_posts');
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('position_id')->nullable()->constrained();
            $t->string('contract_kind', 12)->default('CDI');
            $t->unsignedBigInteger('base_salary');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->text('message')->nullable();
            $t->string('status', 12)->default('draft'); // draft, sent, accepted, declined, withdrawn, expired
            $t->string('token_hash', 64)->nullable()->index();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained();
            $t->unsignedBigInteger('econtract_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        // ---- skills, certificates
        Schema::create('tal_position_skills', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('position_id')->constrained();
            $t->foreignId('skill_id')->constrained('tal_skills');
            $t->unsignedTinyInteger('required_level')->default(3);
            $t->timestamps();
            $t->unique(['position_id', 'skill_id']);
        });
        Schema::create('tal_employee_skills', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('skill_id')->constrained('tal_skills');
            $t->unsignedTinyInteger('level');
            $t->date('assessed_on')->nullable();
            $t->foreignId('assessed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['employee_id', 'skill_id']);
        });
        Schema::create('tal_certifications', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('cert_type_id')->constrained('tal_cert_types');
            $t->date('issued_on');
            $t->date('expires_on')->nullable();
            $t->string('reference')->nullable();
            $t->string('issuer')->nullable();
            $t->string('file_path')->nullable();
            $t->timestamp('last_alert_at')->nullable();
            $t->string('alert_stage', 10)->nullable(); // expiring, expired
            $t->timestamps();
        });

        // ---- training
        Schema::create('tal_training_sessions', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('course_id')->constrained('tal_courses');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('location')->nullable();
            $t->string('trainer')->nullable();
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->unsignedBigInteger('cost')->default(0); // total session cost
            $t->string('status', 12)->default('planned'); // planned, done, cancelled
            $t->timestamps();
        });
        Schema::create('tal_training_attendances', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('session_id')->constrained('tal_training_sessions')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->string('attendance', 10)->default('enrolled'); // enrolled, attended, absent
            $t->string('result', 10)->nullable(); // passed, failed
            $t->decimal('score', 5, 1)->nullable();
            $t->unsignedBigInteger('cost')->default(0);
            $t->boolean('applied')->default(false);
            $t->timestamps();
            $t->unique(['session_id', 'employee_id']);
        });

        // ---- headcount and succession
        Schema::create('tal_headcount_budgets', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('department_id')->constrained();
            $t->string('month', 7);
            $t->unsignedSmallInteger('budgeted');
            $t->timestamps();
            $t->unique(['hotel_id', 'department_id', 'month']);
        });
        Schema::create('tal_key_positions', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('position_id')->constrained();
            $t->string('risk', 8)->default('medium'); // low, medium, high
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'position_id']);
        });
        Schema::create('tal_successors', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('position_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('readiness', 10)->default('1_2y'); // now, 1_2y, 3y_plus
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['position_id', 'employee_id']);
        });

        // ---- self-service requests, settings, payroll checks, privacy log
        Schema::create('tal_requests', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('employee_id')->constrained();
            $t->string('kind', 24); // employment_certificate, salary_certificate, address_change, phone_change, bank_change, momo_change
            $t->jsonb('payload')->default('{}');
            $t->string('status', 10)->default('pending'); // pending, approved, rejected
            $t->text('note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tal_settings', function (Blueprint $t) {
            $this->hotel($t);
            $t->boolean('payroll_review_required')->default(false);
            $t->unsignedSmallInteger('variance_pct')->default(20);
            $t->decimal('overtime_multiple', 4, 1)->default(3.0);
            $t->unsignedSmallInteger('applicant_retention_months')->default(12);
            $t->unsignedSmallInteger('pool_retention_months')->default(24);
            $t->unsignedSmallInteger('leaver_retention_months')->default(60);
            $t->timestamps();
            $t->unique('hotel_id');
        });
        Schema::create('tal_payroll_reviews', function (Blueprint $t) {
            $this->hotel($t);
            $t->foreignId('payroll_run_id')->constrained('payroll_runs');
            $t->jsonb('checks')->default('[]');
            $t->jsonb('acknowledged')->default('[]'); // check codes
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->unique('payroll_run_id');
        });
        Schema::create('tal_privacy_log', function (Blueprint $t) {
            $this->hotel($t);
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->string('action', 12); // export, erase
            $t->string('subject_ref')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->jsonb('meta')->default('{}');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tal_privacy_log', 'tal_payroll_reviews', 'tal_settings', 'tal_requests', 'tal_successors', 'tal_key_positions', 'tal_headcount_budgets',
            'tal_training_attendances', 'tal_training_sessions', 'tal_certifications', 'tal_employee_skills', 'tal_position_skills', 'tal_offers',
            'tal_interview_scores', 'tal_interviews', 'tal_applicant_notes', 'tal_applicants', 'tal_job_posts', 'tal_courses', 'tal_cert_types', 'tal_skills'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

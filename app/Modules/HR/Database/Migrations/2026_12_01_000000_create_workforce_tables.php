<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// H-A workforce: demand forecast, rostering, swaps, leave policies, geofenced clocking, anomalies. All additive.
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
        Schema::create('workforce_settings', function (Blueprint $t) {
            $this->tenant($t);
            $t->decimal('rest_hours_min', 4, 1)->default(11);       // rest between two shifts
            $t->unsignedSmallInteger('max_weekly_hours')->default(48);
            $t->unsignedSmallInteger('max_daily_hours')->default(10);
            $t->unsignedSmallInteger('max_days_per_week')->default(6); // at least one weekly rest day
            $t->unsignedSmallInteger('max_consecutive_nights')->default(5);
            $t->string('night_start', 5)->default('22:00');
            $t->string('night_end', 5)->default('06:00');
            $t->decimal('geo_lat', 9, 6)->nullable();
            $t->decimal('geo_lng', 9, 6)->nullable();
            $t->unsignedInteger('geo_radius_m')->default(150);
            $t->boolean('geo_required')->default(false);
            $t->boolean('photo_required')->default(false);
            $t->boolean('pin_enabled')->default(true);
            $t->unsignedSmallInteger('late_tolerance_min')->default(10);
            $t->unsignedSmallInteger('max_speed_kmh')->default(150);
            $t->unsignedSmallInteger('missing_out_after_hours')->default(14);
            $t->timestamps();
            $t->unique('hotel_id');
        });

        Schema::create('workforce_dept_rules', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('department_id')->constrained();
            $t->string('driver', 12)->default('inhouse'); // arrivals, departures, inhouse, none
            $t->unsignedSmallInteger('units_per_staff')->default(15);
            $t->unsignedSmallInteger('min_staff')->default(1);
            $t->jsonb('revenue_keys')->default('[]'); // folio_lines.department values counted as this department's revenue
            $t->boolean('include_pos')->default(false);
            $t->timestamps();
            $t->unique(['hotel_id', 'department_id']);
        });

        Schema::create('workforce_shift_templates', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('department_id')->constrained();
            $t->string('name');
            $t->string('starts_at', 5);
            $t->string('ends_at', 5);
            $t->unsignedSmallInteger('share')->default(100); // percent of the day's demand covered by this shift
            $t->unsignedSmallInteger('min_staff')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('workforce_rosters', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('department_id')->nullable()->constrained();
            $t->date('week_start');
            $t->string('status', 12)->default('draft'); // draft, published
            $t->jsonb('forecast')->default('[]');
            $t->jsonb('warnings')->default('[]');
            $t->foreignId('generated_by')->nullable()->constrained('users');
            $t->foreignId('published_by')->nullable()->constrained('users');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('workforce_roster_shifts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('roster_id')->constrained('workforce_rosters')->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('employee_id')->nullable()->constrained(); // null = open shift
            $t->date('date');
            $t->string('starts_at', 5);
            $t->string('ends_at', 5);
            $t->string('role')->nullable();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->timestamps();
            $t->index(['roster_id', 'date']);
        });

        Schema::create('workforce_requests', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('kind', 8); // swap, claim
            $t->foreignId('roster_shift_id')->constrained('workforce_roster_shifts')->cascadeOnDelete();
            $t->foreignId('requester_id')->constrained('employees');
            $t->foreignId('counterpart_id')->nullable()->constrained('employees');
            $t->foreignId('target_shift_id')->nullable()->constrained('workforce_roster_shifts')->nullOnDelete(); // swap: the shift taken in exchange
            $t->string('status', 16)->default('pending'); // pending, accepted, approved, rejected, cancelled
            $t->string('note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('workforce_leave_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('leave_type_id')->constrained();
            $t->decimal('accrual_per_month', 4, 2)->default(1.5);
            $t->decimal('max_balance', 6, 1)->nullable();
            $t->decimal('carry_over_max', 6, 1)->default(0);
            $t->unsignedSmallInteger('carry_over_expiry_months')->nullable(); // carried days expire N months into the new year
            $t->jsonb('seniority_rules')->default('[]'); // [{years, extra_days}] extra days per year once the seniority is reached
            $t->timestamps();
            $t->unique(['group_id', 'leave_type_id']);
        });

        Schema::create('workforce_leave_ledger', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('leave_type_id')->constrained();
            $t->string('kind', 12); // accrual, seniority, carry_over, expiry, adjustment
            $t->string('period', 40); // YYYY-MM, YYYY, YYYY-end, YYYY-carry
            $t->date('entry_date');
            $t->decimal('days', 6, 2);
            $t->date('expires_on')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'leave_type_id', 'kind', 'period']);
        });

        Schema::create('workforce_pins', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('employee_id')->constrained();
            $t->string('pin_hash');
            $t->unsignedTinyInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamps();
            $t->unique('employee_id');
        });

        Schema::create('workforce_clock_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('attendance_id')->nullable()->constrained();
            $t->string('direction', 3);
            $t->string('method', 8); // gps, pin, web
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();
            $t->unsignedInteger('accuracy_m')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('inside_geofence')->nullable();
            $t->string('photo_path')->nullable();
            $t->string('photo_hash', 64)->nullable();
            $t->string('device_id', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['employee_id', 'occurred_at']);
            $t->index(['device_id', 'occurred_at']);
        });

        Schema::create('workforce_anomalies', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('attendance_id')->nullable()->constrained();
            $t->foreignId('clock_event_id')->nullable()->constrained('workforce_clock_events')->nullOnDelete();
            $t->date('date');
            $t->string('kind', 24); // late, missing_clock_out, impossible_travel, buddy_punch, outside_geofence, no_photo
            $t->string('severity', 8)->default('warning'); // info, warning, high
            $t->jsonb('detail')->default('{}');
            $t->string('status', 10)->default('open'); // open, reviewed, dismissed
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['workforce_anomalies', 'workforce_clock_events', 'workforce_pins', 'workforce_leave_ledger', 'workforce_leave_policies', 'workforce_requests',
            'workforce_roster_shifts', 'workforce_rosters', 'workforce_shift_templates', 'workforce_dept_rules', 'workforce_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

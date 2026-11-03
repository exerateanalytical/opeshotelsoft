<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-A revenue engine: rate rules, packages, suggestions, overbooking, upsell, promo codes, corporate contracts. */
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
        Schema::create('revenue_settings', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedTinyInteger('high_occupancy_pct')->default(85);
            $t->unsignedTinyInteger('low_occupancy_pct')->default(40);
            $t->decimal('high_occupancy_uplift', 5, 2)->default(10);
            $t->decimal('low_occupancy_discount', 5, 2)->default(10);
            $t->unsignedTinyInteger('pace_threshold_pct')->default(15); // % of capacity picked up in the last 7 days
            $t->decimal('pace_uplift', 5, 2)->default(5);
            $t->decimal('weekend_uplift', 5, 2)->default(5);
            $t->json('weekend_days')->nullable(); // 0=Sunday .. 6=Saturday
            $t->decimal('competitor_undercut_pct', 5, 2)->default(0); // 0 = match the competitor average
            $t->decimal('max_change_pct', 5, 2)->default(25);
            $t->unsignedInteger('rounding')->default(500);
            $t->json('upsell_discount_pct')->nullable(); // {booking, pre_arrival, check_in}
            $t->text('walk_policy')->nullable();
            $t->unsignedBigInteger('walk_compensation')->default(0);
            $t->string('walk_partner_hotel')->nullable();
            $t->timestamps();
            $t->unique('hotel_id');
        });

        Schema::create('rate_plan_rules', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('min_los')->nullable();
            $t->unsignedSmallInteger('max_los')->nullable();
            $t->unsignedSmallInteger('advance_min_days')->nullable(); // must book at least N days before arrival
            $t->unsignedSmallInteger('advance_max_days')->nullable(); // and at most N days before
            $t->json('closed_arrival_days')->nullable(); // weekdays (0-6) on which arrival is not allowed
            $t->bigInteger('derived_adjust_amount')->default(0); // fixed amount per night on top of derived percent
            $t->timestamps();
            $t->unique('rate_plan_id');
        });

        Schema::create('rate_plan_packages', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->json('inclusions')->nullable(); // [{code,name,qty}]
            $t->string('pricing', 12)->default('per_room'); // per_room | per_person
            $t->unsignedBigInteger('room_amount')->default(0);  // per night, whole room
            $t->unsignedBigInteger('adult_amount')->default(0); // per adult per night
            $t->unsignedBigInteger('child_amount')->default(0); // per child per night
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique('rate_plan_id');
        });

        Schema::create('pricing_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name', 150);
            $t->date('start_date');
            $t->date('end_date');
            $t->decimal('impact_pct', 5, 2)->default(10);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'start_date']);
        });

        Schema::create('competitor_rates', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('competitor', 120);
            $t->date('date');
            $t->foreignId('room_type_id')->nullable()->constrained();
            $t->unsignedBigInteger('rate');
            $t->foreignId('entered_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('rate_suggestions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('rate_plan_id')->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->date('date');
            $t->unsignedBigInteger('current_rate');
            $t->unsignedBigInteger('suggested_rate');
            $t->decimal('change_pct', 6, 2);
            $t->json('reasons');
            $t->string('status', 12)->default('pending'); // pending, accepted, rejected, superseded
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'date']);
        });

        Schema::create('overbooking_policies', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('room_type_id')->constrained();
            $t->unsignedSmallInteger('max_rooms')->default(0);
            $t->decimal('max_percent', 5, 2)->default(0); // of physical rooms; the lower cap applies when both are set
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique('room_type_id');
        });

        Schema::create('walk_records', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('reservation_id')->constrained();
            $t->string('partner_hotel')->nullable();
            $t->unsignedBigInteger('compensation')->default(0);
            $t->boolean('transport_paid')->default(false);
            $t->text('note')->nullable();
            $t->foreignId('walked_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('upsell_offers', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('reservation_id')->constrained();
            $t->foreignId('from_room_type_id')->constrained('room_types');
            $t->foreignId('to_room_type_id')->constrained('room_types');
            $t->string('stage', 12); // booking, pre_arrival, check_in
            $t->unsignedBigInteger('price_per_night');
            $t->unsignedSmallInteger('nights');
            $t->unsignedBigInteger('total');
            $t->string('status', 10)->default('offered'); // offered, accepted, declined, expired
            $t->string('channel', 12)->default('staff');
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->unsignedBigInteger('folio_line_id')->nullable();
            $t->timestamps();
            $t->index(['reservation_id', 'status']);
        });

        Schema::create('promo_codes', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 24);
            $t->string('name', 120);
            $t->string('type', 8)->default('percent'); // percent | amount (per stay)
            $t->decimal('value', 12, 2);
            $t->date('book_from')->nullable();
            $t->date('book_to')->nullable();
            $t->date('stay_from')->nullable();
            $t->date('stay_to')->nullable();
            $t->unsignedTinyInteger('min_nights')->default(1);
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->json('room_type_ids')->nullable();
            $t->json('rate_plan_ids')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('promo_redemptions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('promo_code_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->unsignedBigInteger('discount');
            $t->timestamps();
            $t->unique('reservation_id');
        });

        Schema::create('corporate_contracts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('company_id')->constrained();
            $t->foreignId('rate_plan_id')->constrained();
            $t->string('code', 24);
            $t->string('name', 150);
            $t->date('valid_from');
            $t->date('valid_to');
            $t->boolean('last_room_availability')->default(false);
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('corporate_allotments', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_block_id')->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->unsignedSmallInteger('rooms');
            $t->date('start_date');
            $t->date('end_date'); // inclusive night
            $t->unsignedSmallInteger('release_days')->default(0); // unsold rooms go back to general stock N days before the night
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['corporate_allotments', 'corporate_contracts', 'promo_redemptions', 'promo_codes', 'upsell_offers', 'walk_records', 'overbooking_policies',
            'rate_suggestions', 'competitor_rates', 'pricing_events', 'rate_plan_packages', 'rate_plan_rules', 'revenue_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

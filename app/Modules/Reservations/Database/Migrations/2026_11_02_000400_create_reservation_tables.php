<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('meal_plan')->default('RO'); // RO, BB, HB, FB, AI
            $t->boolean('refundable')->default(true);
            $t->unsignedSmallInteger('cancellation_hours')->default(24);
            $t->string('cancellation_penalty')->default('first_night'); // none, first_night, percent, full
            $t->decimal('penalty_percent', 5, 2)->default(0);
            $t->boolean('deposit_required')->default(false);
            $t->decimal('deposit_percent', 5, 2)->default(0);
            $t->boolean('members_only')->default(false);
            $t->foreignId('company_id')->nullable()->constrained(); // negotiated/corporate rate
            $t->foreignId('parent_rate_plan_id')->nullable()->constrained('rate_plans');
            $t->decimal('derived_adjust_percent', 6, 2)->default(0); // e.g. -10.00 from parent
            $t->boolean('public')->default(true); // bookable on website/channels
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        // Daily rate & restriction grid (ARI).
        Schema::create('rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained();
            $t->date('date');
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('extra_adult')->default(0);
            $t->unsignedBigInteger('extra_child')->default(0);
            $t->unsignedTinyInteger('min_stay')->default(1);
            $t->unsignedTinyInteger('max_stay')->nullable();
            $t->boolean('closed')->default(false);
            $t->boolean('closed_to_arrival')->default(false);
            $t->boolean('closed_to_departure')->default(false);
            $t->timestamps();
            $t->unique(['rate_plan_id', 'room_type_id', 'date']);
            $t->index(['hotel_id', 'date']);
        });

        // Inventory ledger per room type and night; locked during booking.
        Schema::create('room_type_inventory', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->date('date');
            $t->unsignedSmallInteger('sold')->default(0);
            $t->unsignedSmallInteger('blocked')->default(0);  // group blocks not yet picked up
            $t->unsignedSmallInteger('out_of_order')->default(0);
            $t->smallInteger('overbooking_limit')->default(0);
            $t->boolean('stop_sell')->default(false);
            $t->unique(['room_type_id', 'date']);
        });

        Schema::create('room_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('rate_plan_id')->nullable()->constrained();
            $t->date('arrival');
            $t->date('departure');
            $t->date('cutoff_date')->nullable();
            $t->string('status')->default('tentative'); // tentative, definite, released, cancelled
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('room_block_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_block_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained();
            $t->date('date');
            $t->unsignedSmallInteger('rooms');
            $t->unsignedSmallInteger('picked_up')->default(0);
            $t->unsignedBigInteger('rate')->nullable();
            $t->unique(['room_block_id', 'room_type_id', 'date']);
        });

        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->string('booking_ref', 20)->index(); // shared by rooms booked together
            $t->string('status')->default('confirmed'); // tentative, confirmed, checked_in, checked_out, cancelled, no_show
            $t->foreignId('guest_id')->constrained();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('agent_id')->nullable()->constrained('companies');
            $t->foreignId('room_block_id')->nullable()->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->foreignId('rate_plan_id')->constrained();
            $t->foreignId('room_id')->nullable()->constrained();
            $t->date('arrival');
            $t->date('departure');
            $t->unsignedTinyInteger('adults')->default(1);
            $t->unsignedTinyInteger('children')->default(0);
            $t->string('channel')->default('direct'); // direct, phone, walk_in, website, app, booking_com, expedia, channel_manager, gds
            $t->string('source')->nullable();
            $t->string('external_ref')->nullable();
            $t->string('currency', 3)->default('XAF');
            $t->unsignedBigInteger('total_amount')->default(0);
            $t->unsignedBigInteger('deposit_required')->default(0);
            $t->unsignedBigInteger('deposit_paid')->default(0);
            $t->string('guarantee')->nullable(); // card, deposit, company, none
            $t->time('eta')->nullable();
            $t->text('special_requests')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason')->nullable();
            $t->unsignedBigInteger('cancellation_fee')->default(0);
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('checked_out_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status', 'arrival']);
            $t->index(['hotel_id', 'departure']);
            $t->index(['channel', 'external_ref']);
        });

        Schema::create('reservation_nights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->unsignedBigInteger('amount');
            $t->boolean('posted')->default(false);
            $t->unique(['reservation_id', 'date']);
        });

        Schema::create('waitlist_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('guest_id')->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->date('arrival');
            $t->date('departure');
            $t->string('status')->default('waiting');
            $t->timestamps();
        });

        DB::statement('ALTER TABLE room_type_inventory ADD CONSTRAINT inventory_non_negative CHECK (sold >= 0 AND blocked >= 0 AND out_of_order >= 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservation_dates CHECK (departure > arrival)');
    }

    public function down(): void
    {
        foreach (['waitlist_entries', 'reservation_nights', 'reservations', 'room_block_allocations', 'room_blocks', 'room_type_inventory', 'rates', 'rate_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

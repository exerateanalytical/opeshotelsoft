<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->unique()->constrained();
            $t->string('name');
            $t->unsignedInteger('spend_per_point')->default(1000); // XAF spent per point earned
            $t->unsignedInteger('point_value')->default(10);       // XAF value of one point when redeemed
            $t->unsignedSmallInteger('expiry_months')->default(24);
            $t->jsonb('eligible_departments')->default('["rooms","fnb","beverage","spa","laundry","events"]');
            $t->unsignedInteger('enrolment_bonus')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $t->string('code', 16);
            $t->string('name');
            $t->unsignedInteger('min_qualifying_points')->default(0);
            $t->unsignedSmallInteger('min_qualifying_nights')->default(0);
            $t->decimal('earn_multiplier', 4, 2)->default(1);
            $t->jsonb('benefits')->default('[]'); // late_checkout, upgrade, welcome_drink, lounge, discount_percent...
            $t->unsignedTinyInteger('rank')->default(0);
            $t->timestamps();
            $t->unique(['loyalty_program_id', 'code']);
        });

        Schema::create('loyalty_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('guest_id')->constrained();
            $t->string('number', 20)->unique();
            $t->foreignId('loyalty_tier_id')->constrained();
            $t->bigInteger('points_balance')->default(0);
            $t->unsignedBigInteger('lifetime_points')->default(0);
            $t->unsignedInteger('lifetime_nights')->default(0);
            $t->string('status')->default('active'); // active, suspended, closed
            $t->foreignId('enrolled_hotel_id')->nullable()->constrained('hotels');
            $t->timestamp('enrolled_at');
            $t->timestamp('tier_reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'guest_id']);
        });

        Schema::create('loyalty_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_membership_id')->constrained()->cascadeOnDelete();
            $t->string('card_number', 20)->unique();
            $t->string('kind')->default('digital'); // digital, physical
            $t->string('token', 64)->unique(); // QR / barcode payload
            $t->string('status')->default('active'); // active, blocked, replaced
            $t->timestamp('issued_at');
            $t->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loyalty_membership_id')->constrained();
            $t->foreignId('hotel_id')->nullable()->constrained();
            $t->string('kind'); // earn, redeem, adjust, expire, bonus
            $t->bigInteger('points');
            $t->unsignedBigInteger('qualifying_points')->default(0);
            $t->unsignedSmallInteger('nights')->default(0);
            $t->unsignedBigInteger('spend')->default(0);
            $t->bigInteger('remaining')->default(0); // for FIFO expiry of earned points
            $t->date('expires_on')->nullable();
            $t->string('description');
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['loyalty_membership_id', 'source_type', 'source_id', 'kind']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions', 'loyalty_cards', 'loyalty_memberships', 'loyalty_tiers', 'loyalty_programs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

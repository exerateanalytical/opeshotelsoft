<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 (W06): rate plan seasons, travel agent IATA numbers, richer waitlist entries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plan_seasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->nullable()->constrained(); // null = every room type
            $t->string('name', 100);
            $t->date('start_date');
            $t->date('end_date'); // inclusive
            $t->decimal('modifier_percent', 6, 2)->nullable(); // e.g. +25.00 high season
            $t->unsignedBigInteger('rate')->nullable(); // fixed nightly rate, wins over modifier
            $t->timestamps();
            $t->index(['rate_plan_id', 'start_date', 'end_date']);
        });

        Schema::table('companies', function (Blueprint $t) {
            $t->string('iata_number', 20)->nullable();
        });

        Schema::table('waitlist_entries', function (Blueprint $t) {
            $t->foreignId('rate_plan_id')->nullable()->constrained();
            $t->unsignedTinyInteger('adults')->default(1);
            $t->unsignedTinyInteger('children')->default(0);
            $t->text('notes')->nullable();
            $t->timestamp('offered_at')->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->index(['hotel_id', 'status', 'arrival']);
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reservation_id');
            $t->dropConstrainedForeignId('rate_plan_id');
            $t->dropIndex(['hotel_id', 'status', 'arrival']);
            $t->dropColumn(['adults', 'children', 'notes', 'offered_at']);
        });
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('iata_number'));
        Schema::dropIfExists('rate_plan_seasons');
    }
};

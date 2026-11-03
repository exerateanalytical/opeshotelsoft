<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R-B reservation experience: payer + deposit deadline + room preferences, settings, guest purchases,
// reviews, room suggestions and rooming-list import log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('payer_guest_id')->nullable()->constrained('guests');
            $t->foreignId('split_from_id')->nullable()->constrained('reservations');
            $t->timestamp('deposit_deadline')->nullable();
            $t->timestamp('deposit_reminded_at')->nullable();
            $t->jsonb('room_prefs')->nullable(); // floor, view, quiet, together (booking_ref to stay next to)
        });

        Schema::table('rooms', function (Blueprint $t) {
            $t->string('view', 30)->nullable();
            $t->boolean('quiet')->default(false);
        });

        Schema::create('experience_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedSmallInteger('deposit_deadline_hours')->default(48);
            $t->unsignedSmallInteger('deposit_reminder_hours')->default(24);
            $t->boolean('auto_release')->default(true);
            $t->boolean('auto_no_show')->default(true);
            $t->boolean('guest_modify')->default(true);
            $t->unsignedSmallInteger('guest_modify_cutoff_hours')->default(48);
            $t->timestamps();
            $t->unique('hotel_id');
        });

        Schema::create('experience_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 24);
            $t->string('name');
            $t->unsignedBigInteger('price');
            $t->string('charge_code', 16);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('reservation_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->string('kind', 20); // service, early_checkin, late_checkout
            $t->string('label');
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->unsignedBigInteger('amount');
            $t->string('charge_code', 16);
            $t->string('requested_time', 5)->nullable();
            $t->string('status', 12)->default('pending'); // pending (not yet on a folio), posted
            $t->unsignedBigInteger('folio_line_id')->nullable();
            $t->string('source', 12)->default('guest');
            $t->timestamps();
            $t->index(['reservation_id', 'status']);
        });

        Schema::create('guest_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->unique()->constrained();
            $t->foreignId('guest_id')->constrained();
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->nullable();
            $t->timestamp('submitted_at');
            $t->timestamps();
        });

        Schema::create('room_suggestions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->integer('score');
            $t->jsonb('reasons')->default('[]');
            $t->string('status', 10)->default('pending'); // pending, accepted, rejected
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['reservation_id', 'room_id']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('rooming_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('room_block_id')->constrained();
            $t->string('filename');
            $t->string('status', 12); // validated, imported, failed
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_ok')->default(0);
            $t->jsonb('errors')->default('[]');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['rooming_imports', 'room_suggestions', 'guest_reviews', 'reservation_purchases', 'experience_services', 'experience_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn(['view', 'quiet']));
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payer_guest_id');
            $t->dropConstrainedForeignId('split_from_id');
            $t->dropColumn(['deposit_deadline', 'deposit_reminded_at', 'room_prefs']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hotel-configurable early/late check-out fees.
        Schema::create('checkout_fee_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('kind'); // late | early
            $t->unsignedTinyInteger('after_hour')->default(12); // late: applies when checking out at or after this hour
            $t->string('charge_code', 16);
            $t->string('fee_type')->default('percent_of_night'); // percent_of_night | fixed
            $t->unsignedBigInteger('value'); // percent (e.g. 50 = half-day) or FCFA
            $t->string('label')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['hotel_id', 'kind']);
        });

        // Per-reservation routing of charge codes or departments to another folio.
        Schema::create('folio_routing_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->string('match_type'); // charge_code | department | all
            $t->string('match_value', 32)->nullable();
            $t->foreignId('target_folio_id')->constrained('folios');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['reservation_id']);
        });

        Schema::table('guest_register_entries', function (Blueprint $t) {
            $t->string('id_photo_path')->nullable();
            $t->string('signature_path')->nullable();
            $t->timestamp('card_captured_at')->nullable();
            $t->foreignId('card_captured_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('guest_register_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('card_captured_by');
            $t->dropColumn(['id_photo_path', 'signature_path', 'card_captured_at']);
        });
        Schema::dropIfExists('folio_routing_rules');
        Schema::dropIfExists('checkout_fee_rules');
    }
};
